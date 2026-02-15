<?php

namespace Ubxty\BedrockAi\Support;

use Ubxty\BedrockAi\Models\ModelSpecResolver;

class TokenEstimator
{
    /**
     * Average characters per token for English text.
     * Claude/GPT models average ~4 chars per token.
     */
    protected const CHARS_PER_TOKEN = 4;

    /**
     * Estimate the number of tokens in a string.
     */
    public static function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Estimate tokens for a full invocation (system + user prompt).
     *
     * @return array{input_tokens: int, available_output: int, fits: bool, context_window: int}
     */
    public static function estimateInvocation(
        string $systemPrompt,
        string $userMessage,
        string $modelId,
        int $maxOutputTokens = 4096
    ): array {
        $inputTokens = self::estimate($systemPrompt) + self::estimate($userMessage);
        $specs = ModelSpecResolver::resolve($modelId);
        $contextWindow = $specs['context_window'];
        $availableOutput = $contextWindow - $inputTokens;

        return [
            'input_tokens' => $inputTokens,
            'available_output' => max(0, $availableOutput),
            'fits' => ($inputTokens + $maxOutputTokens) <= $contextWindow,
            'context_window' => $contextWindow,
        ];
    }

    /**
     * Estimate the cost of an invocation before making the call.
     *
     * @param array{input_price_per_1k: float, output_price_per_1k: float}|null $pricing
     */
    public static function estimateCost(
        string $systemPrompt,
        string $userMessage,
        int $expectedOutputTokens = 1000,
        ?array $pricing = null
    ): float {
        $inputTokens = self::estimate($systemPrompt) + self::estimate($userMessage);
        $inputPrice = $pricing['input_price_per_1k'] ?? 0.003;
        $outputPrice = $pricing['output_price_per_1k'] ?? 0.015;

        return round(
            ($inputTokens / 1000) * $inputPrice + ($expectedOutputTokens / 1000) * $outputPrice,
            6
        );
    }
}
