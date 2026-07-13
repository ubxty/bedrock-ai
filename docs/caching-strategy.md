# Caching Strategy

> Companion to the [README](../README.md). Every cache layer in the package, with the cost math that justifies each.

---

## The five cache layers

| Layer | Where | TTL default | Key shape |
|---|---|---|---|
| Model catalogue | `core-ai.bedrock.cache.models_ttl` | 3600 s | `bedrock_ai_models_*` |
| CloudWatch usage | `core-ai.bedrock.cache.usage_ttl` | 900 s | `bedrock_ai_usage_*` |
| AWS Pricing API | `core-ai.bedrock.cache.pricing_ttl` | 86400 s | `bedrock_ai_pricing_*` |
| Response cache (v2.1.0) | `core-ai.cache.response_ttl` | 0 (off) | `bedrock_ai_response_{sha256(...)}` |
| Embedding cache (v2.1.0) | `core-ai.cache.embedding_ttl` | 604800 s (7 d) | `bedrock_ai_embeddings_{sha256(...)}` |
| Prompt cache markers (v2.1.0) | `core-ai.bedrock.prompt_caching.points` | upstream 300 s (max 3600) | injected into Converse payload |

The package does NOT cache:

- The daily / monthly cost counters (`bedrock_ai_daily_cost_…`, `bedrock_ai_monthly_cost_…`) — those are the spend ledger, not a memo.
- Streaming responses (`converseStream()` / `BedrockManager::stream()`).
- Authentication state.

---

## 1. Prompt cache markers (`cachePoint`)

The biggest single lever. Bedrock charges full rate for the first call's prefix, then ~10% for any subsequent call's prefix that matches a `cachePoint` anchor.

### Anchors

| Anchor | Where it lands | When useful |
|---|---|---|
| `system` | After the system-prompt content blocks | Static system prompts reused across calls. The most common case. |
| `last_user` | After the last user-message content blocks | A user message + cached assistant reply prefix pattern (re-used multi-turn headers). |

Up to 4 checkpoints per Converse request (Bedrock limit). 2 anchors is the typical configuration.

### Configuration

```php
'bedrock' => [
    'prompt_caching' => [
        'points'     => ['system', 'last_user'],
        'ttl_seconds' => 300, // 5 min, max 3600 (1 h)
    ],
],
```

Or via env:

```dotenv
BEDROCK_PROMPT_CACHE_POINTS=system,last_user
BEDROCK_PROMPT_CACHE_TTL=600
```

### Caveat: unsupported models

Bedrock returns a 400 if a `cachePoint` is placed on a model that doesn't support prompt caching. If you switch models and start seeing `400 cachePoint is not supported for this model`, drop the cache-points from config.

### Cost math

Suppose a Claude 3.5 Sonnet call has:

- 600-token system prompt (static).
- 100-token user message (varies).
- 200-token output.

| Scenario | Input bill |
|---|---|
| No cache | 700 input × $0.003/1k = $0.0021 |
| `cachePoint` on `system`, identical system within TTL | 100 fresh × $0.003/1k + 600 cached × 10% × $0.003/1k = $0.00048 |

**~77% off the input-token bill on cached calls.** The output bill is unaffected.

---

## 2. Response cache (`core-ai.cache.response_ttl`)

In-process memoisation. The manager caches `invoke()` and `converse()` results in Laravel's default cache store. Bypassing is trivial (change `temperature` by 0.001 or `maxTokens` by 1).

### When to enable

- Re-ingestion of structured data (CSV → JSON).
- Templated replies (`summarise this`, `classify this support ticket`).
- Anything `(model, system, user, max, temp)`-keyed that repeats inside the TTL window.

### When NOT to enable

- Live chat with rolling history (cache miss every turn = wasted compute).
- Streaming responses.

### Configuration

```php
'cache' => [
    'response_ttl' => 3600, // memoise for 1 hour
],
```

### Cache key

```php
hash('sha256', "{$modelId}|{$systemPrompt}|{$userMessage}|{$maxTokens}|{$temperature}");
```

prefixed with `bedrock_ai_response_`.

### Bypass

Vary any of the five input parts. To force-recompute the same content on demand:

```php
use Illuminate\Support\Facades\Cache;
Cache::forget('bedrock_ai_response_' . hash('sha256', "model|sys|user|1024|0.2"));
```

---

## 3. Embedding cache (`core-ai.cache.embedding_ttl`)

`BedrockManager::embed()` memoise per-text vectors:

```php
$vectors = $manager->embed('amazon.titan-embed-text-v2:0', $corpus, dimensions: 1024);
```

