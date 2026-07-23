<?php

namespace Ubxty\BedrockAi\Commands;

use Ubxty\BedrockAi\BedrockManager;
use Ubxty\CoreAi\Commands\AbstractChatCommand;

/**
 * Interactive chat session against an AWS Bedrock model.
 *
 * Extends core-ai's {@see AbstractChatCommand} to inherit the multi-turn
 * loop, model picker, file commands, streaming toggle, paste spooling,
 * session token/cost tally, and `/help`/`/quit`/`/stats`/`/reset`/
 * `/system`/`/model`/`/temp`/`/cache`/`/image`/`/doc` command surface.
 *
 * Bedrock overrides the platform-specific hooks: manager injection,
 * platform name, cache badge / prompt / config.
 */
class ChatCommand extends AbstractChatCommand
{
    protected $signature = 'bedrock:chat
                            {model? : Model ID or alias to chat with}
                            {--connection= : Connection name}
                            {--system= : System prompt for the conversation}
                            {--max-tokens=4096 : Max tokens per response}
                            {--temperature=0.7 : Temperature for responses}
                            {--no-stream : Disable streaming (wait for full response)}';

    protected $description = 'Start an interactive chat session with a Bedrock model';

    public function handle(BedrockManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeChat();
    }

    protected function platformName(): string
    {
        return 'AWS Bedrock';
    }

    protected function modelSupportsCaching(string $modelId): bool
    {
        return $this->manager->modelSupportsCaching($modelId);
    }

    protected function cachingBadge(string $modelId): string
    {
        return $this->modelSupportsCaching($modelId) ? ' <fg=magenta>[cached]</>' : '';
    }

    protected function shouldPromptForCacheMode(string $modelId): bool
    {
        return $this->modelSupportsCaching($modelId)
            && $this->manager->packageCachePointsConfigured();
    }

    protected function packageCachingEnabled(): bool
    {
        return $this->manager->packageCachePointsConfigured();
    }

    protected function cachePointsFor(bool $cachingEnabled): ?array
    {
        return $cachingEnabled ? $this->manager->configuredCachePoints() : [];
    }
}