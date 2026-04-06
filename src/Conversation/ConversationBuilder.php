<?php

namespace Ubxty\BedrockAi\Conversation;

use Ubxty\BedrockAi\BedrockManager;
use Ubxty\BedrockAi\Support\TokenEstimator;

class ConversationBuilder
{
    protected string $modelId;

    protected string $systemPrompt = '';

    /** @var array<int, array{role: string, content: string}> */
    protected array $messages = [];

    protected int $maxTokens = 4096;

    protected float $temperature = 0.7;

    protected ?array $pricing = null;

    protected ?string $connection = null;

    protected BedrockManager $manager;

    public function __construct(BedrockManager $manager, string $modelId)
    {
        $this->manager = $manager;
        $this->modelId = $modelId;
    }

    /**
     * Set the system prompt.
     */
    public function system(string $prompt): static
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    /**
     * Add a user message to the conversation.
     */
    public function user(string $message): static
    {
        $this->messages[] = ['role' => 'user', 'content' => $message];

        return $this;
    }

    /**
     * Add a user message with an image attachment.
     *
     * Models that support image input (e.g. Claude 3+, Nova Pro, Nova Lite) will
     * analyse the image and answer the prompt.
     *
     * @param  string  $prompt   The question or instruction about the image.
     * @param  string  $source   Absolute file path or already base64-encoded image data.
     * @param  string  $format   Image format: jpeg, png, gif, webp.
     *                           'auto' detects from the file extension.
     */
    public function userWithImage(string $prompt, string $source, string $format = 'auto'): static
    {
        if (is_file($source)) {
            $size = filesize($source);
            if ($size > 15 * 1024 * 1024) {
                throw new \Ubxty\BedrockAi\Exceptions\BedrockException(
                    'Image file exceeds 15 MB limit (' . round($size / 1024 / 1024, 1) . ' MB). Resize or compress it first.'
                );
            }
            $base64 = base64_encode(file_get_contents($source));
        } else {
            $base64 = $source;
        }

        if ($format === 'auto') {
            $ext    = strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $format = match ($ext) {
                'jpg', 'jpeg' => 'jpeg',
                'png'         => 'png',
                'gif'         => 'gif',
                'webp'        => 'webp',
                default       => 'jpeg',
            };
        }

        $this->messages[] = [
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'format' => $format, 'data' => $base64],
                ['type' => 'text',  'text'   => $prompt],
            ],
        ];

        return $this;
    }

    /**
     * Add a user message with a document attachment (e.g. PDF, CSV, DOCX).
     *
     * Models that support document input will read the document and answer the prompt.
     *
     * @param  string  $prompt   The question or instruction about the document.
     * @param  string  $source   Absolute file path or already base64-encoded document data.
     * @param  string  $format   Document format: pdf, csv, doc, docx, xls, xlsx, html, txt, md.
     *                           'auto' detects from the file extension.
     * @param  string  $name     Display name for the document (defaults to the filename).
     */
    public function userWithDocument(string $prompt, string $source, string $format = 'auto', string $name = ''): static
    {
        if (is_file($source)) {
            $size = filesize($source);
            if ($size > 15 * 1024 * 1024) {
                throw new \Ubxty\BedrockAi\Exceptions\BedrockException(
                    'Document file exceeds 15 MB limit (' . round($size / 1024 / 1024, 1) . ' MB). Reduce the file size first.'
                );
            }
            $base64 = base64_encode(file_get_contents($source));
        } else {
            $base64 = $source;
        }

        if ($format === 'auto') {
            $ext    = strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $format = match ($ext) {
                'pdf'          => 'pdf',
                'csv'          => 'csv',
                'doc'          => 'doc',
                'docx'         => 'docx',
                'xls'          => 'xls',
                'xlsx'         => 'xlsx',
                'html', 'htm'  => 'html',
                'txt', 'text'  => 'txt',
                'md', 'markdown' => 'md',
                default        => 'pdf',
            };
        }

        if ($name === '' && is_file($source)) {
            $name = pathinfo($source, PATHINFO_FILENAME);
        }

        $this->messages[] = [
            'role'    => 'user',
            'content' => [
                ['type' => 'document', 'format' => $format, 'name' => $name ?: 'document', 'data' => $base64],
                ['type' => 'text',     'text'   => $prompt],
            ],
        ];

        return $this;
    }

    /**
     * Add an assistant message to the conversation (for multi-turn context).
     */
    public function assistant(string $message): static
    {
        $this->messages[] = ['role' => 'assistant', 'content' => $message];

        return $this;
    }

    /**
     * Set max output tokens.
     */
    public function maxTokens(int $tokens): static
    {
        $this->maxTokens = $tokens;

        return $this;
    }

    /**
     * Set temperature.
     */
    public function temperature(float $temp): static
    {
        $this->temperature = $temp;

        return $this;
    }

    /**
     * Set pricing for cost calculation.
     *
     * @param array{input_price_per_1k: float, output_price_per_1k: float} $pricing
     */
    public function withPricing(array $pricing): static
    {
        $this->pricing = $pricing;

        return $this;
    }

    /**
     * Use a specific connection.
     */
    public function connection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Estimate token usage and cost before sending.
     *
     * @return array{input_tokens: int, available_output: int, fits: bool, context_window: int, estimated_cost: float}
     */
    public function estimate(): array
    {
        $allContent = implode(' ', array_map(function (array $m) {
            $content = $m['content'];
            if (is_string($content)) {
                return $content;
            }
            // Extract only text blocks from multimodal messages for token estimation
            return implode(' ', array_filter(array_map(fn ($b) => $b['text'] ?? null, $content)));
        }, $this->messages));

        $estimation = TokenEstimator::estimateInvocation(
            $this->systemPrompt,
            $allContent,
            $this->modelId,
            $this->maxTokens
        );

        $estimation['estimated_cost'] = TokenEstimator::estimateCost(
            $this->systemPrompt,
            $allContent,
            $this->maxTokens,
            $this->pricing
        );

        return $estimation;
    }

    /**
     * Send the conversation using the Converse API (proper multi-turn support).
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, stop_reason: string, latency_ms: int, model_id: string, key_used: string}
     */
    public function send(): array
    {
        $result = $this->manager->converse(
            $this->modelId,
            $this->messages,
            $this->systemPrompt,
            $this->maxTokens,
            $this->temperature,
            $this->connection,
            $this->pricing,
        );

        // Add assistant response to conversation history
        $this->messages[] = ['role' => 'assistant', 'content' => $result['response']];

        return $result;
    }

    /**
     * Get the current message history.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get the system prompt.
     */
    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /**
     * Get the model ID.
     */
    public function getModelId(): string
    {
        return $this->modelId;
    }

    /**
     * Send the conversation with streaming output.
     *
     * @param callable(string $chunk): void $onChunk
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, latency_ms: int, model_id: string}
     */
    public function sendStream(callable $onChunk): array
    {
        $result = $this->manager->converseStream(
            $this->modelId,
            $this->messages,
            $onChunk,
            $this->systemPrompt,
            $this->maxTokens,
            $this->temperature,
            $this->connection,
            $this->pricing,
        );

        $this->messages[] = ['role' => 'assistant', 'content' => $result['response']];

        return $result;
    }

    /**
     * Reset the conversation history (keeps system prompt and settings).
     */
    public function reset(): static
    {
        $this->messages = [];

        return $this;
    }

    /**
     * Replace the entire message history (used for error recovery).
     *
     * @param  array<int, array{role: string, content: string|array}>  $messages
     */
    public function setMessages(array $messages): static
    {
        $this->messages = $messages;

        return $this;
    }
}
