# Getting Started with `ubxty/bedrock-ai`

> Companion to the [README](../README.md). Walks through AWS setup, package install, first invocation, and the typical gotchas (use-case form, IAM permissions, region pinning).

---

## 1. Prerequisites

- AWS account with Bedrock model access.
- PHP 8.2+, Laravel 11|12.
- AWS credentials with at least:
  - `bedrock:InvokeModel`
  - `bedrock:InvokeModelWithResponseStream`
  - `bedrock:ListFoundationModels`
  - `bedrock:GetFoundationModel`
  - `cloudwatch:GetMetricData` (for usage dashboards)
  - `pricing:GetProducts` (for the live pricing CLI command)

The minimal IAM policy is in the README's [Getting AWS Credentials](../README.md#getting-aws-credentials) section.

## 2. Install

```bash
composer require ubxty/bedrock-ai
```

This pulls in `ubxty/core-ai ^2.1` as a transitive dependency — you don't need to require it separately.

The service provider is auto-discovered.

## 3. Pick an authentication mode

The package supports two auth modes per key: `iam` (long-lived access keys, full SDK features including streaming) and `bearer` (Bedrock API key token, faster to set up, no streaming). Pick one.

### IAM mode (recommended)

```dotenv
BEDROCK_AUTH_MODE=iam
BEDROCK_AWS_KEY=AKIA…
BEDROCK_AWS_SECRET=…
BEDROCK_REGION=us-east-1
BEDROCK_DEFAULT_MODEL=anthropic.claude-sonnet-4-20250514-v1:0
```

Standard AWS SDK credential chain applies — if you set `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` globally and `BEDROCK_AWS_KEY` is empty, the package falls back.

### Bearer mode

```dotenv
BEDROCK_AUTH_MODE=bearer
BEDROCK_BEARER_TOKEN=ABSK…
BEDROCK_REGION=us-east-1
BEDROCK_DEFAULT_MODEL=amazon.nova-pro-v1:0
```

Generate the token from AWS Console → Bedrock → API keys. Bearer mode doesn't support streaming — use IAM if you need real-time chunk responses.

## 4. Publish config (optional)

The package's defaults are usable; publish only if you want to customise:

```bash
php artisan vendor:publish --tag=core-ai-config
```

This writes `config/core-ai.php` containing the Bedrock + Azure OpenAI blocks (since core-ai v2.0, both providers share one config file). Customise under `bedrock.*`.

## 5. Interactive setup

```bash
php artisan bedrock:configure
```

The wizard walks through auth mode, region, model selection, and writes the env vars for you.

## 6. First call

```php
use Ubxty\BedrockAi\Facades\Bedrock;

$result = Bedrock::invoke(
    modelId: '',  // falls back to BEDROCK_DEFAULT_MODEL
    systemPrompt: 'You are a careful summariser.',
    userMessage: 'Q3 revenue was $4.2M, up 18% YoY.',
    maxTokens: 256,
    temperature: 0.2,
);

echo $result['response'];
```

Or by DI:

```php
class FooService
{
    public function __construct(private BedrockManager $bedrock) {}

    public function handle(): array
    {
        return $this->bedrock->invoke('claude-sonnet-4', '…', '…');
    }
}
```

## 7. Add a second key for failover

```dotenv
BEDROCK_KEY_LABEL_PRIMARY=Primary
BEDROCK_KEY_LABEL_SECONDARY=Secondary
BEDROCK_AUTH_MODE_PRIMARY=iam
BEDROCK_AUTH_MODE_SECONDARY=bearer
BEDROCK_AWS_KEY_PRIMARY=…
BEDROCK_AWS_SECRET_PRIMARY=…
BEDROCK_BEARER_TOKEN_SECONDARY=…
BEDROCK_REGION_PRIMARY=us-east-1
BEDROCK_REGION_SECONDARY=us-west-2
```

Or in config:

```php
'connections' => [
    'default' => [
        'keys' => [
            ['label' => 'Primary',   'auth_mode' => 'iam',    'aws_key' => '…', 'aws_secret' => '…', 'region' => 'us-east-1'],
            ['label' => 'Secondary', 'auth_mode' => 'bearer', 'bearer_token' => '…', 'region' => 'us-west-2'],
        ],
    ],
],
```

The package rotates to the next key when the current one hits rate-limit or an auth error. Exponential backoff before rotating (default 2s, 4s, 8s).

## 8. Enable prompt caching

```dotenv
BEDROCK_PROMPT_CACHE_POINTS=system,last_user
BEDROCK_PROMPT_CACHE_TTL=300
```

Subsequent calls with the same prefix within 5 min are billed at ~10% of the input rate on the cached portion. See [`caching-strategy.md`](caching-strategy.md) for the worked cost comparison.

## 9. Verify

```bash
php artisan bedrock:test
```

Walks through model discovery, a sample call, and reports the latency. If `model use case details have not been submitted`, see the README's [Anthropic Model Access](../README.md#anthropic-model-access) section — first-time use of Claude requires a one-time AWS form.

## 10. Multimodal setup

For image / document analysis:

```php
$result = Bedrock::conversation('amazon.nova-pro-v1:0')
    ->system('You extract line items from invoices.')
    ->user('Extract all items.')
    ->userWithDocument('Anything I missed?', '/tmp/invoice.pdf')
    ->maxTokens(4096)
    ->send();
```

`nova-pro-v1:0`, `nova-lite`, `claude-3.5+`, and `claude-4` all support multimodal. Smaller models return text-only.

## 11. Where to go next

- [`caching-strategy.md`](caching-strategy.md) — cost-optimisation playbook.
- [`multi-key-failover.md`](multi-key-failover.md) — rolling keys across regions or accounts.
- [`embeddings.md`](embeddings.md) — batch Titan / Cohere vector ingestion.
- [`cost-and-usage.md`](cost-and-usage.md) — admin dashboards on top of `PricingService`, `UsageTracker`, `CostExplorerService`.
- [`streaming.md`](streaming.md) — SSE / WebSocket / chunk aggregation.
- [`real-world-patterns.md`](real-world-patterns.md) — 10 patterns distilled from production deployments.
