<?php

namespace Ubxty\BedrockAi\Exceptions;

use Ubxty\CoreAi\Exceptions\AiException;

/**
 * Base exception for AWS Bedrock errors.
 *
 * @deprecated since 0.1.0 — extend Ubxty\CoreAi\Exceptions\AiException directly.
 *             Retained for BC; existing catch blocks on BedrockException continue to match.
 */
class BedrockException extends AiException
{
}
