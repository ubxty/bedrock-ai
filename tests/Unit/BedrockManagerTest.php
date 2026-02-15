<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\BedrockManager;
use Ubxty\BedrockAi\Client\BedrockClient;
use Ubxty\BedrockAi\Client\ConverseClient;
use Ubxty\BedrockAi\Client\ModelAliasResolver;
use Ubxty\BedrockAi\Client\StreamingClient;
use Ubxty\BedrockAi\Conversation\ConversationBuilder;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;
use Ubxty\BedrockAi\Logging\InvocationLogger;

class BedrockManagerTest extends TestCase
{
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
                            'aws_secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCY',
                            'region' => 'us-east-1',
                        ],
                    ],
                ],
                'secondary' => [
                    'keys' => [
                        [
                            'label' => 'SecondaryKey',
                            'aws_key' => 'AKIAI44QH8DHBEXAMPLE',
                            'aws_secret' => 'je7MtGbClwBF/2Zp9Utk/h3yCo8nv',
                            'region' => 'us-west-2',
                        ],
                    ],
                ],
                'empty' => [
                    'keys' => [],
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
                'claude' => 'anthropic.claude-sonnet-4-v1:0',
                'haiku' => 'anthropic.claude-3-5-haiku-v1:0',
            ],
            'logging' => [
                'enabled' => false,
                'channel' => 'stack',
            ],
            'health_check' => [
                'enabled' => false,
            ],
        ];
    }

    public function testClientReturnsBedrockClientForDefaultConnection(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $client = $manager->client();

        $this->assertInstanceOf(BedrockClient::class, $client);
    }

    public function testClientReturnsBedrockClientForSpecificConnection(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $client = $manager->client('secondary');

        $this->assertInstanceOf(BedrockClient::class, $client);
    }

    public function testClientThrowsForMissingConnection(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Bedrock connection [nonexistent] is not configured.');

        $manager->client('nonexistent');
    }

    public function testClientThrowsForEmptyKeys(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No AWS keys configured for connection [empty].');

        $manager->client('empty');
    }

    public function testConverseClientReturnsConverseClient(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $client = $manager->converseClient();

        $this->assertInstanceOf(ConverseClient::class, $client);
    }

    public function testConverseClientThrowsForMissingConnection(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->expectException(ConfigurationException::class);

        $manager->converseClient('nonexistent');
    }

    public function testStreamingClientReturnsStreamingClient(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $client = $manager->streamingClient();

        $this->assertInstanceOf(StreamingClient::class, $client);
    }

    public function testStreamingClientThrowsForMissingConnection(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->expectException(ConfigurationException::class);

        $manager->streamingClient('nonexistent');
    }

    public function testConversationReturnsConversationBuilder(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $builder = $manager->conversation('claude');

        $this->assertInstanceOf(ConversationBuilder::class, $builder);
        // Alias should be resolved
        $this->assertSame('anthropic.claude-sonnet-4-v1:0', $builder->getModelId());
    }

    public function testAliasesReturnsModelAliasResolver(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $resolver = $manager->aliases();

        $this->assertInstanceOf(ModelAliasResolver::class, $resolver);
        $this->assertSame('anthropic.claude-sonnet-4-v1:0', $resolver->resolve('claude'));
    }

    public function testResolveAliasResolvesConfiguredAlias(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->assertSame('anthropic.claude-sonnet-4-v1:0', $manager->resolveAlias('claude'));
        $this->assertSame('anthropic.claude-3-5-haiku-v1:0', $manager->resolveAlias('haiku'));
    }

    public function testResolveAliasPassesThroughUnknownAlias(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->assertSame('anthropic.claude-v2', $manager->resolveAlias('anthropic.claude-v2'));
    }

    public function testGetLoggerReturnsInvocationLogger(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $logger = $manager->getLogger();

        $this->assertInstanceOf(InvocationLogger::class, $logger);
        $this->assertFalse($logger->isEnabled());
    }

    public function testIsConfiguredReturnsTrueForValidConnection(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->assertTrue($manager->isConfigured());
        $this->assertTrue($manager->isConfigured('secondary'));
    }

    public function testIsConfiguredReturnsFalseForMissingConnection(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->assertFalse($manager->isConfigured('nonexistent'));
    }

    public function testIsConfiguredReturnsFalseForEmptyKeys(): void
    {
        $manager = new BedrockManager($this->getTestConfig());

        $this->assertFalse($manager->isConfigured('empty'));
    }

    public function testIsConfiguredReturnsFalseForEmptyCredentials(): void
    {
        $config = $this->getTestConfig();
        $config['connections']['default']['keys'] = [
            ['label' => 'Empty', 'aws_key' => '', 'aws_secret' => '', 'region' => 'us-east-1'],
        ];

        $manager = new BedrockManager($config);

        $this->assertFalse($manager->isConfigured());
    }

    public function testGetConfigReturnsFullConfig(): void
    {
        $config = $this->getTestConfig();
        $manager = new BedrockManager($config);

        $this->assertSame($config, $manager->getConfig());
    }
}
