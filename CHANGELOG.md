# Changelog

All notable changes to `ubxty/bedrock-ai` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.0.11] - 2026-04-07

### Added

- **`/image` command in `bedrock:chat`** — Send `/image <path> [prompt]` during a chat session to analyse an image with the current model. Supports JPEG, PNG, GIF, and WebP. Quoted paths with spaces are supported. If no prompt is given, defaults to "Describe this image in detail."
- **`/doc` command in `bedrock:chat`** — Send `/doc <path> [prompt]` to analyse a document (PDF, CSV, DOCX, XLSX, HTML, TXT, MD) with the current model. Tested and confirmed working with Amazon Nova Pro and Nova Lite. If no prompt is given, defaults to "Summarize this document."
- **`ConversationBuilder::setMessages()`** — New method to replace the full message history directly, used internally for error recovery.

### Fixed

- **StreamingClient multimodal support** — `StreamingClient` now uses the shared `formatMessages()` method from `HasRetryLogic`, fixing a crash when streaming multimodal (image/document) conversations.
- **Chat error recovery with multimodal messages** — Error recovery in `bedrock:chat` no longer breaks when the conversation contains image/document blocks. Uses `setMessages()` instead of replaying individual messages.
- **File-size guard on image & document uploads** — `userWithImage()` and `userWithDocument()` now reject files larger than 15 MB with a clear error message.
- **Document format auto-detection** — `userWithDocument()` now maps file extensions to AWS-accepted format names (e.g. `docx` → `docx`, `htm` → `html`, `md` → `md`) instead of passing raw extensions.
- **`ModelsCommand` grouping** — Models are now grouped by provider name via `getModelsGrouped()` instead of splitting the model ID on `.`, which produced incorrect groups for some IDs.

### Changed

- **Shared `formatMessages()` and `calculateCost()` in `HasRetryLogic` trait** — Both methods extracted from `ConverseClient` / `BedrockClient` into the shared trait, eliminating code duplication across all three client classes.

---

## [0.0.10] - 2026-04-06

### Added

- **Vision & multimodal support** — New `userWithImage()` and `userWithDocument()` methods on `ConversationBuilder` let you send images (JPEG, PNG, GIF, WebP) and documents (PDF, CSV, DOCX, XLSX, TXT, HTML, MD) alongside a text prompt. Models that support these input types (Claude 3+, Amazon Nova Pro/Lite, etc.) will analyse the content and respond. Accepts a file path or a pre-encoded base64 string. Multi-turn conversations work — send the image in the first turn and follow up with plain text in subsequent turns.

### Fixed

- **Bearer token: cross-region inference profile prefix removed** — `ConverseClient` was prepending `us./eu.` prefixes to model IDs (e.g. `amazon.nova-pro-v1:0` → `us.amazon.nova-pro-v1:0`) in bearer token mode. These prefixes are required for IAM/SigV4 but cause 403 Authentication failures on bearer endpoints. The original model ID is now passed directly to `converseHttp()`.
- **Error message decodes AWS `Message` key (capital M)** — `BedrockClient::extractUserFriendlyError()` now reads `$decoded['Message']` as a fallback, so the raw JSON blob no longer leaks into error messages.
- **Friendly error for invalid/expired bearer tokens** — A 403 response containing "Authentication failed" or "API Key is valid" now returns a human-readable message: *"Bearer token is invalid or expired. Regenerate your API key in the AWS Console."*
- **`estimate()` handles multimodal messages** — `ConversationBuilder::estimate()` now extracts only text blocks when calculating token estimates, avoiding a fatal error when array content blocks are present.

---

## [0.0.9] - 2026-04-06

### Changed

- **`bedrock:chat` respects `disabled_providers` config** — Model list in the chat command now filters out providers listed in `providers.disabled_providers` and `providers.chat.disabled_providers`, consistent with other commands.
- **`bedrock:chat` prompts before model selection** — When a default chat model is configured, the command now asks "Use default model?" (defaulting to yes) before showing the full model picker, skipping the picker entirely if the user accepts the default.
- **`bedrock:default-model` always asks before setting image model** — Image model selection is now opt-in (defaults to no), so running the command without needing an image model no longer forces the user through the image picker.

---

## [0.0.8] - 2026-04-06

### Added

- **Input modalities display** — Model pickers now show which models support image and document/PDF inputs via `[img]` and `[pdf]` tags.
- **`--legacy` flag** — All three model picker commands (`bedrock:test`, `bedrock:default-model`, `bedrock:models`) now hide legacy/deprecated models by default; pass `--legacy` to include them.
- **Cleaner model display** — Model names show as `Name — Xk context [tags]` format instead of complex tables; duplicate names disambiguated with `(short-id)` suffix.

### Changed

- `BedrockClient::fetchModels()` now captures `inputModalities` from AWS API alongside output capabilities.
- `bedrock:models` table now shows `Accepts` column with input modality tags (`img`, `pdf`, or `—`).
- Provider selector shows simplified `Provider (N models)` instead of `(active / total)` count.
- Model ID displayed in gray after selection for confirmation.

### Database

- **New migration** `add_input_modalities_to_bedrock_models_table` adds `input_modalities` JSON column (auto-applied on migrate).

---

## [0.0.7] - 2026-04-06

### Added

