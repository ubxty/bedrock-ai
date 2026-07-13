<?php

namespace Ubxty\BedrockAi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Ubxty\BedrockAi\Billing\CostExplorerService;
use Ubxty\BedrockAi\Client\BedrockClient;
use Ubxty\BedrockAi\Client\ConverseClient;
use Ubxty\BedrockAi\Client\CredentialManager;
use Ubxty\BedrockAi\Client\StreamingClient;
use Ubxty\BedrockAi\Events\BedrockInvoked;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;
use Ubxty\BedrockAi\Pricing\PricingService;
use Ubxty\BedrockAi\Usage\UsageTracker;
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
            $this->config['defaults']['anthropic_version'] ?? 'bedrock-2023-05-31'
        );

        $client->setModelsCacheTtl($this->config['cache']['models_ttl'] ?? 3600);

        $this->clients[$connection] = $client;

        return $client;
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

        $result = $this->streamingClient($connection)->stream($modelId, $systemPrompt, $userMessage, $onChunk, $maxTokens, $temperature);

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
        ?string $connection
    ): array {
        return $this->converseClient($connection)->converse($modelId, $messages, $systemPrompt, $maxTokens, $temperature);
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
        ?string $connection
    ): array {
        return $this->streamingClient($connection)->converseStream($modelId, $messages, $onChunk, $systemPrompt, $maxTokens, $temperature);
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

    /**
     * Sync models from AWS Bedrock into the bedrock_models table.
     */
    public function syncModels(?string $connection = null): int
    {
        $connection ??= $this->config['default'] ?? 'default';
        $models = $this->fetchModels($connection);
        $now = now();

        if (! Schema::hasTable('bedrock_models')) {
            throw new BedrockException(
                'The bedrock_models table does not exist. Run: php artisan migrate'
            );
        }

        foreach ($models as $model) {
            DB::table('bedrock_models')->upsert(
                [
                    'model_id'         => $model['model_id'],
                    'name'             => $model['name'],
                    'provider'         => $model['provider'],
                    'connection'       => $connection,
                    'context_window'   => $model['context_window'],
                    'max_tokens'       => $model['max_tokens'],
                    'capabilities'     => json_encode($model['capabilities']),
                    'input_modalities' => json_encode($model['input_modalities'] ?? ['text']),
                    'is_active'        => $model['is_active'] ? 1 : 0,
                    'lifecycle_status' => $model['is_active'] ? 'ACTIVE' : 'LEGACY',
                    'synced_at'        => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ],
                ['model_id'],
                ['name', 'provider', 'connection', 'context_window', 'max_tokens', 'capabilities', 'input_modalities', 'is_active', 'lifecycle_status', 'synced_at', 'updated_at']
            );
        }

        return count($models);
    }

    /**
     * Read persisted bedrock_models rows into the normalised shape
     * AbstractAiManager::getModelsGrouped() expects. Empty return
     * signals parent to fall back to a live fetchModels() call.
     */
    protected function fetchModelsForGrouping(?string $connection): array
    {
        try {
            $rows = DB::table('bedrock_models')
                ->when($connection, fn ($q) => $q->where('connection', $connection))
                ->orderBy('provider')
                ->orderBy('name')
                ->get()
                ->map(fn ($row) => [
                    'model_id'         => $row->model_id,
                    'name'             => $row->name,
                    'provider'         => $row->provider,
                    'context_window'   => $row->context_window,
                    'max_tokens'       => $row->max_tokens,
                    'capabilities'     => json_decode($row->capabilities, true) ?? [],
                    'input_modalities' => json_decode($row->input_modalities ?? 'null', true) ?? ['text'],
                    'is_active'        => (bool) $row->is_active,
                ])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('bedrock-ai: failed to read bedrock_models table, falling back to live fetch', [
                'exception' => $e->getMessage(),
            ]);
            $rows = [];
        }

        return $rows;
    }

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
        return true;
    }

    public function getCredentialInfo(?string $connection = null): array
    {
        return $this->client($connection)->getCredentialManager()->list();
    }

    public function platformName(): string
    {
        return 'AWS Bedrock';
    }

    // ─────────────────────────────────────────────────────────
    //  Template-method overrides (BC + platform-specific)
    // ─────────────────────────────────────────────────────────

    /**
     * Cache key prefix is locked to "bedrock_ai" so that in-flight
     * daily/monthly cost counters and the cost-lock key survive
     * the migration to AbstractAiManager.
     */
    protected function cachePrefix(): string
    {
        return 'bedrock_ai';
    }

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
}
