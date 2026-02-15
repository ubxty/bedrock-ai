<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\BedrockManager;
use Ubxty\BedrockAi\Conversation\ConversationBuilder;

class ConversationBuilderTest extends TestCase
{
    protected function createBuilder(string $modelId = 'anthropic.claude-v2'): ConversationBuilder
    {
        $manager = new BedrockManager([
            'default' => 'default',
            'connections' => [
                'default' => [
                    'keys' => [[
                        'label' => 'Test',
                        'aws_key' => 'AKIAEXAMPLE',
                        'aws_secret' => 'secretEXAMPLE',
                        'region' => 'us-east-1',
                    ]],
                ],
            ],
            'retry' => ['max_retries' => 1, 'base_delay' => 1],
            'defaults' => ['anthropic_version' => 'bedrock-2023-05-31'],
            'aliases' => [],
            'logging' => ['enabled' => false, 'channel' => 'stack'],
        ]);

        return new ConversationBuilder($manager, $modelId);
    }

    public function testSystemPromptIsSet(): void
    {
        $builder = $this->createBuilder()->system('You are a doctor.');

        $this->assertSame('You are a doctor.', $builder->getSystemPrompt());
    }

    public function testUserMessageIsAdded(): void
    {
        $builder = $this->createBuilder()->user('Hello');

        $messages = $builder->getMessages();

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Hello', $messages[0]['content']);
    }

    public function testAssistantMessageIsAdded(): void
    {
        $builder = $this->createBuilder()->assistant('Hi there!');

        $messages = $builder->getMessages();

        $this->assertCount(1, $messages);
        $this->assertSame('assistant', $messages[0]['role']);
        $this->assertSame('Hi there!', $messages[0]['content']);
    }

    public function testMultiTurnConversation(): void
    {
        $builder = $this->createBuilder()
            ->system('You are helpful.')
            ->user('What is PHP?')
            ->assistant('PHP is a programming language.')
            ->user('What makes it special?');

        $messages = $builder->getMessages();

        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('user', $messages[2]['role']);
    }

    public function testFluentChaining(): void
    {
        $builder = $this->createBuilder()
            ->system('System prompt')
            ->user('Hello')
            ->maxTokens(2048)
            ->temperature(0.5)
            ->connection('default')
            ->withPricing(['input_price_per_1k' => 0.001, 'output_price_per_1k' => 0.002]);

        // All setters should return $this for chaining
        $this->assertInstanceOf(ConversationBuilder::class, $builder);
    }

    public function testGetModelId(): void
    {
        $builder = $this->createBuilder('anthropic.claude-3-sonnet');

        $this->assertSame('anthropic.claude-3-sonnet', $builder->getModelId());
    }

    public function testResetClearsMessages(): void
    {
        $builder = $this->createBuilder()
            ->system('System')
            ->user('Hello')
            ->assistant('Hi');

        $builder->reset();

        $this->assertEmpty($builder->getMessages());
        $this->assertSame('System', $builder->getSystemPrompt()); // system prompt preserved
    }

    public function testEstimateReturnsTokenInfo(): void
    {
        $builder = $this->createBuilder('anthropic.claude-sonnet-4-v1:0')
            ->system(str_repeat('a', 400))   // ~100 tokens
            ->user(str_repeat('b', 800));     // ~200 tokens

        $estimate = $builder->estimate();

        $this->assertArrayHasKey('input_tokens', $estimate);
        $this->assertArrayHasKey('available_output', $estimate);
        $this->assertArrayHasKey('fits', $estimate);
        $this->assertArrayHasKey('context_window', $estimate);
        $this->assertArrayHasKey('estimated_cost', $estimate);
        $this->assertTrue($estimate['fits']);
    }

    public function testEstimateWithMultiTurnMessages(): void
    {
        $builder = $this->createBuilder('anthropic.claude-sonnet-4-v1:0')
            ->system('Doctor prompt')
            ->user('Question 1')
            ->assistant('Answer 1')
            ->user('Follow up');

        $estimate = $builder->estimate();

        // Multi-turn messages should be concatenated for estimation
        $this->assertGreaterThan(0, $estimate['input_tokens']);
    }

    public function testEmptyConversationEstimate(): void
    {
        $builder = $this->createBuilder();

        $estimate = $builder->estimate();

        $this->assertSame(0, $estimate['input_tokens']);
    }
}
