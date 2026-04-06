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
use Ubxty\BedrockAi\Exceptions\RateLimitException;
use Ubxty\BedrockAi\Models\ModelSpecResolver;

class BedrockClient
{
    protected CredentialManager $credentials;

    protected int $maxRetries;

    protected int $baseDelay;

    protected string $anthropicVersion;

    protected ?BedrockRuntimeClient $sdkClient = null;

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
        $this->credentials->reset();
        $maxKeyAttempts = $this->credentials->count();
        $keyAttempt = 0;

        while ($keyAttempt < $maxKeyAttempts) {
            $retryAttempt = 0;

            while ($retryAttempt <= $this->maxRetries) {
                try {
                    $key = $this->credentials->current();
                    $region = $key['region'] ?? 'us-east-1';
                    $resolvedModelId = InferenceProfileResolver::resolve($modelId, $region);

                    if ($this->credentials->isBearerMode()) {
                        return $this->invokeHttp(
                            $resolvedModelId, $systemPrompt, $userMessage,
                            $maxTokens, $temperature, $startTime, $pricing
                        );
                    }

                    return $this->invokeSdk(
                        $resolvedModelId, $systemPrompt, $userMessage,
                        $maxTokens, $temperature, $startTime, $pricing
                    );
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    $isRateLimited = $this->isRateLimitError($errorMessage);

                    if ($isRateLimited && $retryAttempt < $this->maxRetries) {
                        $waitTime = (int) pow($this->baseDelay, $retryAttempt + 1);
                        Log::warning("Bedrock rate limited, waiting {$waitTime}s before retry", [
                            'attempt' => $retryAttempt + 1,
                            'key_label' => $this->credentials->current()['label'] ?? 'Unknown',
                        ]);
                        sleep($waitTime);
                        $retryAttempt++;

                        continue;
                    }

                    // Try next key
                    $this->sdkClient = null;

                    if ($this->credentials->next()) {
                        Log::warning('Bedrock call failed, trying next key', [
                            'error' => $errorMessage,
                            'key_label' => $key['label'] ?? 'Unknown',
                        ]);

                        if (function_exists('event')) {
                            event(new BedrockKeyRotated(
                                fromKeyLabel: $key['label'] ?? 'Unknown',
                                toKeyLabel: $this->credentials->current()['label'] ?? 'Unknown',
                                reason: $errorMessage,
                                modelId: $modelId,
                            ));
                        }

                        break;
                    }

                    // All keys exhausted
                    if ($isRateLimited) {
                        if (function_exists('event')) {
                            event(new BedrockRateLimited(
                                modelId: $modelId,
                                keyLabel: $key['label'] ?? 'Unknown',
                                retryAttempt: $retryAttempt,
                                waitSeconds: 0,
                            ));
                        }

                        throw new RateLimitException(
                            'AI service is temporarily busy. Please wait a moment and try again.',
                            429, $e, $modelId, $key['label'] ?? null
                        );
                    }

                    throw new BedrockException(
                        self::extractUserFriendlyError($errorMessage),
                        0, $e, $modelId, $key['label'] ?? null
                    );
                }
            }

            $keyAttempt++;
        }

        throw new BedrockException('AI service unavailable. All credential keys exhausted.', 0, null, $modelId);
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

            return [
                'success' => true,
                'message' => 'Connection successful! Found ' . count($models) . ' available models.',
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
                    return $this->listModelsHttp();
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

    // ───────────────────────────────────────────────────────────
    //  Private: SDK invocation
    // ───────────────────────────────────────────────────────────

    protected function invokeSdk(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        float $startTime,
        ?array $pricing
    ): array {
        $client = $this->getSdkClient();
        $isTitan = str_contains($modelId, 'titan');

        $body = $isTitan
            ? $this->buildTitanBody($userMessage, $maxTokens, $temperature)
            : $this->buildClaudeBody($systemPrompt, $userMessage, $maxTokens, $temperature);

        $response = $client->invokeModel([
            'modelId' => $modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode($body),
        ]);

        $responseBody = json_decode($response['body']->getContents(), true);

        return $this->parseResponse($responseBody, $modelId, $startTime, $pricing);
    }

    protected function invokeHttp(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        int $maxTokens,
        float $temperature,
        float $startTime,
        ?array $pricing
    ): array {
        $key = $this->credentials->current();
        $region = $key['region'] ?? 'us-east-1';
        $bearerToken = $this->credentials->getBearerToken();

        $url = "https://bedrock-runtime.{$region}.amazonaws.com/model/{$modelId}/invoke";
        $isTitan = str_contains($modelId, 'titan');

        $body = $isTitan
            ? $this->buildTitanBody($userMessage, $maxTokens, $temperature)
            : $this->buildClaudeBody($systemPrompt, $userMessage, $maxTokens, $temperature);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Amz-Bedrock-Region' => $region,
        ])->post($url, $body);

