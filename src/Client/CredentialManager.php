<?php

namespace Ubxty\BedrockAi\Client;

use Ubxty\BedrockAi\Exceptions\ConfigurationException;

class CredentialManager
{
    /** @var array<int, array{label: string, auth_mode: string, aws_key: string, aws_secret: string, bearer_token: string, region: string}> */
    protected array $keys = [];

    protected int $currentIndex = 0;

    public function __construct(array $keys)
    {
        if (empty($keys)) {
            throw new ConfigurationException('No AWS credential keys configured.');
        }

        $this->keys = array_values(array_map([$this, 'normalizeKey'], $keys));
    }

    /**
     * Normalize a key config, resolving auth_mode from explicit field or auto-detection.
     */
    protected function normalizeKey(array $key): array
    {
        $authMode = $key['auth_mode'] ?? null;

        if (! $authMode) {
            if (! empty($key['bearer_token'])) {
                $authMode = 'bearer';
            } elseif (str_starts_with($key['aws_key'] ?? '', 'ABSK') || str_starts_with($key['aws_secret'] ?? '', 'ABSK')) {
                $authMode = 'bearer';
                $key['bearer_token'] = str_starts_with($key['aws_key'] ?? '', 'ABSK')
                    ? $key['aws_key']
                    : $key['aws_secret'];
            } else {
                $authMode = 'iam';
            }
        }

        $key['auth_mode'] = $authMode;

        if (empty($key['bearer_token']) && $authMode === 'bearer') {
            if (str_starts_with($key['aws_key'] ?? '', 'ABSK')) {
                $key['bearer_token'] = $key['aws_key'];
            } elseif (str_starts_with($key['aws_secret'] ?? '', 'ABSK')) {
                $key['bearer_token'] = $key['aws_secret'];
            }
        }

        return $key;
    }

    /**
     * Get the current credential set.
     *
     * @return array{label: string, auth_mode: string, aws_key: string, aws_secret: string, bearer_token: string, region: string}
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
     * Check if the current key uses HTTP Bearer token mode.
     */
    public function isBearerMode(): bool
    {
        return ($this->current()['auth_mode'] ?? 'iam') === 'bearer';
    }

    /**
     * @deprecated Use isBearerMode() instead.
     */
    public function isHttpMode(): bool
    {
        return $this->isBearerMode();
    }

    /**
     * Get the Bearer token for HTTP mode.
     */
    public function getBearerToken(): string
    {
        $key = $this->current();

        if (! empty($key['bearer_token'])) {
            return $key['bearer_token'];
        }

        throw new ConfigurationException('Bearer token not configured for this key. Set auth_mode to "bearer" and provide bearer_token.');
    }

    /**
     * @deprecated Use getBearerToken() instead.
     */
    public function getHttpBearerToken(): string
    {
        return $this->getBearerToken();
    }

    /**
     * Get all keys (labels and regions only, no secrets).
     *
     * @return array<int, array{index: int, label: string, region: string, auth_mode: string, configured: bool}>
     */
    public function list(): array
    {
        return array_map(function (array $key, int $index) {
            $authMode = $key['auth_mode'] ?? 'iam';
            $configured = $authMode === 'bearer'
                ? ! empty($key['bearer_token'])
                : (! empty($key['aws_key']) && ! empty($key['aws_secret']));

            return [
                'index' => $index,
                'label' => $key['label'] ?? 'Key ' . ($index + 1),
                'region' => $key['region'] ?? 'us-east-1',
                'auth_mode' => $authMode,
                'configured' => $configured,
            ];
        }, $this->keys, array_keys($this->keys));
    }
}
