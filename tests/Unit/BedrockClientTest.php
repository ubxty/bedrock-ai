<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Client\BedrockClient;

class BedrockClientTest extends TestCase
{
    // ─── extractUserFriendlyError ────────────────────────────

    public function testExtractInvalidModelError(): void
    {
        $error = 'Bedrock HTTP Error: 400 - {"message":"model identifier is invalid for this request"}';

        $this->assertSame(
            'Invalid model: This model ID is not valid for Bedrock.',
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractNoOnDemandThroughputError(): void
    {
        $error = "Bedrock HTTP Error: 400 - {\"message\":\"The model doesn't support on-demand throughput\"}";

        $this->assertSame(
            'Model unavailable: This model requires provisioned throughput.',
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractNotSupportedError(): void
    {
        $error = "Bedrock HTTP Error: 400 - {\"message\":\"This model isn't supported in this region\"}";

        $this->assertSame(
            'Model unavailable: This model requires an inference profile.',
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractMalformedInputError(): void
    {
        $error = 'Bedrock HTTP Error: 400 - {"message":"Malformed input request: invalid body"}';

        $this->assertSame(
            'Request error: This model may not support text chat.',
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractEndOfLifeError(): void
    {
        $error = 'Bedrock HTTP Error: 400 - {"message":"This model has reached end of its life"}';

        $this->assertSame(
            'Model deprecated: This model version has been retired.',
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractAccessDeniedError(): void
    {
        $error = 'Bedrock HTTP Error: 403 - {"message":"AccessDeniedException: not authorized"}';

        $this->assertSame(
            "Access denied: You don't have permission to use this model.",
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractValidationExceptionFromSdk(): void
    {
        $error = 'ValidationException: The provided model identifier is not valid';

        $this->assertSame(
            'Validation error: The request was not valid for this model.',
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractResourceNotFoundException(): void
    {
        $error = 'ResourceNotFoundException: Model not found';

        $this->assertSame(
            'Model not found: The requested model does not exist in this region.',
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractGenericError(): void
    {
        $error = 'Some completely unexpected error occurred';

        $this->assertSame(
            'AI service error. Please try again.',
            BedrockClient::extractUserFriendlyError($error)
        );
    }

    public function testExtractHttpErrorWithNonJsonBody(): void
    {
        $error = 'Bedrock HTTP Error: 500 - Internal Server Error';

        $this->assertStringContainsString('Bedrock error (500)', BedrockClient::extractUserFriendlyError($error));
    }

    // ─── calculateCost via Reflection ────────────────────────

    public function testCalculateCostWithDefaultPricing(): void
    {
        $client = $this->createClientWithMockCredentials();

        $method = new \ReflectionMethod($client, 'calculateCost');
        $method->setAccessible(true);

        // 1000 input tokens, 500 output tokens
        // input: 1000/1000 * 0.003 = 0.003
        // output: 500/1000 * 0.015 = 0.0075
        // total: 0.0105
        $cost = $method->invoke($client, 1000, 500, null);

        $this->assertSame(0.0105, $cost);
    }

    public function testCalculateCostWithCustomPricing(): void
    {
        $client = $this->createClientWithMockCredentials();

        $method = new \ReflectionMethod($client, 'calculateCost');
        $method->setAccessible(true);

        $cost = $method->invoke($client, 2000, 1000, [
            'input_price_per_1k' => 0.001,
            'output_price_per_1k' => 0.002,
        ]);

        // input: 2000/1000 * 0.001 = 0.002
        // output: 1000/1000 * 0.002 = 0.002
        // total: 0.004
        $this->assertSame(0.004, $cost);
    }

    // ─── isRateLimitError via Reflection ─────────────────────

    public function testIsRateLimitErrorDetects429(): void
    {
        $client = $this->createClientWithMockCredentials();
        $method = new \ReflectionMethod($client, 'isRateLimitError');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($client, '429 Too many requests'));
        $this->assertTrue($method->invoke($client, 'Error: ThrottlingException'));
        $this->assertTrue($method->invoke($client, 'You hit the rate limit'));
        $this->assertFalse($method->invoke($client, 'ValidationException'));
    }

    /**
     * Create a BedrockClient with mock credentials for testing protected methods.
     */
    protected function createClientWithMockCredentials(): BedrockClient
    {
        $credentials = new \Ubxty\BedrockAi\Client\CredentialManager([
            [
                'label' => 'Test',
                'aws_key' => 'AKIAIOSFODNN7EXAMPLE',
                'aws_secret' => 'wJalrXUtnFEMI/K7MDENG/bPx',
                'region' => 'us-east-1',
            ],
        ]);

        return new BedrockClient($credentials, 2, 1);
    }
}
