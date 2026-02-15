<?php

namespace Ubxty\BedrockAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BedrockRateLimited
{
    use Dispatchable;

    public function __construct(
        public readonly string $modelId,
        public readonly string $keyLabel,
        public readonly int $retryAttempt,
        public readonly int $waitSeconds,
    ) {}
}
