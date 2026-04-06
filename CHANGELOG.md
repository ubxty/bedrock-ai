# Changelog

All notable changes to `ubxty/bedrock-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.0.4] - 2026-04-06

### Added

- **`bedrock_models` database table** — New migration stores synced model metadata (model ID, name, provider, connection, context window, max tokens, capabilities, lifecycle status, synced timestamp). Migrations are auto-loaded and publishable via `php artisan vendor:publish --tag=bedrock-migrations`.
- **`BedrockManager::syncModels()`** — Fetches all models from AWS and upserts them into the `bedrock_models` table for offline browsing and fast lookups.
- **`BedrockManager::getModelsGrouped()`** — Returns models grouped by provider, reading from the database first (with live-fetch fallback if the table is empty or hasn't been migrated yet).
- **`bedrock:test` interactive model picker** — Instead of requiring a raw model ID, the command now presents a two-step interactive selector: first choose a provider (with active/total counts), then choose a model (showing name, ID, context window, and lifecycle status). The `--sync` flag syncs models to the database before displaying the picker.
- **Anthropic use-case tip on test failure** — When `bedrock:test` invocation fails, the command now prints a hint about the Anthropic use-case form requirement.

### Changed

- `BedrockAiServiceProvider` now calls `loadMigrationsFrom()` so migrations are auto-discovered by the host application.
- `bedrock:test --max-tokens` default raised from 100 to 200 for more useful test responses.

---

## [0.0.3] - 2026-04-06

### Added

- **`HasRetryLogic` trait** — Extracted shared retry loop, SDK client construction, and rate-limit detection from `BedrockClient`, `ConverseClient`, and `StreamingClient` into a single reusable trait, eliminating ~150 lines of code duplication.
- **`connection` parameter on `invoke()`** — `BedrockManager::invoke()` now accepts an optional `?string $connection` parameter to target a specific named connection instead of always using the default.
- **`connection` parameter on `converse()` and `stream()`** — Same named connection override, consistent with `invoke()`.
- **Cost limits, events, and logging on `converse()` and `stream()`** — Both methods now call `checkCostLimits()`, `trackCost()`, `fireInvokedEvent()`, and `getLogger()->log()` — matching the guardrails that were previously only applied to `invoke()`.
- **Double-prefix guard in `InferenceProfileResolver`** — `resolve()` now detects already-prefixed model IDs (e.g. `us.anthropic.claude-3-5-*`) and returns them unchanged, preventing malformed IDs like `us.us.anthropic.claude-3-5-*`.

### Changed

- **`BedrockClient::invoke()` delegates to Converse API** — Instead of maintaining separate `buildClaudeBody()` / `buildTitanBody()` / `parseResponse()` paths, `invoke()` now instantiates `ConverseClient` internally and calls `converse()`. This means all model providers (Claude, Titan, Llama, Mistral, Cohere, Nova) work through the single provider-agnostic Converse API.
- **`ConverseClient` uses `HasRetryLogic` trait** — Replaced hand-written retry loop with `$this->withRetry()`.
- **`StreamingClient` uses `HasRetryLogic` trait** — Replaced hand-written retry loop with `$this->withRetry()`.
- **`BedrockClient` uses `HasRetryLogic` trait** — Shared SDK client construction and rate-limit detection now come from the trait; event hooks (`onKeyRotated`, `onRateLimitExhausted`) are overridden locally.

### Removed

- `BedrockClient::buildClaudeBody()` — superseded by Converse API delegation.
- `BedrockClient::buildTitanBody()` — superseded by Converse API delegation.
- `BedrockClient::parseResponse()` — superseded by Converse API delegation.
- `BedrockClient::invokeSdk()` / `invokeHttp()` — superseded by internal `ConverseClient` call.
- Duplicated `getSdkClient()` and `isRateLimitError()` from `ConverseClient` and `StreamingClient` (now in trait).

### Fixed

- **#1+#2**: `invoke()` failed for non-Claude/Titan models (Llama, Mistral, Cohere, Nova) because it used model-specific body builders. Now works for all providers via the Converse API.
- **#4**: `InferenceProfileResolver::resolve()` double-prefixed already-resolved IDs (e.g. `us.anthropic.claude-3-5-*` → `us.us.anthropic.claude-3-5-*`).
- **#6**: `BedrockManager::converse()` and `stream()` bypassed cost limit checks, cost tracking, event dispatching, and invocation logging.
- **#7**: `BedrockManager::invoke()` was hardcoded to use the default connection with no way to specify an alternative.

---

## [0.0.2] - 2026-04-06

### Added

- **Bearer token authentication** — New `auth_mode` config field (`'iam'` or `'bearer'`). Explicitly declare the auth strategy per key instead of relying on token prefix heuristics.
- **`bedrock:chat` command** — Interactive CLI chat session with real-time streaming output, in-session commands (`/reset`, `/stats`, `/system`, `/model`, `/temp`), and automatic streaming fallback for Bearer mode.
- **`ConfigureCommand` auth mode selection** — The `bedrock:configure` wizard now presents an explicit IAM vs. Bearer token choice at step 1 and generates the correct `.env` lines for each mode.
- **Connection client caching** — `BedrockManager` now caches `BedrockClient` instances per connection name, avoiding redundant re-construction on repeated calls.
- **Models list caching** — `BedrockClient::listModels()` is now cached (default TTL: 1 hour, configurable via `cache.models_ttl`). TTL is set via the new `setModelsCacheTtl()` method.
- **Cost limit enforcement** — `BedrockManager::invoke()` now calls `checkCostLimits()` before every invocation and `trackCost()` after success. Daily and monthly Cache-backed accumulators enforce the `limits.daily` / `limits.monthly` config values with an atomic lock to prevent race conditions under concurrent requests.
- **Event dispatching in `BedrockClient`** — `BedrockKeyRotated` and `BedrockRateLimited` events are now fired directly from `BedrockClient` on key rotation and rate-limit exhaustion, not only from the manager layer.
- **`ConverseClient` Bearer mode** — Added `converseHttp()` to support the Converse API over HTTP Bearer token auth, matching the existing `invokeHttp()` in `BedrockClient`.
- **`StreamingClient` unified via `converseStream`** — Streaming now uses the provider-agnostic `converseStream` AWS API instead of `invokeModelWithResponseStream`, enabling streaming for any model provider (Claude, Nova, Titan, Llama, Mistral, Cohere, etc.).
- **`BedrockManager::isBearerMode()`** — New convenience method (and matching Facade annotation) to check the auth mode of any connection.
- **`BedrockClient::getCredentialManager()`** — Exposes the underlying `CredentialManager` for introspection.
- **`CredentialManager::isBearerMode()` / `getBearerToken()`** — Canonical method names replacing `isHttpMode()` and `getHttpBearerToken()` (deprecated aliases retained for backwards compatibility).
- **`CredentialManager::list()` extended** — Now includes `auth_mode` in the per-key info list.

### Changed

- **`CredentialManager::normalizeKey()`** — Keys are normalized on construction. `auth_mode` is auto-detected from the presence of `bearer_token` or an `ABSK`-prefixed `aws_key`/`aws_secret` when not explicitly set, removing heuristic checks scattered across other classes.
- **`BedrockManager::isConfigured()`** — Now delegates to `CredentialManager` for key normalization, ensuring Bearer mode auto-detection is consistent with how `CredentialManager` handles it.
- **`ConversationBuilder::send()`** — Delegates to `ConverseClient` (Converse API) for proper multi-turn support, replacing the single-turn `invoke()` path.
- **`ConversationBuilder::sendStream()`** — Delegates to `StreamingClient::converseStream()` using the unified Converse streaming API.

### Fixed

- `BedrockClient::invokeHttp()` was calling the deprecated `getHttpBearerToken()` instead of `getBearerToken()`.
- `BedrockManager` import order violated PSR-12 (`Illuminate\Support\Facades\Cache` was placed among `Ubxty` imports); corrected.
- `isConfigured()` incorrectly reported keys with auto-detected Bearer mode (no explicit `auth_mode`) as unconfigured when `aws_key`/`aws_secret` were absent.
- `trackCost()` had a non-atomic read-modify-write race condition under concurrent requests; now protected with `Cache::lock()`.

### Deprecated

- `CredentialManager::isHttpMode()` — use `isBearerMode()` instead.
- `CredentialManager::getHttpBearerToken()` — use `getBearerToken()` instead.

---

## [0.0.1] - 2026-04-05

### Added

- Initial release.
- `BedrockClient` with multi-key rotation, exponential backoff retry, cross-region inference profile auto-resolution.
- `ConverseClient` for the AWS Converse API (multi-turn, provider-agnostic).
- `StreamingClient` for real-time token streaming.
- `ConversationBuilder` — fluent multi-turn conversation API.
- `CredentialManager` — multi-key credential pool with `next()` / `reset()` rotation.
- `InferenceProfileResolver` — automatic `us.` / `eu.` prefix injection.
- `ModelAliasResolver` — map short names to full model IDs.
- `ModelSpecResolver` — static context-window and max-token specs for 30+ models.
- `PricingService` — live pricing from the AWS Pricing API with cache.
- `UsageTracker` — CloudWatch-based invocation and token metrics.
- `TokenEstimator` — pre-call token and cost estimation.
- `InvocationLogger` — auto-log invocations to any Laravel log channel.
- `BedrockManager` — orchestrates all services; supports multiple named connections.
- `Bedrock` Facade.
- `BedrockAiServiceProvider` with config publishing and optional health-check route.
- `HealthCheckController` — `/health/bedrock` route.
- Custom exceptions: `BedrockException`, `RateLimitException`, `ConfigurationException`, `CostLimitExceededException`.
- Laravel events: `BedrockInvoked`, `BedrockRateLimited`, `BedrockKeyRotated`.
- CLI commands: `bedrock:configure`, `bedrock:test`, `bedrock:models`, `bedrock:usage`, `bedrock:pricing`.
- 176 tests with 331 assertions.
