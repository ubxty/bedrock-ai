<?php

namespace Ubxty\BedrockAi\Events;

use Ubxty\CoreAi\Events\AiKeyRotated;

/**
 * Fired when Bedrock rotates from one credential key to the next.
 *
 * @deprecated since 0.1.0 — listen to Ubxty\CoreAi\Events\AiKeyRotated for
 *             multi-platform code. BedrockKeyRotated is retained as a BC
 *             alias so existing `Event::listen(BedrockKeyRotated::class, …)`
 *             registrations keep firing.
 */
class BedrockKeyRotated extends AiKeyRotated
{
    public function __construct(
        string $fromKeyLabel,
        string $toKeyLabel,
        string $reason,
        string $modelId,
    ) {
        parent::__construct(
            fromKeyLabel: $fromKeyLabel,
            toKeyLabel: $toKeyLabel,
            reason: $reason,
            modelId: $modelId,
            platform: 'AWS Bedrock',
        );
    }
}
