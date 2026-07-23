<?php

namespace Ubxty\BedrockAi\Commands;

use Ubxty\BedrockAi\BedrockManager;
use Ubxty\CoreAi\Commands\AbstractDefaultModelCommand;

/**
 * Default chat + image model picker for AWS Bedrock.
 *
 * Extends core-ai's {@see AbstractDefaultModelCommand} to inherit the
 * provider→model picker flow. Bedrock supplies the env-key map for
 * chat and image models.
 */
class DefaultModelCommand extends AbstractDefaultModelCommand
{
    protected $signature = 'bedrock:default-model {--connection= : Connection name}';

    protected $description = 'Set the default chat and image Bedrock models';

    public function handle(BedrockManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeDefaultModel();
    }

    protected function platformName(): string
    {
        return 'AWS Bedrock';
    }

    /** @return array<string, string> */
    protected function envKeyMap(): array
    {
        return [
            'default' => 'BEDROCK_DEFAULT_MODEL',
            'image' => 'BEDROCK_DEFAULT_IMAGE_MODEL',
        ];
    }
}