<?php

namespace Ubxty\BedrockAi\Pricing;

/**
 * Normalizes AWS Pricing API model names to Bedrock model IDs.
 */
class ModelIdNormalizer
{
    /**
     * Normalize a model name from the AWS Pricing API to a Bedrock model ID.
     */
    public static function normalize(string $modelName, string $provider, array $attributes = []): ?string
    {
        $modelLower = strtolower($modelName);
        $providerLower = strtolower($provider);

        return self::matchClaude($modelLower)
            ?? self::matchTitan($modelLower)
            ?? self::matchNova($modelLower)
            ?? self::matchLlama($modelLower)
            ?? self::matchMistral($modelLower, $providerLower)
            ?? self::matchCohere($modelLower)
            ?? self::matchAi21($modelLower);
    }

    protected static function matchClaude(string $model): ?string
    {
        if (! str_contains($model, 'claude')) {
            return null;
        }

        if (str_contains($model, '3.5') || str_contains($model, '3-5')) {
            if (str_contains($model, 'sonnet')) {
                return 'anthropic.claude-3-5-sonnet';
            }
            if (str_contains($model, 'haiku')) {
                return 'anthropic.claude-3-5-haiku';
            }
        }
        if (str_contains($model, '3.0') || str_contains($model, '3 ')) {
            if (str_contains($model, 'opus')) {
                return 'anthropic.claude-3-opus';
            }
            if (str_contains($model, 'sonnet')) {
                return 'anthropic.claude-3-sonnet';
            }
            if (str_contains($model, 'haiku')) {
                return 'anthropic.claude-3-haiku';
            }
        }
        if (str_contains($model, 'v2')) {
            return 'anthropic.claude-v2';
        }
        if (str_contains($model, 'instant')) {
            return 'anthropic.claude-instant-v1';
        }

        return null;
    }

    protected static function matchTitan(string $model): ?string
    {
        if (! str_contains($model, 'titan')) {
            return null;
        }

        if (str_contains($model, 'premier')) {
            return 'amazon.titan-text-premier-v1:0';
        }
        if (str_contains($model, 'express')) {
            return 'amazon.titan-text-express-v1';
        }
        if (str_contains($model, 'lite')) {
            return 'amazon.titan-text-lite-v1';
        }

        return null;
    }

    protected static function matchNova(string $model): ?string
    {
        if (! str_contains($model, 'nova')) {
            return null;
        }

        if (str_contains($model, 'pro')) {
            return 'amazon.nova-pro-v1:0';
        }
        if (str_contains($model, 'lite')) {
            return 'amazon.nova-lite-v1:0';
        }
        if (str_contains($model, 'micro')) {
            return 'amazon.nova-micro-v1:0';
        }

        return null;
    }

    protected static function matchLlama(string $model): ?string
    {
        if (! str_contains($model, 'llama')) {
            return null;
        }

        $patterns = [
            ['3.3', '70b', 'meta.llama3-3-70b-instruct-v1:0'],
            ['3.2', '90b', 'meta.llama3-2-90b-instruct-v1:0'],
            ['3.2', '11b', 'meta.llama3-2-11b-instruct-v1:0'],
            ['3.2', '3b', 'meta.llama3-2-3b-instruct-v1:0'],
            ['3.2', '1b', 'meta.llama3-2-1b-instruct-v1:0'],
            ['3.1', '405b', 'meta.llama3-1-405b-instruct-v1:0'],
            ['3.1', '70b', 'meta.llama3-1-70b-instruct-v1:0'],
            ['3.1', '8b', 'meta.llama3-1-8b-instruct-v1:0'],
        ];

        foreach ($patterns as [$version, $size, $id]) {
            $versionAlt = str_replace('.', '-', $version);
            if ((str_contains($model, $version) || str_contains($model, $versionAlt)) && str_contains($model, $size)) {
                return $id;
            }
        }

        if (str_contains($model, '70b')) {
            return 'meta.llama3-70b-instruct-v1:0';
        }
        if (str_contains($model, '8b')) {
            return 'meta.llama3-8b-instruct-v1:0';
        }

        return null;
    }

    protected static function matchMistral(string $model, string $provider): ?string
    {
        if ($provider !== 'mistral' && ! str_contains($model, 'mistral') && ! str_contains($model, 'mixtral')) {
            return null;
        }

        if (str_contains($model, 'large') && str_contains($model, '2')) {
            return 'mistral.mistral-large-2407-v1:0';
        }
        if (str_contains($model, 'large')) {
            return 'mistral.mistral-large-2402-v1:0';
        }
        if (str_contains($model, 'small')) {
            return 'mistral.mistral-small-2402-v1:0';
        }
        if (str_contains($model, 'mixtral')) {
            return 'mistral.mixtral-8x7b-instruct-v0:1';
        }
        if (str_contains($model, 'ministral') && str_contains($model, '8b')) {
            return 'mistral.ministral-8b-2410-v1:0';
        }
        if (str_contains($model, 'ministral') && str_contains($model, '14b')) {
            return 'mistral.ministral-14b-2410-v1:0';
        }
        if (str_contains($model, '7b')) {
            return 'mistral.mistral-7b-instruct-v0:2';
        }

        return null;
    }

    protected static function matchCohere(string $model): ?string
    {
        if (! str_contains($model, 'command')) {
            return null;
        }

        if (str_contains($model, 'r+') || str_contains($model, 'r plus')) {
            return 'cohere.command-r-plus-v1:0';
        }
        if (str_contains($model, 'r')) {
            return 'cohere.command-r-v1:0';
        }

        return 'cohere.command-text-v14';
    }

    protected static function matchAi21(string $model): ?string
    {
        if (str_contains($model, 'jamba')) {
            if (str_contains($model, '1.5') && str_contains($model, 'large')) {
                return 'ai21.jamba-1-5-large-v1:0';
            }
            if (str_contains($model, '1.5') && str_contains($model, 'mini')) {
                return 'ai21.jamba-1-5-mini-v1:0';
            }

            return 'ai21.jamba-instruct-v1:0';
        }

        if (str_contains($model, 'jurassic')) {
            if (str_contains($model, 'ultra')) {
                return 'ai21.j2-ultra-v1';
            }
            if (str_contains($model, 'mid')) {
                return 'ai21.j2-mid-v1';
            }
        }

        return null;
    }
}
