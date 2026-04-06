# Bedrock AI

<p align="center">
<img src="https://img.shields.io/badge/PHP-8.2%2B-blue" alt="PHP 8.2+">
<img src="https://img.shields.io/badge/Laravel-11%20%7C%2012-red" alt="Laravel 11|12">
<img src="https://img.shields.io/badge/License-MIT-green" alt="MIT License">
</p>

A Laravel package for seamless **AWS Bedrock** integration. Provides multi-key credential rotation, cross-region inference profiles, CloudWatch usage tracking, real-time pricing, and powerful CLI tools—all with zero boilerplate.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
  - [Basic Setup](#basic-setup)
  - [Multiple AWS Keys (Failover)](#multiple-aws-keys-failover)
  - [Multiple Connections](#multiple-connections)
  - [Cost Limits](#cost-limits)
  - [Pricing & Usage API](#pricing--usage-api)
- [Usage](#usage)
  - [Facade](#facade)
  - [Dependency Injection](#dependency-injection)
  - [Invoking Models](#invoking-models)
  - [Model Aliases](#model-aliases)
  - [Conversation Builder](#conversation-builder)
  - [Converse API](#converse-api)
  - [Streaming Responses](#streaming-responses)
  - [Token Estimation](#token-estimation)
  - [Listing Models](#listing-models)
  - [Testing Connection](#testing-connection)
  - [Pricing Data](#pricing-data)
  - [Usage Tracking](#usage-tracking)
  - [Events](#events)
  - [Invocation Logging](#invocation-logging)
  - [Health Check Route](#health-check-route)
- [CLI Commands](#cli-commands)
  - [bedrock:configure](#bedrockconfigure)
  - [bedrock:test](#bedrocktest)
  - [bedrock:models](#bedrockmodels)
  - [bedrock:chat](#bedrockchat)
  - [bedrock:usage](#bedrockusage)
  - [bedrock:pricing](#bedrockpricing)
- [Architecture](#architecture)
  - [Cross-Region Inference Profiles](#cross-region-inference-profiles)
  - [Multi-Key Rotation & Retry](#multi-key-rotation--retry)
  - [HTTP Bearer Token Mode](#http-bearer-token-mode)
  - [Model Spec Resolution](#model-spec-resolution)
- [Getting AWS Credentials](#getting-aws-credentials)
  - [Option A: IAM Access Keys](#option-a-iam-access-keys)
  - [Option B: Bearer Token](#option-b-bearer-token)
- [Error Handling](#error-handling)
- [API Reference](#api-reference)
- [Testing](#testing)
- [License](#license)

---

## Features

| Feature | Description |
|---|---|
| **Multi-key rotation** | Configure multiple AWS credential sets with automatic failover |
| **Cross-region inference** | Automatic `us.`/`eu.` prefix for newer models (Claude 3.5+, Nova, Llama 3.3+) |
| **Dual auth mode** | Explicit `auth_mode`: IAM Access Key+Secret **or** Bearer Token — no guesswork |
| **Rate limit retry** | Exponential backoff with configurable retries per key |
| **Titan + Claude support** | Handles different request/response formats transparently |
| **Converse API** | Unified AWS Converse API across all model providers |
| **Streaming** | Real-time token streaming via `converseStream` (all providers) |
| **Conversation Builder** | Fluent multi-turn conversation API with chaining |
| **Model Aliases** | Define short names for model IDs (e.g., `'claude'` → full model ID) |
| **Token Estimation** | Pre-call token count and cost estimation |
| **Cost limits** | Configurable daily/monthly spending limits with enforcement |
| **Laravel Events** | `BedrockInvoked`, `BedrockRateLimited`, `BedrockKeyRotated` events |
| **Invocation Logger** | Auto-log all invocations with configurable channels |
| **Health Check Route** | Registerable `/health/bedrock` endpoint for monitoring |
| **CloudWatch usage** | Track input/output tokens, invocations, latency from CloudWatch metrics |
| **Real-time pricing** | Fetch current Bedrock pricing from the AWS Pricing API |
| **6 CLI commands** | Configure, test, list models, chat, view usage, fetch pricing |
| **Facade + DI** | Use `Bedrock::invoke()` or inject `BedrockManager` |

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- `aws/aws-sdk-php` ^3.300
- AWS credentials with Bedrock access

---

## Installation

```bash
composer require ubxty/bedrock-ai
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=bedrock-config
```

Or use the interactive wizard:

```bash
php artisan bedrock:configure
```

---

## Quick Start

```php
use Ubxty\BedrockAi\Facades\Bedrock;

// Simple invocation
$result = Bedrock::invoke(
    modelId: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
    systemPrompt: 'You are a helpful assistant.',
    userMessage: 'What is the capital of France?'
);

echo $result['response'];       // "The capital of France is Paris."
echo $result['total_tokens'];   // 42
echo $result['cost'];           // 0.000234
echo $result['latency_ms'];     // 850
```

---

## Configuration

### Basic Setup

The package supports two authentication modes. Choose **one**:

#### IAM Mode (Recommended)

Uses standard AWS IAM Access Key + Secret:

```env
BEDROCK_AUTH_MODE=iam
BEDROCK_AWS_KEY=AKIA...
BEDROCK_AWS_SECRET=your-secret-key
BEDROCK_REGION=us-east-1
```

#### Bearer Token Mode

Uses a Bearer token (e.g., from AWS Bedrock API keys):

```env
BEDROCK_AUTH_MODE=bearer
BEDROCK_BEARER_TOKEN=your-bearer-token
BEDROCK_REGION=us-east-1
```

> **Note:** If you don't set `BEDROCK_AUTH_MODE`, it defaults to `iam`. The package also falls back to standard `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` env vars if the Bedrock-specific ones aren't set.

### Multiple AWS Keys (Failover)

In `config/bedrock.php`, add multiple keys to a connection. If the first key hits a rate limit or fails, the client automatically tries the next:

```php
'connections' => [
    'default' => [
        'keys' => [
            [
                'label' => 'Primary',
                'auth_mode' => 'iam',
                'aws_key' => env('BEDROCK_AWS_KEY'),
                'aws_secret' => env('BEDROCK_AWS_SECRET'),
                'region' => 'us-east-1',
            ],
            [
                'label' => 'Backup',
                'auth_mode' => 'iam',
                'aws_key' => env('BEDROCK_AWS_KEY_2'),
                'aws_secret' => env('BEDROCK_AWS_SECRET_2'),
                'region' => 'us-west-2',
            ],
        ],
    ],
],
```

### Multiple Connections

Define separate connections for different environments or use cases:

```php
'connections' => [
    'default' => [
        'keys' => [/* ... */],
    ],
    'production' => [
        'keys' => [/* ... */],
    ],
    'staging' => [
        'keys' => [/* ... */],
    ],
],
```

Switch at runtime:

```php
Bedrock::invoke('anthropic.claude...', $system, $user, connection: 'production');
// or
$client = Bedrock::client('staging');
```

### Cost Limits

```env
BEDROCK_DAILY_LIMIT=10.00
BEDROCK_MONTHLY_LIMIT=300.00
```

When exceeded, a `CostLimitExceededException` is thrown.

### Pricing & Usage API

If you use separate IAM credentials for the Pricing or CloudWatch APIs:

```env
BEDROCK_PRICING_KEY=AKIA...
BEDROCK_PRICING_SECRET=your-pricing-secret

BEDROCK_USAGE_KEY=AKIA...
BEDROCK_USAGE_SECRET=your-usage-secret
BEDROCK_USAGE_REGION=us-east-1
```

If not set, the package falls back to the default connection's first key.

---

## Usage

### Facade

```php
use Ubxty\BedrockAi\Facades\Bedrock;

// Invoke a model
$result = Bedrock::invoke('anthropic.claude-3-5-sonnet-20241022-v2:0', $system, $user);

// Test connection
$test = Bedrock::testConnection();

// List models
$models = Bedrock::fetchModels();

// Check if configured
if (Bedrock::isConfigured()) { /* ... */ }

// Pricing
$pricing = Bedrock::pricing()->getPricing();

// Usage
$usage = Bedrock::usage()->getAggregatedUsage(30);
```

### Dependency Injection

```php
use Ubxty\BedrockAi\BedrockManager;

class MyService
{
    public function __construct(protected BedrockManager $bedrock) {}

    public function analyze(string $text): string
    {
        $result = $this->bedrock->invoke(
            'anthropic.claude-3-5-sonnet-20241022-v2:0',
            'You are an analyst.',
            $text,
            maxTokens: 2048
        );

        return $result['response'];
    }
}
```

### Invoking Models

```php
$result = Bedrock::invoke(
    modelId: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
    systemPrompt: 'You are a medical assistant.',
    userMessage: 'Explain hypertension in simple terms.',
    maxTokens: 1024,
    temperature: 0.5,
    pricing: [
        'input_price_per_1k' => 0.003,
        'output_price_per_1k' => 0.015,
    ]
);

// $result structure:
// [
//     'response' => 'Hypertension, or high blood pressure...',
//     'input_tokens' => 45,
//     'output_tokens' => 230,
//     'total_tokens' => 275,
//     'cost' => 0.003585,
//     'latency_ms' => 1250,
//     'status' => 'success',
//     'key_used' => 'Primary',
//     'model_id' => 'us.anthropic.claude-3-5-sonnet-20241022-v2:0',
// ]
```

**Supported models include:**
- Claude 3.5 Sonnet/Haiku, Claude 3 Opus/Sonnet/Haiku, Claude 4
- Amazon Titan Text Express/Lite/Premier
- Amazon Nova Pro/Lite/Micro
- Meta Llama 3/3.1/3.2/3.3/4
- Mistral Large/Small/Mixtral/Ministral
- Cohere Command R/R+
- AI21 Jamba

### Model Aliases

Define short names for frequently used model IDs in `config/bedrock.php`:

```php
'aliases' => [
    'claude' => 'anthropic.claude-sonnet-4-20250514-v1:0',
    'haiku'  => 'anthropic.claude-3-5-haiku-20241022-v1:0',
    'nova'   => 'amazon.nova-pro-v1:0',
],
```

Use aliases anywhere a model ID is accepted:

```php
Bedrock::invoke('claude', 'You are helpful.', 'Hello!');

$builder = Bedrock::conversation('haiku');

// Register aliases at runtime
Bedrock::aliases()->register('fast', 'anthropic.claude-3-5-haiku-20241022-v1:0');
```

### Conversation Builder

Fluent API for multi-turn conversations:

```php
$conversation = Bedrock::conversation('claude')
    ->system('You are a medical assistant.')
    ->user('What causes headaches?')
    ->maxTokens(2048)
    ->temperature(0.5);

// Send and get response
$result = $conversation->send();
echo $result['response'];

// Continue the conversation (assistant response is auto-added)
$conversation->user('Tell me more about migraines');
$result2 = $conversation->send();

// Estimate tokens and cost before sending
$estimate = $conversation->estimate();
echo "Estimated input tokens: {$estimate['input_tokens']}";
echo "Fits in context: " . ($estimate['fits'] ? 'yes' : 'no');
echo "Estimated cost: \${$estimate['estimated_cost']}";

// Reset conversation (keeps system prompt and settings)
$conversation->reset();
```

### Converse API

The AWS Converse API provides a unified interface across all model providers—no need to handle different request formats:

```php
// Direct converse call
$result = Bedrock::converse(
    modelId: 'anthropic.claude-sonnet-4-20250514-v1:0',
    messages: [
        ['role' => 'user', 'content' => 'What is PHP?'],
        ['role' => 'assistant', 'content' => 'PHP is a programming language.'],
        ['role' => 'user', 'content' => 'What makes it special?'],
    ],
    systemPrompt: 'You are a helpful teacher.',
    maxTokens: 1024
);

// Or get the client directly
$converseClient = Bedrock::converseClient();
$result = $converseClient->converse($modelId, $messages);
```

### Streaming Responses

Stream responses in real-time for chat UIs and long outputs. Uses the unified `converseStream` API which works with **all** model providers:

```php
// Stream via the manager
$result = Bedrock::stream(
    modelId: 'anthropic.claude-sonnet-4-20250514-v1:0',
    systemPrompt: 'You are helpful.',
    userMessage: 'Write a poem about PHP.',
    onChunk: function (string $chunk, array $metadata) {
        echo $chunk; // Print each token as it arrives
        flush();
    }
);

// Stream with Converse API (multi-turn)
$streamingClient = Bedrock::streamingClient();
$result = $streamingClient->converseStream(
    modelId: 'anthropic.claude-sonnet-4-20250514-v1:0',
    messages: [['role' => 'user', 'content' => 'Hello!']],
    onChunk: fn(string $text) => echo $text,
    systemPrompt: 'Be concise.'
);

// Stream via ConversationBuilder
$conversation = Bedrock::conversation('claude')
    ->system('You are helpful.')
    ->user('Write a haiku about Laravel.');

$result = $conversation->sendStream(function (string $chunk) {
    echo $chunk;
});
```

> **Note:** Streaming requires IAM auth mode. Bearer token mode does not support streaming — use `converse()` or `send()` instead.

### Token Estimation

Estimate token usage and cost before making API calls:

```php
use Ubxty\BedrockAi\Support\TokenEstimator;

// Estimate tokens in a string
$tokens = TokenEstimator::estimate($text);

// Full invocation estimation
$estimation = TokenEstimator::estimateInvocation(
    systemPrompt: $system,
    userMessage: $user,
    modelId: 'anthropic.claude-sonnet-4-v1:0',
    maxOutputTokens: 4096
);

echo "Input tokens: ~{$estimation['input_tokens']}";
echo "Fits in context: " . ($estimation['fits'] ? 'yes' : 'no');
echo "Available output tokens: {$estimation['available_output']}";

// Estimate cost
$cost = TokenEstimator::estimateCost($system, $user, 1000, [
    'input_price_per_1k' => 0.003,
    'output_price_per_1k' => 0.015,
]);
echo "Estimated cost: \${$cost}";
```

### Listing Models

```php
// Raw model summaries from Bedrock
$raw = Bedrock::listModels();

// Normalized with specs
$models = Bedrock::fetchModels();

foreach ($models as $model) {
    echo "{$model['name']} ({$model['model_id']}) - Context: {$model['context_window']}";
}
```

### Testing Connection

```php
$result = Bedrock::testConnection();

if ($result['success']) {
    echo "Connected! Found {$result['model_count']} models in {$result['response_time']}ms";
}
```

### Pricing Data

```php
$pricing = Bedrock::pricing()->getPricing();

foreach ($pricing as $modelId => $data) {
    echo "{$data['model_name']}: Input \${$data['input_price']}/1K, Output \${$data['output_price']}/1K\n";
}

// Force refresh from AWS
$fresh = Bedrock::pricing()->refreshPricing();
```

### Usage Tracking

```php
$tracker = Bedrock::usage();

// Active models from CloudWatch
$models = $tracker->getActiveModels();

// Aggregated usage
$usage = $tracker->getAggregatedUsage(days: 30);

foreach ($usage as $modelId => $data) {
    echo "{$modelId}: {$data['invocations']} calls, {$data['total_tokens']} tokens\n";
}

// Daily trend for charts
$trend = $tracker->getDailyTrend(30);

// Cost estimation
$costs = $tracker->calculateCosts($usage, $pricingMap);
echo "Total cost: \${$costs['total_cost']}";
```

### Events

The package fires Laravel events for observability. Listen to them in your `EventServiceProvider` or with closures:

```php
use Ubxty\BedrockAi\Events\BedrockInvoked;
use Ubxty\BedrockAi\Events\BedrockRateLimited;
use Ubxty\BedrockAi\Events\BedrockKeyRotated;

// In a listener or closure
Event::listen(BedrockInvoked::class, function (BedrockInvoked $event) {
    // $event->modelId, $event->inputTokens, $event->outputTokens
    // $event->cost, $event->latencyMs, $event->keyUsed, $event->connection
});

Event::listen(BedrockRateLimited::class, function (BedrockRateLimited $event) {
    // $event->modelId, $event->keyLabel, $event->retryAttempt, $event->waitSeconds
    Notification::send($admin, new RateLimitAlert($event));
});

Event::listen(BedrockKeyRotated::class, function (BedrockKeyRotated $event) {
    // $event->fromKeyLabel, $event->toKeyLabel, $event->reason, $event->modelId
});
```

### Invocation Logging

Auto-log every Bedrock invocation for auditing and cost tracking:

```env
BEDROCK_LOGGING_ENABLED=true
BEDROCK_LOG_CHANNEL=bedrock
```

In `config/bedrock.php`:

```php
'logging' => [
    'enabled' => env('BEDROCK_LOGGING_ENABLED', false),
    'channel' => env('BEDROCK_LOG_CHANNEL', 'stack'),
],
```

Each invocation logs: model ID, tokens (input/output/total), cost, latency, status, and key used.

### Health Check Route

Register a health check endpoint for monitoring dashboards:

```env
BEDROCK_HEALTH_CHECK_ENABLED=true
```

In `config/bedrock.php`:

```php
'health_check' => [
    'enabled' => env('BEDROCK_HEALTH_CHECK_ENABLED', false),
    'path' => '/health/bedrock',
    'middleware' => ['auth:sanctum'], // optional
],
```

The endpoint returns:

```json
{
    "status": "healthy",
    "message": "Connection successful! Found 42 available models.",
    "response_time_ms": 350,
    "model_count": 42
}
```

---

## CLI Commands

### `bedrock:configure`

Interactive wizard for first-time setup.

```bash
php artisan bedrock:configure

# Show current config (masked secrets)
php artisan bedrock:configure --show

# Auto-test after configuring
php artisan bedrock:configure --test
```

**What it does:**
1. Asks whether you want IAM or Bearer token auth
2. Prompts for the relevant credentials based on your choice
3. Optionally configures Pricing API credentials
4. Generates `.env` entries
5. Optionally writes to `.env` automatically
6. Tests the connection

### `bedrock:test`

Test connection and optionally invoke a model.

```bash
# Basic connection test
php artisan bedrock:test

# Test a specific model
php artisan bedrock:test anthropic.claude-3-5-sonnet-20241022-v2:0

# Custom prompt
php artisan bedrock:test anthropic.claude-3-5-sonnet-20241022-v2:0 --prompt="Explain gravity"

# Test all credential keys
php artisan bedrock:test --all-keys

# JSON output
php artisan bedrock:test anthropic.claude-3-5-sonnet-20241022-v2:0 --json
```

### `bedrock:models`

List available foundation models.

```bash
# All models
php artisan bedrock:models

# Filter by name/ID
php artisan bedrock:models --filter=claude

# Filter by provider
php artisan bedrock:models --provider=anthropic

# JSON output
php artisan bedrock:models --json
```

### `bedrock:chat`

Interactive CLI chat session with any Bedrock model.

```bash
# Start interactive chat (will prompt for model selection)
php artisan bedrock:chat

# Start with a specific model
php artisan bedrock:chat --model=anthropic.claude-sonnet-4-20250514-v1:0

# Set a custom system prompt
php artisan bedrock:chat --system="You are a medical assistant."

# Use a specific connection
php artisan bedrock:chat --connection=production
```

**In-session commands:**

| Command | Description |
|---|---|
| `/help` | Show available commands |
| `/quit` | End the session |
| `/reset` | Clear conversation history (keeps settings) |
| `/stats` | Show session stats (messages, tokens, cost) |
| `/system <prompt>` | Change the system prompt |
| `/model <id>` | Switch to a different model |
| `/temp <0.0-1.0>` | Adjust temperature |

### `bedrock:usage`

View CloudWatch usage metrics.

```bash
# Last 30 days (default)
php artisan bedrock:usage

# Custom time range
php artisan bedrock:usage --days=7

# Show daily breakdown
php artisan bedrock:usage --daily

# JSON output
php artisan bedrock:usage --json
```

### `bedrock:pricing`

Fetch real-time pricing from the AWS Pricing API.

```bash
# All models
php artisan bedrock:pricing

# Filter
php artisan bedrock:pricing --filter=claude

# Force refresh (bypass cache)
php artisan bedrock:pricing --refresh

# JSON output
php artisan bedrock:pricing --json
```

---

## Architecture

### Cross-Region Inference Profiles

Newer models (Claude 3.5+, Nova, Llama 3.3+, Claude 4) cannot be invoked directly. They require cross-region inference profiles with a `us.` or `eu.` prefix. This package handles this automatically:

```
anthropic.claude-3-5-sonnet-20241022-v2:0
  → us.anthropic.claude-3-5-sonnet-20241022-v2:0  (in us-east-1)
  → eu.anthropic.claude-3-5-sonnet-20241022-v2:0  (in eu-west-1)
```

Models that require inference profiles:
- `anthropic.claude-3-5-*`
- `anthropic.claude-3-7-*`
- `anthropic.claude-sonnet-4*`, `claude-opus-4*`, `claude-haiku-4*`
- `amazon.nova-*`
- `meta.llama3-3*`, `meta.llama4*`

You can register additional patterns:

```php
use Ubxty\BedrockAi\Client\InferenceProfileResolver;

InferenceProfileResolver::addPattern('custom.model-prefix-');
```

### Multi-Key Rotation & Retry

```
Request → Key 1 → Rate Limited → Retry (2s) → Retry (4s) → Retry (8s) → Key 2 → Success
```

- Each key gets up to `max_retries` attempts (default: 3) with exponential backoff
- On persistent failure, the next key is tried
- All keys exhausted → `RateLimitException` or `BedrockException`

### HTTP Bearer Token Mode

When `auth_mode` is set to `bearer`, the client uses HTTP Bearer token authentication instead of the AWS SDK. This is useful for:
- Bedrock API keys distributed via the AWS console
- Environments without full IAM credentials
- Simplified authentication without managing access key pairs

> **Limitation:** Bearer token mode does not support streaming responses. Use IAM mode if you need streaming.

### Model Spec Resolution

The `ModelSpecResolver` maps model IDs to known context windows and max token limits:

```php
use Ubxty\BedrockAi\Models\ModelSpecResolver;

$specs = ModelSpecResolver::resolve('anthropic.claude-3-5-sonnet-20241022-v2:0');
// ['context_window' => 200000, 'max_tokens' => 8192]
```

---

## Getting AWS Credentials

You need AWS credentials with Bedrock access. There are two options:

### Option A: IAM Access Keys

This is the **recommended** approach for production use.

1. **Create an IAM user** in the [AWS Console → IAM → Users](https://console.aws.amazon.com/iam/home#/users):
   - Click **Create user** → enter a name (e.g., `bedrock-api`)
   - Do NOT enable console access — this is a programmatic user

2. **Attach Bedrock permissions** — create or attach a policy with:
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
           }
       ]
   }
   ```
   > For tighter security, replace `"Resource": "*"` with specific model ARNs.

3. **Generate Access Keys** — go to the user → **Security credentials** tab → **Create access key**:
   - Choose **Third-party service** as the use case
   - Copy the **Access Key ID** (`AKIA...`) and **Secret Access Key**

4. **Add to `.env`:**
   ```env
   BEDROCK_AUTH_MODE=iam
   BEDROCK_AWS_KEY=AKIA...
   BEDROCK_AWS_SECRET=your-secret-key
   BEDROCK_REGION=us-east-1
   ```

5. **(Optional)** For usage tracking and pricing, add CloudWatch and Pricing permissions:
   ```json
   {
       "Effect": "Allow",
       "Action": [
           "cloudwatch:GetMetricData",
           "pricing:GetProducts"
       ],
       "Resource": "*"
   }
   ```

### Option B: Bearer Token

1. In the [AWS Bedrock Console](https://console.aws.amazon.com/bedrock/), navigate to **API keys**
2. Create a new API key and copy the token
3. **Add to `.env`:**
   ```env
   BEDROCK_AUTH_MODE=bearer
   BEDROCK_BEARER_TOKEN=your-token-here
   BEDROCK_REGION=us-east-1
   ```

> **Tip:** Run `php artisan bedrock:configure` for an interactive wizard that guides you through the setup and writes your `.env` automatically.

---

## Error Handling

The package provides specific exception types:

```php
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;
use Ubxty\BedrockAi\Exceptions\CostLimitExceededException;

try {
    $result = Bedrock::invoke($modelId, $system, $user);
} catch (RateLimitException $e) {
    // All keys exhausted after retries
    // $e->getModelId(), $e->getKeyLabel()
} catch (CostLimitExceededException $e) {
    // Daily or monthly limit exceeded
    // $e->getLimitType(), $e->getLimit(), $e->getCurrentSpend()
} catch (ConfigurationException $e) {
    // Missing credentials or connection
} catch (BedrockException $e) {
    // General Bedrock errors (model not found, access denied, etc.)
    // User-friendly messages are automatically extracted
}
```

Raw Bedrock errors are automatically mapped to user-friendly messages:

| Raw Error | Friendly Message |
|---|---|
| `model identifier is invalid` | Invalid model: This model ID is not valid for Bedrock. |
| `doesn't support on-demand throughput` | Model unavailable: This model requires provisioned throughput. |
| `Malformed input request` | Request error: This model may not support text chat. |
| `end of its life` | Model deprecated: This model version has been retired. |
| `AccessDeniedException` | Access denied: You don't have permission to use this model. |
| `ResourceNotFoundException` | Model not found: The requested model does not exist in this region. |

---

## API Reference

### `BedrockManager`

| Method | Returns | Description |
|---|---|---|
| `client(?string $connection)` | `BedrockClient` | Get a client for the given connection |
| `invoke(string $modelId, ...)` | `array` | Invoke a model on the default connection |
| `converse(array $messages, ...)` | `array` | Invoke via the Converse API |
| `converseClient(?string $connection)` | `ConverseClient` | Get a Converse API client |
| `stream(string $modelId, ..., callable $onChunk)` | `array` | Stream a model response |
| `streamingClient(?string $connection)` | `StreamingClient` | Get a streaming client |
| `conversation(?string $modelId)` | `ConversationBuilder` | Start a fluent conversation |
| `aliases()` | `ModelAliasResolver` | Get the alias resolver |
| `resolveAlias(string $alias)` | `string` | Resolve an alias to a model ID |
| `getLogger()` | `InvocationLogger` | Get the invocation logger |
| `testConnection(?string $connection)` | `array` | Test connection |
| `listModels(?string $connection)` | `array` | List raw model summaries |
| `fetchModels(?string $connection)` | `array` | List normalized models with specs |
| `pricing()` | `PricingService` | Get the pricing service |
| `usage()` | `UsageTracker` | Get the usage tracker |
| `isConfigured(?string $connection)` | `bool` | Check if configured |

### `BedrockClient::invoke()` Return Value

```php
[
    'response' => string,      // The model's text response
    'input_tokens' => int,     // Tokens in the prompt
    'output_tokens' => int,    // Tokens in the response
    'total_tokens' => int,     // input + output
    'cost' => float,           // Estimated cost in USD
    'latency_ms' => int,       // End-to-end latency in milliseconds
    'status' => 'success',     // Always 'success' (failures throw)
    'key_used' => string,      // Label of the credential key that worked
    'model_id' => string,      // Resolved model ID (with inference prefix if applied)
]
```

### `PricingService`

| Method | Returns | Description |
|---|---|---|
| `getPricing()` | `array` | Cached pricing data |
| `refreshPricing()` | `array` | Force-refresh from AWS |
| `testConnection()` | `array` | Test Pricing API access |

### `UsageTracker`

| Method | Returns | Description |
|---|---|---|
| `getActiveModels()` | `array` | Models with CloudWatch metrics |
| `getModelUsage(string $modelId, int $days)` | `array` | Per-model daily metrics |
| `getAggregatedUsage(int $days)` | `array` | Aggregated across all models |
| `calculateCosts(array $usage, array $pricingMap)` | `array` | Cost estimation |
| `getDailyTrend(int $days)` | `array` | Daily breakdown for charts |
| `testConnection()` | `array` | Test CloudWatch access |

---

## Testing

The package includes **179 tests** with **346 assertions** covering all components.

```bash
cd packages/ubxty/bedrock-ai
composer install
./vendor/bin/phpunit
```

---

## License

MIT License. See [LICENSE](LICENSE) for details.
