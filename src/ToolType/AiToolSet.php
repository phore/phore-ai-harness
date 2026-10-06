<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

interface AiToolSet
{
    /**
     * Return the tools contributed by this set.
     *
     * @return list<ToolType>
     */
    public function getTools(): array;
}
