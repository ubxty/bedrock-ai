# Changelog

All notable changes to `ubxty/bedrock-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- 179 tests with 346 assertions.
