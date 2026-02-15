<?php

namespace Ubxty\BedrockAi\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BedrockKeyRotated
{
    use Dispatchable;

    public function __construct(
        public readonly string $fromKeyLabel,
        public readonly string $toKeyLabel,
        public readonly string $reason,
        public readonly string $modelId,
    ) {}
}
