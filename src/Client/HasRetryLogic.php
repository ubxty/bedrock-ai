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
     * Named anchors where the manager wants a `cachePoint` block injected.
     * Supported values:
     *   - 'system'              after the system prompt blocks
     *   - 'last_user'           after the last user message blocks
     *   - 'last_assistant'      after the last assistant message blocks
     *   - 'every_n_assistant:N' after every Nth assistant message (N >= 1)
     *
     * Bedrock accepts up to 4 cachePoints per Converse call — applyCachePoints()
     * clamps the total emitted anchors to that ceiling.
     *
     * @var string[]
     */
    protected array $promptCachePoints = [];

    /**
     * Bedrock cachePoint `type` value. 'default' keeps the model-default TTL;
     * '1h' pins a one-hour cache window (matches BEDROCK_PROMPT_CACHE_TTL).
     */
    protected string $promptCachePointType = 'default';

    /**
     * Bedrock hard cap on cachePoints per Converse call.
     * Excess configured anchors are dropped (last-wins ordering).
     */
    protected const MAX_CACHE_POINTS = 4;

    /**
     * Optional explicit Retry-After (seconds) set by the HTTP path after parsing
     * the rate-limit response. Cleared on each retry iteration.
     */
    protected ?int $retryAfterSeconds = null;

    /**
     * Set the prompt-cache checkpoint anchors. Pass-through from
     * `core-ai.bedrock.prompt_caching.points`.
     *
     * Accepts the static anchors ('system', 'last_user', 'last_assistant')
     * and the parametric 'every_n_assistant:N' strategy. Unknown strings
     * are silently dropped so a stray env value cannot disable caching.
     */
    public function setPromptCachePoints(array $points): static
    {
        $this->promptCachePoints = array_values(array_filter(
            array_map('strval', $points),
            fn (string $p) => $this->isValidCachePointStrategy($p),
        ));

        return $this;
    }

    /**
     * Set the cachePoint `type` block ('default' or '1h'). Pass-through from
     * `core-ai.bedrock.prompt_caching.ttl_seconds` (>0 => '1h', else 'default').
     */
    public function setPromptCachePointType(string $type): static
    {
        $allowed = ['default', '1h'];
        $this->promptCachePointType = in_array($type, $allowed, true) ? $type : 'default';

        return $this;
    }

    /**
     * Validate a configured cache-point strategy string.
     */
    protected function isValidCachePointStrategy(string $point): bool
    {
        if (in_array($point, ['system', 'last_user', 'last_assistant'], true)) {
            return true;
        }

        // 'every_n_assistant:N' where N is a positive integer.
        if (preg_match('/^every_n_assistant:([1-9]\d*)$/', $point, $m) === 1) {
            return (int) $m[1] >= 1;
        }

        return false;
    }

    /**
     * Parse the trailing integer from an 'every_n_assistant:N' strategy.
     * Returns null for non-matching strategy names.
     */
    protected function everyNAssistantInterval(string $strategy): ?int
    {
        if (preg_match('/^every_n_assistant:([1-9]\d*)$/', $strategy, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Set the Retry-After hint parsed from an upstream HTTP 429/503 response.
     * When set, withRetry() uses it in preference to the exponential backoff.
     */
    public function setRetryAfterSeconds(?int $seconds): static
    {
        $this->retryAfterSeconds = $seconds;

        return $this;
    }

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
                        // Honour Retry-After when set (HTTP bearer path). Otherwise
                        // fall back to exponential backoff: baseDelay^attempt.
                        $waitTime = $this->retryAfterSeconds !== null
                            ? max(1, $this->retryAfterSeconds)
                            : (int) pow($this->baseDelay, $retryAttempt + 1);
                        $this->retryAfterSeconds = null; // consume the hint
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

    /**
     * Resolve the effective input-token count from a Converse `usage` block.
     *
     * Amazon Nova (Pro/Lite/Micro) reports `inputTokens=0` for cached
     * requests and surfaces the real count via `cacheReadInputTokens +
     * cacheWriteInputTokens`. Anthropic Claude reports the total via
     * `inputTokens` and the cache fields as subsets, so summing them all
     * would double-count. We pick whichever reading is non-zero so both
     * model families surface the correct effective input to callers.
     */
    protected function effectiveInputTokens(int $inputTokens, int $cacheReadInputTokens, int $cacheWriteInputTokens): int
    {
        if ($inputTokens > 0) {
            return $inputTokens;
        }

        return $cacheReadInputTokens + $cacheWriteInputTokens;
    }

    /**
     * Inject `cachePoint` blocks into the Converse body at the configured named
     * anchors. Empty $this->promptCachePoints is a no-op.
     *
     * Anchors are emitted in conversation order (system first, then by message
     * index) so a multi-turn chat reuses progressively longer cached prefixes
     * — single biggest input-cost saving for chat workloads.
     *
     * The Bedrock Converse API allows up to 4 cachePoints per call; the total
     * number of anchors actually written is clamped to {@see MAX_CACHE_POINTS}.
     * When the ceiling is hit, earlier anchors win (system > last_user >
     * last_assistant > every_n_assistant).
     *
     * @param  array<int, array{role: string, content: array<int, mixed>}>  $messages
     * @param  array<int, array{text?: string}>  $system
     * @return array{0: array<int, array{role: string, content: array<int, mixed>}>, 1: array<int, array{text?: string|cachePoint?: array}>}
     */
    protected function applyCachePoints(array $messages, array $system): array
    {
        if (empty($this->promptCachePoints)) {
            return [$messages, $system];
        }

        $type = ['type' => $this->promptCachePointType];
        $remaining = self::MAX_CACHE_POINTS;

        if ($remaining > 0 && in_array('system', $this->promptCachePoints, true) && ! empty($system)) {
            $system[] = ['cachePoint' => $type];
            $remaining--;
        }

        if ($remaining > 0 && in_array('last_user', $this->promptCachePoints, true) && ! empty($messages)) {
            // Find the last message with role 'user' and append the checkpoint to its content.
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? null) === 'user') {
                    if (! is_array($messages[$i]['content'])) {
                        // Plain string content: convert to a block array.
                        $messages[$i]['content'] = [['text' => (string) $messages[$i]['content']]];
                    }
                    $messages[$i]['content'][] = ['cachePoint' => $type];
                    $remaining--;
                    break;
                }
            }
        }

        if ($remaining > 0 && in_array('last_assistant', $this->promptCachePoints, true) && ! empty($messages)) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? null) === 'assistant') {
                    if (! is_array($messages[$i]['content'])) {
                        $messages[$i]['content'] = [['text' => (string) $messages[$i]['content']]];
                    }
                    $messages[$i]['content'][] = ['cachePoint' => $type];
                    $remaining--;
                    break;
                }
            }
        }

        if ($remaining > 0 && ! empty($messages)) {
            // Pre-compute every_n_assistant:N intervals (one per N), then emit
            // a cachePoint at each Nth assistant message — walking from the
            // end backwards so we always fill with the most-recent eligible
            // anchors first (best cache-hit reuse for chat workloads).
            $intervals = [];
            foreach ($this->promptCachePoints as $strategy) {
                $n = $this->everyNAssistantInterval($strategy);
                if ($n !== null) {
                    $intervals[$n] = true;
                }
            }
            $intervals = array_keys($intervals);

            if (! empty($intervals)) {
                $assistantIndexes = [];
                foreach ($messages as $i => $msg) {
                    if (($msg['role'] ?? null) === 'assistant') {
                        $assistantIndexes[] = $i;
                    }
                }

                // For each interval N, pick the (assistantCount mod N == 0)
                // assistant indexes walking from the newest backwards. Emit at
                // most one anchor per N until the cap is hit.
                foreach ($intervals as $n) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $count = 0;
                    for ($k = count($assistantIndexes) - 1; $k >= 0; $k--) {
                        $count++;
                        if ($count % $n !== 0) {
                            continue;
                        }
                        $idx = $assistantIndexes[$k];
                        if (! is_array($messages[$idx]['content'])) {
                            $messages[$idx]['content'] = [['text' => (string) $messages[$idx]['content']]];
                        }
                        // cachePoint must remain the LAST element of
                        // messages[i].content per the Bedrock Converse spec.
                        $messages[$idx]['content'][] = ['cachePoint' => $type];
                        $remaining--;
                        if ($remaining <= 0) {
                            break;
                        }
                    }
                }
            }
        }

        return [$messages, $system];
    }
}
