<?php

namespace Ubxty\BedrockAi\Commands;

use Ubxty\BedrockAi\BedrockManager;
use Ubxty\CoreAi\Commands\AbstractConfigureCommand;

/**
 * Bedrock IAM/bearer credential wizard.
 *
 * Extends core-ai's {@see AbstractConfigureCommand} to inherit the
 * configure-command flow (env-prefix discovery, key validation, .env
 * write, config:clear invocation). Bedrock only supplies the platform
 * name, env-prefix, and required-env-keys shape.
 */
class ConfigureCommand extends AbstractConfigureCommand
{
    protected $signature = 'bedrock:configure {--connection=default : Connection name}';

    protected $description = 'Configure AWS Bedrock credentials';

    public function handle(BedrockManager $manager): int
    {
        $this->manager = $manager;

        return $this->executeConfigure();
    }

    protected function platformName(): string
    {
        return 'AWS Bedrock';
    }

    protected function envPrefix(): string
    {
        return 'BEDROCK';
    }

    /** @return array<string, string> */
    protected function requiredEnvKeys(): array
    {
        return [
            'AWS_REGION' => 'AWS region (e.g. us-east-1)',
            'AWS_ACCESS_KEY_ID' => 'AWS access key ID',
            'AWS_SECRET_ACCESS_KEY' => 'AWS secret access key',
        ];
    }
}