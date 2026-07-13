<?php

namespace Ubxty\BedrockAi\Events;

use Ubxty\CoreAi\Events\AiRateLimited;

/**
 * Fired when all Bedrock credential keys are exhausted under rate limiting.
 *
 * @deprecated since 0.1.0 — listen to Ubxty\CoreAi\Events\AiRateLimited for
 *             multi-platform code. BedrockRateLimited is retained as a BC
 *             alias so existing `Event::listen(BedrockRateLimited::class, …)`
 *             registrations keep firing.
 */
class BedrockRateLimited extends AiRateLimited
{
    public function __construct(
        string $modelId,
        string $keyLabel,
        int $retryAttempt,
        int $waitSeconds,
    ) {
        parent::__construct(
            modelId: $modelId,
            keyLabel: $keyLabel,
            retryAttempt: $retryAttempt,
            waitSeconds: $waitSeconds,
            platform: 'AWS Bedrock',
        );
    }
}
