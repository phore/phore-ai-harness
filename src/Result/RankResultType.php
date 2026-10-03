<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/** @internal Structured ranking result for AiContext::rank(). */
final readonly class RankResultType
{
    /** @var list<int> */
    public array $indices;

    /** @param list<int> $indices */
    public function __construct(array $indices)
    {
        $this->indices = $indices;
    }
}
