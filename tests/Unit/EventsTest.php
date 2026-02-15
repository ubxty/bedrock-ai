<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Events\BedrockInvoked;
use Ubxty\BedrockAi\Events\BedrockKeyRotated;
use Ubxty\BedrockAi\Events\BedrockRateLimited;

class EventsTest extends TestCase
{
    public function testBedrockInvokedEventProperties(): void
    {
        $event = new BedrockInvoked(
            modelId: 'anthropic.claude-v2',
            inputTokens: 100,
            outputTokens: 50,
            cost: 0.01,
            latencyMs: 500,
            keyUsed: 'Primary',
            connection: 'default'
        );

        $this->assertSame('anthropic.claude-v2', $event->modelId);
        $this->assertSame(100, $event->inputTokens);
        $this->assertSame(50, $event->outputTokens);
        $this->assertSame(0.01, $event->cost);
        $this->assertSame(500, $event->latencyMs);
        $this->assertSame('Primary', $event->keyUsed);
        $this->assertSame('default', $event->connection);
    }

    public function testBedrockInvokedEventNullConnection(): void
    {
        $event = new BedrockInvoked(
            modelId: 'test',
            inputTokens: 0,
            outputTokens: 0,
            cost: 0,
            latencyMs: 0,
            keyUsed: 'test'
        );

        $this->assertNull($event->connection);
    }

    public function testBedrockRateLimitedEventProperties(): void
    {
        $event = new BedrockRateLimited(
            modelId: 'anthropic.claude-v2',
            keyLabel: 'Primary',
            retryAttempt: 2,
            waitSeconds: 4
        );

        $this->assertSame('anthropic.claude-v2', $event->modelId);
        $this->assertSame('Primary', $event->keyLabel);
        $this->assertSame(2, $event->retryAttempt);
        $this->assertSame(4, $event->waitSeconds);
    }

    public function testBedrockKeyRotatedEventProperties(): void
    {
        $event = new BedrockKeyRotated(
            fromKeyLabel: 'Primary',
            toKeyLabel: 'Secondary',
            reason: 'Rate limited',
            modelId: 'anthropic.claude-v2'
        );

        $this->assertSame('Primary', $event->fromKeyLabel);
        $this->assertSame('Secondary', $event->toKeyLabel);
        $this->assertSame('Rate limited', $event->reason);
        $this->assertSame('anthropic.claude-v2', $event->modelId);
    }
}
