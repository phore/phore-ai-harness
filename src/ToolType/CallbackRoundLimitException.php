<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

/** The shared callback budget was exhausted; no further tool was executed. */
final class CallbackRoundLimitException extends \RuntimeException
{
}
