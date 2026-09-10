<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

use RuntimeException;

/**
 * Signals a callback-tool error that should be returned to the model.
 *
 * All other exceptions keep their normal fatal semantics and abort the run.
 */
final class RecoverableToolException extends RuntimeException
{
}
