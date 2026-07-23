<?php

namespace Ubxty\BedrockAi\Commands;

use Ubxty\BedrockAi\BedrockManager;
use Ubxty\CoreAi\Commands\AbstractTestCommand;

class TestCommand extends AbstractTestCommand
{
    protected $signature = 'bedrock:test
                            {model? : Model ID to test}
                            {--connection= : Connection name}
                            {--all-keys : Test all configured keys}';

    protected $description = 'Test connection and invoke a Bedrock model';

    public function handle(BedrockManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeTest();
    }

    protected function platformName(): string
    {
        return 'AWS Bedrock';
    }
}