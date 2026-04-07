<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\BedrockManager;
use Ubxty\BedrockAi\Conversation\ConversationBuilder;
use Ubxty\BedrockAi\Exceptions\BedrockException;

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

    // ─── Image / Document validation tests ────────────────────────

    public function testUserWithImageRejectsUnsupportedModel(): void
    {
        $this->expectException(BedrockException::class);
        $this->expectExceptionMessage('does not support image input');

        // Llama 3.3 is text-only
        $builder = $this->createBuilder('meta.llama3-3-70b-instruct-v1:0');
        $builder->userWithImage('Describe this', base64_encode('fakedata'), 'jpeg');
    }

    public function testUserWithDocumentRejectsUnsupportedModel(): void
    {
        $this->expectException(BedrockException::class);
        $this->expectExceptionMessage('does not support document input');

        // Mistral is text-only
        $builder = $this->createBuilder('mistral.mistral-large-2402-v1:0');
        $builder->userWithDocument('Read this', base64_encode('fakedata'), 'pdf');
    }

    public function testUserWithImageAcceptsSupportedModel(): void
    {
        // Claude 3 supports image input
        $builder = $this->createBuilder('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $builder->userWithImage('Describe', base64_encode('fakedata'), 'jpeg');

        $messages = $builder->getMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertIsArray($messages[0]['content']);
        $this->assertSame('image', $messages[0]['content'][0]['type']);
    }

    public function testUserWithDocumentAcceptsSupportedModel(): void
    {
        // Nova Pro supports document input
        $builder = $this->createBuilder('amazon.nova-pro-v1:0');
        $builder->userWithDocument('Read this', base64_encode('fakedata'), 'pdf');

        $messages = $builder->getMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('document', $messages[0]['content'][0]['type']);
        $this->assertSame('pdf', $messages[0]['content'][0]['format']);
    }

    public function testUserWithImageRejectsInvalidFormat(): void
    {
        $this->expectException(BedrockException::class);
        $this->expectExceptionMessage("Unsupported image format 'bmp'");

        $builder = $this->createBuilder('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $builder->userWithImage('Test', base64_encode('fakedata'), 'bmp');
    }

    public function testUserWithDocumentRejectsInvalidFormat(): void
    {
        $this->expectException(BedrockException::class);
        $this->expectExceptionMessage("Unsupported document format 'exe'");

        $builder = $this->createBuilder('amazon.nova-pro-v1:0');
        $builder->userWithDocument('Test', base64_encode('fakedata'), 'exe');
    }

    public function testUserWithDocumentRejectsEmptyFile(): void
    {
        $tmpFile = sys_get_temp_dir() . '/bedrock_test_empty_' . uniqid() . '.pdf';
        file_put_contents($tmpFile, '');

        try {
            $this->expectException(BedrockException::class);
            $this->expectExceptionMessage('file is empty');

            $builder = $this->createBuilder('amazon.nova-pro-v1:0');
            $builder->userWithDocument('Read', $tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    // ─── Multi-document tests ─────────────────────────────────────

    public function testUserWithDocumentsCreatesMultipleBlocks(): void
    {
        $tmpFile1 = sys_get_temp_dir() . '/bedrock_test_doc1_' . uniqid() . '.pdf';
        $tmpFile2 = sys_get_temp_dir() . '/bedrock_test_doc2_' . uniqid() . '.csv';
        file_put_contents($tmpFile1, 'fake pdf content');
        file_put_contents($tmpFile2, 'col1,col2');

        try {
            $builder = $this->createBuilder('amazon.nova-pro-v1:0');
            $builder->userWithDocuments('Compare these', [$tmpFile1, $tmpFile2]);

            $messages = $builder->getMessages();
            $this->assertCount(1, $messages);

            $content = $messages[0]['content'];
            // 2 document blocks + 1 text block
            $this->assertCount(3, $content);
            $this->assertSame('document', $content[0]['type']);
            $this->assertSame('pdf', $content[0]['format']);
            $this->assertSame('document', $content[1]['type']);
            $this->assertSame('csv', $content[1]['format']);
            $this->assertSame('text', $content[2]['type']);
        } finally {
            @unlink($tmpFile1);
            @unlink($tmpFile2);
        }
    }

    public function testUserWithDocumentsAcceptsAssocArrayFiles(): void
    {
        $tmpFile = sys_get_temp_dir() . '/bedrock_test_assoc_' . uniqid() . '.pdf';
        file_put_contents($tmpFile, 'fake pdf');

        try {
            $builder = $this->createBuilder('amazon.nova-pro-v1:0');
            $builder->userWithDocuments('Analyse', [
                ['path' => $tmpFile, 'format' => 'pdf', 'name' => 'report'],
            ]);

            $messages = $builder->getMessages();
            $this->assertSame('report', $messages[0]['content'][0]['name']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testUserWithDocumentsRejectsEmptyArray(): void
    {
        $this->expectException(BedrockException::class);
        $this->expectExceptionMessage('At least one document');

        $builder = $this->createBuilder('amazon.nova-pro-v1:0');
        $builder->userWithDocuments('Read', []);
    }

    // ─── Mixed media attachments tests ────────────────────────────

    public function testUserWithAttachmentsMixedTypes(): void
    {
        $tmpPdf = sys_get_temp_dir() . '/bedrock_test_mix_' . uniqid() . '.pdf';
        $tmpImg = sys_get_temp_dir() . '/bedrock_test_mix_' . uniqid() . '.png';
        file_put_contents($tmpPdf, 'fake pdf');
        file_put_contents($tmpImg, 'fake png');

        try {
            // Claude 3 supports both image and document
            $builder = $this->createBuilder('anthropic.claude-3-5-sonnet-20241022-v2:0');
            $builder->userWithAttachments('Compare', [
                ['type' => 'document', 'path' => $tmpPdf],
                ['type' => 'image', 'path' => $tmpImg, 'format' => 'png'],
            ]);

            $messages = $builder->getMessages();
            $this->assertCount(1, $messages);

            $content = $messages[0]['content'];
            $this->assertCount(3, $content); // doc + image + text
            $this->assertSame('document', $content[0]['type']);
            $this->assertSame('image', $content[1]['type']);
            $this->assertSame('text', $content[2]['type']);
        } finally {
            @unlink($tmpPdf);
            @unlink($tmpImg);
        }
    }

    public function testUserWithAttachmentsRejectsUnknownType(): void
    {
        $this->expectException(BedrockException::class);
        $this->expectExceptionMessage("Unknown attachment type 'video'");

        $builder = $this->createBuilder('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $builder->userWithAttachments('Test', [
            ['type' => 'video', 'path' => '/fake/video.mp4'],
        ]);
    }

    public function testUserWithAttachmentsRejectsEmpty(): void
    {
        $this->expectException(BedrockException::class);
        $this->expectExceptionMessage('At least one attachment');

        $builder = $this->createBuilder('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $builder->userWithAttachments('Test', []);
    }

    // ─── Multimodal token estimation tests ────────────────────────

    public function testEstimateAccountsForDocumentTokens(): void
    {
        // Build a message with a document block containing ~7500 base64 chars (~10 tokens)
        $fakeBase64 = str_repeat('A', 7500);

        $builder = $this->createBuilder('amazon.nova-pro-v1:0');
        $builder->system('System prompt');

        // Manually inject a document message to avoid file validation
        $reflection = new \ReflectionProperty($builder, 'messages');
        $reflection->setAccessible(true);
        $reflection->setValue($builder, [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'format' => 'pdf', 'name' => 'test', 'data' => $fakeBase64],
                    ['type' => 'text', 'text' => 'Read this document.'],
                ],
            ],
        ]);

        $estimate = $builder->estimate();

        // With old text-only estimation this would be ~5 tokens; multimodal should be >> 5
        $this->assertGreaterThan(10, $estimate['input_tokens']);
        $this->assertArrayHasKey('estimated_cost', $estimate);
    }

    public function testEstimateAccountsForImageTokens(): void
    {
        $builder = $this->createBuilder('anthropic.claude-3-5-sonnet-20241022-v2:0');
        $builder->system('Describe');

        $reflection = new \ReflectionProperty($builder, 'messages');
        $reflection->setAccessible(true);
        $reflection->setValue($builder, [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'format' => 'jpeg', 'data' => base64_encode('fake')],
                    ['type' => 'text', 'text' => 'What is this?'],
                ],
            ],
        ]);

        $estimate = $builder->estimate();

        // Image should contribute ~1600 tokens
        $this->assertGreaterThan(1500, $estimate['input_tokens']);
    }
}
