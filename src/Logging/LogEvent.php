<?php

declare(strict_types=1);

namespace Phore\AiHarness\Logging;

final readonly class LogEvent
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $model,
        public string $type,
        public string $level = 'debug',
        public string $message = '',
        public array $context = [],
        public ?RunStatistics $statistics = null,
        public string $runId = '',
    ) {
    }
}
