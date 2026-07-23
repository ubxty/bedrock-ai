<?php

namespace Ubxty\BedrockAi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Ubxty\BedrockAi\Billing\CostExplorerService;
use Ubxty\BedrockAi\Client\BedrockClient;
use Ubxty\BedrockAi\Client\CredentialManager;
use Ubxty\BedrockAi\Events\BedrockInvoked;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Pricing\PricingService;
use Ubxty\BedrockAi\Usage\UsageTracker;
use Ubxty\CoreAi\Exceptions\ConfigurationException;
use Ubxty\CoreAi\Manager\AbstractAiManager;

class BedrockManager extends AbstractAiManager
{
    /** @var array<string, BedrockClient> */
    protected array $clients = [];

    protected ?PricingService $pricing = null;

    protected ?UsageTracker $usage = null;

    protected ?CostExplorerService $billing = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    // ─────────────────────────────────────────────────────────
    //  Lazy client factories (bedrock-specific accessors)
    // ─────────────────────────────────────────────────────────

    /**
     * Get the Bedrock client for the given (or default) connection.
     */
    public function client(?string $connection = null): BedrockClient
    {
        $connection ??= $this->config['default'] ?? 'default';

        if (isset($this->clients[$connection])) {
            return $this->clients[$connection];
        }

        $connectionConfig = $this->config['connections'][$connection] ?? null;

        if (! $connectionConfig) {
            throw new ConfigurationException("Bedrock connection [{$connection}] is not configured.");
        }

        $keys = $connectionConfig['keys'] ?? [];

        if (empty($keys)) {
            throw new ConfigurationException("No AWS keys configured for connection [{$connection}].");
        }

        $retryConfig = $this->config['retry'] ?? [];

        $client = new BedrockClient(
            new CredentialManager($keys),
            $retryConfig['max_retries'] ?? 3,
            $retryConfig['base_delay'] ?? 2,
            $this->config['cache']['models_ttl'] ?? 3600,
        );

        $client->setModelsCacheTtl($this->config['cache']['models_ttl'] ?? 3600);
        $client->setPromptCachePoints($this->promptCachePoints());
        $client->setPromptCachePointType($this->promptCachePointType());
        $client->setPromptCacheSupportedModels($this->cacheSupportedModels());

        $this->clients[$connection] = $client;

        return $client;
    }

    /**
     * Get a Converse API client for the given connection.
     *
     * v2.2: ConverseClient and StreamingClient have been merged into
     * BedrockClient (which extends core-ai's Standards\Converse\ConverseClient).
     * Both `converseClient()` and `streamingClient()` return the same client
     * — BC surface preserved for callers using either factory.
     */
    public function converseClient(?string $connection = null): BedrockClient
    {
        return $this->client($connection);
    }

    /**
     * Get a streaming client for the given connection.
     *
     * v2.2: now an alias for `client()` — BedrockClient implements
     * converseStream() directly (via inheritance from
     * core-ai/Standards/Converse/ConverseClient). BC surface preserved.
     */
    public function streamingClient(?string $connection = null): BedrockClient
    {
        return $this->client($connection);
    }

    /**
     * Build a one-off BedrockClient with the package's cachePoint config
     * overridden for a single call. Used by `performConverse()` when a
     * ConversationBuilder has set a per-session cache-points override.
     */
    protected function converseClientWithCacheOverride(?string $connection, array $cachePointsOverride): BedrockClient
    {
        $client = $this->client($connection);
        $client->setPromptCachePoints($cachePointsOverride);

        return $client;
    }

    /**
     * Build a one-off BedrockClient with the package's cachePoint config
     * overridden for a single call. Used by `performConverseStream()` when
     * a ConversationBuilder has set a per-session cache-points override.
     */
    protected function streamingClientWithCacheOverride(?string $connection, array $cachePointsOverride): BedrockClient
    {
        $client = $this->client($connection);
        $client->setPromptCachePoints($cachePointsOverride);

        return $client;
    }

    // ─────────────────────────────────────────────────────────
    //  bedrock-only public stream() — raw streaming with chat-style
    //  message order. Distinct from parent's converseStream() which
    //  is the multi-turn templated stream.
    // ─────────────────────────────────────────────────────────

