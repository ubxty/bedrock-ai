<?php

namespace Ubxty\BedrockAi;

/**
 * Constants for all AWS Bedrock model providers.
 *
 * Use these in your bedrock.php config to avoid typos with space-containing names:
 *
 *   use Ubxty\BedrockAi\Providers;
 *
 *   'disabled_providers' => [Providers::COHERE, Providers::WRITER, Providers::AI21_LABS],
 */
final class Providers
{
    public const AI21_LABS    = 'AI21 Labs';
    public const AMAZON       = 'Amazon';
    public const ANTHROPIC    = 'Anthropic';
    public const COHERE       = 'Cohere';
    public const DEEPSEEK     = 'DeepSeek';
    public const GOOGLE       = 'Google';
    public const META         = 'Meta';
    public const MINIMAX      = 'MiniMax';
    public const MISTRAL_AI   = 'Mistral AI';
    public const MOONSHOT_AI  = 'Moonshot AI';
    public const NVIDIA       = 'NVIDIA';
    public const OPENAI       = 'OpenAI';
    public const QWEN         = 'Qwen';
    public const STABILITY_AI = 'Stability AI';
    public const TWELVELABS   = 'TwelveLabs';
    public const WRITER       = 'Writer';
    public const ZAI          = 'Z.AI';
}
