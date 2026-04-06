<?php

namespace Ubxty\BedrockAi\Facades;

use Illuminate\Support\Facades\Facade;
use Ubxty\BedrockAi\BedrockManager;

/**
 * @method static \Ubxty\BedrockAi\Client\BedrockClient client(?string $connection = null)
 * @method static array invoke(string $modelId, string $systemPrompt, string $userMessage, int $maxTokens = 4096, float $temperature = 0.7, ?array $pricing = null)
 * @method static array testConnection(?string $connection = null)
 * @method static array listModels(?string $connection = null)
 * @method static array fetchModels(?string $connection = null)
 * @method static \Ubxty\BedrockAi\Pricing\PricingService pricing()
 * @method static \Ubxty\BedrockAi\Usage\UsageTracker usage()
 * @method static bool isConfigured(?string $connection = null)
 * @method static array getConfig()
 * @method static \Ubxty\BedrockAi\Client\ConverseClient converseClient(?string $connection = null)
 * @method static array converse(string $modelId, array $messages, string $systemPrompt = '', int $maxTokens = 4096, float $temperature = 0.7)
 * @method static \Ubxty\BedrockAi\Client\StreamingClient streamingClient(?string $connection = null)
 * @method static array stream(string $modelId, string $systemPrompt, string $userMessage, callable $onChunk, int $maxTokens = 4096, float $temperature = 0.7)
 * @method static \Ubxty\BedrockAi\Conversation\ConversationBuilder conversation(string $modelId)
 * @method static \Ubxty\BedrockAi\Client\ModelAliasResolver aliases()
 * @method static string resolveAlias(string $modelIdOrAlias)
 * @method static \Ubxty\BedrockAi\Logging\InvocationLogger getLogger()
 * @method static bool isBearerMode(?string $connection = null)
 * @method static int syncModels(?string $connection = null)
 * @method static array<string, array> getModelsGrouped(?string $connection = null)
 * @method static string defaultModel()
 * @method static string defaultImageModel()
 *
 * @see \Ubxty\BedrockAi\BedrockManager
 */
class Bedrock extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BedrockManager::class;
    }
}
