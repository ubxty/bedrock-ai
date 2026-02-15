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
        $fullInput = $this->buildFullInput();
        $estimation = TokenEstimator::estimateInvocation(
            $this->systemPrompt,
            $fullInput,
            $this->modelId,
            $this->maxTokens
        );

        $estimation['estimated_cost'] = TokenEstimator::estimateCost(
            $this->systemPrompt,
            $fullInput,
            $this->maxTokens,
            $this->pricing
        );

        return $estimation;
    }

    /**
     * Send the conversation and get a response.
     *
     * @return array{response: string, input_tokens: int, output_tokens: int, total_tokens: int, cost: float, latency_ms: int, status: string, key_used: string, model_id: string}
     */
    public function send(): array
    {
        $client = $this->manager->client($this->connection);
        $fullInput = $this->buildFullInput();

        $result = $client->invoke(
            $this->modelId,
            $this->systemPrompt,
            $fullInput,
            $this->maxTokens,
            $this->temperature,
            $this->pricing
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
     * Reset the conversation history (keeps system prompt and settings).
     */
    public function reset(): static
    {
        $this->messages = [];

        return $this;
    }

    /**
     * Build the full input string from multi-turn message history.
     * The last user message is sent as the userMessage param;
     * prior turns are prepended as context.
     */
    protected function buildFullInput(): string
    {
        if (empty($this->messages)) {
            return '';
        }

        if (count($this->messages) === 1) {
            return $this->messages[0]['content'];
        }

        $parts = [];
        foreach ($this->messages as $msg) {
            $role = ucfirst($msg['role']);
            $parts[] = "{$role}: {$msg['content']}";
        }

        return implode("\n\n", $parts);
    }
}
