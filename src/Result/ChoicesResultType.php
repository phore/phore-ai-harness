<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/** @internal Structured index-list result for AiContext::choices(). */
final readonly class ChoicesResultType
{
    /** @var list<int> */
    public array $indices;

    /** @param list<int> $indices */
    public function __construct(
        public bool $determined,
        array $indices,
    ) {
        $this->indices = $indices;
    }
}
