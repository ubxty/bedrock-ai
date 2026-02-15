<?php

namespace Ubxty\BedrockAi\Client;

use Ubxty\BedrockAi\Exceptions\ConfigurationException;

class CredentialManager
{
    /** @var array<int, array{label: string, aws_key: string, aws_secret: string, region: string}> */
    protected array $keys = [];

    protected int $currentIndex = 0;

    public function __construct(array $keys)
    {
        if (empty($keys)) {
            throw new ConfigurationException('No AWS credential keys configured.');
        }

        $this->keys = array_values($keys);
    }

    /**
     * Get the current credential set.
     *
     * @return array{label: string, aws_key: string, aws_secret: string, region: string}
     */
    public function current(): array
    {
        return $this->keys[$this->currentIndex];
    }

    /**
     * Advance to the next credential set. Returns false if no more keys.
     */
    public function next(): bool
    {
        if ($this->currentIndex + 1 >= count($this->keys)) {
            return false;
        }

        $this->currentIndex++;

        return true;
    }

    /**
     * Reset to the first credential set.
     */
    public function reset(): void
    {
        $this->currentIndex = 0;
    }

    /**
     * Select a specific key by index.
     */
    public function select(int $index): void
    {
        if (! isset($this->keys[$index])) {
            throw new ConfigurationException("Key index {$index} does not exist.");
        }

        $this->currentIndex = $index;
    }

    /**
     * Get the number of available credential sets.
     */
    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * Get the current key index.
     */
    public function currentIndex(): int
    {
        return $this->currentIndex;
    }

    /**
     * Check if the current key uses HTTP Bearer token mode (ABSK tokens).
     */
    public function isHttpMode(): bool
    {
        $key = $this->current();

        return str_starts_with($key['aws_key'] ?? '', 'ABSK')
            || str_starts_with($key['aws_secret'] ?? '', 'ABSK');
    }

    /**
     * Get the Bearer token for HTTP mode.
     */
    public function getHttpBearerToken(): string
    {
        $key = $this->current();

        if (str_starts_with($key['aws_key'] ?? '', 'ABSK')) {
            return $key['aws_key'];
        }

        return $key['aws_secret'] ?? '';
    }

    /**
     * Get all keys (labels and regions only, no secrets).
     *
     * @return array<int, array{index: int, label: string, region: string, configured: bool}>
     */
    public function list(): array
    {
        return array_map(function (array $key, int $index) {
            return [
                'index' => $index,
                'label' => $key['label'] ?? 'Key ' . ($index + 1),
                'region' => $key['region'] ?? 'us-east-1',
                'configured' => ! empty($key['aws_key']) && ! empty($key['aws_secret']),
            ];
        }, $this->keys, array_keys($this->keys));
    }
}
