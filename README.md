# Bedrock AI

<p align="center">
<img src="https://img.shields.io/badge/PHP-8.2%2B-blue" alt="PHP 8.2+">
<img src="https://img.shields.io/badge/Laravel-11%20%7C%2012-red" alt="Laravel 11|12">
<img src="https://img.shields.io/badge/License-MIT-green" alt="MIT License">
<img src="https://img.shields.io/badge/Version-0.0.13-orange" alt="Version 0.0.13">
</p>

A Laravel package for seamless **AWS Bedrock** integration. One fluent API to invoke, converse, and stream responses from any Bedrock model — with multi-key failover, cross-region inference, vision/document analysis, cost tracking, and a full suite of CLI tools.

---

## Table of Contents

- [Why This Package](#why-this-package)
- [Feature Overview](#feature-overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
  - [Authentication Modes](#authentication-modes)
  - [Multi-Key Failover](#multi-key-failover)
  - [Multiple Connections](#multiple-connections)
  - [Default Models](#default-models)
  - [Provider Filtering](#provider-filtering)
  - [Cost Limits](#cost-limits)
  - [Model Aliases](#model-aliases)
  - [Retry Behaviour](#retry-behaviour)
  - [Caching](#caching)
  - [Invocation Logging](#invocation-logging)
  - [Health Check Route](#health-check-route)
  - [Pricing & Usage API Credentials](#pricing--usage-api-credentials)
- [Usage](#usage)
  - [Facade vs Dependency Injection](#facade-vs-dependency-injection)
  - [Invoking Models](#invoking-models)
  - [Conversation Builder](#conversation-builder)
  - [Vision — Sending Images](#vision--sending-images)
  - [Documents — Sending PDFs and Files](#documents--sending-pdfs-and-files)
  - [Multiple Documents in One Turn](#multiple-documents-in-one-turn)
  - [Mixed Attachments](#mixed-attachments)
  - [Multi-Turn Conversations with Media](#multi-turn-conversations-with-media)
  - [Streaming Responses](#streaming-responses)
  - [Converse API (Direct)](#converse-api-direct)
  - [Token Estimation](#token-estimation)
  - [Listing & Syncing Models](#listing--syncing-models)
  - [Pricing Data](#pricing-data)
  - [Usage Tracking](#usage-tracking)
  - [Events](#events)
  - [Error Handling](#error-handling)
- [CLI Commands](#cli-commands)
  - [bedrock:configure](#bedrockconfigure)
  - [bedrock:test](#bedrocktest)
  - [bedrock:models](#bedrockmodels)
  - [bedrock:default-model](#bedrockdefault-model)
  - [bedrock:chat](#bedrockchat)
  - [bedrock:usage](#bedrockusage)
  - [bedrock:pricing](#bedrockpricing)
- [Supported Models](#supported-models)
- [Architecture Deep Dive](#architecture-deep-dive)
  - [Cross-Region Inference Profiles](#cross-region-inference-profiles)
  - [Multi-Key Rotation & Retry](#multi-key-rotation--retry)
  - [Bearer Token Mode](#bearer-token-mode)
  - [System Prompt Auto-Folding](#system-prompt-auto-folding)
  - [Model Spec Resolution](#model-spec-resolution)
  - [Input Modality Validation](#input-modality-validation)
- [Getting AWS Credentials](#getting-aws-credentials)
  - [Option A: IAM Access Keys (Recommended)](#option-a-iam-access-keys-recommended)
  - [Option B: Bearer Token](#option-b-bearer-token)
- [Anthropic Model Access](#anthropic-model-access)
- [API Reference](#api-reference)
- [Testing](#testing)
- [Changelog](#changelog)
- [License](#license)

---

## Why This Package

Integrating AWS Bedrock into a Laravel application involves more boilerplate than it should. You need to handle:

- Different request/response formats per model provider (Claude, Llama, Mistral, etc.)
- Cross-region inference profile prefixes for newer models
- Rate limiting, key rotation, and retry logic
- Streaming responses with proper chunk aggregation
- Multi-turn conversation state management
- Token estimation and cost tracking before and after calls
- Bearer vs IAM authentication across environments

This package handles all of that behind a single, consistent API so you can focus on your application logic.

---

## Feature Overview

| Feature | Details |
|---|---|
| **Multi-key credential rotation** | Configure multiple AWS key sets per connection; automatic failover on rate limits or errors |
| **Cross-region inference** | Auto-prefixes `us.`/`eu.` for models that require cross-region inference profiles |
| **Dual auth modes** | Explicit `iam` (Access Key + Secret) or `bearer` token per key — no guesswork |
| **Rate limit retry** | Exponential backoff per key, then rotates to the next key |
| **Model aliases** | Define short names like `claude` or `nova` that resolve to full model IDs |
| **Converse API** | Unified request/response format across all providers via AWS Converse API |
| **Conversation Builder** | Fluent multi-turn conversation API with chaining, token estimation, and cost tracking |
| **Streaming** | Real-time token streaming via `converseStream` (all providers, IAM mode) |
| **Vision** | Send images (JPEG, PNG, GIF, WebP) alongside prompts using `userWithImage()` |
| **Document analysis** | Send PDFs, CSVs, DOCX, XLSX, HTML, TXT, MD using `userWithDocument()` |
| **Multi-document batching** | Send multiple documents in one turn with `userWithDocuments()` |
| **Mixed attachments** | Send images and documents together with `userWithAttachments()` |
| **Input modality validation** | Pre-flight check that the selected model supports image/document inputs |
| **Token estimation** | Estimate input token count and cost before making API calls; multimodal-aware |
| **Cost limits** | Configurable daily/monthly spend caps with atomic enforcement |
| **Provider filtering** | Globally or per-context (chat/image) hide providers you don't use |
| **Default models** | Configure `BEDROCK_DEFAULT_MODEL` and `BEDROCK_DEFAULT_IMAGE_MODEL` per env |
| **Laravel Events** | `BedrockInvoked`, `BedrockRateLimited`, `BedrockKeyRotated` |
| **Invocation Logger** | Auto-log every call with configurable channel |
| **CloudWatch usage** | Token counts, invocation counts, latency from CloudWatch metrics |
| **Real-time pricing** | Current per-token pricing from the AWS Pricing API |
| **Health check route** | Registerable `/health/bedrock` endpoint for uptime monitoring |
| **Database model cache** | Sync models to a local DB table for fast offline lookups |
| **7 CLI commands** | Configure, test, list models, set default models, chat, usage, and pricing |
| **System prompt auto-folding** | Automatically retries with system prompt injected into first user message for models that reject system blocks (Mixtral, Mistral 7B) |

---

## Requirements

- PHP **8.2+**
- Laravel **11** or **12**
- `aws/aws-sdk-php` **^3.300**
- AWS credentials with Bedrock access

> **Anthropic models only:** First-time use of any Claude model requires a one-time use-case form submission per AWS account. See [Anthropic Model Access](#anthropic-model-access).

---

## Installation

```bash
composer require ubxty/bedrock-ai
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=bedrock-config
```

Publish and run the database migrations (needed for the model sync and interactive pickers):

```bash
php artisan vendor:publish --tag=bedrock-migrations
php artisan migrate
```

Or run the interactive setup wizard to configure everything at once:

```bash
php artisan bedrock:configure
```

---

## Quick Start

```php
use Ubxty\BedrockAi\Facades\Bedrock;

$result = Bedrock::invoke(
    modelId:      'anthropic.claude-3-5-sonnet-20241022-v2:0',
    systemPrompt: 'You are a helpful assistant.',
    userMessage:  'What is the capital of France?'
);

echo $result['response'];     // "The capital of France is Paris."
echo $result['total_tokens']; // 42
echo $result['cost'];         // 0.000234
echo $result['latency_ms'];   // 850
```

Cross-region inference profiles, credential management, and error mapping are all handled automatically.

---

## Configuration

### Authentication Modes

The package supports two authentication modes configured per key via `auth_mode`:

#### IAM Mode (recommended for production)

```env
BEDROCK_AUTH_MODE=iam
BEDROCK_AWS_KEY=AKIA...
BEDROCK_AWS_SECRET=your-secret-key
BEDROCK_REGION=us-east-1
```

#### Bearer Token Mode

```env
BEDROCK_AUTH_MODE=bearer
BEDROCK_BEARER_TOKEN=your-bearer-token
BEDROCK_REGION=us-east-1
```

> Bearer token mode does **not** support streaming. Use IAM mode if you need real-time token streaming.

The package also falls back to the standard `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` env vars if the Bedrock-specific ones are not set.

---

### Multi-Key Failover

Add multiple credential sets to a connection. When the first key hits a rate limit or error, the client automatically rotates to the next:

```php
// config/bedrock.php
'connections' => [
    'default' => [
        'keys' => [
            [
                'label'      => 'Primary',
                'auth_mode'  => 'iam',
                'aws_key'    => env('BEDROCK_AWS_KEY'),
                'aws_secret' => env('BEDROCK_AWS_SECRET'),
                'region'     => 'us-east-1',
            ],
            [
                'label'      => 'Backup US West',
                'auth_mode'  => 'iam',
                'aws_key'    => env('BEDROCK_AWS_KEY_2'),
                'aws_secret' => env('BEDROCK_AWS_SECRET_2'),
                'region'     => 'us-west-2',
            ],
            [
                'label'        => 'Bearer Fallback',
                'auth_mode'    => 'bearer',
                'bearer_token' => env('BEDROCK_BEARER_TOKEN'),
                'region'       => 'us-east-1',
            ],
        ],
    ],
],
```

---

### Multiple Connections

Define separate connections for different environments or teams:

```php
'connections' => [
    'default'    => ['keys' => [/* ... */]],
    'production' => ['keys' => [/* ... */]],
    'staging'    => ['keys' => [/* ... */]],
    'eu'         => ['keys' => [/* region => eu-west-1 ... */]],
],
```

Switch at runtime using the `connection` parameter available on all main methods:

```php
Bedrock::invoke('anthropic.claude...', $system, $user, connection: 'production');
Bedrock::conversation('nova', connection: 'eu');
$client = Bedrock::client('staging');
```

---

### Default Models

Set default models in `.env` so you don't need to pass a model ID to every call:

```env
BEDROCK_DEFAULT_MODEL=anthropic.claude-sonnet-4-20250514-v1:0
BEDROCK_DEFAULT_IMAGE_MODEL=amazon.nova-pro-v1:0
```

Or configure interactively:

```bash
php artisan bedrock:default-model
```

Once set, you can omit the model ID:

```php
Bedrock::invoke('', 'You are helpful.', 'Hello!');           // uses BEDROCK_DEFAULT_MODEL
Bedrock::conversation()->system('You are helpful.')->send(); // uses BEDROCK_DEFAULT_MODEL
```

---

### Provider Filtering

Hide providers you don't use to keep all model pickers and listings tidy. Uses the `Providers` constants class to avoid typos with space-containing provider names:

```php
use Ubxty\BedrockAi\Providers;

'providers' => [
    // Hidden globally everywhere
    'disabled_providers' => [
        Providers::AI21_LABS,
        Providers::WRITER,
    ],

    // Hidden only in chat model pickers
    'chat' => [
        'disabled_providers' => [Providers::COHERE],
    ],

    // Hidden only in image model pickers
    'image' => [
        'disabled_providers' => [Providers::META],
    ],
],
```

Or via `.env`:

```env
BEDROCK_DISABLED_PROVIDERS="AI21 Labs,Writer"
BEDROCK_CHAT_DISABLED_PROVIDERS="Cohere"
BEDROCK_IMAGE_DISABLED_PROVIDERS="Meta"
```

---

### Cost Limits

Enforce daily and monthly spending caps. When exceeded, a `CostLimitExceededException` is thrown **before** the API call. Accumulators are stored in Laravel's cache with an atomic lock to prevent race conditions under concurrent requests:

```env
BEDROCK_DAILY_LIMIT=10.00
BEDROCK_MONTHLY_LIMIT=300.00
```

---

### Model Aliases

Define short names for frequently used model IDs:

```php
'aliases' => [
    'claude'  => 'anthropic.claude-sonnet-4-20250514-v1:0',
    'haiku'   => 'anthropic.claude-3-5-haiku-20241022-v1:0',
    'nova'    => 'amazon.nova-pro-v1:0',
    'llama'   => 'meta.llama3-3-70b-instruct-v1:0',
],
```

Use aliases anywhere a model ID is accepted:

```php
Bedrock::invoke('claude', 'You are a poet.', 'Write a haiku.');

$builder = Bedrock::conversation('haiku');

// Register an alias at runtime
Bedrock::aliases()->register('fast', 'anthropic.claude-3-5-haiku-20241022-v1:0');
$resolved = Bedrock::resolveAlias('fast'); // full model ID
```

---

### Retry Behaviour

```env
BEDROCK_MAX_RETRIES=3       # attempts per key before rotating to the next
BEDROCK_RETRY_DELAY=2       # base delay in seconds; doubles each retry
```

`max_retries=3` with `base_delay=2` → waits 2s, 4s, 8s before rotating to the next key.

---

### Caching

```php
'cache' => [
    'pricing_ttl' => 86400,  // 24 hours
    'usage_ttl'   => 900,    // 15 minutes
    'models_ttl'  => 3600,   // 1 hour
],
```

---

### Invocation Logging

Log every Bedrock call (model ID, tokens, cost, latency, key used) to any Laravel log channel:

```env
BEDROCK_LOGGING_ENABLED=true
BEDROCK_LOG_CHANNEL=bedrock
```

---

### Health Check Route

Register a `/health/bedrock` endpoint for uptime monitoring:

```env
BEDROCK_HEALTH_CHECK_ENABLED=true
```

```php
'health_check' => [
    'enabled'    => env('BEDROCK_HEALTH_CHECK_ENABLED', false),
    'path'       => '/health/bedrock',
    'middleware' => ['auth:sanctum'], // optional
],
```

Response:

```json
{
  "status": "healthy",
  "message": "Connection successful! Found 42 available models.",
  "response_time_ms": 350,
  "model_count": 42
}
```

---

### Pricing & Usage API Credentials

The AWS Pricing API and CloudWatch Metrics API require additional IAM permissions. You can use separate credentials or let the package fall back to the default connection's first key:

```env
# Pricing API (hosted in us-east-1 only)
BEDROCK_PRICING_KEY=AKIA...
BEDROCK_PRICING_SECRET=your-pricing-secret

# CloudWatch usage metrics
BEDROCK_USAGE_KEY=AKIA...
BEDROCK_USAGE_SECRET=your-usage-secret
BEDROCK_USAGE_REGION=us-east-1
```

---

## Usage

### Facade vs Dependency Injection

Both approaches work identically:

```php
// Facade — great for quick usage and controllers
use Ubxty\BedrockAi\Facades\Bedrock;

$result = Bedrock::invoke('claude', 'You are helpful.', 'Hi!');
```

```php
// Dependency Injection — preferred for services and testability
use Ubxty\BedrockAi\BedrockManager;

class AnalysisService
{
    public function __construct(protected BedrockManager $bedrock) {}

    public function analyse(string $text): string
    {
        return $this->bedrock
            ->conversation('claude')
            ->system('You are a data analyst.')
            ->user($text)
            ->send()['response'];
    }
}
```

---

### Invoking Models

```php
$result = Bedrock::invoke(
    modelId:      'anthropic.claude-3-5-sonnet-20241022-v2:0',
    systemPrompt: 'You are a medical assistant.',
    userMessage:  'Explain hypertension in simple terms.',
    maxTokens:    1024,
    temperature:  0.5,
    pricing: [
        'input_price_per_1k'  => 0.003,
        'output_price_per_1k' => 0.015,
    ],
    connection: 'default', // optional, defaults to config 'default'
);
```

**Return value:**

```php
[
    'response'      => 'Hypertension, or high blood pressure, is...',
    'input_tokens'  => 45,
    'output_tokens' => 230,
    'total_tokens'  => 275,
    'cost'          => 0.003585,
    'latency_ms'    => 1250,
    'status'        => 'success',
    'key_used'      => 'Primary',
    'model_id'      => 'us.anthropic.claude-3-5-sonnet-20241022-v2:0',
]
```

> `modelId` can be a full model ID, a configured alias, or empty string to use `BEDROCK_DEFAULT_MODEL`.

---

### Conversation Builder

Fluent API for multi-turn conversations. The builder manages message history and automatically applies cost limits, dispatches events, and logs invocations:

```php
$conversation = Bedrock::conversation('claude')
    ->system('You are a helpful assistant.')
    ->user('What causes headaches?')
    ->maxTokens(2048)
    ->temperature(0.5)
    ->withPricing([
        'input_price_per_1k'  => 0.003,
        'output_price_per_1k' => 0.015,
    ]);

// Estimate token count and cost before sending
$estimate = $conversation->estimate();
// ['input_tokens' => 52, 'fits' => true, 'estimated_cost' => 0.000156]

// Send and get a response
$result = $conversation->send();
echo $result['response'];

// Continue — the assistant's response is automatically added to history
$result2 = $conversation
    ->user('Tell me more about migraines.')
    ->send();

// Inspect the full message history
$messages = $conversation->getMessages();

// Restore a saved conversation
$conversation->reset()->setMessages($savedMessages);

// Clear history (keeps system prompt and settings)
$conversation->reset();
```

---

### Vision — Sending Images

Send an image alongside a text prompt to any model with `[img]` support (Claude 3+, Amazon Nova Pro/Lite):

```php
$result = Bedrock::conversation('amazon.nova-pro-v1:0')
    ->system('You are a visual analysis assistant.')
    ->userWithImage(
        prompt: 'Describe what you see in this image.',
        source: '/absolute/path/to/image.png', // file path OR base64-encoded string
        format: 'auto'                          // auto-detect from extension, or: jpeg|png|gif|webp
    )
    ->send();
```

Passing pre-encoded base64 data:

```php
$base64 = base64_encode(file_get_contents($imagePath));

$result = Bedrock::conversation('nova')
    ->userWithImage('What brand logo is this?', $base64, 'png')
    ->send();
```

**Accepted image formats:** `jpeg`, `png`, `gif`, `webp`  
**Max file size:** 15 MB — larger files are rejected before the request is sent.

---

### Documents — Sending PDFs and Files

```php
$result = Bedrock::conversation('anthropic.claude-sonnet-4-20250514-v1:0')
    ->userWithDocument(
        prompt:  'Summarise the key findings of this report.',
        source:  '/path/to/report.pdf',
        format:  'auto',        // auto-detect, or: pdf|csv|doc|docx|xls|xlsx|html|txt|md
        name:    'Q1 Report'    // optional display name shown to the model
    )
    ->send();
```

**Accepted document formats:** `pdf`, `csv`, `doc`, `docx`, `xls`, `xlsx`, `html`, `txt`, `md`

---

### Multiple Documents in One Turn

Send several documents at once for comparison or batch analysis:

```php
$result = Bedrock::conversation('claude')
    ->system('You are a contract analysis assistant.')
    ->userWithDocuments(
        prompt: 'Compare these two contracts and highlight the key differences.',
        documents: [
            '/path/to/contract_a.pdf',
            ['path' => '/path/to/contract_b.docx', 'name' => 'Contract B', 'format' => 'docx'],
        ]
    )
    ->send();
```

Each document can be a plain file path string, or an associative array with `path`, `format` (optional), and `name` (optional) keys.

---

### Mixed Attachments

Send any combination of images and documents in a single message:

```php
$result = Bedrock::conversation('amazon.nova-pro-v1:0')
    ->userWithAttachments(
        prompt: 'This invoice image matches which line item in the spreadsheet?',
        attachments: [
            ['type' => 'image',    'path' => '/path/to/invoice.png'],
            ['type' => 'document', 'path' => '/path/to/ledger.xlsx'],
        ]
    )
    ->send();
```

---

### Multi-Turn Conversations with Media

Send media once in the first turn and follow up with plain text. The model retains the media in context:

```php
$conversation = Bedrock::conversation('amazon.nova-pro-v1:0')
    ->system('You are an expert visual analyst.');

// Turn 1: include the image
$r1 = $conversation
    ->userWithImage('What colours are dominant in this image?', '/path/to/image.png')
    ->send();

// Turn 2: plain text follow-up — no need to re-send the image
$r2 = $conversation
    ->user('What real-world object do those colours remind you of?')
    ->send();
```

> **Tip:** Run `php artisan bedrock:models` to see which models support images (`[img]`) and documents (`[pdf]`) in the **Accepts** column.

---

### Streaming Responses

Stream tokens in real-time using the `converseStream` API (works with all model providers):

```php
// Stream a single-turn response
$result = Bedrock::stream(
    modelId:      'anthropic.claude-sonnet-4-20250514-v1:0',
    systemPrompt: 'You are a storyteller.',
    userMessage:  'Tell me a short story about a lighthouse.',
    onChunk: function (string $chunk, array $meta) {
        echo $chunk;
        flush();
    }
);

// Stream a multi-turn conversation via the builder
$result = Bedrock::conversation('claude')
    ->system('You are helpful.')
    ->user('Write a haiku about Laravel.')
    ->sendStream(function (string $chunk) {
        echo $chunk;
    });

// Direct StreamingClient usage with full control
$streamClient = Bedrock::streamingClient();
$result = $streamClient->converseStream(
    modelId:      'amazon.nova-pro-v1:0',
    messages:     [['role' => 'user', 'content' => 'Hello!']],
    onChunk:      fn(string $text) => print($text),
    systemPrompt: 'Be concise.',
    maxTokens:    512,
);
```

> Streaming requires **IAM auth mode**. Bearer token auth does not support streaming — `bedrock:chat` automatically falls back to non-streaming for bearer connections.

---

### Converse API (Direct)

For complete control over the message array:

```php
$result = Bedrock::converse(
    modelId: 'anthropic.claude-sonnet-4-20250514-v1:0',
    messages: [
        ['role' => 'user',      'content' => 'What is PHP?'],
        ['role' => 'assistant', 'content' => 'PHP is a server-side scripting language.'],
        ['role' => 'user',      'content' => 'What makes it good for web development?'],
    ],
    systemPrompt: 'You are a senior developer.',
    maxTokens:    1024,
    temperature:  0.7,
    connection:   'default',
);
```

---

### Token Estimation

Estimate usage and cost before making a call to avoid surprises:

```php
use Ubxty\BedrockAi\Support\TokenEstimator;

// Simple token count
$tokens = TokenEstimator::estimate($text);

// Full pre-call estimation
$est = TokenEstimator::estimateInvocation(
    systemPrompt:    $system,
    userMessage:     $user,
    modelId:         'anthropic.claude-sonnet-4-20250514-v1:0',
    maxOutputTokens: 4096,
);
// ['input_tokens' => 120, 'fits' => true, 'available_output' => 3976]

// Estimate cost
$cost = TokenEstimator::estimateCost($system, $user, 1000, [
    'input_price_per_1k'  => 0.003,
    'output_price_per_1k' => 0.015,
]);

// Multimodal-aware estimation via the builder
// Automatically accounts for document tokens (~750 base64 bytes/token, 100-token minimum)
// and image budgets (~1,600 tokens per image)
$estimate = Bedrock::conversation('claude')
    ->system('You are a data analyst.')
    ->userWithDocument('Summarise this.', '/path/to/report.pdf')
    ->estimate();
```

---

### Listing & Syncing Models

```php
// Fetch live from AWS, normalised with context window and capability specs
$models = Bedrock::fetchModels();
foreach ($models as $model) {
    echo "{$model['name']} — {$model['context_window']}k context\n";
}

// Sync to the local DB table for fast offline lookups and the interactive pickers
Bedrock::syncModels();

// Grouped by provider with optional context-scoped filtering
$grouped = Bedrock::getModelsGrouped(context: 'chat');   // 'chat', 'image', or null

// Quick connectivity check
$result = Bedrock::testConnection();
// ['success' => true, 'model_count' => 42, 'response_time' => 320]
```

---

### Pricing Data

```php
$pricingService = Bedrock::pricing();

$pricing = $pricingService->getPricing();
foreach ($pricing as $modelId => $data) {
    echo "{$data['model_name']}: \${$data['input_price']}/1K in, \${$data['output_price']}/1K out\n";
}

// Force-refresh, bypassing the 24-hour cache
$fresh = $pricingService->refreshPricing();

// Test that Pricing API credentials are working
$test = $pricingService->testConnection();
```

---

### Usage Tracking

```php
$tracker = Bedrock::usage();

// Models with CloudWatch activity in the account
$activeModels = $tracker->getActiveModels();

// Per-model daily metrics
$raw = $tracker->getModelUsage('anthropic.claude-sonnet-4-20250514-v1:0', days: 30);

// Aggregated across all models
$usage = $tracker->getAggregatedUsage(days: 30);
foreach ($usage as $modelId => $data) {
    echo "{$modelId}: {$data['invocations']} calls, {$data['total_tokens']} tokens\n";
}

// Day-by-day breakdown for charts
$trend = $tracker->getDailyTrend(30);

// Cost estimation from raw usage + pricing data
$costs = $tracker->calculateCosts($usage, $pricingMap);
echo "Total estimated cost: \${$costs['total_cost']}";
```

---

### Events

Listen to package events in your `EventServiceProvider` for monitoring, alerting, and auditing:

```php
use Ubxty\BedrockAi\Events\BedrockInvoked;
use Ubxty\BedrockAi\Events\BedrockRateLimited;
use Ubxty\BedrockAi\Events\BedrockKeyRotated;

Event::listen(BedrockInvoked::class, function (BedrockInvoked $event) {
    // $event->modelId, $event->inputTokens, $event->outputTokens
    // $event->cost, $event->latencyMs, $event->keyUsed, $event->connection
    MyAuditLog::record($event);
});

Event::listen(BedrockRateLimited::class, function (BedrockRateLimited $event) {
    // $event->modelId, $event->keyLabel, $event->retryAttempt, $event->waitSeconds
    Notification::send($admin, new RateLimitAlert($event));
});

Event::listen(BedrockKeyRotated::class, function (BedrockKeyRotated $event) {
    // $event->fromKeyLabel, $event->toKeyLabel, $event->reason, $event->modelId
    Log::warning("Key rotated from {$event->fromKeyLabel} to {$event->toKeyLabel}");
});
```

---

### Error Handling

```php
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;
use Ubxty\BedrockAi\Exceptions\CostLimitExceededException;

try {
    $result = Bedrock::invoke($modelId, $system, $user);
} catch (RateLimitException $e) {
    // All keys exhausted after retries
} catch (CostLimitExceededException $e) {
    // $e->getLimitType() — 'daily' or 'monthly'
    // $e->getLimit(), $e->getCurrentSpend()
} catch (ConfigurationException $e) {
    // Missing/invalid credentials or unconfigured connection
} catch (BedrockException $e) {
    // General Bedrock errors — user-friendly messages are extracted automatically
}
```

**Automatic error message mapping:**

| Raw AWS Error | Friendly Message |
|---|---|
| `model identifier is invalid` | Invalid model: This model ID is not valid for Bedrock. |
| `doesn't support on-demand throughput` | Model unavailable: This model requires provisioned throughput. |
| `Malformed input request` | Request error: This model may not support text chat. |
| `end of its life` | Model deprecated: This model version has been retired. |
| `AccessDeniedException` | Access denied: You don't have permission to use this model. |
| `ResourceNotFoundException` | Model not found: The model does not exist in this region. |
| `unsupported input` | Unsupported input: This model does not support the provided input type. |
| `Invalid format` | Invalid format: The provided file format is not supported by this model. |
| `Authentication failed` | Bearer token is invalid or expired. Regenerate your API key in the AWS Console. |

---

## CLI Commands

### `bedrock:configure`

Interactive wizard for first-time setup. Walks you through auth mode, credentials, optional Pricing API setup, and writes config directly to your `.env`.

```bash
php artisan bedrock:configure

# Show current config (secrets masked)
php artisan bedrock:configure --show

# Auto-test immediately after configuring
php artisan bedrock:configure --test
```

---

### `bedrock:test`

Test your connection and optionally invoke a model with a prompt.

```bash
# Interactive two-step model picker (provider → model)
php artisan bedrock:test

# Test a specific model
php artisan bedrock:test anthropic.claude-3-5-sonnet-20241022-v2:0

# Custom prompt
php artisan bedrock:test --prompt="Explain gravity briefly"

# Test all configured credential keys
php artisan bedrock:test --all-keys

# Include legacy/deprecated models in the picker
php artisan bedrock:test --legacy

# JSON output
php artisan bedrock:test --json
```

---

### `bedrock:models`

List all available foundation models, grouped by provider.

```bash
# All models
php artisan bedrock:models

# Sync to DB first, then list
php artisan bedrock:models --sync

# Filter by name or ID
php artisan bedrock:models --filter=claude

# Filter by provider
php artisan bedrock:models --provider=anthropic

# Include legacy/deprecated models
php artisan bedrock:models --legacy

# JSON output
php artisan bedrock:models --json
```

The **Accepts** column shows `[img]` and `[pdf]` for models supporting image and document inputs.

---

### `bedrock:default-model`

Interactive wizard to set your default chat model and default image model. Includes a test-before-set step to confirm the model works before saving.

```bash
# Launch wizard
php artisan bedrock:default-model

# Show current defaults
php artisan bedrock:default-model --show

# Reset to empty
php artisan bedrock:default-model --reset

# Use a specific connection
php artisan bedrock:default-model --connection=production
```

Writes `BEDROCK_DEFAULT_MODEL` and `BEDROCK_DEFAULT_IMAGE_MODEL` to your `.env`.

---

### `bedrock:chat`

Interactive CLI chat session with any Bedrock model. Streaming is enabled by default in IAM mode.

```bash
# Interactive session — prompts for default model or shows picker
php artisan bedrock:chat

# Start with a specific model or alias
php artisan bedrock:chat anthropic.claude-sonnet-4-20250514-v1:0
php artisan bedrock:chat claude

# Set a custom system prompt
php artisan bedrock:chat --system="You are a medical assistant."

# Tune generation
php artisan bedrock:chat --max-tokens=2048 --temperature=0.3

# Disable streaming (wait for full response)
php artisan bedrock:chat --no-stream

# Use a specific connection
php artisan bedrock:chat --connection=production
```

**In-session commands:**

| Command | Description |
|---|---|
| `/help` | Show all available commands |
| `/quit` | End the session |
| `/reset` | Clear conversation history (keeps system prompt and settings) |
| `/stats` | Show session stats: messages, tokens used, estimated cost |
| `/system <prompt>` | Change the system prompt mid-session |
| `/model <id or alias>` | Switch to a different model |
| `/temp <0.0–1.0>` | Adjust temperature |
| `/image <path> [prompt]` | Send an image for analysis with the current model |
| `/doc <path> [prompt]` | Send a document (PDF, DOCX, CSV…) for analysis |

> Streaming is auto-enabled in IAM mode and auto-disabled in Bearer token mode. `/image` and `/doc` work in both modes.

---

### `bedrock:usage`

View CloudWatch usage metrics for your Bedrock account.

```bash
# Last 30 days (default)
php artisan bedrock:usage

# Custom time range
php artisan bedrock:usage --days=7

# Daily breakdown
php artisan bedrock:usage --daily

# JSON output
php artisan bedrock:usage --json
```

---

### `bedrock:pricing`

Fetch real-time per-token pricing from the AWS Pricing API.

```bash
# All models
php artisan bedrock:pricing

# Filter by model name or ID
php artisan bedrock:pricing --filter=claude

# Force refresh, bypassing the 24-hour cache
php artisan bedrock:pricing --refresh

# JSON output
php artisan bedrock:pricing --json
```

---

## Supported Models

| Provider | Models |
|---|---|
| **Anthropic** | Claude 4 (Sonnet, Opus, Haiku), Claude 3.7 Sonnet, Claude 3.5 Sonnet/Haiku, Claude 3 Opus/Sonnet/Haiku |
| **Amazon** | Nova Pro, Nova Lite, Nova Micro, Titan Text Express/Lite/Premier |
| **Meta** | Llama 4, Llama 3.3, Llama 3.2, Llama 3.1, Llama 3 (8B/70B) |
| **Mistral AI** | Mistral Large, Mistral Small, Mixtral 8x7B, Ministral 3B/8B, Pixtral |
| **Cohere** | Command R, Command R+, Command R7B |
| **AI21 Labs** | Jamba 1.5 Mini/Large |
| **Writer** | Palmyra X5, Palmyra X4 |

Any model accessible via the AWS Bedrock Converse API works, even if not listed above.

---

## Architecture Deep Dive

### Cross-Region Inference Profiles

Newer models cannot be invoked directly — they require cross-region inference profiles with a `us.` or `eu.` prefix based on your region. This is handled **automatically**:

```
anthropic.claude-3-5-sonnet-20241022-v2:0  (in us-east-1)
  → us.anthropic.claude-3-5-sonnet-20241022-v2:0

amazon.nova-pro-v1:0  (in eu-west-1)
  → eu.amazon.nova-pro-v1:0
```

Models that require inference profiles: `anthropic.claude-3-5-*`, `claude-3-7-*`, `claude-sonnet-4*`, `claude-opus-4*`, `claude-haiku-4*`, `amazon.nova-*`, `meta.llama3-1*`, `meta.llama3-2*`, `meta.llama3-3*`, `meta.llama4*`.

Already-prefixed IDs (e.g. `us.anthropic.claude-3-5-*`) are detected and passed through unchanged to prevent double-prefixing.

---

### Multi-Key Rotation & Retry

```
Request → Key 1
  → ThrottlingException → wait 2s → retry
  → ThrottlingException → wait 4s → retry
  → ThrottlingException → wait 8s → rotate to next key
→ Key 2
  → Success ✓
```

`BedrockRateLimited` and `BedrockKeyRotated` events fire at each step for observability.

---

### Bearer Token Mode

When `auth_mode` is `bearer`, the package uses HTTP requests with `Authorization: Bearer <token>` headers instead of AWS SDK SigV4 signing. Useful for Bedrock API keys distributed through the AWS console or environments without full IAM credentials.

**Limitation:** Bearer token mode does not support streaming responses. Use IAM mode for full functionality.

---

### System Prompt Auto-Folding

Some models (Mixtral, Mistral 7B) reject a top-level `system` block and return an error matching `"doesn't support system"`. The package detects this and automatically retries the request with the system prompt prepended as `[System: ...]` in the first user message — 100% transparent to your application code, and works for both plain-text and multimodal (block-array) messages.

---

### Model Spec Resolution

The `ModelSpecResolver` provides known context windows, max token limits, and input modalities for all supported models — without any API call:

```php
use Ubxty\BedrockAi\Models\ModelSpecResolver;

$specs = ModelSpecResolver::resolve('anthropic.claude-3-5-sonnet-20241022-v2:0');
// ['context_window' => 200000, 'max_tokens' => 8192]

$modalities = ModelSpecResolver::inputModalities('amazon.nova-pro-v1:0');
// ['text', 'image', 'document']

$ok = ModelSpecResolver::supportsModality('amazon.nova-pro-v1:0', 'image');
// true
```

---

### Input Modality Validation

Before sending any multimodal request (`userWithImage`, `userWithDocument`, `userWithDocuments`, `userWithAttachments`), the package checks whether the selected model supports that input type. If it doesn't, a `BedrockException` is thrown **immediately** with a clear error listing what the model does support — no wasted API request, no confusing raw AWS error.

---

## Getting AWS Credentials

### Option A: IAM Access Keys (Recommended)

1. **Create an IAM user** in [AWS Console → IAM → Users](https://console.aws.amazon.com/iam/home#/users):
   - Click **Create user** → name it (e.g. `bedrock-api`)
   - Do **not** enable console access (programmatic only)

2. **Attach a policy** with the required permissions:
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Action": [
           "bedrock:InvokeModel",
           "bedrock:InvokeModelWithResponseStream",
           "bedrock:ListFoundationModels",
           "bedrock:GetFoundationModel"
         ],
         "Resource": "*"
       },
       {
         "Effect": "Allow",
         "Action": [
           "cloudwatch:GetMetricData",
           "pricing:GetProducts"
         ],
         "Resource": "*"
       }
     ]
   }
   ```
   > Replace `"Resource": "*"` with specific model ARNs for tighter security.

3. **Generate Access Keys**: user → **Security credentials** → **Create access key** → choose **Third-party service** → copy the key ID and secret.

4. **Add to `.env`:**
   ```env
   BEDROCK_AUTH_MODE=iam
   BEDROCK_AWS_KEY=AKIA...
   BEDROCK_AWS_SECRET=your-secret-key
   BEDROCK_REGION=us-east-1
   ```

5. **Verify with the wizard:**
   ```bash
   php artisan bedrock:configure --test
   ```

---

### Option B: Bearer Token

1. Open the [AWS Bedrock Console](https://console.aws.amazon.com/bedrock/) → **API keys**
2. Create a new API key and copy the token
3. Add to `.env`:
   ```env
   BEDROCK_AUTH_MODE=bearer
   BEDROCK_BEARER_TOKEN=your-token-here
   BEDROCK_REGION=us-east-1
   ```

---

## Anthropic Model Access

> **This only affects Anthropic Claude models.** Amazon Nova, Meta Llama, Mistral, Cohere, and all other providers work immediately without any form.

Before any Claude model can be invoked, AWS requires a **one-time use-case form submission** per account.

### Steps

1. Open the Bedrock Model Catalog in your region:
   ```
   https://us-east-1.console.aws.amazon.com/bedrock/home?region=us-east-1#/model-catalog
   ```

2. Search for **Claude** and click any model card.

3. Click **"Open in playground"** — this triggers the use-case form if not yet submitted for your account.

4. Fill in: company/project description, intended use, use case category, and regulated use questions. Submit.

5. Access is typically granted within seconds to a few minutes for standard use cases.

6. Test it:
   ```bash
   php artisan bedrock:test
   ```

### The error before submission

```
Bedrock error (404): Model use case details have not been submitted for this account.
Fill out the Anthropic use case details form before using the model.
If you have already filled out the form, try again in 15 minutes.
```

This is an AWS account-level restriction, not a credentials or package issue.

### Models available while waiting

```php
Bedrock::invoke('amazon.nova-pro-v1:0', $system, $message);            // Amazon — no form
Bedrock::invoke('meta.llama3-3-70b-instruct-v1:0', $system, $message); // Meta — no form
Bedrock::invoke('mistral.mistral-large-2402-v1:0', $system, $message);  // Mistral — no form
Bedrock::invoke('cohere.command-r-plus-v1:0', $system, $message);       // Cohere — no form
```

---

## API Reference

### `BedrockManager`

| Method | Returns | Description |
|---|---|---|
| `client(?string $connection)` | `BedrockClient` | Get the raw client for a connection |
| `invoke(string $modelId, string $system, string $user, ...)` | `array` | Single-turn model invocation |
| `converse(string $modelId, array $messages, ...)` | `array` | Multi-turn via Converse API |
| `converseClient(?string $connection)` | `ConverseClient` | Get a Converse API client |
| `stream(string $modelId, ..., callable $onChunk)` | `array` | Single-turn streaming |
| `converseStream(string $modelId, array $messages, callable $onChunk, ...)` | `array` | Multi-turn streaming |
| `streamingClient(?string $connection)` | `StreamingClient` | Get a streaming client |
| `conversation(?string $modelId, ?string $connection)` | `ConversationBuilder` | Start a fluent conversation |
| `aliases()` | `ModelAliasResolver` | Get the alias resolver |
| `resolveAlias(string $alias)` | `string` | Resolve alias to full model ID |
| `defaultModel()` | `string` | Get configured default chat model |
| `defaultImageModel()` | `string` | Get configured default image model |
| `getLogger()` | `InvocationLogger` | Get the invocation logger |
| `testConnection(?string $connection)` | `array` | Test connection and return model count |
| `listModels(?string $connection)` | `array` | Raw model summaries from AWS |
| `fetchModels(?string $connection)` | `array` | Normalised models with specs |
| `syncModels(?string $connection)` | `int` | Sync models to DB; returns upserted count |
| `getModelsGrouped(?string $context)` | `array` | Models grouped by provider with filtering |
| `pricing()` | `PricingService` | Get the pricing service |
| `usage()` | `UsageTracker` | Get the usage tracker |
| `isConfigured(?string $connection)` | `bool` | True if the connection has valid credentials |
| `isBearerMode(?string $connection)` | `bool` | True if the connection uses Bearer auth |

### `invoke()` / `converse()` Response Shape

```php
[
    'response'      => string,  // Model's text response
    'input_tokens'  => int,     // Prompt tokens consumed
    'output_tokens' => int,     // Response tokens generated
    'total_tokens'  => int,     // input + output
    'cost'          => float,   // Estimated USD cost
    'latency_ms'    => int,     // End-to-end latency in milliseconds
    'status'        => string,  // Always 'success' (failures throw exceptions)
    'key_used'      => string,  // Label of the credential key that succeeded
    'model_id'      => string,  // Resolved model ID (with inference prefix if applied)
]
```

### `ConversationBuilder` Methods

| Method | Description |
|---|---|
| `system(string $prompt)` | Set the system prompt |
| `user(string $message)` | Add a plain text user message |
| `userWithImage(string $prompt, string $source, string $format)` | Add user message with an image |
| `userWithDocument(string $prompt, string $source, string $format, string $name)` | Add user message with a document |
| `userWithDocuments(string $prompt, array $documents)` | Add user message with multiple documents |
| `userWithAttachments(string $prompt, array $attachments)` | Add user message with mixed image/doc attachments |
| `maxTokens(int $tokens)` | Set max output tokens |
| `temperature(float $temp)` | Set temperature (0.0–1.0) |
| `withPricing(array $pricing)` | Set pricing arrays for cost calculation |
| `send()` | Send and return the response array |
| `sendStream(callable $onChunk)` | Send with real-time streaming callback |
| `estimate()` | Estimate tokens and cost without sending (multimodal-aware) |
| `getMessages()` | Return the full message history array |
| `setMessages(array $messages)` | Replace the full message history |
| `reset()` | Clear history (keeps system prompt and settings) |

### `PricingService`

| Method | Returns | Description |
|---|---|---|
| `getPricing()` | `array` | Cached pricing data (24h TTL) |
| `refreshPricing()` | `array` | Force-refresh from AWS Pricing API |
| `testConnection()` | `array` | Test Pricing API connectivity |

### `UsageTracker`

| Method | Returns | Description |
|---|---|---|
| `getActiveModels()` | `array` | Models with CloudWatch activity |
| `getModelUsage(string $modelId, int $days)` | `array` | Per-model daily metrics |
| `getAggregatedUsage(int $days)` | `array` | Aggregated across all active models |
| `getDailyTrend(int $days)` | `array` | Day-by-day breakdown for charts |
| `calculateCosts(array $usage, array $pricingMap)` | `array` | Cost estimation from usage + pricing |
| `testConnection()` | `array` | Test CloudWatch connectivity |

---

## Testing

The package ships with **179 tests** and **346 assertions** covering all components.

```bash
cd packages/ubxty/bedrock-ai
composer install
./vendor/bin/phpunit
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full history of changes.

---

## License

MIT License. See [LICENSE](LICENSE) for details.
