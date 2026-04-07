<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Models\ModelSpecResolver;

class ModelSpecResolverTest extends TestCase
{
    public function testClaude3Specs(): void
    {
        $specs = ModelSpecResolver::resolve('anthropic.claude-3-sonnet-20240229-v1:0');

        $this->assertSame(200000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testClaude35Sonnet20241022HasHigherMaxTokens(): void
    {
        $specs = ModelSpecResolver::resolve('anthropic.claude-3-5-sonnet-20241022-v2');

        $this->assertSame(200000, $specs['context_window']);
        $this->assertSame(8192, $specs['max_tokens']);
    }

    public function testClaude4Specs(): void
    {
        $specs = ModelSpecResolver::resolve('anthropic.claude-sonnet-4-20250514-v1:0');

        $this->assertSame(200000, $specs['context_window']);
        $this->assertSame(16384, $specs['max_tokens']);
    }

    public function testClaude4OpusSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('anthropic.claude-opus-4-v1:0');

        $this->assertSame(200000, $specs['context_window']);
        $this->assertSame(16384, $specs['max_tokens']);
    }

    public function testClaude4HaikuSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('anthropic.claude-haiku-4-v1:0');

        $this->assertSame(200000, $specs['context_window']);
        $this->assertSame(16384, $specs['max_tokens']);
    }

