<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Exceptions\BedrockException;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;
use Ubxty\BedrockAi\Exceptions\CostLimitExceededException;
use Ubxty\BedrockAi\Exceptions\RateLimitException;

class ExceptionsTest extends TestCase
{
    public function testBedrockExceptionStoresModelId(): void
    {
        $e = new BedrockException('Test error', 0, null, 'anthropic.claude-v2');

        $this->assertSame('anthropic.claude-v2', $e->getModelId());
        $this->assertSame('Test error', $e->getMessage());
    }

    public function testBedrockExceptionStoresKeyLabel(): void
    {
        $e = new BedrockException('Test error', 0, null, 'model-id', 'Primary');

        $this->assertSame('Primary', $e->getKeyLabel());
    }

    public function testBedrockExceptionAcceptsNullModelAndKey(): void
    {
        $e = new BedrockException('Error');

        $this->assertNull($e->getModelId());
        $this->assertNull($e->getKeyLabel());
    }

    public function testRateLimitExceptionExtendsBedrock(): void
    {
        $e = new RateLimitException('Rate limited', 429, null, 'model-id', 'Key1');

        $this->assertInstanceOf(BedrockException::class, $e);
        $this->assertSame(429, $e->getCode());
        $this->assertSame('model-id', $e->getModelId());
        $this->assertSame('Key1', $e->getKeyLabel());
    }

    public function testConfigurationExceptionExtendsBedrock(): void
    {
        $e = new ConfigurationException('Bad config');

        $this->assertInstanceOf(BedrockException::class, $e);
        $this->assertSame('Bad config', $e->getMessage());
    }

    public function testCostLimitExceededException(): void
    {
        $e = new CostLimitExceededException('daily', 10.00, 12.50);

        $this->assertSame('daily', $e->getLimitType());
        $this->assertSame(10.00, $e->getLimit());
        $this->assertSame(12.50, $e->getCurrentSpend());
        $this->assertStringContainsString('Daily cost limit', $e->getMessage());
        $this->assertStringContainsString('$10', $e->getMessage());
        $this->assertStringContainsString('$12.5', $e->getMessage());
    }

    public function testCostLimitExceededMonthly(): void
    {
        $e = new CostLimitExceededException('monthly', 300.00, 305.25);

        $this->assertSame('monthly', $e->getLimitType());
        $this->assertSame(300.00, $e->getLimit());
        $this->assertStringContainsString('Monthly cost limit', $e->getMessage());
    }
}
