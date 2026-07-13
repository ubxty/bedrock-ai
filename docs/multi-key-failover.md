# Multi-Key Failover

> Companion to the [README](../README.md). How the `keys[]` array works, when rotation fires, and how to design a 2-region, 2-account failover for production.

---

## The shape

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => 'Primary',   'auth_mode' => 'iam',    'aws_key' => '…', 'aws_secret' => '…', 'region' => 'us-east-1'],
            ['label' => 'Secondary', 'auth_mode' => 'iam',    'aws_key' => '…', 'aws_secret' => '…', 'region' => 'us-west-2'],
            ['label' => 'Bearer',    'auth_mode' => 'bearer', 'bearer_token' => '…', 'region' => 'us-east-1'],
        ],
    ],
],
```

The credential manager (`Ubxty\BedrockAi\Client\CredentialManager`) normalises each entry, tracks `currentIndex`, and exposes `current()` / `next()` / `reset()` / `select($i)` / `count()` / `currentIndex()`. Rotation is in-order — there's no random sharding.

## When rotation fires

`HasRetryLogic::withRetry()` (inherited from core-ai, mounted on `BedrockClient` / `ConverseClient` / `StreamingClient`) rotates when:

1. The current attempt is rate-limited (`429`, `ThrottlingException`) AND the retry budget on the current key is exhausted.
2. The current attempt fails with an auth error (e.g. `403: SignatureDoesNotMatch`, `403: ExpiredToken`).
3. The SDK raises any non-rate-limit error after the retry budget is exhausted.

It does NOT rotate on:

- Programming errors (4xx other than 429/403).
- Stream interruptions (the streaming path reconnects on the same key with `Retry-After`).
- Cost-limit-exceeded (this throws `CostLimitExceededException` synchronously).

After a key is exhausted, `BedrockKeyRotated` fires. After all keys are exhausted on rate-limit, `BedrockRateLimited` fires (and a `RateLimitException` is thrown).

## Exponential backoff vs `Retry-After`

The retry path prefers an upstream `Retry-After` header (seconds) over the exponential backoff when one is captured from the HTTP response. With hints, recovery is often 5-30 s; without, `2 s → 4 s → 8 s`.

```dotenv
BEDROCK_MAX_RETRIES=3       # attempts per key
BEDROCK_RETRY_DELAY=2       # base delay (doubles each attempt)
```

Total recovery time before rotating to the next key:

- With hint: typically `Retry-After` seconds (e.g. 17).
- Without hint: 2 + 4 + 8 = 14 seconds.

If all keys fail, the manager throws `RateLimitException` (every `keys[]` × `max_retries` combination was exhausted).

---

## Multi-region pattern

A common production setup:

```dotenv
# Primary: dedicated IAM key for prod workload
BEDROCK_AWS_KEY_PRIMARY=AKIA…
BEDROCK_AWS_SECRET_PRIMARY=…
BEDROCK_REGION_PRIMARY=us-east-1
BEDROCK_KEY_LABEL_PRIMARY=primary-prod

# Secondary: same account, different region (same key + us-west-2 fallback)
BEDROCK_AWS_KEY_SECONDARY=AKIA…
BEDROCK_AWS_SECRET_SECONDARY=…
BEDROCK_REGION_SECONDARY=us-west-2
BEDROCK_KEY_LABEL_SECONDARY=west-coast

# Tertiary: a long-lived Bearer token as last resort
BEDROCK_BEARER_TOKEN_FALLBACK=ABSK…
BEDROCK_REGION_FALLBACK=us-east-1
BEDROCK_AUTH_MODE_FALLBACK=bearer
```

Then bind those in config (the env-var-driven config picks them up):

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => env('BEDROCK_KEY_LABEL_PRIMARY', 'primary'),   'auth_mode' => 'iam',    'aws_key'    => env('BEDROCK_AWS_KEY_PRIMARY'),    'aws_secret'  => env('BEDROCK_AWS_SECRET_PRIMARY'),    'region' => env('BEDROCK_REGION_PRIMARY', 'us-east-1')],
            ['label' => env('BEDROCK_KEY_LABEL_SECONDARY', 'west'),   'auth_mode' => 'iam',    'aws_key'    => env('BEDROCK_AWS_KEY_SECONDARY'),  'aws_secret'  => env('BEDROCK_AWS_SECRET_SECONDARY'),  'region' => env('BEDROCK_REGION_SECONDARY', 'us-west-2')],
            ['label' => env('BEDROCK_KEY_LABEL_FALLBACK', 'fallback'), 'auth_mode' => 'bearer', 'bearer_token' => env('BEDROCK_BEARER_TOKEN_FALLBACK'), 'region'  => env('BEDROCK_REGION_FALLBACK', 'us-east-1')],
        ],
    ],
],
```

## Cross-account pattern

For multi-account resilience (production account = primary; backup account = secondary IAM):

```php
'connections' => [
    'default' => [
        'keys' => [
            // Account A — production workload
            ['label' => 'prod-account', 'auth_mode' => 'iam', 'aws_key' => env('AWS_KEY_PROD'), 'aws_secret' => env('AWS_SECRET_PROD'), 'region' => 'us-east-1'],
            // Account B — DR account
            ['label' => 'dr-account',   'auth_mode' => 'iam', 'aws_key' => env('AWS_KEY_DR'),   'aws_secret' => env('AWS_SECRET_DR'),   'region' => 'us-east-1'],
        ],
    ],
],
```

Both keys need access to the same model ARNs in both accounts. Configure Bedrock model access identically in both accounts (the one-time use-case form is account-scoped, so submit in each).

## Programming with rotation in mind

The `key_used` field of every invocation result tells you which key succeeded:

```php
$result = Bedrock::invoke(…);
Log::info('ai.invoke', ['key_used' => $result['key_used'], 'cost' => $result['cost']]);
```

Listen on `BedrockKeyRotated` for alerting:

```php
Event::listen(BedrockKeyRotated::class, function ($e) {
    Log::warning('rotation', ['from' => $e->fromKeyLabel, 'to' => $e->toKeyLabel, 'reason' => $e->reason, 'model' => $e->modelId]);
});
```

The `reason` field contains the upstream error message (or "429: Too many requests") that triggered the rotation. Use it to cluster alerts —repeated `"ThrottlingException"` on the same key is a quota issue; `"SignatureDoesNotMatch"` is a credential rotation lapse.

## Multiple connections

For different environments (prod + staging, or prod per tenant), use multiple connections:

```php
'connections' => [
    'prod'    => ['keys' => [['label' => 'prod-1', 'auth_mode' => 'iam', 'aws_key' => env('AWS_KEY_PROD'), 'aws_secret' => env('AWS_SECRET_PROD'), 'region' => 'us-east-1']]],
    'staging' => ['keys' => [['label' => 'stage-1','auth_mode' => 'iam', 'aws_key' => env('AWS_KEY_STAGE'),'aws_secret' => env('AWS_SECRET_STAGE'),'region' => 'us-east-1']]],
],
```

Switch at call time:

```php
$result = Bedrock::invoke('sonnet', $sys, $user, connection: 'staging');
```

## Disabling rotation programmatically

If you want to pin a single key (e.g. for testing or for compliance isolation), use `select()`:

```php
$cm = app(BedrockManager::class)->client()->getCredentialManager();
$cm->select(0); // pin to the first key
```

The next call uses key 0 unconditionally. The retry path still rotates on errors, though — use this for instrumentation, not for production pinning.
