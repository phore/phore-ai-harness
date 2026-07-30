<?php

declare(strict_types=1);

namespace Phore\AiHarness\OutputFormat;

interface OutputFormat
{
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
