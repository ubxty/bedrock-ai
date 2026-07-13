<?php

namespace Ubxty\BedrockAi\Events;

use Ubxty\CoreAi\Events\AiInvoked;

/**
 * Fired after every AWS Bedrock invocation.
 *
 * @deprecated since 0.1.0 — listen to Ubxty\CoreAi\Events\AiInvoked for
 *             multi-platform code. BedrockInvoked is retained as a BC
 *             alias so existing `Event::listen(BedrockInvoked::class, …)`
 *             registrations keep firing.
 */
class BedrockInvoked extends AiInvoked
{
    public function __construct(
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        float $cost,
        int $latencyMs,
        string $keyUsed,
        ?string $connection = null,
    ) {
        parent::__construct(
            modelId: $modelId,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $cost,
            latencyMs: $latencyMs,
            keyUsed: $keyUsed,
            connection: $connection,
            platform: 'AWS Bedrock',
        );
    }
}
