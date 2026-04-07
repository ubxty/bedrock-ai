<?php

namespace Ubxty\BedrockAi\Models;

class ModelSpecResolver
{
    /**
     * Resolve model specifications (context window, max tokens) based on model ID patterns.
     *
     * @return array{context_window: int, max_tokens: int}
     */
    public static function resolve(string $modelId): array
    {
        $specs = ['context_window' => 128000, 'max_tokens' => 4096];

        // Claude 3+ family
        if (str_contains($modelId, 'claude-3')) {
            $specs['context_window'] = 200000;
            $specs['max_tokens'] = str_contains($modelId, 'claude-3-5-sonnet-20241022-v2') ? 8192 : 4096;

            return $specs;
        }

        // Claude 4 family
        if (str_contains($modelId, 'claude-sonnet-4') || str_contains($modelId, 'claude-opus-4') || str_contains($modelId, 'claude-haiku-4')) {
            $specs['context_window'] = 200000;
            $specs['max_tokens'] = 16384;

            return $specs;
        }

        // Claude 2.x
        if (str_contains($modelId, 'claude-v2:1')) {
            return ['context_window' => 200000, 'max_tokens' => 4096];
        }
        if (str_contains($modelId, 'claude-v2')) {
            return ['context_window' => 100000, 'max_tokens' => 4096];
        }
        if (str_contains($modelId, 'claude-instant')) {
            return ['context_window' => 100000, 'max_tokens' => 4096];
        }

        // Amazon Titan
        if (str_contains($modelId, 'titan-text-express')) {
            return ['context_window' => 8192, 'max_tokens' => 8192];
        }
        if (str_contains($modelId, 'titan-text-lite')) {
            return ['context_window' => 4096, 'max_tokens' => 4096];
        }
        if (str_contains($modelId, 'titan-text-premier')) {
            return ['context_window' => 32768, 'max_tokens' => 8192];
        }

        // Amazon Nova
        if (str_contains($modelId, 'nova-pro')) {
            return ['context_window' => 300000, 'max_tokens' => 5120];
        }
        if (str_contains($modelId, 'nova-lite')) {
            return ['context_window' => 300000, 'max_tokens' => 5120];
        }
        if (str_contains($modelId, 'nova-micro')) {
            return ['context_window' => 128000, 'max_tokens' => 5120];
        }

        // Llama 3.x
        if (str_contains($modelId, 'llama4')) {
            return ['context_window' => 256000, 'max_tokens' => 4096];
        }
        if (str_contains($modelId, 'llama3-3')) {
            return ['context_window' => 128000, 'max_tokens' => 4096];
        }
        if (str_contains($modelId, 'llama3')) {
            return ['context_window' => 128000, 'max_tokens' => 2048];
        }

        // Mistral
        if (str_contains($modelId, 'mistral-large')) {
            return ['context_window' => 128000, 'max_tokens' => 8192];
        }
        if (str_contains($modelId, 'mixtral')) {
            return ['context_window' => 32000, 'max_tokens' => 4096];
        }
        if (str_contains($modelId, 'mistral')) {
            return ['context_window' => 32000, 'max_tokens' => 4096];
        }

        // Cohere
        if (str_contains($modelId, 'command-r-plus')) {
            return ['context_window' => 128000, 'max_tokens' => 4096];
        }
        if (str_contains($modelId, 'command-r')) {
            return ['context_window' => 128000, 'max_tokens' => 4096];
        }

        // AI21 Jamba
        if (str_contains($modelId, 'jamba')) {
            return ['context_window' => 256000, 'max_tokens' => 4096];
        }

        return $specs;
    }

    /**
     * Resolve the known input modalities for a model based on its ID.
     *
     * @return array<int, string>  e.g. ['text'], ['text', 'image', 'document']
     */
    public static function inputModalities(string $modelId): array
    {
        // Claude 3+ and Claude 4 support text, image, and document
        if (str_contains($modelId, 'claude-3') || str_contains($modelId, 'claude-sonnet-4')
            || str_contains($modelId, 'claude-opus-4') || str_contains($modelId, 'claude-haiku-4')) {
            return ['text', 'image', 'document'];
        }

        // Claude 2.x / Instant — text only
        if (str_contains($modelId, 'claude-v2') || str_contains($modelId, 'claude-instant')) {
            return ['text'];
        }

        // Amazon Nova Pro & Lite — text, image, document
        if (str_contains($modelId, 'nova-pro') || str_contains($modelId, 'nova-lite')) {
            return ['text', 'image', 'document'];
        }

        // Amazon Nova Micro — text only
        if (str_contains($modelId, 'nova-micro')) {
            return ['text'];
        }

        // Amazon Titan — text only
        if (str_contains($modelId, 'titan')) {
            return ['text'];
        }

        // Llama 4 Scout/Maverick — text, image
        if (str_contains($modelId, 'llama4')) {
            return ['text', 'image'];
        }

        // Llama 3.x — text only
        if (str_contains($modelId, 'llama3') || str_contains($modelId, 'llama-3')) {
            return ['text'];
        }

        // Mistral / Mixtral — text only
        if (str_contains($modelId, 'mistral') || str_contains($modelId, 'mixtral')) {
            return ['text'];
        }

        // Cohere — text only
        if (str_contains($modelId, 'command-r')) {
            return ['text'];
        }

        // AI21 Jamba — text only
        if (str_contains($modelId, 'jamba')) {
            return ['text'];
        }

        // Unknown model — assume text only (safe default)
        return ['text'];
    }

    /**
     * Check if a model supports a given input modality.
     */
    public static function supportsModality(string $modelId, string $modality): bool
    {
        return in_array($modality, self::inputModalities($modelId), true);
    }

    /**
     * Get all known model families and their base specs.
     *
     * @return array<string, array{name: string, context_window: int, max_tokens: int}>
     */
    public static function families(): array
    {
        return [
            'claude-3.5' => ['name' => 'Claude 3.5', 'context_window' => 200000, 'max_tokens' => 8192],
            'claude-4' => ['name' => 'Claude 4', 'context_window' => 200000, 'max_tokens' => 16384],
            'nova' => ['name' => 'Amazon Nova', 'context_window' => 300000, 'max_tokens' => 5120],
            'titan' => ['name' => 'Amazon Titan', 'context_window' => 8192, 'max_tokens' => 8192],
            'llama-3' => ['name' => 'Meta Llama 3', 'context_window' => 128000, 'max_tokens' => 4096],
            'llama-4' => ['name' => 'Meta Llama 4', 'context_window' => 256000, 'max_tokens' => 4096],
            'mistral' => ['name' => 'Mistral', 'context_window' => 128000, 'max_tokens' => 8192],
            'cohere' => ['name' => 'Cohere Command', 'context_window' => 128000, 'max_tokens' => 4096],
            'jamba' => ['name' => 'AI21 Jamba', 'context_window' => 256000, 'max_tokens' => 4096],
        ];
    }
}
