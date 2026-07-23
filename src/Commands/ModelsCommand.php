<?php

namespace Ubxty\BedrockAi\Commands;

use Ubxty\BedrockAi\BedrockManager;
use Ubxty\CoreAi\Commands\AbstractModelsCommand;

class ModelsCommand extends AbstractModelsCommand
{
    protected $signature = 'bedrock:models {--connection= : Connection name}';

    protected $description = 'List available AWS Bedrock models';

    public function handle(BedrockManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeModels();
    }

    protected function platformName(): string
    {
        return 'AWS Bedrock';
    }
}