<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/** @internal Structured boolean result for AiContext::yesNo(). */
final readonly class YesNoResultType
{
    public function __construct(
        public bool $determined,
        public bool $value,
    ) {
    }
}
