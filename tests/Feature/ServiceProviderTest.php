<?php

namespace Ubxty\BedrockAi\Tests\Feature;

use Ubxty\BedrockAi\BedrockManager;
use Ubxty\BedrockAi\Facades\Bedrock;
use Ubxty\BedrockAi\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function testBedrockManagerIsBoundAsSingleton(): void
    {
        $instance1 = $this->app->make(BedrockManager::class);
        $instance2 = $this->app->make(BedrockManager::class);

        $this->assertSame($instance1, $instance2);
    }

    public function testBedrockAliasIsBound(): void
    {
        $instance = $this->app->make('bedrock');

        $this->assertInstanceOf(BedrockManager::class, $instance);
    }

    public function testConfigIsMerged(): void
    {
        $config = config('bedrock');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('default', $config);
        $this->assertArrayHasKey('connections', $config);
        $this->assertArrayHasKey('retry', $config);
        $this->assertArrayHasKey('aliases', $config);
        $this->assertArrayHasKey('logging', $config);
        $this->assertArrayHasKey('health_check', $config);
    }

    public function testFacadeResolves(): void
    {
        $this->assertInstanceOf(BedrockManager::class, Bedrock::getFacadeRoot());
    }

    public function testFacadeIsConfiguredWorks(): void
    {
        $this->assertTrue(Bedrock::isConfigured());
    }

    public function testFacadeResolveAliasWorks(): void
    {
        $this->assertSame(
            'anthropic.claude-sonnet-4-20250514-v1:0',
            Bedrock::resolveAlias('claude')
        );
    }

    public function testFacadeAliasesReturnsResolver(): void
    {
        $resolver = Bedrock::aliases();

        $this->assertSame('anthropic.claude-sonnet-4-20250514-v1:0', $resolver->resolve('claude'));
        $this->assertSame('anthropic.claude-3-5-haiku-20241022-v1:0', $resolver->resolve('haiku'));
    }

    public function testFacadeGetConfigReturnsConfig(): void
    {
        $config = Bedrock::getConfig();

        $this->assertSame('default', $config['default']);
    }
}
