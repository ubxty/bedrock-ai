<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Pricing\ModelIdNormalizer;

class ModelIdNormalizerTest extends TestCase
{
    // ─── Claude ──────────────────────────────────────────────

    public function testMatchesClaude35Sonnet(): void
    {
        $this->assertSame(
            'anthropic.claude-3-5-sonnet',
            ModelIdNormalizer::normalize('Claude 3.5 Sonnet', 'Anthropic')
        );
    }

    public function testMatchesClaude35Haiku(): void
    {
        $this->assertSame(
            'anthropic.claude-3-5-haiku',
            ModelIdNormalizer::normalize('Claude 3-5 Haiku', 'Anthropic')
        );
    }

    public function testMatchesClaude3Opus(): void
    {
        $this->assertSame(
            'anthropic.claude-3-opus',
            ModelIdNormalizer::normalize('Claude 3.0 Opus', 'Anthropic')
        );
    }

    public function testMatchesClaude3Sonnet(): void
    {
        $this->assertSame(
            'anthropic.claude-3-sonnet',
            ModelIdNormalizer::normalize('Claude 3 Sonnet', 'Anthropic')
        );
    }

    public function testMatchesClaude3Haiku(): void
    {
        $this->assertSame(
            'anthropic.claude-3-haiku',
            ModelIdNormalizer::normalize('Claude 3 Haiku', 'Anthropic')
        );
    }

    public function testMatchesClaudeV2(): void
    {
        $this->assertSame(
            'anthropic.claude-v2',
            ModelIdNormalizer::normalize('Claude v2', 'Anthropic')
        );
    }

    public function testMatchesClaudeInstant(): void
    {
        $this->assertSame(
            'anthropic.claude-instant-v1',
            ModelIdNormalizer::normalize('Claude Instant', 'Anthropic')
        );
    }

    // ─── Titan ───────────────────────────────────────────────

    public function testMatchesTitanPremier(): void
    {
        $this->assertSame(
            'amazon.titan-text-premier-v1:0',
            ModelIdNormalizer::normalize('Titan Text Premier', 'Amazon')
        );
    }

    public function testMatchesTitanExpress(): void
    {
        $this->assertSame(
            'amazon.titan-text-express-v1',
            ModelIdNormalizer::normalize('Titan Express', 'Amazon')
        );
    }

    public function testMatchesTitanLite(): void
    {
        $this->assertSame(
            'amazon.titan-text-lite-v1',
            ModelIdNormalizer::normalize('Titan Lite', 'Amazon')
        );
    }

    // ─── Nova ────────────────────────────────────────────────

    public function testMatchesNovaPro(): void
    {
        $this->assertSame(
            'amazon.nova-pro-v1:0',
            ModelIdNormalizer::normalize('Nova Pro', 'Amazon')
        );
    }

    public function testMatchesNovaLite(): void
    {
        $this->assertSame(
            'amazon.nova-lite-v1:0',
            ModelIdNormalizer::normalize('Nova Lite', 'Amazon')
        );
    }

    public function testMatchesNovaMicro(): void
    {
        $this->assertSame(
            'amazon.nova-micro-v1:0',
            ModelIdNormalizer::normalize('Nova Micro', 'Amazon')
        );
    }

    // ─── Llama ───────────────────────────────────────────────

    public function testMatchesLlama33_70b(): void
    {
        $this->assertSame(
            'meta.llama3-3-70b-instruct-v1:0',
            ModelIdNormalizer::normalize('Llama 3.3 70B Instruct', 'Meta')
        );
    }

    public function testMatchesLlama32_90b(): void
    {
        $this->assertSame(
            'meta.llama3-2-90b-instruct-v1:0',
            ModelIdNormalizer::normalize('Llama 3.2 90B', 'Meta')
        );
    }

    public function testMatchesLlama31_405b(): void
    {
        $this->assertSame(
            'meta.llama3-1-405b-instruct-v1:0',
            ModelIdNormalizer::normalize('Llama 3.1 405B', 'Meta')
        );
    }

    public function testMatchesLlama70b(): void
    {
        $this->assertSame(
            'meta.llama3-70b-instruct-v1:0',
            ModelIdNormalizer::normalize('Llama 70B Instruct', 'Meta')
        );
    }