- **`bedrock:default-model` command (replaces `bedrock:model`)** — Completely rewritten interactive wizard that now sets both chat and image default models. Features a two-step provider→model picker, test-before-set confirmation, capability-based filtering (image models only shown for image selection), and `--show` / `--reset` / `--connection=` flags.
- **`BedrockManager::defaultImageModel()`** — Returns the configured default image model from `BEDROCK_DEFAULT_IMAGE_MODEL` env.
- **`BedrockManager::converseStream()`** — New manager-level method for multi-turn streaming conversations with full guardrails (cost limits, events, logging, cost calculation).
- **`Providers` constants class** — Type-safe provider name constants (`Providers::ANTHROPIC`, `Providers::META`, etc.) for use in config and code, avoiding typos with space-containing names like `'AI21 Labs'`.
- **`WritesEnvFile` trait** — Extracted shared `.env` write logic used by `ConfigureCommand` and `DefaultModelCommand`.
- **Per-context provider filtering** — Config now supports `providers.chat.disabled_providers` and `providers.image.disabled_providers` for context-scoped filtering, in addition to the global `providers.disabled_providers`.
- **`BEDROCK_DEFAULT_IMAGE_MODEL` env variable** — Configure a default image model in `.env`.
- **`BEDROCK_DISABLED_PROVIDERS` env variable** — Comma-separated list of globally disabled providers via `.env`.
- **`BEDROCK_CHAT_DISABLED_PROVIDERS` / `BEDROCK_IMAGE_DISABLED_PROVIDERS` env variables** — Context-scoped provider filtering via `.env`.

### Changed

- **`getModelsGrouped()` accepts `?string $context` parameter** — Pass `'chat'` or `'image'` to apply context-scoped provider filtering in addition to global disabled list.
- **`converse()` and `stream()` now accept `?array $pricing`** — Pricing arrays can be passed for accurate cost calculation, matching `invoke()` behavior.
- **`converse()` and `stream()` now calculate and return cost** — Both methods compute cost from token counts and include a `cost` key in the result array (previously always `$0`).
- **`ConversationBuilder::send()` routes through `BedrockManager::converse()`** — Applies cost limits, event dispatching, logging, and cost calculation. Previously bypassed all manager guardrails by calling `ConverseClient` directly.
- **`ConversationBuilder::sendStream()` routes through `BedrockManager::converseStream()`** — Same guardrail fix for streaming.
- **`ConversationBuilder::withPricing()` now flows through to cost calculation** — Pricing is passed to the manager for accurate cost tracking (previously only used by `estimate()`).
- **Facade PHPDoc annotations updated** — `invoke()`, `converse()`, `stream()` now include `?string $connection` and `?array $pricing` parameters. New `converseStream()` annotation added.
- `config/bedrock.php` `defaults` section now includes `'image_model' => env('BEDROCK_DEFAULT_IMAGE_MODEL', '')`.
- `config/bedrock.php` now has full `providers` section with global, chat, and image disabled provider lists.

### Fixed

- **ConversationBuilder bypassed Manager guardrails** (BUGS2 #1, High) — `send()` and `sendStream()` called client classes directly, skipping cost limits, cost tracking, event dispatching, and invocation logging.
- **`converse()` / `stream()` always tracked $0 cost** (BUGS2 #2, High) — `ConverseClient` and `StreamingClient` did not return a `cost` key; `BedrockManager` now calculates cost from token counts.
- **`syncModels()` threw raw SQL exception on missing migration** (BUGS2 #4) — Now checks `Schema::hasTable()` first with a clear error message.
- **`ModelsCommand` ignored `disabled_providers` config** (BUGS2 #7) — Output now respects the configured disabled providers filter.
- **`ConverseClient` / `StreamingClient` silently swallowed key rotation and rate limit events** (BUGS2 #8) — Both now override `onKeyRotated()` and `onRateLimitExhausted()` to dispatch `BedrockKeyRotated` and `BedrockRateLimited` events.
- **Duplicate `writeEnv()` in two commands** (BUGS2 #6) — Extracted to shared `WritesEnvFile` trait.
- **Bearer token model listing 403** — `BedrockClient::listModels()` now catches 403 errors from bearer tokens (management-plane access restricted) and returns an empty list instead of throwing.
- **`config:clear` after `bedrock:configure`** — Wizard now automatically clears the config cache so updated `.env` values take effect immediately.

---

## [0.0.6] - 2026-04-06

### Fixed

- **`TestCommand` ParseError** — Removed duplicate class body that was appended at line 255, causing a `syntax error, unexpected token "protected"` on Laravel's `package:discover` post-autoload hook.

---

## [0.0.5] - 2026-04-07

### Added

- **`BEDROCK_DEFAULT_MODEL` env variable** — Add `BEDROCK_DEFAULT_MODEL=<model-id>` to your `.env` to configure a default model used whenever no explicit model ID is passed to `invoke()`.
- **`bedrock:model` command** — Interactive terminal command to browse providers and models (using the same two-step picker as `bedrock:test`) and write the selected model ID to `BEDROCK_DEFAULT_MODEL` in your `.env`. Supports `--show` (display current default), `--reset` (clear default), and `--connection=` (filter by connection).
- **`BedrockManager::defaultModel()`** — Returns the configured default model from `config('bedrock.defaults.model')`.
- **`invoke()` default model fallback** — `BedrockManager::invoke()` now accepts an empty `$modelId` and automatically falls back to the configured default; throws a `ConfigurationException` with a helpful message if neither is set.

### Changed

- `config/bedrock.php` `defaults` section now includes `'model' => env('BEDROCK_DEFAULT_MODEL', '')`.
- `Facades/Bedrock.php` updated with `@method static string defaultModel()` annotation.

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
