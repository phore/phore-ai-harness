<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

final readonly class ComputerUseTool extends AbstractToolType
{
    protected const PROVIDER_TYPES = [
        'open_ai' => 'computer_use_preview',
    ];
}
