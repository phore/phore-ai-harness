<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

/**
 * Common contract for typed prompt fragments.
 */
interface PromptType
{
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
