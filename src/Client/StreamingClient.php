<?php

namespace Ubxty\BedrockAi\Client;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;

/**
 * Streaming client for Bedrock models.
 * Uses converseStream for real-time token streaming (unified across all providers).
 * Falls back to invokeModelWithResponseStream for Anthropic-specific format.
 */
class StreamingClient
{
    protected CredentialManager $credentials;

    protected int $maxRetries;

    protected int $baseDelay;

    protected string $anthropicVersion;

    protected ?BedrockRuntimeClient $sdkClient = null;

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
     * Stream a model invocation with key rotation and retry support.
     * Uses converseStream (provider-agnostic) by default.
     *
     * @param callable(string $chunk, array $metadata): void $onChunk Called for each streamed text chunk
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, latency_ms: int, model_id: string, key_used: string}
     */
    public function stream(
        string $modelId,
        string $systemPrompt,
        string $userMessage,
        callable $onChunk,
        int $maxTokens = 4096,
        float $temperature = 0.7,
    ): array {
        if ($this->credentials->isBearerMode()) {
            throw new ConfigurationException('Streaming is not supported in Bearer token mode. Use IAM credentials or the non-streaming converse() method.');
        }

        $messages = [['role' => 'user', 'content' => $userMessage]];

        return $this->converseStream($modelId, $messages, $onChunk, $systemPrompt, $maxTokens, $temperature);
    }

    /**
     * Stream using the Converse API with converseStream.
     * Supports all model providers (Claude, Titan, Nova, Llama, Mistral, etc.).
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param callable(string $chunk): void $onChunk
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, latency_ms: int, model_id: string}
     */
    public function converseStream(
        string $modelId,
        array $messages,
        callable $onChunk,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
    ): array {
        if ($this->credentials->isBearerMode()) {
            throw new ConfigurationException('Streaming is not supported in Bearer token mode. Use IAM credentials or the non-streaming converse() method.');
        }

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
                    $client = $this->getSdkClient();

                    $params = [
                        'modelId' => $resolvedModelId,
                        'messages' => array_map(function (array $msg) {
                            return [
                                'role' => $msg['role'],
                                'content' => [['text' => $msg['content']]],
                            ];
                        }, $messages),
                        'inferenceConfig' => [
                            'maxTokens' => $maxTokens,
                            'temperature' => $temperature,
                        ],
                    ];

                    if ($systemPrompt !== '') {
                        $params['system'] = [['text' => $systemPrompt]];
                    }

                    $response = $client->converseStream($params);

                    $fullResponse = '';
                    $inputTokens = 0;
                    $outputTokens = 0;

                    foreach ($response['stream'] as $event) {
                        if (isset($event['contentBlockDelta'])) {
                            $text = $event['contentBlockDelta']['delta']['text'] ?? '';
                            $fullResponse .= $text;
                            $onChunk($text, ['type' => 'delta']);
                        }

                        if (isset($event['metadata']['usage'])) {
                            $inputTokens = $event['metadata']['usage']['inputTokens'] ?? 0;
                            $outputTokens = $event['metadata']['usage']['outputTokens'] ?? 0;
                        }
                    }

                    return [
                        'response' => $fullResponse,
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'total_tokens' => $inputTokens + $outputTokens,
                        'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
                        'model_id' => $resolvedModelId,
                        'key_used' => $key['label'] ?? 'Primary',
                    ];
                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    $isRateLimited = $this->isRateLimitError($errorMessage);

                    if ($isRateLimited && $retryAttempt < $this->maxRetries) {
                        $waitTime = (int) pow($this->baseDelay, $retryAttempt + 1);
                        sleep($waitTime);
                        $retryAttempt++;

                        continue;
                    }

                    $this->sdkClient = null;

                    if ($this->credentials->next()) {
                        break;
                    }

                    if ($isRateLimited) {
                        throw new RateLimitException(
                            'AI service is temporarily busy.',
                            429, $e, $modelId, $key['label'] ?? null
                        );
                    }

                    throw new BedrockException(
                        BedrockClient::extractUserFriendlyError($errorMessage),
                        0, $e, $modelId, $key['label'] ?? null
                    );
                }
            }

            $keyAttempt++;
        }

        throw new BedrockException('Streaming failed after all keys exhausted.', 0, null, $modelId);
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
}