        if (! $response->successful()) {
            $status = $response->status();

            if ($status === 429) {
                throw new RateLimitException('429 Too many requests - rate limited', 429);
            }

            throw new BedrockException("Bedrock HTTP Error: {$status} - {$response->body()}", $status);
        }

        return $this->parseResponse($response->json(), $modelId, $startTime, $pricing);
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
    //  Private: Request/Response helpers
    // ───────────────────────────────────────────────────────────

    protected function buildClaudeBody(string $systemPrompt, string $userMessage, int $maxTokens, float $temperature): array
    {
        return [
            'anthropic_version' => $this->anthropicVersion,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];
    }

    protected function buildTitanBody(string $userMessage, int $maxTokens, float $temperature): array
    {
        return [
            'inputText' => $userMessage,
            'textGenerationConfig' => [
                'maxTokenCount' => $maxTokens,
                'temperature' => $temperature,
            ],
        ];
    }

    protected function parseResponse(array $responseBody, string $modelId, float $startTime, ?array $pricing): array
    {
        $isTitan = str_contains($modelId, 'titan');

        if ($isTitan) {
            $content = $responseBody['results'][0]['outputText'] ?? '';
            $inputTokens = $responseBody['inputTextTokenCount'] ?? 0;
            $outputTokens = $responseBody['results'][0]['tokenCount'] ?? 0;
        } else {
            $content = $responseBody['content'][0]['text'] ?? '';
            $inputTokens = $responseBody['usage']['input_tokens'] ?? 0;
            $outputTokens = $responseBody['usage']['output_tokens'] ?? 0;
        }

        $cost = $this->calculateCost($inputTokens, $outputTokens, $pricing);

        return [
            'response' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'cost' => $cost,
            'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'status' => 'success',
            'key_used' => $this->credentials->current()['label'] ?? 'Primary',
            'model_id' => $modelId,
        ];
    }

    protected function calculateCost(int $inputTokens, int $outputTokens, ?array $pricing): float
    {
        $inputPrice = $pricing['input_price_per_1k'] ?? 0.003;
        $outputPrice = $pricing['output_price_per_1k'] ?? 0.015;

        return round(
            ($inputTokens / 1000) * $inputPrice + ($outputTokens / 1000) * $outputPrice,
            6
        );
    }

    protected function getSdkClient(): BedrockRuntimeClient
    {
        if (! $this->sdkClient) {
            $key = $this->credentials->current();

            $this->sdkClient = new BedrockRuntimeClient([
                'version' => 'latest',
                'region' => $key['region'] ?? 'us-east-1',
                'credentials' => [
                    'key' => $key['aws_key'],
                    'secret' => $key['aws_secret'],
                ],
            ]);
        }

        return $this->sdkClient;
    }

    protected function isRateLimitError(string $message): bool
    {
        return str_contains($message, '429')
            || str_contains($message, 'Too many requests')
            || str_contains($message, 'ThrottlingException')
            || str_contains($message, 'rate limit');
    }

    /**
     * Extract a user-friendly error message from raw Bedrock errors.
     */
    public static function extractUserFriendlyError(string $errorMessage): string
    {
        if (preg_match('/Bedrock HTTP Error: (\d+) - (.+)$/', $errorMessage, $matches)) {
            $statusCode = $matches[1];
            $decoded = json_decode($matches[2], true);
            $message = $decoded['message'] ?? $matches[2];

            $friendlyMessages = [
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
