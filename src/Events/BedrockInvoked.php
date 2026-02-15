<?php

namespace Ubxty\BedrockAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BedrockInvoked
{
    use Dispatchable;

    public function __construct(
        public readonly string $modelId,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $cost,
        public readonly int $latencyMs,
        public readonly string $keyUsed,
        public readonly ?string $connection = null,
    ) {}
}
