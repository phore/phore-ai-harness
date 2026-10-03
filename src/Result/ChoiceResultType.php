<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/** @internal Structured index result for AiContext::choice(). */
final readonly class ChoiceResultType
{
    public function __construct(public int $index)
    {
    }
}