The cache key is `sha256(model|dimensions|text)`. Changing any of those resets the cache.

### When to invalidate

You almost never need to. If the source text changes for one item, the new SHA differs and a new row is written. The 7-day default is the time-to-cold; you can stretch it:

```php
'cache' => [
    'embedding_ttl' => 30 * 86400, // 30 days
],
```

---

## 4. Idempotency-Key (v2.1.0+)

`BedrockClient::invoke()` derives an `Idempotency-Key` HTTP header from `sha256(modelId|system|user)` and attaches it on the Bearer-mode HTTP path:

```
Idempotency-Key: <sha256 of modelId|system|user>
```

Bedrock uses the header to deduplicate retries. A network-blip retry (or `withRetry()` exhaustion) returns the same cached response instead of double-billing. From your app's perspective, two requests with the same key are idempotent.

### Compute the same key in your code

```php
$key = app(BedrockManager::class)->idempotencyKey($modelId, $systemPrompt.$userMessage);
// 'bedrock_ai-<sha256 hash>'
```

Use it when storing invocation metadata so retries can be traced by key.

---

## 5. `Retry-After` honouring (v2.1.0+)

When the upstream returns `429: Too Many Requests`, the Bearer-mode HTTP path captures the `Retry-After` header before throwing. `HasRetryLogic::withRetry()` prefers the hint over the exponential backoff:

| Scenario | Effective wait |
|---|---|
| Exponential path (no hint) | 2 s, 4 s, 8 s (per `BEDROCK_RETRY_DELAY`) |
| With `Retry-After` hint | Often 5-30 s — the actual upstream cooldown |

Recovery is usually much faster with hints. If the upstream doesn't include `Retry-After`, the exponential path is the fallback.

---

## 6. Putting it all together

For a hot path that uses a 600-token static system prompt and 1k variable user messages per hour:

| Lever | Without v2.1.0 | With v2.1.0 (all on) |
|---|---|---|
| Input cost per call (avg 700 tokens) | $0.0021 | $0.00048 + 0 % retries |
| Network blip retry | double-billed | deduplicated upstream |
| Rate-limit backoff | 14 s exponential | 5-30 s upstream cooldown |
| Repeated prompt (e.g. ingest) | full rate | 0 cost |

For ingestion of a 100k-row corpus:

| Lever | Impact |
|---|---|
| `core-ai.cache.embedding_ttl = 7 days` | One-shot cost; re-runs are free |

---

## 7. Cache store

The package uses Laravel's default cache store. For production:

```dotenv
CACHE_STORE=redis
```

Row sizes:

- Response cache: a few KB per row.
- Embedding cache: vector bytes (1.5 KB for 1024-dim float embeddings).
- Model catalogue: 1-50 KB per `listFoundationModels` response.

Redis is recommended for prod. The file driver works for local dev. APCu is fast but evicted on php-fpm restart — avoid for the embedding cache.

---

## Worked example: 1M calls/day

Inputs:

- 1M chat calls/day.
- 600-token static system prompt.
- 100-token average user message.
- 200-token average output.
- 1 % network-blip retry rate.

| Layer | Per-call cost (avg) | Daily |
|---|---|---|
| Base (no v2.1.0) | input $0.0021, retry +1 % × $0.0021 | $2.10 / call × 1.01M ≈ $2,121/day |
| `cachePoint` on `system` | input $0.00048, no retry cost | $0.48 / call × 1M ≈ $480/day |
| `response_ttl = 1h` on templated calls (20 %) | 0 cost on 20 % of calls | (-$0.0001 × 200k) ≈ -$20/day |
| `Retry-After` honouring | 30 % faster recovery (latency win, not cost) | (latency) |

Net: ~$1.6k/day saved on a 1M-call hot path, + ~30 % faster recovery. Multiplied by 30 days = ~$48k/month. For 10 hot paths: ~$580k/year.

Numbers use Claude 3.5 Sonnet's published $0.003/1k input rate and 10 % cached rate. Plug your own rates in.

---

## Caveats and pitfalls

- **In-memory cache**: the package does NOT use per-process memory. All caches go through Laravel's cache facade. Multi-process safety is automatic.
- **Content-hash determinism**: the SHA256 hash is a function of `$systemPrompt` concatenated with `$userMessage`. Whitespace, casing, and Unicode all matter — `Claude.\n` and `Claude.` hash differently.
- **Cache-busting for a fast iteration loop**: if you're testing prompts, set `cache.response_ttl = 0` or change `temperature` between iterations.
- **Memory pressure**: if the response cache holds 100k entries, your Redis or file cache will swell. Set a sensible TTL.
