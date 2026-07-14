# Cost & Usage Reference

> Companion to the [README](../README.md). Everything to do with `PricingService`, `CostExplorerService`, and `UsageTracker`.

---

## Three services, three purposes

| Service | Talks to | Output | Purpose |
|---|---|---|---|
| `PricingService` | AWS Pricing API | Per-model price map | Convert token counts → dollars. |
| `CostExplorerService` | AWS Cost Explorer (CE API) | Aggregated cost + usage breakdowns | Daily / monthly bill breakdown by Bedrock. |
| `UsageTracker` | CloudWatch | Per-model invocation count + token totals | Real-time invocation count per model per hour / day / month. |

Public entry points on `BedrockManager`: `pricing()`, `billing()`, `usage()`.
Namespaces: `Ubxty\BedrockAi\Pricing\PricingService`,
`Ubxty\BedrockAi\Billing\CostExplorerService`,
`Ubxty\BedrockAi\Usage\UsageTracker`.

---

## `PricingService` — per-model pricing

The service exposes `getPricing()`, `refreshPricing()`, and `testConnection()`.
It does not have an `estimate()` method — cost estimation for a specific call
is done via `BedrockManager` (cost is included in every `invoke` / `converse`
result) or by multiplying against a known price row.

```php
use Ubxty\BedrockAi\Pricing\PricingService;

$pricing = app(PricingService::class)->getPricing();

foreach ($pricing as $modelId => $data) {
    echo "{$data['model_name']}: \${$data['input_price']}/1K in, \${$data['output_price']}/1K out\n";
}
```

Each row has the shape:

```php
[
    'model_id'     => 'anthropic.claude-sonnet-4-20250514-v1:0',
    'model_name'   => 'Claude Sonnet 4',
    'provider'     => 'Anthropic',
    'region'       => 'us-east-1',
    'input_price'  => 0.003,   // USD per 1k input tokens
    'output_price' => 0.015,   // USD per 1k output tokens
    'unit'         => 'HRS',
]
```

### Pricing cache

Cached for 24 hours under the literal key `bedrock_ai_pricing` (TTL controlled
by `core-ai.bedrock.cache.pricing_ttl`, env `BEDROCK_PRICING_TTL`). Manually
invalidate by calling the service's own refresh method:

```php
app(PricingService::class)->refreshPricing();   // drop cache + reload
```

### Failure mode

If the AWS Pricing API rejects a model ID (e.g. a custom FM not on the public
catalogue), `PricingService::testConnection()` returns `{success: false, …}`.
For unknown model IDs the service simply omits the row from `getPricing()`. Use
`calculateCosts($usage, $pricingMap)` on `UsageTracker` (see below) to assign a
fallback per-token rate to those rows.

---

## `CostExplorerService` — daily / monthly bill breakdown

CE exposes daily cost series (`getDailyCosts(int $days = 30)`), monthly
summaries (`getMonthlySummary(int $months = 3)`), per-model breakdowns
(`getBedrockCosts(string $start, string $end, string $granularity = 'DAILY')`),
usage-type splits (`getCostByUsageType(string $start, string $end)`), and
forecasts (`getCostForecast(string $start, string $end, string $granularity = 'MONTHLY')`).
There is no `monthly(string $region)` shortcut; pass dates and grain explicitly.

```php
use Ubxty\BedrockAi\Billing\CostExplorerService;

$ce = app(CostExplorerService::class);

// Last 30 days, daily grain
$daily = $ce->getDailyCosts(30);
// [
//   ['date' => '2026-07-13', 'unblended_cost_usd' => 41.42, ...],
//   ...
// ]

// Last 3 months, monthly summary
$summary = $ce->getMonthlySummary(3);
```

### Required IAM permissions

```json
{
    "Effect": "Allow",
    "Action": [
        "ce:GetCostAndUsage",
        "ce:GetCostForecast"
    ],
    "Resource": "*"
}
```

### Caching

Each call memoises the raw CE response under a `bedrock_billing_*` key with
TTL `core-ai.bedrock.cache.billing_ttl` (default 1 hour). For dashboards,
one hour is fine — for accounting reconciliation, drop the TTL to `3600` (also
one hour) and rely on fresh fetches within the same TTL.

---

## `UsageTracker` — per-model invocation count

The tracker returns CloudWatch `AWS/Bedrock` metrics. The real API is
`getActiveModels()`, `getModelUsage(string $modelId, int $days = 7)`,
`getAggregatedUsage(int $days = 30)`, `getDailyTrend(int $days = 30, ?array $aggregatedUsage = null)`,
`calculateCosts(array $usage, array $pricingMap = [])`, and `testConnection()`.
There is no `lastDays($n)` nor `forModel($id)` fluent helper — pass the
arguments explicitly.

