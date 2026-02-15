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

    // ─── buildClaudeBody / buildTitanBody via Reflection ─────

    public function testBuildClaudeBodyStructure(): void
    {
        $client = $this->createClientWithMockCredentials();

        $method = new \ReflectionMethod($client, 'buildClaudeBody');
        $method->setAccessible(true);

        $body = $method->invoke($client, 'You are a doctor.', 'Hello!', 2048, 0.5);

        $this->assertSame('bedrock-2023-05-31', $body['anthropic_version']);
        $this->assertSame(2048, $body['max_tokens']);
        $this->assertSame(0.5, $body['temperature']);
        $this->assertSame('You are a doctor.', $body['system']);
        $this->assertCount(1, $body['messages']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertSame('Hello!', $body['messages'][0]['content']);
    }

    public function testBuildTitanBodyStructure(): void
    {
        $client = $this->createClientWithMockCredentials();

        $method = new \ReflectionMethod($client, 'buildTitanBody');
        $method->setAccessible(true);

        $body = $method->invoke($client, 'Hello Titan', 1024, 0.3);

        $this->assertSame('Hello Titan', $body['inputText']);
        $this->assertSame(1024, $body['textGenerationConfig']['maxTokenCount']);
        $this->assertSame(0.3, $body['textGenerationConfig']['temperature']);
    }

    // ─── parseResponse via Reflection ────────────────────────

    public function testParseClaudeResponse(): void
    {
        $client = $this->createClientWithMockCredentials();

        $method = new \ReflectionMethod($client, 'parseResponse');
        $method->setAccessible(true);

        $responseBody = [
            'content' => [['text' => 'Hello, I am Claude.']],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
        ];

        $result = $method->invoke($client, $responseBody, 'anthropic.claude-v2', microtime(true), null);

        $this->assertSame('Hello, I am Claude.', $result['response']);
        $this->assertSame(50, $result['input_tokens']);
        $this->assertSame(20, $result['output_tokens']);
        $this->assertSame(70, $result['total_tokens']);
        $this->assertSame('success', $result['status']);
    }

    public function testParseTitanResponse(): void
    {
        $client = $this->createClientWithMockCredentials();

        $method = new \ReflectionMethod($client, 'parseResponse');
        $method->setAccessible(true);

        $responseBody = [
            'inputTextTokenCount' => 30,
            'results' => [['outputText' => 'Titan response', 'tokenCount' => 15]],
        ];

        $result = $method->invoke($client, $responseBody, 'amazon.titan-text-express-v1', microtime(true), null);

        $this->assertSame('Titan response', $result['response']);
        $this->assertSame(30, $result['input_tokens']);
        $this->assertSame(15, $result['output_tokens']);
        $this->assertSame(45, $result['total_tokens']);
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