    /**
     * Stream a model invocation with a callback for each chunk.
     *
     * @param callable(string $chunk, array $metadata): void $onChunk
     */
    public function stream(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        callable $onChunk,
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $connection = null,
        ?array $pricing = null
    ): array {
        $this->checkCostLimits();

        $modelId = $this->resolveAlias($modelId);

        $result = $this->client($connection)->converseStreamWithModel(
            $modelId,
            [['role' => 'user', 'content' => $userMessage]],
            $onChunk,
            $systemPrompt,
            $maxTokens,
            $temperature,
        );

        $cost = $this->calculateCost($result['input_tokens'] ?? 0, $result['output_tokens'] ?? 0, $pricing);
        $result['cost'] = $cost;

        $this->trackCost($cost);
        $this->fireInvokedEvent($result);
        $this->getLogger()->log($result);

        return $result;
    }

    // ─────────────────────────────────────────────────────────
    //  AbstractAiManager: protected perform* hooks
    // ─────────────────────────────────────────────────────────

    /**
     * Bedrock Invoke API path. Parent's invoke() already checks cost
     * limits, resolves aliases, calls performInvoke, tracks cost,
     * fires the event, and logs the result.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    protected function performInvoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        ?array $pricing,
        ?string $connection
    ): array {
        return $this->client($connection)->invoke($modelId, $systemPrompt, $userMessage, $maxTokens, $temperature, $pricing);
    }

    /**
     * Bedrock Converse API path with multi-turn messages.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string}
     */
    protected function performConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride = null
    ): array {
        $client = $cachePointsOverride !== null
            ? $this->converseClientWithCacheOverride($connection, $cachePointsOverride)
            : $this->converseClient($connection);

        return $client->converseWithModel($modelId, $messages, $systemPrompt, $maxTokens, $temperature);
    }

    /**
     * Whether the resolved model_id is in the cache-capable allowlist, so
     * cachePoint markers would actually be injected for it. Mirrors the
     * gating inside {@see HasRetryLogic::applyCachePoints()}. Public so
     * interactive commands (`bedrock:chat`, `bedrock:models`, …) can
     * annotate the model picker.
     */
    public function modelSupportsCaching(string $modelId): bool
    {
        return $this->converseClient()->modelSupportsCaching($modelId);
    }

    /**
     * Whether the package config has at least one cachePoint anchor
     * configured (`core-ai.bedrock.prompt_caching.points` non-empty).
     * Used by the chat command to decide whether to ask the user about
     * cached vs standard mode.
     */
    public function packageCachePointsConfigured(): bool
    {
        return ! empty($this->promptCachePoints());
    }

    /**
     * Return the configured cachePoint anchors verbatim. Used by the chat
     * command to force-enable caching when the user picks cached mode and
     * the package config has anchors.
     *
     * @return string[]
     */
    public function configuredCachePoints(): array
    {
        return $this->promptCachePoints();
    }

    /**
     * Bedrock Converse streaming path.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  callable(string $chunk): void  $onChunk
     */
    protected function performConverseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $connection,
        ?array $cachePointsOverride = null
    ): array {
        $client = $cachePointsOverride !== null
            ? $this->streamingClientWithCacheOverride($connection, $cachePointsOverride)
            : $this->streamingClient($connection);

        return $client->converseStreamWithModel($modelId, $messages, $onChunk, $systemPrompt, $maxTokens, $temperature);
    }

    // ─────────────────────────────────────────────────────────
    //  AbstractAiManager: public platform methods
    // ─────────────────────────────────────────────────────────

    public function testConnection(?string $connection = null): array
    {
        return $this->client($connection)->testConnection();
    }

    public function listModels(?string $connection = null): array
    {
        return $this->client($connection)->listModels();
    }

    public function fetchModels(?string $connection = null): array
    {
        return $this->client($connection)->fetchModels();
    }

    // ─────────────────────────────────────────────────────────
    //  syncModels() / fetchModelsForGrouping() / getConfiguredModels()
    //  are inherited from AbstractAiManager since the v2.2 platform
    //  refactor. Bedrock keeps its own isConfigured() override below
    //  (IAM-vs-bearer branching + ctor try/catch) and supportsStreaming()
    //  override (IAM bearer mode returns false).
    // ─────────────────────────────────────────────────────────

    /**
     * Configuration check delegates normalization to CredentialManager
     * to handle bearer mode auto-detection.
     */
    public function isConfigured(?string $connection = null): bool
    {
        $connection ??= $this->config['default'] ?? 'default';
        $connectionConfig = $this->config['connections'][$connection] ?? null;

        if (! $connectionConfig) {
            return false;
        }

        $keys = $connectionConfig['keys'] ?? [];

        if (empty($keys)) {
            return false;
        }

        try {
            $cm = new CredentialManager($keys);
        } catch (\Exception $e) {
            return false;
        }

        $key = $cm->current();

        return $key['auth_mode'] === 'bearer'
            ? ! empty($key['bearer_token'])
            : ! empty($key['aws_key']) && ! empty($key['aws_secret']);
    }

    public function supportsStreaming(?string $connection = null): bool
    {
        // Bedrock streaming is IAM-only. Bearer mode throws ConfigurationException
        // at runtime — surface that honestly here so callers can switch mode.
        if ($this->client($connection)->getCredentialManager()->isBearerMode()) {
            return false;
        }
        return true;
    }

    public function platformName(): string
    {
        return 'AWS Bedrock';
    }

    protected function providerDefault(): string
    {
        return 'Other';
    }

    // ─────────────────────────────────────────────────────────
    //  Template-method overrides (BC + platform-specific)
    // ─────────────────────────────────────────────────────────

    /**
     * Fire a BedrockInvoked event instead of the parent's AiInvoked,
     * preserving listener BC.
     */
    protected function fireInvokedEvent(array $result): void
    {
        if (function_exists('event')) {
            event(new BedrockInvoked(
                modelId: $result['model_id'] ?? 'unknown',
                inputTokens: $result['input_tokens'] ?? 0,
                outputTokens: $result['output_tokens'] ?? 0,
                cost: $result['cost'] ?? 0,
                latencyMs: $result['latency_ms'] ?? 0,
                keyUsed: $result['key_used'] ?? 'unknown',
            ));
        }
    }

    /**
     * Bedrock cost calc accepts a permissive shape — same defaults
     * as the parent, retained here for explicit overridability.
     */
    protected function calculateCost(int $inputTokens, int $outputTokens, ?array $pricing = null): float
    {
        $inputPrice = $pricing['input_price_per_1k'] ?? 0.003;
        $outputPrice = $pricing['output_price_per_1k'] ?? 0.015;

        return round(
            ($inputTokens / 1000) * $inputPrice + ($outputTokens / 1000) * $outputPrice,
            6
        );
    }

    // ─────────────────────────────────────────────────────────
    //  Bedrock-specific public accessors
    // ─────────────────────────────────────────────────────────

    /**
     * Get the Pricing service.
     */
    public function pricing(): PricingService
    {
        if (! $this->pricing) {
            $pricingConfig = $this->config['pricing'] ?? [];
            $fallbackKey = $this->getDefaultKey();

            $this->pricing = new PricingService(
                $pricingConfig['aws_key'] ?: ($fallbackKey['aws_key'] ?? ''),
                $pricingConfig['aws_secret'] ?: ($fallbackKey['aws_secret'] ?? ''),
                $this->config['cache']['pricing_ttl'] ?? 86400
            );
        }

        return $this->pricing;
    }

    /**
     * Get the Billing / Cost Explorer service.
     */
    public function billing(): CostExplorerService
    {
        if (! $this->billing) {
            $billingConfig = $this->config['billing'] ?? [];
            $fallbackKey = $this->getDefaultKey();

            $this->billing = new CostExplorerService(
                $billingConfig['aws_key'] ?: ($fallbackKey['aws_key'] ?? ''),
                $billingConfig['aws_secret'] ?: ($fallbackKey['aws_secret'] ?? ''),
                $billingConfig['region'] ?: ($fallbackKey['region'] ?? 'us-east-1'),
                $this->config['cache']['billing_ttl'] ?? 3600
            );
        }

        return $this->billing;
    }

    /**
     * Get the Usage tracker.
     */
    public function usage(): UsageTracker
    {
        if (! $this->usage) {
            $usageConfig = $this->config['usage'] ?? [];
            $fallbackKey = $this->getDefaultKey();

            $this->usage = new UsageTracker(
                $usageConfig['aws_key'] ?: ($fallbackKey['aws_key'] ?? ''),
                $usageConfig['aws_secret'] ?: ($fallbackKey['aws_secret'] ?? ''),
                $usageConfig['region'] ?: ($fallbackKey['region'] ?? 'us-east-1'),
                $this->config['cache']['usage_ttl'] ?? 900
            );
        }

        return $this->usage;
    }

    /**
     * Check if the default (or given) connection uses Bearer token authentication.
     */
    public function isBearerMode(?string $connection = null): bool
    {
        return $this->client($connection)->getCredentialManager()->isBearerMode();
    }

    // ─────────────────────────────────────────────────────────
    //  v2.1.0 — prompt-cache config + embeddings
    // ─────────────────────────────────────────────────────────

    /**
     * Read the configured prompt-cache checkpoint anchors
     * (`core-ai.bedrock.prompt_caching.points`).
     *
     * Returns the raw configured list — filtering against the allowed set
     * lives in {@see HasRetryLogic::setPromptCachePoints()} so adding a new
     * strategy only requires touching the trait, not this method.
     *
     * @return string[]
     */
    protected function promptCachePoints(): array
    {
        $configured = $this->config['prompt_caching']['points'] ?? [];

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_map('strval', $configured));
    }

    /**
     * Read the configured cache-capable model allowlist
     * (`core-ai.bedrock.prompt_caching.supported_models`).
     *
     * Glob patterns are matched against the resolved model_id (with the
     * `us.|eu.|apac.|ca.` cross-region prefix stripped) inside
     * {@see HasRetryLogic::supportsCaching()}. Empty list = no allowlist
     * configured = every model is eligible for cachePoint markers (the
     * pre-v2.1.4 behaviour, kept for opt-in rollouts).
     *
     * @return string[]
     */
    protected function cacheSupportedModels(): array
    {
        $configured = $this->config['prompt_caching']['supported_models'] ?? [];

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_map('strval', $configured));
    }

    /**
     * Resolve the cachePoint `type` field for Converse-API requests.
     * Reads `core-ai.bedrock.prompt_caching.ttl_seconds` and maps it to
     * the documented Bedrock values:
     *
     *   - 0 or absent  → 'default' (Bedrock's default TTL, typically 5m)
     *   - 0 < ttl < 5m → '5m'  (5-minute cache-point type)
     *   - ttl >= 5m     → '1h'  (1-hour cache-point type)
     *
     * The AWS Bedrock Converse API only accepts these three literal
     * strings. Any other value (including the previously buggy
     * `> 0 → '1h'` shortcut) was either silently coerced or rejected
     * by the upstream SDK. A `BEDROCK_PROMPT_CACHE_TTL=300` (the
     * upstream default) used to be silently turned into a 1h cache
     * point — pricing and invalidation semantics diverged from docs.
     *
     * The 5m boundary is taken from the AWS docs (5 minutes is the
     * only shorter window they expose). Anything under 5m rounds up
     * to the 5m type because the API has no finer granularity.
     */
    protected function promptCachePointType(): string
    {
        $defaultModel = (string) ($this->config['defaults']['model'] ?? '');

        return $this->resolveCachePointType($defaultModel);
    }

    /**
     * Resolve the cachePoint `type` for a given model. Reads
     * `core-ai.bedrock.prompt_caching.ttl_seconds` and maps it to the
     * documented Bedrock values:
     *
     *   - 0 or absent  → 'default' (Bedrock's default TTL, typically 5m)
     *   - 0 < ttl < 5m → '5m'  (5-minute cache-point type)
     *   - ttl >= 5m     → '1h'  (1-hour cache-point type)
     *
     * The AWS Bedrock Converse API only accepts these three literal
     * strings. Any other value (including the previously buggy
     * `> 0 → '1h'` shortcut) was either silently coerced or rejected
     * by the upstream SDK. A `BEDROCK_PROMPT_CACHE_TTL=300` (the
     * upstream default) used to be silently turned into a 1h cache
     * point — pricing and invalidation semantics diverged from docs.
     *
     * Not every model accepts '5m' or '1h' — the Converse API returns
     * 400 for Amazon Nova and Claude 3 / 3.5 Haiku when given anything
     * other than 'default'. We only allow the longer TTL types for
     * models documented to support them, falling back to 'default'
     * otherwise.
     *
     * The 5m boundary is taken from the AWS docs (5 minutes is the
     * only shorter window they expose). Anything under 5m rounds up
     * to the 5m type because the API has no finer granularity.
     */
    protected function resolveCachePointType(string $modelId): string
    {
        $ttlSeconds = (int) ($this->config['prompt_caching']['ttl_seconds']
            ?? (int) (env('BEDROCK_PROMPT_CACHE_TTL') ?: 0));

        $desired = match (true) {
            $ttlSeconds <= 0                => 'default',
            $ttlSeconds < 5 * 60            => '5m',
            default                         => '1h',
        };

        if ($desired === 'default') {
            return 'default';
        }

        // Conservative allowlist of models that accept '5m' / '1h'
        // cachePoint types per current AWS docs. Unknown models fall
        // back to 'default' so a misconfigured TTL can't 400 the call.
        $supportsExtended = str_contains($modelId, 'claude-3-5-sonnet')
            || str_contains($modelId, 'claude-3-opus')
            || str_contains($modelId, 'claude-sonnet-4')
            || str_contains($modelId, 'claude-opus-4')
            || str_contains($modelId, 'claude-haiku-4');

        return $supportsExtended ? $desired : 'default';
    }

    /**
     * Generate embeddings for a batch of texts using the Bedrock InvokeModel
     * action (Titan / Cohere Embed). Cached per (modelId, dimensions, content hash)
     * for `core-ai.cache.embedding_ttl` seconds (default 7 days).
     *
     * @param  string[]  $texts
     * @param  int  $dimensions  Optional vector size. Pass `null` for the model's native output.
     * @return array<int, float[]>
     */
    public function embed(
        string $modelId,
        array $texts,
        ?int $dimensions = null,
        ?string $connection = null,
    ): array {
        $modelId = $this->resolveAlias($modelId);

        $ttl = (int) ($this->config['cache']['embedding_ttl']
            ?? config('core-ai.cache.embedding_ttl', 604800));

        $cm = new CredentialManager(
            $this->config['connections'][$connection ?? $this->config['default'] ?? 'default']['keys'] ?? []
        );

        $isBearer = $cm->isBearerMode();
        $region = $cm->current()['region'] ?? 'us-east-1';

        $results = [];
        $pending = [];

        foreach ($texts as $i => $text) {
            $hash = hash('sha256', $modelId.'|'.((string) $dimensions).'|'.$text);
            $cacheKey = "bedrock_ai_embeddings_{$hash}";
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $results[$i] = $cached;
            } else {
                $pending[$i] = $text;
            }
        }

        foreach ($pending as $i => $text) {
            $body = ['inputText' => $text];
            if ($dimensions !== null) {
                $body['dimensions'] = $dimensions;
                $body['normalize'] = true;
            }

            if ($isBearer) {
                $token = $cm->getBearerToken();
                $url = "https://bedrock-runtime.{$region}.amazonaws.com/model/{$modelId}/invoke";
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post($url, $body);
                if (! $response->successful()) {
                    throw new BedrockException("Bedrock embed HTTP {$response->status()}: ".$response->body(), $response->status());
                }
                $vec = $response->json('embedding') ?? [];
            } else {
                $sdk = new \Aws\BedrockRuntime\BedrockRuntimeClient([
                    'version' => 'latest',
                    'region' => $region,
                    'credentials' => [
                        'key' => $cm->current()['aws_key'],
                        'secret' => $cm->current()['aws_secret'],
                    ],
                ]);
                $resp = $sdk->invokeModel([
                    'modelId' => $modelId,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode($body),
                ]);
                $payload = json_decode((string) $resp['body'], true) ?: [];
                $vec = $payload['embedding'] ?? [];
            }

            if (! is_array($vec) || empty($vec)) {
                throw new BedrockException("Bedrock embed returned no vector for text index {$i}");
            }

            $hash = hash('sha256', $modelId.'|'.((string) $dimensions).'|'.$text);
            Cache::put("bedrock_ai_embeddings_{$hash}", $vec, $ttl);
            $results[$i] = $vec;
        }

        ksort($results);

        return array_values($results);
    }
}
