<?php

declare(strict_types=1);

namespace Phore\AiHarness\Logging;

final readonly class RunStatistics
{
    /**
     * Immutable invocation statistics. Cached tokens are a subset of tokens_in,
     * never additional tokens; null means no cached-token usage was reported.
     * The optional trailing parameter preserves existing constructor calls.
     *
     * @example $cached = $event->statistics?->tokens_cached;
     * @see ConsoleLogger
     * @see RunContext::finish()
     */
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
        public ?int $tokens_cached = null,
    ) {
    }
}
