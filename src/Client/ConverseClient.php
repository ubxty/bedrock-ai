<?php

namespace Ubxty\BedrockAi\Client;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ubxty\BedrockAi\Events\BedrockKeyRotated;
use Ubxty\BedrockAi\Events\BedrockRateLimited;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;

/**
 * Wrapper around the AWS Bedrock Converse API.
 * Provides a unified interface across all model providers.
 */
class ConverseClient
{
    use HasRetryLogic;

    public function __construct(
        CredentialManager $credentials,
        int $maxRetries = 3,
        int $baseDelay = 2
    ) {
        $this->credentials = $credentials;
        $this->maxRetries = $maxRetries;
        $this->baseDelay = $baseDelay;
    }

    /**
     * Send a conversation using the Converse API.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string}
     */
    public function converse(
        string $modelId,
        array $messages,
        string $systemPrompt = '',
        int $maxTokens = 4096,
        float $temperature = 0.7,
    ): array {
        $startTime = microtime(true);

        return $this->withRetry($modelId, function (string $resolvedModelId, array $key) use ($messages, $systemPrompt, $maxTokens, $temperature, $startTime) {
            if ($this->credentials->isBearerMode()) {
                return $this->converseHttp(
                    $resolvedModelId, $messages, $systemPrompt,
                    $maxTokens, $temperature, $startTime
                );
            }

            $client = $this->getSdkClient();

            $params = [
                'modelId' => $resolvedModelId,
                'messages' => $this->formatMessages($messages),
                'inferenceConfig' => [
                    'maxTokens' => $maxTokens,
                    'temperature' => $temperature,
                ],
            ];

            if ($systemPrompt !== '') {
                $params['system'] = [
                    ['text' => $systemPrompt],
                ];
            }

            $result = $client->converse($params);

            $outputText = $result['output']['message']['content'][0]['text'] ?? '';
            $inputTokens = $result['usage']['inputTokens'] ?? 0;
            $outputTokens = $result['usage']['outputTokens'] ?? 0;
            $stopReason = $result['stopReason'] ?? 'end_turn';

            return [
                'response' => $outputText,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
                'stop_reason' => $stopReason,
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'model_id' => $resolvedModelId,
                'key_used' => $key['label'] ?? 'Primary',
            ];
        });
    }

    /**
     * Format messages into Converse API format.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array{role: string, content: array}>
     */
    protected function formatMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            return [
                'role' => $msg['role'],
                'content' => [
                    ['text' => $msg['content']],
                ],
            ];
        }, $messages);
    }

    /**
     * Send a conversation via HTTP Bearer mode.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string}
     */
    protected function converseHttp(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        float $startTime,
    ): array {
        $key = $this->credentials->current();
        $region = $key['region'] ?? 'us-east-1';
        $bearerToken = $this->credentials->getBearerToken();

        $url = "https://bedrock-runtime.{$region}.amazonaws.com/model/{$modelId}/converse";

        $body = [
            'messages' => $this->formatMessages($messages),
            'inferenceConfig' => [
                'maxTokens' => $maxTokens,
                'temperature' => $temperature,
            ],
        ];

        if ($systemPrompt !== '') {
            $body['system'] = [['text' => $systemPrompt]];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($url, $body);

        if (! $response->successful()) {
            $status = $response->status();

            if ($status === 429) {
                throw new RateLimitException('429 Too many requests - rate limited', 429);
            }

            throw new BedrockException("Bedrock HTTP Error: {$status} - {$response->body()}", $status);
        }

        $data = $response->json();
        $outputText = $data['output']['message']['content'][0]['text'] ?? '';
        $inputTokens = $data['usage']['inputTokens'] ?? 0;
        $outputTokens = $data['usage']['outputTokens'] ?? 0;
        $stopReason = $data['stopReason'] ?? 'end_turn';

        return [
            'response' => $outputText,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'stop_reason' => $stopReason,
            'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'model_id' => $modelId,
            'key_used' => $key['label'] ?? 'Primary',
        ];
    }

    protected function onKeyRotated(array $fromKey, array $toKey, string $reason, string $modelId): void
    {
        Log::warning('Bedrock Converse call failed, trying next key', [
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
}
