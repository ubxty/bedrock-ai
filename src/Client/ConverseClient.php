<?php

namespace Ubxty\BedrockAi\Client;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;

/**
 * Wrapper around the AWS Bedrock Converse API.
 * Provides a unified interface across all model providers.
 */
class ConverseClient
{
    protected CredentialManager $credentials;

    protected int $maxRetries;

    protected int $baseDelay;

    protected ?BedrockRuntimeClient $sdkClient = null;

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
