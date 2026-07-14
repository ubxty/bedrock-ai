# Real-World Patterns

> Companion to the [README](../README.md). Production patterns distilled from real deployments of `ubxty/bedrock-ai`.

---

## 1. Tenant-scoped invocation

For multi-tenant apps, isolate per-tenant rate-limit + rotation:

```php
class TenantBedrockAdapter
{
    public function __construct(
        private BedrockManager $bedrock,
        private string $tenantId,
    ) {}

    public function invoke(string $system, string $user): array
    {
        return $this->bedrock->invoke(
            modelId: config('core-ai.bedrock.defaults.model'),
            systemPrompt: $system,
            userMessage: $user,
        );
    }
}
```

The `$tenantId` is held by the wrapper class so it can be passed to downstream
listeners (e.g. an event listener that reads `auth()->user()->tenant_id` or
your own tenant resolver). The Bedrock SDK does not surface `tenant_id` into
CloudWatch dimensions by itself — fan out via your application listener on the
`BedrockInvoked` event and write a per-tenant aggregator cache of your own.

---

## 2. Cost-cap listener

Hard cap a tenant's monthly spend:

```php
Event::listen(BedrockInvoked::class, function (BedrockInvoked $e) {
    $tenant = auth()->user()?->tenant_id ?? 'default';
    $cost = $e->cost;
    $monthly = Cache::increment("tenant.{$tenant}.monthly_spend", $cost);

    if ($monthly > config("tenants.{$tenant}.cost_cap_usd", 1000)) {
        throw new CostLimitExceededException("Tenant {$tenant} over cap.");
    }
});
```

The exception is caught by the framework's exception handler — return `429: Over monthly cap` to the caller. (The `BedrockInvoked` event carries `modelId`, `inputTokens`, `outputTokens`, `cost`, `latencyMs`, and `keyUsed` — no per-call tenant tag is attached, so resolve tenant context from the auth/user inside the listener.)

---

## 3. Multi-turn + multimodal

Symptom mining pipeline:

```php
$result = Bedrock::conversation('amazon.nova-pro-v1:0')
    ->system('You extract symptom vectors from clinical case notes. Output JSON.')
    ->userWithDocument('Read this case.', '/tmp/case-123.pdf')
    ->user('Output: { "symptoms": [{ "code": "S-101", "severity": 3 }] }')
    ->schema([                                   // requires ubxty/core-ai ^2.1.3
        'type' => 'object',
        'properties' => [
            'symptoms' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ])
    ->temperature(0.0)
    ->maxTokens(2048)
    ->send();
```

Nova Lite + Claude 4 + Sonnet 4 all support the schema-out / JSON-mode pattern.

---

## 4. Idempotent worker (queue job)

```php
class ExtractCaseJob
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(): void
    {
        $key = $this->job->payload()['idempotency_key'] ?? null;

        $result = Bedrock::invoke(
            config('core-ai.bedrock.defaults.model'),
            '…',
            file_get_contents(storage_path("cases/{$this->caseId}.txt")),
            temperature: 0.0,
        );

        $this->keyUsed = $result['key_used'];  // for observability
        $this->cost    = $result['cost'];

        CaseModel::find($this->caseId)->update(['extraction' => $result['response']]);
    }
}
```

The package automatically attaches `Idempotency-Key = sha256(model|sys|user)` — a network-blip retry does not double-bill.

---

## 5. Cache-bypass loop (prompt engineering)

```php
$base = $system;
$samples = ['v1', 'v2', 'v3', 'v4'];

foreach ($samples as $variant) {
    config(['core-ai.cache.response_ttl' => 0]); // bypass cache
    $out = Bedrock::invoke('amazon.nova-pro-v1:0', $base, $variant);
    Storage::append("experiments/p1.log", "[$variant] {$out['response']}\n");
}
```

For evaluation, disable the response cache so each variant produces a fresh sample.

---

## 6. Multi-key round-robin across 2 regions

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => 'east-prod', 'auth_mode' => 'iam', 'aws_key' => env('AWS_KEY_PROD_EAST'), 'aws_secret' => env('AWS_SECRET_PROD_EAST'), 'region' => 'us-east-1'],
            ['label' => 'west-prod', 'auth_mode' => 'iam', 'aws_key' => env('AWS_KEY_PROD_WEST'), 'aws_secret' => env('AWS_SECRET_PROD_WEST'), 'region' => 'us-west-2'],
            ['label' => 'east-dr',   'auth_mode' => 'iam', 'aws_key' => env('AWS_KEY_DR_EAST'),   'aws_secret' => env('AWS_SECRET_DR_EAST'),   'region' => 'us-east-1'],
        ],
    ],
],
```

Three keys across two regions. Two keys in `us-east-1` are redundant — one is primary, one is DR. The `us-west-2` key is for failover when the `us-east-1` region is impaired.

---

## 7. Cost-aware image analysis

When the user uploads a 5-page PDF, you don't want to send 25k tokens of image to sonnet 4. Split + downsample:

```php
$pages = Pdf::split($path);          // local lib
$summaries = [];

