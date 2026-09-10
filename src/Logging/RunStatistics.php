<?php

declare(strict_types=1);

namespace Phore\AiHarness\Logging;

final readonly class RunStatistics
{
    public function __construct(
        public string $status,
        public int $requests,
        public int $tool_calls,
        public int $errors,
        public int $retries,
        public ?int $tokens_in,
        public ?int $tokens_out,
        public ?int $tokens_total,
        public float $duration_total,
        public float $duration_api,
        public float $duration_tools,
    ) {
    }
}
