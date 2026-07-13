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
        ?string $idempotencyKey = null,
    ): array {
        try {
            return $this->doConverse($modelId, $messages, $systemPrompt, $maxTokens, $temperature, $idempotencyKey);
        } catch (BedrockException $e) {
            // Some models (e.g. Mixtral, Mistral 7B) reject system messages.
            // Automatically fold the system prompt into the first user message and retry.
            if ($systemPrompt !== '' && str_contains($e->getMessage(), "doesn't support system")) {
                $messages = $this->foldSystemIntoMessages($messages, $systemPrompt);

                return $this->doConverse($modelId, $messages, '', $maxTokens, $temperature, $idempotencyKey);
            }

            throw $e;
        }
    }

    /**
     * Internal: execute a converse call with retry logic.
     */
    protected function doConverse(
        string $modelId,
        array $messages,
        string $systemPrompt,
        int $maxTokens,
        float $temperature,
        ?string $idempotencyKey = null,
    ): array {
        $startTime = microtime(true);

        return $this->withRetry($modelId, function (string $resolvedModelId, array $key) use ($messages, $systemPrompt, $maxTokens, $temperature, $idempotencyKey, $startTime) {
            if ($this->credentials->isBearerMode()) {
                // Bearer mode also needs inference profile prefixes (us./eu.) for
                // models that require cross-region inference profiles.
                return $this->converseHttp(
                    $resolvedModelId, $messages, $systemPrompt,
                    $maxTokens, $temperature, $startTime, $idempotencyKey
                );
            }

            $client = $this->getSdkClient();

            $params = [
                'modelId' => $resolvedModelId,
                'inferenceConfig' => [
                    'maxTokens' => $maxTokens,
                    'temperature' => $temperature,
                ],
            ];

            $systemBlocks = $systemPrompt !== '' ? [['text' => $systemPrompt]] : [];
            $formattedMessages = $this->formatMessages($messages);

            // Inject cachePoint markers at the configured anchors (v2.1.0).
            [$formattedMessages, $systemBlocks] = $this->applyCachePoints($formattedMessages, $systemBlocks);

            $params['messages'] = $formattedMessages;

            if (! empty($systemBlocks)) {
                $params['system'] = $systemBlocks;
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

    // formatMessages() is inherited from HasRetryLogic trait.

    /**
     * Fold a system prompt into the first user message for models that don't support system messages.
     */
    protected function foldSystemIntoMessages(array $messages, string $systemPrompt): array
    {
        if (empty($messages)) {
            return $messages;
        }

        $first = &$messages[0];
        if ($first['role'] === 'user') {
            if (is_string($first['content'])) {
                $first['content'] = "[System: {$systemPrompt}]\n\n" . $first['content'];
            } elseif (is_array($first['content'])) {
                // Prepend as a text block for multimodal messages
                array_unshift($first['content'], ['type' => 'text', 'text' => "[System: {$systemPrompt}]"]);
            }
        }

        return $messages;
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
        ?string $idempotencyKey = null,
    ): array {
        $key = $this->credentials->current();
        $region = $key['region'] ?? 'us-east-1';
        $bearerToken = $this->credentials->getBearerToken();

        $url = "https://bedrock-runtime.{$region}.amazonaws.com/model/{$modelId}/converse";

        $systemBlocks = $systemPrompt !== '' ? [['text' => $systemPrompt]] : [];
        $formattedMessages = $this->formatMessages($messages, true);

        // Inject cachePoint markers at the configured anchors (v2.1.0).
        [$formattedMessages, $systemBlocks] = $this->applyCachePoints($formattedMessages, $systemBlocks);

        $body = [
            'messages' => $formattedMessages,
            'inferenceConfig' => [
                'maxTokens' => $maxTokens,
                'temperature' => $temperature,
            ],
        ];

        if (! empty($systemBlocks)) {
            $body['system'] = $systemBlocks;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $bearerToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $response = Http::withHeaders($headers)->post($url, $body);

        if (! $response->successful()) {
            $status = $response->status();

            if ($status === 429) {
                // Honour the upstream Retry-After hint when present.
                $retryAfter = $response->header('Retry-After');
                if ($retryAfter !== null) {
                    $this->setRetryAfterSeconds((int) $retryAfter);
                }
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
