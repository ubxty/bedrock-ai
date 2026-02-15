<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Logging\InvocationLogger;

class InvocationLoggerTest extends TestCase
{
    public function testDisabledLoggerDoesNothing(): void
    {
        $logger = new InvocationLogger(false, 'stack');

        // Should not throw or error
        $logger->log([
            'model_id' => 'test',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'cost' => 0.01,
            'latency_ms' => 500,
            'status' => 'success',
            'key_used' => 'Primary',
        ]);

        $logger->logError('test-model', 'Some error', 'Key1');
        $logger->logRateLimit('test-model', 'Key1', 1, 5);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testIsEnabledReturnsCorrectly(): void
    {
        $enabled = new InvocationLogger(true);
        $disabled = new InvocationLogger(false);

        $this->assertTrue($enabled->isEnabled());
        $this->assertFalse($disabled->isEnabled());
    }

    public function testGetChannelReturnsConfiguredChannel(): void
    {
        $logger = new InvocationLogger(true, 'bedrock');

        $this->assertSame('bedrock', $logger->getChannel());
    }

    public function testDefaultChannelIsStack(): void
    {
        $logger = new InvocationLogger(true);

        $this->assertSame('stack', $logger->getChannel());
    }
}
