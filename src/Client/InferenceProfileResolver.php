<?php

namespace Ubxty\BedrockAi\Client;

class InferenceProfileResolver
{
    /**
     * Patterns for models that require cross-region inference profiles.
     * These models cannot be invoked directly and need a us. or eu. prefix.
     *
     * @var array<string>
     */
    protected static array $inferenceProfilePatterns = [
        'anthropic.claude-3-5-',
        'anthropic.claude-3-7-',
        'anthropic.claude-sonnet-4',
        'anthropic.claude-opus-4',
        'anthropic.claude-haiku-4',
        'amazon.nova-',
        'meta.llama3-3',
        'meta.llama4',
    ];

    /**
     * Resolve a model ID, applying cross-region inference profile prefix if needed.
     */
    public static function resolve(string $modelId, string $region = 'us-east-1'): string
    {
        // Strip context window suffixes (e.g., :48k, :200k) that are for display only
        $modelId = preg_replace('/:\d+k$/', '', $modelId);

        foreach (static::$inferenceProfilePatterns as $pattern) {
            if (str_starts_with($modelId, $pattern)) {
                $regionPrefix = str_starts_with($region, 'eu-') ? 'eu' : 'us';

                return "{$regionPrefix}.{$modelId}";
            }
        }

        return $modelId;
    }

    /**
     * Check if a model requires an inference profile.
     */
    public static function requiresProfile(string $modelId): bool
    {
        $modelId = preg_replace('/:\d+k$/', '', $modelId);

        foreach (static::$inferenceProfilePatterns as $pattern) {
            if (str_starts_with($modelId, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register additional inference profile patterns at runtime.
     */
    public static function addPattern(string $pattern): void
    {
        if (! in_array($pattern, static::$inferenceProfilePatterns)) {
            static::$inferenceProfilePatterns[] = $pattern;
        }
    }
}