```php
use Ubxty\BedrockAi\Usage\UsageTracker;

$track = app(UsageTracker::class);

// Aggregated across all models in the account, last 30 days
$usage = $track->getAggregatedUsage(30);
// [
//   'anthropic.claude-sonnet-4-20250514-v1:0' => [
//     'input_tokens' => 12_440_122, 'output_tokens' => 1_834_117,
//     'invocations' => 18_422, 'avg_latency_ms' => 950,
//   ],
//   'amazon.nova-pro-v1:0' => [
//     'input_tokens' => 122_440, 'output_tokens' => 24_000,
//     'invocations' => 1_002, 'avg_latency_ms' => 720,
//   ],
// ]

// Per-model, last 7 days — returns raw CloudWatch series
$modelUsage = $track->getModelUsage('amazon.titan-embed-text-v2:0', days: 7);
// ['InputTokenCount' => [...], 'OutputTokenCount' => [...], 'Invocations' => [...], 'InvocationLatency' => [...]]

// Day-by-day breakdown for charts (pass the aggregated result to avoid a second fetch)
$trend = $track->getDailyTrend(30, $usage);
```

### Source — CloudWatch `GetMetricData`

`UsageTracker` queries:

```
namespace = "AWS/Bedrock"
metric_name = "Invocations" / "InputTokenCount" / "OutputTokenCount" / "InvocationLatency"
period = 3600 s
dimensions = [{ "Name": "ModelId", "Value": "<model-id>" }]
```

Cache keys:

- `getModelUsage(...)` uses a **process-local** key of `"{$modelId}_{$days}"`
  (held on the instance; not in the Laravel cache). Repeat calls within the
  same worker hit the in-memory map.
- `getAggregatedUsage(int $days)` uses a **Laravel cache** key
  `bedrock_ai_usage_{days}d_<md5(accessKey)>` (TTL `core-ai.bedrock.cache.usage_ttl`,
  default 15 minutes).

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

- **CloudWatch delay** — `AWS/Bedrock` metrics lag by 2-5 minutes. Don't use
  them for live quotas; use `BedrockInvoked` events for that.
- **Per-account aggregation** — accounts with multiple keys get merged at the
  CloudWatch level. There is no per-key dimension exposure here.

---

## Cost attribution — putting it together

```php
use Ubxty\BedrockAi\BedrockManager;

class BedrockAdminController
{
    public function __construct(private BedrockManager $bedrock) {}

    public function dashboard(): array
    {
        $usage = $this->bedrock->usage()->getAggregatedUsage(7);
        $pricing = $this->bedrock->pricing()->getPricing();
        $ce = $this->bedrock->billing()->getDailyCosts(30);

        $costs = $this->bedrock->usage()->calculateCosts($usage, $pricing);
        // ['total_cost' => 12.34, 'by_model' => [...]]

        $monthly = array_sum(array_column($ce, 'unblended_cost_usd'));

        return [
            'window' => 'last 7 days',
            'invocations' => array_sum(array_column($usage, 'invocations')),
            'tokens' => array_sum(array_column($usage, 'input_tokens'))
                      + array_sum(array_column($usage, 'output_tokens')),
            'estimated_cost_usd' => $costs['total_cost'],
            'ce_window_total_usd' => $monthly,
            'top_model' => collect($usage)
                ->sortByDesc(fn ($d) => $d['output_tokens'])
                ->keys()
                ->first(),
        ];
    }
}
```

Combined with the `BedrockInvoked` event for in-process, per-invocation cost,
this gives a live + reconciled dashboard.

---

## CLI commands

```bash
php artisan bedrock:pricing                                # list all models and rates
php artisan bedrock:pricing --filter=claude                # filter by model
php artisan bedrock:pricing --refresh                      # force fresh fetch
php artisan bedrock:usage                                  # aggregated, last 30 days
php artisan bedrock:usage --days=7                        # custom window
php artisan bedrock:usage --daily                          # day-by-day breakdown
php artisan bedrock:usage --json                           # machine-readable
```

The `bedrock:pricing` and `bedrock:usage` commands do not accept `--model=`;
filter at the CLI with `--filter=` (pricing) or pass a single model ID via the
service in PHP (usage tracking).

Both commands respect the cache TTLs.

---

## Numbers tip

The CloudWatch `AWS/Bedrock` `Invocations` count is per-call (1 per `invoke()`),
not per HTTP chunk. Streaming responses count as **1 invocation**, not N. Batch
`embed()` counts **N invocations** (one per text).

For accurate cost attribution, combine:

- `UsageTracker::getAggregatedUsage($days)` → invocation count + token totals.
- `PricingService::getPricing()` → per-1k-token rates.
- `UsageTracker::calculateCosts($usage, $pricingMap)` → reconciled dollar
  estimate.
- `BedrockInvoked` event listener (in-process, second-precision) → per-call
  cost, idempotency-key-traced.

---

## Pricing for unsupported models

If you deploy a Custom Model on Bedrock (your own fine-tune), `PricingService`
omits it from `getPricing()` (the AWS Pricing API only publishes the catalogue).
`calculateCosts()` on `UsageTracker` will return `$0` for those rows because the
pricing map is empty.

To pin the rate explicitly, pass a `pricing` map to `calculateCosts()` — or pass
a `pricing: [...]` array to the per-call `invoke(...)` arguments:

```php
$result = Bedrock::invoke(
    modelId: 'myorg.custom-summariser-v1',
    systemPrompt: '…',
    userMessage:  '…',
    pricing: [
        'input_price_per_1k'  => 0.0002,
        'output_price_per_1k' => 0.001,
    ],
);
```

The per-call `pricing:` argument is honored by `calculateCost()` on the manager
override, so the returned `cost` field reflects your override.
