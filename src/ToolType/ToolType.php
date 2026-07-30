<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

interface ToolType
{
    public function available(string $provider = 'open_ai'): bool;

    public function type(string $provider = 'open_ai'): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $provider = 'open_ai'): array;
}
