<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use RuntimeException;

/**
 * Signals malformed or incompatible serialized AiContext resume state.
 *
 * Provider request errors, including an expired remote response cursor, remain
 * provider exceptions and are not converted to this local validation error.
 *
 * @see AiContext::importState()
 * @see ResumeOptions
 */
class ResumeStateException extends RuntimeException
{
}