foreach ($pages as $i => $page) {
    $img = Imagick::thumbnail($page->toImage(), 1024, 1024);

    $summaries[] = Bedrock::conversation('amazon.nova-lite-v1:0')  // cheap model for OCR
        ->userWithImage('Read this page', $img)
        ->maxTokens(512)
        ->send()['response'];
}

$aggregated = Bedrock::invoke(  // now use the bigger model
    config('core-ai.bedrock.defaults.model'),
    'You compile multiple page summaries into one structured record.',
    json_encode(['summaries' => $summaries]),
    maxTokens: 8192,
);
```

`nova-lite` is ~1/10th the per-page cost of sonnet 4 for OCR, and the structured aggregation is small. Saves 60-80 % vs "send the PDF to sonnet 4 directly".

---

## 8. Live call centre assistant

Streaming + session storage:

```php
$session = AiSession::find($sid);

return Bedrock::conversation('anthropic.claude-sonnet-4-20250514-v1:0')
    ->system($session->systemPrompt)
    ->user('')  // dummy
    ->history($session->messages)  // ConversationBuilder::history() — append-mode (requires ubxty/core-ai ^2.1.3)
    ->user($request->userMessage)
    ->stream();
```

Persist the assistant's streamed chunks to `$session->messages` after the stream completes.

---

## 9. Embedding ingestion with concurrency

For a 100k-row corpus:

```php
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

Bus::batch(
    collect($corpus)->chunk(1000)->map(
        fn ($chunk, $i) => new IngestEmbeddingsJob($chunk->all(), $tenantId, $i),
    )->toArray()
)->name('embeddings-v3')->dispatch();

class IngestEmbeddingsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private array $texts, private string $tenantId, private int $batchIdx)
    {
        $this->onQueue('embeddings');
    }

    public function handle(): void
    {
        $vectors = Bedrock::embed('amazon.titan-embed-text-v2:0', $this->texts, dimensions: 1024);

        DB::table("embeddings_{$this->tenantId}")->insert(
            array_map(fn ($v, $t) => ['text' => $t, 'vec' => pack('g*', ...$v)], $vectors, $this->texts)
        );
    }
}
```

Per-text SHA256 caching means re-running a batch (e.g. on a job failure) is free.

---

## 10. Audit log via `BedrockInvoked`

A simple structured-log audit trail:

```php
Event::listen(BedrockInvoked::class, function (BedrockInvoked $e) {
    Log::channel('audit')->info('ai.invoke', [
        'model'      => $e->modelId,
        'cost'       => $e->cost,
        'tokens_in'  => $e->inputTokens,
        'tokens_out' => $e->outputTokens,
        'key_used'   => $e->keyUsed,
        'latency_ms' => $e->latencyMs,
    ]);
});
```

The `BedrockInvoked` event exposes `modelId`, `inputTokens`, `outputTokens`,
`cost`, `latencyMs`, `keyUsed` — there is no per-event `cacheHit`,
`idempotencyKey`, or `tags` property. For per-call tenant attribution, resolve
`auth()->user()->tenant_id` (or your tenant resolver) inside the listener and
write to your own aggregator.

Pipe `LOG_CHANNEL_AI=audit` to your compliance log store.

---

## 11. Replay-safe retries in distributed workers

In a stack with multiple Laravel workers consuming the same queue, two workers might pick up the same idempotency-keyed job. Prevent double-billing by stamping the `Idempotency-Key` on the queue:

```php
$job = (new ExtractCaseJob($caseId))->onQueue('cases');
$job->setIdempotencyKey("case-{$caseId}-v2"); // Laravel 11+
dispatch($job);
```

If the same job runs twice, the underlying Bedrock call returns the same cached response.

---

## 12. Rate-limit-aware queue throttling

```php
Event::listen(BedrockRateLimited::class, function (BedrockRateLimited $e) {
    // BedrockRateLimited exposes modelId, keyLabel, retryAttempt, waitSeconds.
    // waitSeconds is the upstream Retry-After hint (seconds), or 0 if not present.
    $secs = $e->waitSeconds ?: 30;
    Cache::put('ai.rate_limited_until', now()->addSeconds($secs));
    Log::warning('rate limited', ['for' => $secs, 'model' => $e->modelId, 'key' => $e->keyLabel]);
});
```

Workers can then sleep before their next invoke:

```php
if (Cache::has('ai.rate_limited_until') && now()->lt(Cache::get('ai.rate_limited_until'))) {
    $this->release(now()->diffInSeconds(Cache::get('ai.rate_limited_until')));
}
```

This prevents a thundering herd when a region's quota is exhausted.