    public function testClaudeV2Specs(): void
    {
        $specs = ModelSpecResolver::resolve('anthropic.claude-v2');

        $this->assertSame(100000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testClaudeV21Specs(): void
    {
        $specs = ModelSpecResolver::resolve('anthropic.claude-v2:1');

        $this->assertSame(200000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testClaudeInstantSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('anthropic.claude-instant-v1');

        $this->assertSame(100000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testTitanTextExpressSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('amazon.titan-text-express-v1');

        $this->assertSame(8192, $specs['context_window']);
        $this->assertSame(8192, $specs['max_tokens']);
    }

    public function testTitanTextLiteSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('amazon.titan-text-lite-v1');

        $this->assertSame(4096, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testTitanTextPremierSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('amazon.titan-text-premier-v1:0');

        $this->assertSame(32768, $specs['context_window']);
        $this->assertSame(8192, $specs['max_tokens']);
    }

    public function testNovaProSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('amazon.nova-pro-v1:0');

        $this->assertSame(300000, $specs['context_window']);
        $this->assertSame(5120, $specs['max_tokens']);
    }

    public function testNovaLiteSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('amazon.nova-lite-v1:0');

        $this->assertSame(300000, $specs['context_window']);
        $this->assertSame(5120, $specs['max_tokens']);
    }

    public function testNovaMicroSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('amazon.nova-micro-v1:0');

        $this->assertSame(128000, $specs['context_window']);
        $this->assertSame(5120, $specs['max_tokens']);
    }

    public function testLlama4Specs(): void
    {
        $specs = ModelSpecResolver::resolve('meta.llama4-scout-17b-16e-instruct-v1:0');

        $this->assertSame(256000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testLlama33Specs(): void
    {
        $specs = ModelSpecResolver::resolve('meta.llama3-3-70b-instruct-v1:0');

        $this->assertSame(128000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testLlama3Specs(): void
    {
        $specs = ModelSpecResolver::resolve('meta.llama3-70b-instruct-v1:0');

        $this->assertSame(128000, $specs['context_window']);
        $this->assertSame(2048, $specs['max_tokens']);
    }

    public function testMistralLargeSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('mistral.mistral-large-2402-v1:0');

        $this->assertSame(128000, $specs['context_window']);
        $this->assertSame(8192, $specs['max_tokens']);
    }

    public function testMixtralSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('mistral.mixtral-8x7b-instruct-v0:1');

        $this->assertSame(32000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testMistralSmallSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('mistral.mistral-7b-instruct-v0:2');

        $this->assertSame(32000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testCommandRPlusSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('cohere.command-r-plus-v1:0');

        $this->assertSame(128000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testCommandRSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('cohere.command-r-v1:0');

        $this->assertSame(128000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testJambaSpecs(): void
    {
        $specs = ModelSpecResolver::resolve('ai21.jamba-instruct-v1:0');

        $this->assertSame(256000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testUnknownModelReturnsDefaults(): void
    {
        $specs = ModelSpecResolver::resolve('unknown.model-v1');

        $this->assertSame(128000, $specs['context_window']);
        $this->assertSame(4096, $specs['max_tokens']);
    }

    public function testFamiliesReturnsExpectedFamilies(): void
    {
        $families = ModelSpecResolver::families();

        $expectedKeys = [
            'claude-3.5', 'claude-4', 'nova', 'titan',
            'llama-3', 'llama-4', 'mistral', 'cohere', 'jamba',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $families, "Missing family: {$key}");
            $this->assertArrayHasKey('name', $families[$key]);
            $this->assertArrayHasKey('context_window', $families[$key]);
            $this->assertArrayHasKey('max_tokens', $families[$key]);
        }
    }

    // ─── Input modality tests ─────────────────────────────────────

    public function testClaude3SupportsImageAndDocument(): void
    {
        $modalities = ModelSpecResolver::inputModalities('anthropic.claude-3-5-sonnet-20241022-v2:0');

        $this->assertContains('text', $modalities);
        $this->assertContains('image', $modalities);
        $this->assertContains('document', $modalities);
    }

    public function testClaude4SupportsImageAndDocument(): void
    {
        $modalities = ModelSpecResolver::inputModalities('anthropic.claude-sonnet-4-20250514-v1:0');

        $this->assertContains('text', $modalities);
        $this->assertContains('image', $modalities);
        $this->assertContains('document', $modalities);
    }

    public function testClaudeV2IsTextOnly(): void
    {
        $modalities = ModelSpecResolver::inputModalities('anthropic.claude-v2');

        $this->assertSame(['text'], $modalities);
    }

    public function testNovaProSupportsImageAndDocument(): void
    {
        $modalities = ModelSpecResolver::inputModalities('amazon.nova-pro-v1:0');

        $this->assertContains('text', $modalities);
        $this->assertContains('image', $modalities);
        $this->assertContains('document', $modalities);
    }

    public function testNovaLiteSupportsImageAndDocument(): void
    {
        $modalities = ModelSpecResolver::inputModalities('amazon.nova-lite-v1:0');

        $this->assertContains('text', $modalities);
        $this->assertContains('image', $modalities);
        $this->assertContains('document', $modalities);
    }

    public function testNovaMicroIsTextOnly(): void
    {
        $modalities = ModelSpecResolver::inputModalities('amazon.nova-micro-v1:0');

        $this->assertSame(['text'], $modalities);
    }

    public function testTitanIsTextOnly(): void
    {
        $modalities = ModelSpecResolver::inputModalities('amazon.titan-text-express-v1');

        $this->assertSame(['text'], $modalities);
    }

    public function testLlama4SupportsImage(): void
    {
        $modalities = ModelSpecResolver::inputModalities('meta.llama4-scout-17b-16e-instruct-v1:0');

        $this->assertContains('text', $modalities);
        $this->assertContains('image', $modalities);
        $this->assertNotContains('document', $modalities);
    }

    public function testLlama3IsTextOnly(): void
    {
        $modalities = ModelSpecResolver::inputModalities('meta.llama3-3-70b-instruct-v1:0');

        $this->assertSame(['text'], $modalities);
    }

    public function testMistralIsTextOnly(): void
    {
        $modalities = ModelSpecResolver::inputModalities('mistral.mistral-large-2402-v1:0');

        $this->assertSame(['text'], $modalities);
    }

    public function testCohereIsTextOnly(): void
    {
        $modalities = ModelSpecResolver::inputModalities('cohere.command-r-plus-v1:0');

        $this->assertSame(['text'], $modalities);
    }

    public function testJambaIsTextOnly(): void
    {
        $modalities = ModelSpecResolver::inputModalities('ai21.jamba-instruct-v1:0');

        $this->assertSame(['text'], $modalities);
    }

    public function testUnknownModelDefaultsToTextOnly(): void
    {
        $modalities = ModelSpecResolver::inputModalities('unknown.model-v1');

        $this->assertSame(['text'], $modalities);
    }

    public function testSupportsModalityHelper(): void
    {
        $this->assertTrue(ModelSpecResolver::supportsModality('anthropic.claude-3-5-sonnet-20241022-v2:0', 'document'));
        $this->assertTrue(ModelSpecResolver::supportsModality('anthropic.claude-3-5-sonnet-20241022-v2:0', 'image'));
        $this->assertFalse(ModelSpecResolver::supportsModality('meta.llama3-3-70b-instruct-v1:0', 'document'));
        $this->assertFalse(ModelSpecResolver::supportsModality('meta.llama3-3-70b-instruct-v1:0', 'image'));
        $this->assertTrue(ModelSpecResolver::supportsModality('meta.llama4-scout-17b-16e-instruct-v1:0', 'image'));
        $this->assertFalse(ModelSpecResolver::supportsModality('meta.llama4-scout-17b-16e-instruct-v1:0', 'document'));
    }
}
