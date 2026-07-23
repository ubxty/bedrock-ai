<?php

namespace Ubxty\BedrockAi\Client;

use Aws\Bedrock\BedrockClient as AwsBedrockClient;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ubxty\BedrockAi\Events\BedrockKeyRotated;
use Ubxty\BedrockAi\Events\BedrockRateLimited;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\CoreAi\Client\AbstractCredentialManager;
use Ubxty\CoreAi\Exceptions\ConfigurationException;
use Ubxty\CoreAi\Exceptions\RateLimitException;
use Ubxty\CoreAi\Models\ModelSpecResolver;
use Ubxty\CoreAi\Standards\Converse\ConverseClient;

/**
 * AWS Bedrock platform client.
 *
 * Extends core-ai's {@see ConverseClient} to inherit the AWS Bedrock
 * Converse wire format, prompt-cachePoint injection, key-rotation retry,
 * and SDK-vs-HTTP-Bearer branching. Adds:
 *
 *   - Legacy v2.1.x `invoke()` / `converse()` envelopes (the BC surface
 *     used by BedrockManager until it adopts the v2.2 platform hook).
 *   - Model listing (SDK + HTTP Bearer paths).
 *   - User-friendly error mapping for AWS exception shapes.
 *
 * The $anthropicVersion constructor arg from v2.1.x was deleted — it was
 * never serialized to any request (verified 2026-07-17 in
 * `StreamingClient.php:23` and the old `BedrockClient.php` constructor).
 */
class BedrockClient extends ConverseClient
{
    public function __construct(
        AbstractCredentialManager $credentials,
        int $maxRetries = 3,
        int $baseDelay = 2,
        int $modelsCacheTtl = 3600,
        ?BedrockRuntimeClient $sdkClient = null,
    ) {
        parent::__construct($credentials, $maxRetries, $baseDelay, $modelsCacheTtl, $sdkClient);
    }

    // ─────────────────────────────────────────────────────────
    //  Legacy v2.1.x envelope — BC surface for BedrockManager
    // ─────────────────────────────────────────────────────────

    /**
     * Legacy v2.1.x invoke path. Wraps a single-user-message converse call
     * and computes the cost (manager callers pass `$pricing`; the converse
     * envelope does not).
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cache_read_input_tokens: int, cache_write_input_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    public function invoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?array $pricing = null
    ): array {
        $start = microtime(true);

        $result = $this->converse(
            $modelId,
            [['role' => 'user', 'content' => $userMessage]],
            $systemPrompt,
            $maxTokens,
            $temperature,
        );

        $cost = $this->calculateCost($result['input_tokens'], $result['output_tokens'], $pricing);

        return [
            'response' => $result['response'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'cache_read_input_tokens' => $result['cache_read_input_tokens'] ?? 0,
            'cache_write_input_tokens' => $result['cache_write_input_tokens'] ?? 0,
            'cost' => $cost,
            'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            'status' => 'success',
            'key_used' => $result['key_used'],
            'model_id' => $result['model_id'],
        ];
    }

    /**
     * Legacy v2.1.x converse envelope. Threads modelId into parent
     * AbstractLLMClient::converse() via the `currentModelId` slot.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cache_read_input_tokens: int, cache_write_input_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string}
     */
    public function converse(
        string $modelId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $idempotencyKey = null,
    ): array {
        $start = microtime(true);
        $this->currentModelId = $modelId;
        $keyLabel = $this->credentials->current()['label'] ?? 'Primary';

        try {
            $result = parent::converse(
                messages: $messages,
                systemPrompt: $systemPrompt,
                maxTokens: $maxTokens,
                temperature: $temperature,
                idempotencyKey: $idempotencyKey,
            );
        } finally {
            $this->currentModelId = null;
        }

        return [
            'response' => $result->text,
            'input_tokens' => $result->usage?->inputTokens ?? 0,
            'output_tokens' => $result->usage?->outputTokens ?? 0,
            'total_tokens' => ($result->usage?->inputTokens ?? 0) + ($result->usage?->outputTokens ?? 0),
            'cache_read_input_tokens' => $result->usage?->cachedReadTokens ?? 0,
            'cache_write_input_tokens' => $result->usage?->cachedWriteTokens ?? 0,
            'stop_reason' => $result->finishReason ?? 'end_turn',
            'latency_ms' => $result->latencyMs ?: (int) ((microtime(true) - $start) * 1000),
            'model_id' => $result->modelId ?? $modelId,
            'key_used' => $result->keyLabel ?: $keyLabel,
        ];
    }

