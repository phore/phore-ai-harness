<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/** @internal Structured normalized score for AiContext::score(). */
final readonly class ScoreResultType
{
    public function __construct(
        public bool $determined,
        public float $score,
    ) {
    }
}
