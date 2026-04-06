<?php

namespace Ubxty\BedrockAi;

use Ubxty\BedrockAi\Client\BedrockClient;
use Ubxty\BedrockAi\Client\ConverseClient;
use Ubxty\BedrockAi\Client\CredentialManager;
use Ubxty\BedrockAi\Client\ModelAliasResolver;
use Ubxty\BedrockAi\Client\StreamingClient;
use Ubxty\BedrockAi\Conversation\ConversationBuilder;
use Ubxty\BedrockAi\Events\BedrockInvoked;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;
use Ubxty\BedrockAi\Exceptions\CostLimitExceededException;
use Illuminate\Support\Facades\Cache;
use Ubxty\BedrockAi\Logging\InvocationLogger;
use Ubxty\BedrockAi\Pricing\PricingService;
use Ubxty\BedrockAi\Usage\UsageTracker;

class BedrockManager
{
    protected array $config;

    /** @var array<string, BedrockClient> */
    protected array $clients = [];

    protected ?PricingService $pricing = null;

    protected ?UsageTracker $usage = null;

    protected ?ModelAliasResolver $aliasResolver = null;

    protected ?InvocationLogger $logger = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

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
            $this->config['defaults']['anthropic_version'] ?? 'bedrock-2023-05-31'
        );

        $client->setModelsCacheTtl($this->config['cache']['models_ttl'] ?? 3600);

        $this->clients[$connection] = $client;

        return $client;
    }

    /**
     * Invoke a model via the default connection.
     * Supports model aliases, fires events, and logs invocations.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string}
     */
    public function invoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?array $pricing = null
    ): array {
        $this->checkCostLimits();

        $modelId = $this->resolveAlias($modelId);

        $result = $this->client()->invoke($modelId, $systemPrompt, $userMessage, $maxTokens, $temperature, $pricing);

        $this->trackCost($result['cost'] ?? 0);
        $this->fireInvokedEvent($result);
        $this->getLogger()->log($result);

        return $result;
    }

    /**
     * Get a Converse API client for the given connection.
     */
    public function converseClient(?string $connection = null): ConverseClient
    {
        $connection ??= $this->config['default'] ?? 'default';
        $connectionConfig = $this->config['connections'][$connection] ?? null;

        if (! $connectionConfig) {
            throw new ConfigurationException("Bedrock connection [{$connection}] is not configured.");
        }

        $keys = $connectionConfig['keys'] ?? [];
        $retryConfig = $this->config['retry'] ?? [];

        return new ConverseClient(
            new CredentialManager($keys),
            $retryConfig['max_retries'] ?? 3,
            $retryConfig['base_delay'] ?? 2
        );
    }

    /**
     * Send a conversation via the Converse API.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function converse(
        string $modelId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
    ): array {
        $modelId = $this->resolveAlias($modelId);

        return $this->converseClient()->converse($modelId, $messages, $systemPrompt, $maxTokens, $temperature);
    }

    /**
     * Get a streaming client for the given connection.
     */
    public function streamingClient(?string $connection = null): StreamingClient
    {
        $connection ??= $this->config['default'] ?? 'default';
        $connectionConfig = $this->config['connections'][$connection] ?? null;

        if (! $connectionConfig) {
            throw new ConfigurationException("Bedrock connection [{$connection}] is not configured.");
        }

        $keys = $connectionConfig['keys'] ?? [];
        $retryConfig = $this->config['retry'] ?? [];

        return new StreamingClient(
            new CredentialManager($keys),
            $retryConfig['max_retries'] ?? 3,
            $retryConfig['base_delay'] ?? 2,
            $this->config['defaults']['anthropic_version'] ?? 'bedrock-2023-05-31'
        );
    }

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
    ): array {
        $modelId = $this->resolveAlias($modelId);

        return $this->streamingClient()->stream($modelId, $systemPrompt, $userMessage, $onChunk, $maxTokens, $temperature);
    }

    /**
     * Start a fluent conversation builder.
     */
    public function conversation(string $modelId): ConversationBuilder
    {
        $modelId = $this->resolveAlias($modelId);

        return new ConversationBuilder($this, $modelId);
    }

    /**
     * Test connection on the default (or given) connection.
     */
    public function testConnection(?string $connection = null): array
    {
        return $this->client($connection)->testConnection();
    }

    /**
     * List available foundation models.
     */
    public function listModels(?string $connection = null): array
    {
        return $this->client($connection)->listModels();
    }

    /**
     * Fetch models with normalized structure.
     */
    public function fetchModels(?string $connection = null): array
    {
        return $this->client($connection)->fetchModels();
    }

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
     * Get the model alias resolver.
     */
    public function aliases(): ModelAliasResolver
    {
        if (! $this->aliasResolver) {
            $this->aliasResolver = new ModelAliasResolver($this->config['aliases'] ?? []);
        }

        return $this->aliasResolver;
    }

    /**
     * Resolve a model alias to its full model ID.
     */
    public function resolveAlias(string $modelIdOrAlias): string
    {
        return $this->aliases()->resolve($modelIdOrAlias);
    }

    /**
     * Get the invocation logger.
     */
    public function getLogger(): InvocationLogger
    {
        if (! $this->logger) {
            $loggingConfig = $this->config['logging'] ?? [];
            $this->logger = new InvocationLogger(
                $loggingConfig['enabled'] ?? false,
                $loggingConfig['channel'] ?? 'stack'
            );
        }

        return $this->logger;
    }

    /**
     * Get the full configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Check if the default connection is configured.
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

        $firstKey = $keys[0];
        $authMode = $firstKey['auth_mode'] ?? 'iam';

        if ($authMode === 'bearer') {
            return ! empty($firstKey['bearer_token']);
        }

        return ! empty($firstKey['aws_key']) && ! empty($firstKey['aws_secret']);
    }

    /**
     * Get the first key from the default connection (used as fallback for pricing/usage).
     */
    protected function getDefaultKey(): array
    {
        $connection = $this->config['default'] ?? 'default';
        $keys = $this->config['connections'][$connection]['keys'] ?? [];

        return $keys[0] ?? [];
    }

    /**
     * Check cost limits before making an invocation.
     */
    protected function checkCostLimits(): void
    {
        $dailyLimit = $this->config['limits']['daily'] ?? null;
        $monthlyLimit = $this->config['limits']['monthly'] ?? null;

        if ($dailyLimit !== null) {
            $dailyCost = (float) Cache::get('bedrock_ai_daily_cost_' . date('Y-m-d'), 0);

            if ($dailyCost >= (float) $dailyLimit) {
                throw new CostLimitExceededException('daily', (float) $dailyLimit, $dailyCost);
            }
        }

        if ($monthlyLimit !== null) {
            $monthlyCost = (float) Cache::get('bedrock_ai_monthly_cost_' . date('Y-m'), 0);

            if ($monthlyCost >= (float) $monthlyLimit) {
                throw new CostLimitExceededException('monthly', (float) $monthlyLimit, $monthlyCost);
            }
        }
    }

    /**
     * Track cost after a successful invocation.
     */
    protected function trackCost(float $cost): void
    {
        if ($cost <= 0) {
            return;
        }

        $dailyKey = 'bedrock_ai_daily_cost_' . date('Y-m-d');
        $monthlyKey = 'bedrock_ai_monthly_cost_' . date('Y-m');

        Cache::put($dailyKey, (float) Cache::get($dailyKey, 0) + $cost, now()->endOfDay());
        Cache::put($monthlyKey, (float) Cache::get($monthlyKey, 0) + $cost, now()->endOfMonth());
    }

    /**
     * Fire a BedrockInvoked event if the event dispatcher is available.
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
}
