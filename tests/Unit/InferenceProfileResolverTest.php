<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Client\InferenceProfileResolver;

class InferenceProfileResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset static state by reflecting
        $reflection = new \ReflectionClass(InferenceProfileResolver::class);
        $property = $reflection->getProperty('inferenceProfilePatterns');
        $property->setAccessible(true);
        $property->setValue(null, [
            'anthropic.claude-3-5-',
            'anthropic.claude-3-7-',
            'anthropic.claude-sonnet-4',
            'anthropic.claude-opus-4',
            'anthropic.claude-haiku-4',
            'amazon.nova-',
            'meta.llama3-3',
            'meta.llama4',
        ]);

        parent::tearDown();
    }

    public function testResolvesClaude35WithUsPrefix(): void
    {
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-3-5-sonnet-20241022-v2:0', 'us-east-1');

        $this->assertSame('us.anthropic.claude-3-5-sonnet-20241022-v2:0', $resolved);
    }

    public function testResolvesClaude35WithEuPrefix(): void
    {
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-3-5-sonnet-20241022-v2:0', 'eu-west-1');

        $this->assertSame('eu.anthropic.claude-3-5-sonnet-20241022-v2:0', $resolved);
    }

    public function testResolvesClaude37(): void
    {
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-3-7-sonnet-20250219-v1:0', 'us-east-1');

        $this->assertSame('us.anthropic.claude-3-7-sonnet-20250219-v1:0', $resolved);
    }

    public function testResolvesSonnet4(): void
    {
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-sonnet-4-20250514-v1:0', 'us-east-1');

        $this->assertSame('us.anthropic.claude-sonnet-4-20250514-v1:0', $resolved);
    }

    public function testResolvesOpus4(): void
    {
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-opus-4-20250514-v1:0', 'us-west-2');

        $this->assertSame('us.anthropic.claude-opus-4-20250514-v1:0', $resolved);
    }

    public function testResolvesHaiku4(): void
    {
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-haiku-4-20250514-v1:0', 'eu-central-1');

        $this->assertSame('eu.anthropic.claude-haiku-4-20250514-v1:0', $resolved);
    }

    public function testResolvesNova(): void
    {
        $resolved = InferenceProfileResolver::resolve('amazon.nova-pro-v1:0', 'us-east-1');

        $this->assertSame('us.amazon.nova-pro-v1:0', $resolved);
    }

    public function testResolvesLlama33(): void
    {
        $resolved = InferenceProfileResolver::resolve('meta.llama3-3-70b-instruct-v1:0', 'us-east-1');

        $this->assertSame('us.meta.llama3-3-70b-instruct-v1:0', $resolved);
    }

    public function testResolvesLlama4(): void
    {
        $resolved = InferenceProfileResolver::resolve('meta.llama4-scout-17b-16e-instruct-v1:0', 'eu-west-1');

        $this->assertSame('eu.meta.llama4-scout-17b-16e-instruct-v1:0', $resolved);
    }

    public function testDoesNotPrefixOlderModels(): void
    {
        $models = [
            'anthropic.claude-v2',
            'anthropic.claude-v2:1',
            'anthropic.claude-instant-v1',
            'anthropic.claude-3-sonnet-20240229-v1:0',
            'anthropic.claude-3-haiku-20240307-v1:0',
            'amazon.titan-text-express-v1',
            'mistral.mistral-large-2402-v1:0',
            'cohere.command-r-plus-v1:0',
        ];

        foreach ($models as $modelId) {
            $resolved = InferenceProfileResolver::resolve($modelId, 'us-east-1');
            $this->assertSame($modelId, $resolved, "Model {$modelId} should not get a prefix");
        }
    }

    public function testStripsContextWindowSuffix(): void
    {
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-3-5-sonnet:48k', 'us-east-1');

        $this->assertSame('us.anthropic.claude-3-5-sonnet', $resolved);
    }

    public function testStrips200kSuffix(): void
    {
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-3-5-sonnet:200k', 'us-east-1');

        $this->assertSame('us.anthropic.claude-3-5-sonnet', $resolved);
    }

    public function testRequiresProfileReturnsTrue(): void
    {
        $this->assertTrue(InferenceProfileResolver::requiresProfile('anthropic.claude-3-5-sonnet-v2:0'));
        $this->assertTrue(InferenceProfileResolver::requiresProfile('amazon.nova-pro-v1:0'));
        $this->assertTrue(InferenceProfileResolver::requiresProfile('meta.llama4-scout'));
    }

    public function testRequiresProfileReturnsFalse(): void
    {
        $this->assertFalse(InferenceProfileResolver::requiresProfile('anthropic.claude-v2'));
        $this->assertFalse(InferenceProfileResolver::requiresProfile('amazon.titan-text-express-v1'));
        $this->assertFalse(InferenceProfileResolver::requiresProfile('mistral.mistral-large-v1:0'));
    }

    public function testAddPatternRegistersNewPattern(): void
    {
        InferenceProfileResolver::addPattern('custom.model-');

        $resolved = InferenceProfileResolver::resolve('custom.model-v1', 'us-east-1');
        $this->assertSame('us.custom.model-v1', $resolved);
    }

    public function testDoesNotDoublePrefixAlreadyResolvedIds(): void
    {
        $alreadyPrefixed = [
            'us.anthropic.claude-3-5-sonnet-20241022-v2:0',
            'eu.anthropic.claude-3-5-sonnet-20241022-v2:0',
            'us.amazon.nova-pro-v1:0',
            'eu.meta.llama4-scout-17b-16e-instruct-v1:0',
        ];

        foreach ($alreadyPrefixed as $modelId) {
            $resolved = InferenceProfileResolver::resolve($modelId, 'us-east-1');
            $this->assertSame($modelId, $resolved, "Already-prefixed model {$modelId} should not get double-prefixed");
        }
    }

    public function testAddPatternDoesNotDuplicate(): void
    {
        InferenceProfileResolver::addPattern('anthropic.claude-3-5-');
        InferenceProfileResolver::addPattern('anthropic.claude-3-5-');

        // Should still work without issues
        $resolved = InferenceProfileResolver::resolve('anthropic.claude-3-5-sonnet', 'us-east-1');
        $this->assertSame('us.anthropic.claude-3-5-sonnet', $resolved);
    }
}