    public function testMatchesLlama8b(): void
    {
        $this->assertSame(
            'meta.llama3-8b-instruct-v1:0',
            ModelIdNormalizer::normalize('Llama 8B', 'Meta')
        );
    }

    // ─── Mistral ─────────────────────────────────────────────

    public function testMatchesMistralLarge2(): void
    {
        $this->assertSame(
            'mistral.mistral-large-2407-v1:0',
            ModelIdNormalizer::normalize('Mistral Large 2', 'Mistral')
        );
    }

    public function testMatchesMistralLarge(): void
    {
        $this->assertSame(
            'mistral.mistral-large-2402-v1:0',
            ModelIdNormalizer::normalize('Mistral Large', 'Mistral')
        );
    }

    public function testMatchesMistralSmall(): void
    {
        $this->assertSame(
            'mistral.mistral-small-2402-v1:0',
            ModelIdNormalizer::normalize('Mistral Small', 'Mistral')
        );
    }

    public function testMatchesMixtral(): void
    {
        $this->assertSame(
            'mistral.mixtral-8x7b-instruct-v0:1',
            ModelIdNormalizer::normalize('Mixtral 8x7b', 'Mistral')
        );
    }

    public function testMatchesMistral7b(): void
    {
        $this->assertSame(
            'mistral.mistral-7b-instruct-v0:2',
            ModelIdNormalizer::normalize('Mistral 7B', 'Mistral')
        );
    }

    // ─── Cohere ──────────────────────────────────────────────

    public function testMatchesCommandRPlus(): void
    {
        $this->assertSame(
            'cohere.command-r-plus-v1:0',
            ModelIdNormalizer::normalize('Command R+', 'Cohere')
        );
    }

    public function testMatchesCommandR(): void
    {
        $this->assertSame(
            'cohere.command-r-v1:0',
            ModelIdNormalizer::normalize('Command R', 'Cohere')
        );
    }

    public function testMatchesCommandText(): void
    {
        $this->assertSame(
            'cohere.command-text-v14',
            ModelIdNormalizer::normalize('Command', 'Cohere')
        );
    }

    // ─── AI21 ────────────────────────────────────────────────

    public function testMatchesJamba15Large(): void
    {
        $this->assertSame(
            'ai21.jamba-1-5-large-v1:0',
            ModelIdNormalizer::normalize('Jamba 1.5 Large', 'AI21')
        );
    }

    public function testMatchesJamba15Mini(): void
    {
        $this->assertSame(
            'ai21.jamba-1-5-mini-v1:0',
            ModelIdNormalizer::normalize('Jamba 1.5 Mini', 'AI21')
        );
    }

    public function testMatchesJambaInstruct(): void
    {
        $this->assertSame(
            'ai21.jamba-instruct-v1:0',
            ModelIdNormalizer::normalize('Jamba Instruct', 'AI21')
        );
    }

    public function testMatchesJurassicUltra(): void
    {
        $this->assertSame(
            'ai21.j2-ultra-v1',
            ModelIdNormalizer::normalize('Jurassic Ultra', 'AI21')
        );
    }

    public function testMatchesJurassicMid(): void
    {
        $this->assertSame(
            'ai21.j2-mid-v1',
            ModelIdNormalizer::normalize('Jurassic Mid', 'AI21')
        );
    }

    // ─── Edge cases ──────────────────────────────────────────

    public function testReturnsNullForUnknownModel(): void
    {
        $this->assertNull(ModelIdNormalizer::normalize('Unknown Model XYZ', 'Unknown'));
    }

    public function testIsCaseInsensitive(): void
    {
        $this->assertSame(
            'anthropic.claude-3-5-sonnet',
            ModelIdNormalizer::normalize('CLAUDE 3.5 SONNET', 'ANTHROPIC')
        );
    }

    public function testMatchesMistralByProvider(): void
    {
        $this->assertSame(
            'mistral.mistral-7b-instruct-v0:2',
            ModelIdNormalizer::normalize('7B Model', 'mistral')
        );
    }
}
