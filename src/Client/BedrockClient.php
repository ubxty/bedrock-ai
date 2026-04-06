<?php

namespace Ubxty\BedrockAi\Client;

use Aws\Bedrock\BedrockClient as AwsBedrockClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ubxty\BedrockAi\Events\BedrockKeyRotated;
use Ubxty\BedrockAi\Events\BedrockRateLimited;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;
use Ubxty\BedrockAi\Models\ModelSpecResolver;

class BedrockClient
{
    use HasRetryLogic;

    protected string $anthropicVersion;

    protected int $modelsCacheTtl = 3600;

    public function __construct(
        CredentialManager $credentials,
        int $maxRetries = 3,
        int $baseDelay = 2,
        string $anthropicVersion = 'bedrock-2023-05-31'
    ) {
        $this->credentials = $credentials;
        $this->maxRetries = $maxRetries;
        $this->baseDelay = $baseDelay;
        $this->anthropicVersion = $anthropicVersion;
    }

    /**
     * Invoke a Bedrock model with automatic key rotation and retry.
     * Internally delegates to the Converse API for provider-agnostic support.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    public function invoke(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens = 4096,
        float $temperature = 0.7,
        ?array $pricing = null
    ): array {
        $startTime = microtime(true);

        $converseClient = new ConverseClient($this->credentials, $this->maxRetries, $this->baseDelay);

        $result = $converseClient->converse(
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
            'cost' => $cost,
            'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'status' => 'success',
            'key_used' => $result['key_used'],
            'model_id' => $result['model_id'],
        ];
    }

    /**
     * Test the connection by listing available models.
     *
     * @return array{success: bool, message: string, response_time: int, model_count?: int}
     */
    public function testConnection(): array
    {
        $startTime = microtime(true);

        try {
            $models = $this->listModels();
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            $msg = count($models) > 0
                ? 'Connection successful! Found ' . count($models) . ' available models.'
                : 'Bearer token accepted (model listing requires IAM — token validity not verified here; use `bedrock:test` → test a model to confirm).';

            return [
                'success' => true,
                'message' => $msg,
                'response_time' => $responseTime,
                'model_count' => count($models),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'response_time' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * List available foundation models from Bedrock.
     *
     * @return array<int, array{modelId: string, modelName: string, ...}>
     */
    public function listModels(): array
    {
        return Cache::remember(
            'bedrock_ai_models_' . md5(serialize($this->credentials->current())),
            $this->modelsCacheTtl,
            function () {
                if ($this->credentials->isBearerMode()) {
                    try {
                        return $this->listModelsHttp();
                    } catch (BedrockException $e) {
                        // ABSK bearer tokens are scoped to the runtime inference plane.
                        // The management-plane listing endpoint may return 403 — treat this
                        // as "no models available" rather than a fatal error.
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

    /**
     * Set the models cache TTL in seconds.
     */
    public function setModelsCacheTtl(int $ttl): static
    {
        $this->modelsCacheTtl = $ttl;

        return $this;
    }

    /**
     * Fetch models with normalized structure for easy consumption.
     *
     * @return array<int, array{model_id: string, name: string, context_window: int, max_tokens: int, capabilities: array, is_active: bool}>
     */
    public function fetchModels(): array
    {
        $rawModels = $this->listModels();

        return array_map(function (array $model) {
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

    /**
     * Get the credential manager instance.
     */
    public function getCredentialManager(): CredentialManager
    {
        return $this->credentials;
    }

    /**
     * Dispatch key rotation events and log warnings.
     */
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

    /**
     * Dispatch rate limit exhaustion events.
     */
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

    // ───────────────────────────────────────────────────────────
    //  Private: Model listing
    // ───────────────────────────────────────────────────────────

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

    // ───────────────────────────────────────────────────────────
    //  Private: Helpers
    // ───────────────────────────────────────────────────────────

    protected function calculateCost(int $inputTokens, int $outputTokens, ?array $pricing): float
    {
        $inputPrice = $pricing['input_price_per_1k'] ?? 0.003;
        $outputPrice = $pricing['output_price_per_1k'] ?? 0.015;

        return round(
            ($inputTokens / 1000) * $inputPrice + ($outputTokens / 1000) * $outputPrice,
            6
        );
    }

    /**
     * Extract a user-friendly error message from raw Bedrock errors.
     */
    public static function extractUserFriendlyError(string $errorMessage): string
    {
        if (preg_match('/Bedrock HTTP Error: (\d+) - (.+)$/', $errorMessage, $matches)) {
            $statusCode = $matches[1];
            $decoded = json_decode($matches[2], true);
            // AWS returns both 'message' and 'Message' depending on the service path
            $message = $decoded['message'] ?? $decoded['Message'] ?? $matches[2];

            $friendlyMessages = [
                'Authentication failed' => 'Bearer token is invalid or expired. Regenerate your API key in the AWS Console.',
                'API Key is valid' => 'Bearer token is invalid or expired. Regenerate your API key in the AWS Console.',
                'model identifier is invalid' => 'Invalid model: This model ID is not valid for Bedrock.',
                "doesn't support on-demand throughput" => 'Model unavailable: This model requires provisioned throughput.',
                "isn't supported" => 'Model unavailable: This model requires an inference profile.',
                'Malformed input request' => 'Request error: This model may not support text chat.',
                'end of its life' => 'Model deprecated: This model version has been retired.',
                'AccessDeniedException' => "Access denied: You don't have permission to use this model.",
                'not authorized' => "Access denied: You don't have permission to use this model.",
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
}
