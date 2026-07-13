# Embeddings — `BedrockManager::embed()`

> Companion to the [README](../README.md). Reference for the v2.1.0 batch embeddings method.

---

## Quickstart

```php
use Ubxty\BedrockAi\Facades\Bedrock;

$corpus = [
    'The quick brown fox jumps over the lazy dog.',
    'To be or not to be, that is the question.',
    'All your base are belong to us.',
];

$vectors = Bedrock::embed('amazon.titan-embed-text-v2:0', $corpus, dimensions: 1024);
// [
//     [0.0123, -0.0456, 0.0789, …],  // 1024 floats
//     [0.0234, -0.0567, 0.0890, …],
//     [0.0345, -0.0678, 0.0901, …],
// ]
```

The method signature:

```php
public function embed(
    string $modelId,
    array $texts,
    ?int $dimensions = null,
    ?string $connection = null,
): array;
```

| Parameter | Description |
|---|---|
| `$modelId` | Bedrock model ID — `amazon.titan-embed-text-v2:0`, `cohere.embed-english-v3`, `cohere.embed-multilingual-v3`, `amazon.titan-embed-text-v1`. Aliases resolved first. |
| `$texts` | Array of strings. Order preserved in the returned array. Empty arrays return empty array. |
| `$dimensions` | Optional vector size. Titan v2 supports 1024 / 512 / 256. Cohere v3 returns 1024 only. `null` → model default. |
| `$connection` | Optional named connection. Defaults to `core-ai.bedrock.default`. |

Return: `array<int, float[]>` — same indices as `$texts`. Each row is the embedding for `$texts[$i]`.

---

## Caching

Per-row memoisations under `core-ai.cache.embedding_ttl` (default 7 days = 604800 s):

| Field | Description |
|---|---|
| Key prefix | `bedrock_ai_embeddings_` |
| Key hash | `sha256(modelId \| dimensions \| text)` |
| Value | The vector as a `float[]` |

Cache hit = zero AWS spend. Cache miss = one Bedrock `InvokeModel` call (in Bearer mode: HTTP POST; in IAM mode: AWS SDK call).

To extend the TTL for slowly-changing corpora:

```php
'cache' => [
    'embedding_ttl' => 30 * 86400, // 30 days
],
```

The cache is invalidated automatically when you change `$modelId` or `$dimensions` (different hash). To force-refresh a single row, call `embed()` after updating the text — different text hash = different key.

For full invalidation (e.g. on production model retraining):

```php
use Illuminate\Support\Facades\Cache;

// Drop everything prefixed with bedrock_ai_embeddings_:
foreach (Cache::getRedis()->keys('bedrock_ai_embeddings_*') as $key) {
    Cache::forget($key);
}
```

---

## Supported models

| Model ID | Native dim | Allowed dim | Notes |
|---|---|---|---|
| `amazon.titan-embed-text-v2:0` | 1024 | 1024 / 512 / 256 | Multilingual. Pass `dimensions` to truncate / normalise. |
| `amazon.titan-embed-text-v1` | 1536 | 1536 | Older English-only. |
| `cohere.embed-english-v3` | 1024 | 1024 | English-only. |
| `cohere.embed-multilingual-v3` | 1024 | 1024 | Multilingual. |

`amazon.titan-embed-image-v1` is for image input — not exposed through this method. Use the SDK directly.

---

## Auth differences

In Bearer mode (ABSK token), `embed()` makes a POST to:

```
https://bedrock-runtime.{$region}.amazonaws.com/model/{$modelId}/invoke
```

with body:

```json
{
  "inputText": "…",
  "dimensions": 1024,
  "normalize": true
}
```

In IAM mode, the SDK calls `InvokeModel` with the same body. Both paths return:

```json
{
  "embedding": [0.0123, -0.0456, …],
  "inputTextTokenCount": 12
}
```

The `inputTextTokenCount` is logged but not returned by `embed()` (the caller doesn't need per-text token counts by default).

---

## Batch sizing

The package processes each text individually (no internal batching), but the per-row cache means re-ingest is free within the TTL. Parallel HTTP is the responsibility of the host app — the package does not pool requests.

For a 1M-row corpus with average 200-token texts, parallel HTTP at concurrency 50 with p50 latency 200 ms / text achieves the full ingestion in ~1 hour.

```php
use Illuminate\Support\Facades\Concurrency;

Concurrency::driver('process')->run(
    fn () => Bedrock::embed('amazon.titan-embed-text-v2:0', $chunk, dimensions: 1024),
    // …
);
```

> Concurrency drivers vary by Laravel version — `Concurrency::run()` (Laravel 11+) requires `spatie/fork` or `reactivex/rxphp`. Check Laravel docs for the right driver for your stack.

---

## Use cases

- **Semantic search corpora** — embed your documents once (cached for 7+ days), then at query time embed the user prompt and do a vector similarity search.
- **RAG retrieval** — combine with a vector DB (Postgres `pgvector`, Pinecone, Qdrant) for nearest-neighbour lookup.
- **Document clustering** — embed, then cluster via K-means / UMAP.
- **Topic modelling** — embed and feed to a classifier.

---

## Failure modes

| Symptom | Likely cause | Fix |
|---|---|---|
| `BedrockException("Bedrock embed returned no vector for text index N")` | Model returned a non-array for that row (rare) | Skip or retry just that row. |
| `BedrockException("Bedrock embed HTTP 4XX …")` | Auth error, region restricted, model ID typo | Check `isConfigured()` first. |
| All rows return the same vector | You're sending the same text for all rows (caching returns the same cached vector) | Verify texts are distinct. |
| Cache lock contention on huge batches | N/A — the cache is per-row, no lock. | — |
