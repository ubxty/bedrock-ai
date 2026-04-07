<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Support\TokenEstimator;

class TokenEstimatorTest extends TestCase
{
    public function testEstimateEmptyString(): void
    {
        $this->assertSame(0, TokenEstimator::estimate(''));
    }

    public function testEstimateShortString(): void
    {
        // "Hello" = 5 chars / 4 = 1.25, ceil = 2
        $this->assertSame(2, TokenEstimator::estimate('Hello'));
    }

    public function testEstimateLongerString(): void
    {
        // 100 chars / 4 = 25 tokens
        $text = str_repeat('abcd', 25);
        $this->assertSame(25, TokenEstimator::estimate($text));
    }

    public function testEstimateHandlesMultibyteChars(): void
    {
        $text = 'こんにちは世界'; // 7 multibyte chars
        $result = TokenEstimator::estimate($text);

        // mb_strlen returns 7, so 7/4 = 1.75 => ceil = 2
        $this->assertSame(2, $result);
    }

    public function testEstimateInvocationBasicScenario(): void
    {
        $system = str_repeat('a', 400); // ~100 tokens
        $user = str_repeat('b', 800); // ~200 tokens

        $result = TokenEstimator::estimateInvocation(
            $system,
            $user,
            'anthropic.claude-sonnet-4-v1:0', // 200k context
            4096
        );

        $this->assertSame(300, $result['input_tokens']);
        $this->assertSame(200000, $result['context_window']);
        $this->assertTrue($result['fits']);
        $this->assertSame(199700, $result['available_output']);
    }

    public function testEstimateInvocationDoesNotFit(): void
    {
        // Titan lite has 4096 context window
        $system = str_repeat('a', 16000); // ~4000 tokens
        $user = str_repeat('b', 4000); // ~1000 tokens

        $result = TokenEstimator::estimateInvocation(
            $system,
            $user,
            'amazon.titan-text-lite-v1',
            4096
        );

        $this->assertSame(5000, $result['input_tokens']);
        $this->assertFalse($result['fits']);
        $this->assertSame(0, $result['available_output']); // max(0, negative)
    }

    public function testEstimateCostWithDefaultPricing(): void
    {
        $system = str_repeat('a', 4000); // ~1000 tokens
        $user = str_repeat('b', 4000); // ~1000 tokens

        $cost = TokenEstimator::estimateCost($system, $user, 1000);

        // input: 2000/1000 * 0.003 = 0.006
        // output: 1000/1000 * 0.015 = 0.015
        // total: 0.021
        $this->assertSame(0.021, $cost);
    }

    public function testEstimateCostWithCustomPricing(): void
    {
        $system = str_repeat('a', 4000); // ~1000 tokens
        $user = str_repeat('b', 4000); // ~1000 tokens

        $cost = TokenEstimator::estimateCost($system, $user, 500, [
            'input_price_per_1k' => 0.001,
            'output_price_per_1k' => 0.005,
        ]);

        // input: 2000/1000 * 0.001 = 0.002
        // output: 500/1000 * 0.005 = 0.0025
        // total: 0.0045
        $this->assertSame(0.0045, $cost);
    }

    // ─── Multimodal estimation tests ──────────────────────────────

    public function testEstimateMultimodalWithTextOnly(): void
    {
        $messages = [
            ['role' => 'user', 'content' => str_repeat('a', 400)],      // ~100 tokens
            ['role' => 'assistant', 'content' => str_repeat('b', 200)],  // ~50 tokens
        ];

        $tokens = TokenEstimator::estimateMultimodal($messages, str_repeat('c', 100)); // ~25 tokens

        $this->assertSame(175, $tokens);
    }

    public function testEstimateMultimodalWithDocument(): void
    {
        $fakePdfBase64 = str_repeat('A', 7500); // 7500 / 750 = 10 doc tokens, but min is 100

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'format' => 'pdf', 'name' => 'test', 'data' => $fakePdfBase64],
                    ['type' => 'text', 'text' => str_repeat('x', 40)],  // ~10 tokens
                ],
            ],
        ];

        $tokens = TokenEstimator::estimateMultimodal($messages);

        // max(100, 10) doc tokens + 10 text tokens = 110
        $this->assertSame(110, $tokens);
    }

    public function testEstimateMultimodalWithImage(): void
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'format' => 'jpeg', 'data' => base64_encode('fake')],
                    ['type' => 'text', 'text' => 'Describe'],  // ~2 tokens
                ],
            ],
        ];

        $tokens = TokenEstimator::estimateMultimodal($messages);

        // 1600 image tokens + ~2 text tokens = 1602
        $this->assertSame(1602, $tokens);
    }

    public function testEstimateMultimodalDocumentMinimumTokens(): void
    {
        // Very small document should still return at least 100 tokens
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'format' => 'csv', 'name' => 'tiny', 'data' => 'abc'],
                    ['type' => 'text', 'text' => 'Read'],
                ],
            ],
        ];

        $tokens = TokenEstimator::estimateMultimodal($messages);

        // min 100 for document + 1 for text
        $this->assertSame(101, $tokens);
    }

    public function testEstimateMultimodalWithSystemPrompt(): void
    {
        $messages = [
            ['role' => 'user', 'content' => str_repeat('a', 40)], // ~10 tokens
        ];

        $tokens = TokenEstimator::estimateMultimodal($messages, str_repeat('s', 80)); // ~20 tokens

        $this->assertSame(30, $tokens);
    }
}
