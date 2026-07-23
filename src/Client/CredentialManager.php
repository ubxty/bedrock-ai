<?php

namespace Ubxty\BedrockAi\Client;

use Ubxty\CoreAi\Client\AbstractCredentialManager;
use Ubxty\CoreAi\Exceptions\ConfigurationException;

/**
 * AWS Bedrock credential manager with multi-key rotation and bearer/IAM
 * auth mode auto-detection (ABSK-prefixed keys are routed to bearer mode).
 */
class CredentialManager extends AbstractCredentialManager
{
    /**
     * Construct from an array of credential configs. The error message
     * wording is preserved from the pre-AbstractCredentialManager era so
     * existing test assertions keep matching.
     *
     * @param  array<int, array<string, mixed>>  $keys
     */
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
     * Check if the current key uses Bearer token authentication.
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
     * Get the Bearer token for the current key.
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
     * Get all keys with safe info only (no secrets). Adds region and
     * auth_mode on top of the parent's index/label/configured shape.
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
