<?php

namespace Ubxty\BedrockAi\Client;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;

/**
 * Streaming client for Bedrock models.
 * Uses invokeModelWithResponseStream for real-time token streaming.
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
     * Invoke a model with streaming, calling the callback for each text chunk.
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
        $startTime = microtime(true);
        $this->credentials->reset();
        $key = $this->credentials->current();
        $region = $key['region'] ?? 'us-east-1';
        $resolvedModelId = InferenceProfileResolver::resolve($modelId, $region);

        $retryAttempt = 0;

        while ($retryAttempt <= $this->maxRetries) {
            try {
                $client = $this->getSdkClient();

                $body = [
                    'anthropic_version' => $this->anthropicVersion,
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ];

                $response = $client->invokeModelWithResponseStream([
                    'modelId' => $resolvedModelId,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode($body),
                ]);

                $fullResponse = '';
                $inputTokens = 0;
                $outputTokens = 0;

                foreach ($response['body'] as $event) {
                    $chunk = json_decode($event['chunk']['bytes'] ?? '{}', true);

                    if (isset($chunk['type'])) {
                        switch ($chunk['type']) {
                            case 'content_block_delta':
                                $text = $chunk['delta']['text'] ?? '';
                                $fullResponse .= $text;
                                $onChunk($text, ['type' => 'delta']);
                                break;

                            case 'message_start':
                                $inputTokens = $chunk['message']['usage']['input_tokens'] ?? 0;
                                break;

                            case 'message_delta':
                                $outputTokens = $chunk['usage']['output_tokens'] ?? 0;
                                break;
                        }
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
                if ($this->isRateLimitError($e->getMessage()) && $retryAttempt < $this->maxRetries) {
                    $waitTime = (int) pow($this->baseDelay, $retryAttempt + 1);
                    sleep($waitTime);
                    $retryAttempt++;

                    continue;
                }

                if ($this->isRateLimitError($e->getMessage())) {
                    throw new RateLimitException(
                        'AI service is temporarily busy.',
                        429, $e, $modelId, $key['label'] ?? null
                    );
                }

                throw new BedrockException(
                    BedrockClient::extractUserFriendlyError($e->getMessage()),
                    0, $e, $modelId, $key['label'] ?? null
                );
            }
        }

        throw new BedrockException('Streaming failed after all retries.', 0, null, $modelId);
    }

    /**
     * Stream using the Converse API with converseStream.
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
        $startTime = microtime(true);
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
                $onChunk($text);
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
