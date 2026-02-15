<?php

namespace Ubxty\BedrockAi\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Ubxty\BedrockAi\BedrockAiServiceProvider;
use Ubxty\BedrockAi\Facades\Bedrock;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            BedrockAiServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Bedrock' => Bedrock::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('bedrock', $this->getTestConfig());
    }

    protected function getTestConfig(): array
    {
        return [
            'default' => 'default',
            'connections' => [
                'default' => [
                    'keys' => [
                        [
                            'label' => 'TestKey1',
                            'aws_key' => 'AKIAIOSFODNN7EXAMPLE',
                            'aws_secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                            'region' => 'us-east-1',
                        ],
                        [
                            'label' => 'TestKey2',
                            'aws_key' => 'AKIAI44QH8DHBEXAMPLE',
                            'aws_secret' => 'je7MtGbClwBF/2Zp9Utk/h3yCo8nvbEXAMPLEKEY',
                            'region' => 'eu-west-1',
                        ],
                    ],
                ],
                'secondary' => [
                    'keys' => [
                        [
                            'label' => 'SecondaryKey',
                            'aws_key' => 'AKIAI77QH8DHBEXAMPLE',
                            'aws_secret' => 'ab7MtGbClwBF/2Zp9Utk/h3yCo8nvbEXAMPLEKEY',
                            'region' => 'us-west-2',
                        ],
                    ],
                ],
            ],
            'retry' => [
                'max_retries' => 2,
                'base_delay' => 1,
            ],
            'limits' => [
                'daily' => 10.00,
                'monthly' => 100.00,
            ],
            'pricing' => [
                'aws_key' => '',
                'aws_secret' => '',
            ],
            'usage' => [
                'aws_key' => '',
                'aws_secret' => '',
                'region' => 'us-east-1',
            ],
            'cache' => [
                'pricing_ttl' => 86400,
                'usage_ttl' => 900,
                'models_ttl' => 3600,
            ],
            'defaults' => [
                'max_tokens' => 4096,
                'temperature' => 0.7,
                'anthropic_version' => 'bedrock-2023-05-31',
            ],
            'aliases' => [
                'claude' => 'anthropic.claude-sonnet-4-20250514-v1:0',
                'haiku' => 'anthropic.claude-3-5-haiku-20241022-v1:0',
                'nova' => 'amazon.nova-pro-v1:0',
            ],
            'logging' => [
                'enabled' => false,
                'channel' => 'stack',
            ],
            'health_check' => [
                'enabled' => false,
                'path' => '/health/bedrock',
                'middleware' => [],
            ],
        ];
    }
}
