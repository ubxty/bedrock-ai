# Cost & Usage Reference

> Companion to the [README](../README.md). Everything to do with `PricingService`, `CostExplorerService`, and `UsageTracker`.

---

## Three services, three purposes

| Service | Talks to | Output | Purpose |
|---|---|---|---|
| `PricingService` | AWS Pricing API | Model price map (per 1k input / output tokens by region) | Convert token counts → dollars. |
| `CostExplorerService` | AWS Cost Explorer (ce API) | Aggregated cost + usage breakdowns by service, region, time period | Monthly / dashboard breakdown by Bedrock. |
| `UsageTracker` | CloudWatch | Per-model invocation count + token totals | Real-time invocation count per model per hour / day / month. |

`BedrockManager::pricing()`, `BedrockManager::usage()`, and `BedrockManager::billing()` (an alias for `CostExplorerService`) are the public entry points.

---

## `PricingService` — turn tokens into dollars

```php
$pricing = $manager->pricing();

$pricing->estimate(
    'anthropic.claude-sonnet-4-20250514-v1:0',
    inputTokens: 700,
    outputTokens: 200,
    region: 'us-east-1',
);
// [
//     'modelId'        => 'anthropic.claude-sonnet-4-20250514-v1:0',
//     'region'         => 'us-east-1',
//     'input_rate'     => 0.003,   // USD per 1k input tokens
//     'output_rate'    => 0.015,   // USD per 1k output tokens
//     'cache_read_rate'=> 0.0003,  // USD per 1k cached input tokens
//     'cache_write_rate'=> 0.00375,
//     'input_cost'     => 700 * 0.003 / 1k,
//     'output_cost'    => 200 * 0.015 / 1k,
//     'cache_read_cost'=> 0,
//     'total_cost'     => 0.0051,
// ]
```

### Pricing cache

Cached for 24 hours (`core-ai.bedrock.cache.pricing_ttl`, env `BEDROCK_PRICING_TTL`). Manually invalidate:

```php
app(PricingService::class)->refresh(); // drop cache + reload
```

### When it fails

If the AWS Pricing API rejects the model ID (custom FM not on the public catalogue), `PricingService::estimate()` returns a fallback price derived from the model spec. The fallback is logged at warning level — check `LOG_CHANNEL=stack` logs in dev.

---

## `CostExplorerService` — monthly bill breakdown

```php
$ce = $manager->billing();

$breakdown = $ce->monthly('us-east-1');
// [
//     'period' => '2026-06-01..2026-06-30',
//     'unblended_cost_usd' => 1245.78,
//     'by_model' => [
//         'anthropic.claude-sonnet-4-20250514-v1:0' => 814.32,
//         'amazon.nova-pro-v1:0' => 412.90,
//         'amazon.titan-embed-text-v2:0' => 18.56,
//     ],
//     'by_service_linked_account' => [
//         '111122223333' => 1245.78,
//     ],
//     'unblended_unit_count' => 4_582_134,
// ]
```

### Required IAM permissions

```json
{
    "Effect": "Allow",
    "Action": [
        "ce:GetCostAndUsage"
    ],
    "Resource": "*"
}
```

### Caching

`CostExplorerService` caches the raw CE response for `core-ai.bedrock.cache.cost_explorer_ttl` (default 6 hours). For dashboards, 6 hours is fine — for accounting reconciliation, drop the TTL to `3600` (1 h).

---

## `UsageTracker` — per-model invocation count

```php
$track = $manager->usage();

// Last 7 days, all models, token totals
$stats = $track->lastDays(7);
// [
//     ['model_id' => 'anthropic.claude-sonnet-4-20250514-v1:0', 'period' => '2026-07-13', 'invocations' => 18_422, 'input_tokens' => 12_440_122, 'output_tokens' => 1_834_117],
//     ['model_id' => 'amazon.nova-pro-v1:0', 'period' => '2026-07-13', 'invocations' => 1_002, 'input_tokens' => 122_440, 'output_tokens' => 24_000],
//     ...
// ]

// Or scoped to a model
$stats = $track->forModel('amazon.titan-embed-text-v2:0')->lastDays(30);
```

### Source — CloudWatch GetMetricData

`UsageTracker` queries:

```
namespace = "AWS/Bedrock"
metric_name = "Invocations" / "InputTokenCount" / "OutputTokenCount"
period = 3600 s
dimensions = [{ "Name": "ModelId", "Value": "<model-id>" }]
```

The cache key is `sha256(namespace|metric_name|model_id|period_start|period_end)` with TTL `core-ai.bedrock.cache.usage_ttl` (default 15 minutes).

### Required IAM permissions

```json
{
    "Effect": "Allow",
    "Action": [
        "cloudwatch:GetMetricData",
        "cloudwatch:ListMetrics"
    ],
    "Resource": "*"
}
```

### Caveats

- **CloudWatch delay** — `AWS/Bedrock` metrics lag by 2-5 minutes. Don't use them for live quotas.
- **Per-account aggregation** — accounts with multiple keys get merged. To track per-key, you need the SDK's `AWS/Bedrock` metric with the `KeyArn` dimension (not currently exposed).

---

## Putting it together — admin dashboard

A minimal dashboard with all three:

```php
use Ubxty\BedrockAi\Facades\Bedrock;
use Ubxty\BedrockAi\Services\{UsageTracker, PricingService, CostExplorerService};

class BedrockAdminController
{
    public function dashboard(): array
    {
        /** @var BedrockManager $m */
        $m = app(BedrockManager::class);

        $usage = $m->usage()->lastDays(7);
        $ce    = $m->billing()->monthly('us-east-1');

        $totalTokens = array_sum(array_column($usage, 'input_tokens')) + array_sum(array_column($usage, 'output_tokens'));

        return [
            'window' => 'last 7 days',
            'invocations' => array_sum(array_column($usage, 'invocations')),
            'tokens' => $totalTokens,
            'monthly_total_usd' => $ce['unblended_cost_usd'],
            'top_model' => collect($usage)
                ->groupBy('model_id')
                ->map(fn ($g) => array_sum(array_column($g, 'output_tokens')))
                ->sortDesc()
                ->keys()
                ->first(),
        ];
    }
}
```

Combined with the `BedrockInvoked` event for in-process counters, you get an end-to-end live + reconciled dashboard.

---

## CLI commands

```bash
php artisan bedrock:pricing                                  # list all models and rates
php artisan bedrock:pricing --model=anthropic.claude-sonnet-4-20250514-v1:0
php artisan bedrock:usage --days=30                         # per-model invocations
php artisan bedrock:usage --model=amazon.nova-pro-v1:0 --days=7
```

Both commands respect the cache TTLs.

---

## Numbers tip

The CloudWatch `AWS/Bedrock` `Invocations` count is per-call (1 per `invoke()`), not per HTTP chunk. Streaming responses count as 1 invocation. Batch `embed()` counts N invocations (one per text).

For accurate cost attribution, combine:

- `UsageTracker::forModel()->lastDays(30)` → invocation count.
- `BedrockInvoked` event listener (in-process, second-precision) → idempotency-key-traced cost.

---

## Pricing for unsupported models

If you deploy a Custom Model on Bedrock (your own fine-tune), `PricingService` falls back to the underlying base model's rate (the Bedrock API treats your FM as a derivative of a base). `pricing()` will warn-log the fallback. To pin the rate explicitly:

```php
config(['core-ai.bedrock.model_price_overrides' => [
    'myorg.custom-summariser-v1' => ['input' => 0.0002, 'output' => 0.001],
]]);
```

The override is read first, then live Pricing API, then fallback.
