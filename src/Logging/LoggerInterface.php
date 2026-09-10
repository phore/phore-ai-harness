<?php

declare(strict_types=1);

namespace Phore\AiHarness\Logging;

interface LoggerInterface
{
    /** Receives sanitized events. Implementations should not throw. */
    public function log(LogEvent $event): void;
}
