<?php

namespace Ubxty\BedrockAi\Exceptions;

/**
 * Raised when all credential keys are exhausted under rate limiting.
 *
 * @deprecated since 0.1.0 — extend Ubxty\CoreAi\Exceptions\AiException directly.
 */
class RateLimitException extends BedrockException
{
}
