<?php

namespace Ubxty\BedrockAi\Client;

use Ubxty\BedrockAi\Events\BedrockKeyRotated;
use Ubxty\BedrockAi\Events\BedrockRateLimited;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;

/**
 * Streaming client for Bedrock models.
 * Uses converseStream for real-time token streaming (unified across all providers).
 */
class StreamingClient
{
    use HasRetryLogic;

    protected string $anthropicVersion;

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

        return $this->withRetry($modelId, function (string $resolvedModelId, array $key) use ($messages, $onChunk, $systemPrompt, $maxTokens, $temperature, $startTime) {
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
        });
    }

    protected function onKeyRotated(array $fromKey, array $toKey, string $reason, string $modelId): void
    {
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
}