    /**
     * Public alias for the legacy v2.1.x converse — same envelope.
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  callable(string $chunk): void  $onChunk
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cache_read_input_tokens: int, cache_write_input_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string}
     */
    public function converseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?string $idempotencyKey = null,
    ): array {
        $start = microtime(true);
        $this->currentModelId = $modelId;
        $keyLabel = $this->credentials->current()['label'] ?? 'Primary';

        try {
            $result = parent::converseStream(
                messages: $messages,
                onDelta: $onChunk,
                systemPrompt: $systemPrompt,
                maxTokens: $maxTokens,
                temperature: $temperature,
                idempotencyKey: $idempotencyKey,
            );
        } finally {
            $this->currentModelId = null;
        }

        return [
            'response' => $result->text,
            'input_tokens' => $result->usage?->inputTokens ?? 0,
            'output_tokens' => $result->usage?->outputTokens ?? 0,
            'total_tokens' => ($result->usage?->inputTokens ?? 0) + ($result->usage?->outputTokens ?? 0),
            'cache_read_input_tokens' => $result->usage?->cachedReadTokens ?? 0,
            'cache_write_input_tokens' => $result->usage?->cachedWriteTokens ?? 0,
            'stop_reason' => $result->finishReason ?? 'end_turn',
            'latency_ms' => $result->latencyMs ?: (int) ((microtime(true) - $start) * 1000),
            'model_id' => $result->modelId ?? $modelId,
            'key_used' => $result->keyLabel ?: $keyLabel,
        ];
    }

    // ─────────────────────────────────────────────────────────
    //  AWS-specific: model listing (not part of Converse standard)
    // ─────────────────────────────────────────────────────────

    public function testConnection(): array
    {
        $start = microtime(true);
        try {
            $models = $this->listModels();
            $responseTime = (int) ((microtime(true) - $start) * 1000);
            $msg = count($models) > 0
                ? 'Connection successful! Found ' . count($models) . ' available models.'
                : 'Bearer token accepted (model listing requires IAM — token validity not verified here; use `bedrock:test` → test a model to confirm).';
            return [
                'success' => true,
                'message' => $msg,
                'response_time' => $responseTime,
                'model_count' => count($models),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'response_time' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function listModels(): array
    {
        return Cache::remember(
            'bedrock_ai_models_' . md5(serialize($this->credentials->current())),
            $this->modelsCacheTtl,
            function (): array {
                if ($this->credentials->isBearerMode()) {
                    try {
                        return $this->listModelsHttp();
                    } catch (BedrockException $e) {
                        if (str_contains($e->getMessage(), '403')) {
                            Log::debug('Bedrock model listing returned 403 for bearer token; management-plane access may be restricted.', [
                                'error' => $e->getMessage(),
                            ]);
                            return [];
                        }
                        throw $e;
                    }
                }
                return $this->listModelsSdk();
            }
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchModels(): array
    {
        $rawModels = $this->listModels();
        return array_map(function (array $model): array {
            $specs = ModelSpecResolver::resolve($model['modelId']);
            return [
                'model_id' => $model['modelId'],
                'name' => $model['modelName'] ?? $model['modelId'],
                'context_window' => $model['contextWindow'] ?? $specs['context_window'],
                'max_tokens' => $model['maxTokens'] ?? $specs['max_tokens'],
                'capabilities' => array_map('strtolower', $model['outputModalities'] ?? ['text']),
                'input_modalities' => array_map('strtolower', $model['inputModalities'] ?? ['text']),
                'is_active' => ($model['modelLifecycle']['status'] ?? 'ACTIVE') === 'ACTIVE',
                'provider' => $model['providerName'] ?? '',
            ];
        }, $rawModels);
    }

    public function setModelsCacheTtl(int $ttl): static
    {
        $this->modelsCacheTtl = $ttl;
        return $this;
    }

    public function getCredentialManager(): CredentialManager
    {
        // Concrete bedrock-ai CredentialManager is preserved as a BC surface —
        // callers that type-hint `CredentialManager` (vs the parent
        // AbstractCredentialManager) still get the same instance.
        return $this->credentials;
    }

    /** @return array<int, array<string, mixed>> */
    protected function listModelsSdk(): array
    {
        $key = $this->credentials->current();
        $client = new AwsBedrockClient([
            'region' => $key['region'] ?? 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key' => $key['aws_key'],
                'secret' => $key['aws_secret'],
            ],
        ]);
        $result = $client->listFoundationModels();
        return $result['modelSummaries'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    protected function listModelsHttp(): array
    {
        $key = $this->credentials->current();
        $region = $key['region'] ?? 'us-east-1';
        $bearerToken = $this->credentials->getBearerToken();
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
            'Accept' => 'application/json',
            'X-Amz-Bedrock-Region' => $region,
        ])->get("https://bedrock.{$region}.amazonaws.com/foundation-models");
        if (! $response->successful()) {
            throw new BedrockException('Failed to list models via HTTP: ' . $response->status());
        }
        return $response->json('modelSummaries') ?? [];
    }

    // ─────────────────────────────────────────────────────────
    //  HasRetryLogic hooks — AWS-specific event dispatch
    // ─────────────────────────────────────────────────────────

    protected function onKeyRotated(array $fromKey, array $toKey, string $reason, string $modelId): void
    {
        Log::warning('Bedrock call failed, trying next key', [
            'error' => $reason,
            'key_label' => $fromKey['label'] ?? 'Unknown',
        ]);
        if (function_exists('event')) {
            event(new BedrockKeyRotated(
                fromKeyLabel: $fromKey['label'] ?? 'Unknown',
                toKeyLabel: $toKey['label'] ?? 'Unknown',
                reason: $reason,
                modelId: $modelId,
            ));
        }
    }

    protected function onRateLimitExhausted(string $modelId, array $key, int $retryAttempt): void
    {
        if (function_exists('event')) {
            event(new BedrockRateLimited(
                modelId: $modelId,
                keyLabel: $key['label'] ?? 'Unknown',
                retryAttempt: $retryAttempt,
                waitSeconds: 0,
            ));
        }
    }

    protected function resetPlatformClient(): void
    {
        // Force lazy SDK-client re-creation on next call (handles key rotation).
        $this->sdkClient = null;
    }

    protected function extractFriendlyError(string $errorMessage): string
    {
        return self::extractUserFriendlyError($errorMessage);
    }

    // ─────────────────────────────────────────────────────────
    //  AWS-specific error mapping (static so it can be called from
    //  outside the trait chain — kept BC for v2.1.x callers).
    // ─────────────────────────────────────────────────────────

    public static function extractUserFriendlyError(string $errorMessage): string
    {
        if (preg_match('/Bedrock HTTP Error: (\d+) - (.+)$/', $errorMessage, $matches)) {
            $statusCode = $matches[1];
            $decoded = json_decode($matches[2], true);
            $message = $decoded['message'] ?? $decoded['Message'] ?? $matches[2];

            $friendlyMessages = [
                'Authentication failed' => 'Bearer token is invalid or expired. Regenerate your API key in the AWS Console.',
                'API Key is valid' => 'Bearer token is invalid or expired. Regenerate your API key in the AWS Console.',
                'model identifier is invalid' => 'Invalid model: This model ID is not valid for Bedrock.',
                "doesn't support on-demand throughput" => 'Model unavailable: This model requires provisioned throughput.',
                "isn't supported" => 'Model unavailable: This model requires an inference profile.',
                'Malformed input request' => 'Request error: The model may not support this input type. Check that the model supports document/image input.',
                'end of its life' => 'Model deprecated: This model version has been retired.',
                'AccessDeniedException' => "Access denied: You don't have permission to use this model.",
                'not authorized' => "Access denied: You don't have permission to use this model.",
                'Document input is not supported' => 'Document not supported: This model does not accept document input. Use a model like Claude 3+, Nova Pro, or Nova Lite.',
                'document is not supported' => 'Document not supported: This model does not accept document input. Use a model like Claude 3+, Nova Pro, or Nova Lite.',
                'Image input is not supported' => 'Image not supported: This model does not accept image input. Use a model like Claude 3+, Nova Pro, or Nova Lite.',
                'image is not supported' => 'Image not supported: This model does not accept image input. Use a model like Claude 3+, Nova Pro, or Nova Lite.',
                'Invalid document format' => 'Invalid document: The document format or content is not valid. Supported formats: pdf, csv, doc, docx, xls, xlsx, html, txt, md.',
                'Invalid image format' => 'Invalid image: The image format or content is not valid. Supported formats: jpeg, png, gif, webp.',
                'too many images' => 'Too many images: The request contains more images than the model allows.',
                'too many documents' => 'Too many documents: The request contains more documents than the model allows.',
            ];

            foreach ($friendlyMessages as $needle => $friendly) {
                if (str_contains($message, $needle)) {
                    return $friendly;
                }
            }

            return "Bedrock error ({$statusCode}): " . substr($message, 0, 200);
        }

        $sdkErrors = [
            'ValidationException' => 'Validation error: The request was not valid for this model.',
            'ResourceNotFoundException' => 'Model not found: The requested model does not exist in this region.',
        ];

        foreach ($sdkErrors as $needle => $friendly) {
            if (str_contains($errorMessage, $needle)) {
                return $friendly;
            }
        }

        return 'AI service error. Please try again.';
    }

    public function platformName(): string
    {
        return 'AWS Bedrock';
    }
}