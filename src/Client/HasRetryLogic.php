<?php

namespace Ubxty\BedrockAi\Client;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;

/**
 * Shared retry, key-rotation, and SDK client logic for Bedrock client classes.
 */
trait HasRetryLogic
{
    protected CredentialManager $credentials;

    protected int $maxRetries;

    protected int $baseDelay;

    protected ?BedrockRuntimeClient $sdkClient = null;

    /**
     * Execute a callable with key rotation and retry logic.
     *
     * @param string $modelId Used for exception context
     * @param callable(string $resolvedModelId, array $key): array $callback
     * @return array
     */
    protected function withRetry(string $modelId, callable $callback): array
    {
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

                    return $callback($resolvedModelId, $key);
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
                        $this->onKeyRotated($key, $this->credentials->current(), $errorMessage, $modelId);
                        break;
                    }

                    if ($isRateLimited) {
                        $this->onRateLimitExhausted($modelId, $key, $retryAttempt);

                        throw new RateLimitException(
                            'AI service is temporarily busy. Please wait a moment and try again.',
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

        throw new BedrockException('AI service unavailable. All credential keys exhausted.', 0, null, $modelId);
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
     * Hook called when a key is rotated due to error. Override for event dispatching.
     */
    protected function onKeyRotated(array $fromKey, array $toKey, string $reason, string $modelId): void
    {
        // Override in subclasses to dispatch events, log, etc.
    }

    /**
     * Hook called when all keys are exhausted due to rate limiting. Override for event dispatching.
     */
    protected function onRateLimitExhausted(string $modelId, array $key, int $retryAttempt): void
    {
        // Override in subclasses to dispatch events, log, etc.
    }

    /**
     * Format messages into Converse API format.
     *
     * Supports plain-text messages (`content` is a string) and multimodal messages
     * (`content` is an array of typed blocks: text, image, document).
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     * @param  bool  $httpMode  true keeps image/document bytes as base64 (HTTP/JSON API),
     *                          false decodes to raw binary for the AWS PHP SDK.
     * @return array<int, array{role: string, content: array}>
     */
    protected function formatMessages(array $messages, bool $httpMode = false): array
    {
        return array_map(function (array $msg) use ($httpMode) {
            $content = $msg['content'];

            if (is_string($content)) {
                return [
                    'role' => $msg['role'],
                    'content' => [['text' => $content]],
                ];
            }

            $blocks = [];
            foreach ($content as $block) {
                $type = $block['type'] ?? 'text';

                if ($type === 'text') {
                    $blocks[] = ['text' => $block['text']];
                } elseif ($type === 'image') {
                    $bytes = $httpMode ? $block['data'] : base64_decode($block['data']);
                    $blocks[] = [
                        'image' => [
                            'format' => $block['format'],
                            'source' => ['bytes' => $bytes],
                        ],
                    ];
                } elseif ($type === 'document') {
                    $bytes = $httpMode ? $block['data'] : base64_decode($block['data']);
                    $blocks[] = [
                        'document' => [
                            'format' => $block['format'],
                            'name'   => $block['name'] ?? 'document',
                            'source' => ['bytes' => $bytes],
                        ],
                    ];
                }
            }

            return ['role' => $msg['role'], 'content' => $blocks];
        }, $messages);
    }

    /**
     * Calculate the cost of an invocation from token counts.
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
}
