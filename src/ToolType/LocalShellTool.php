<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

final readonly class LocalShellTool extends AbstractToolType
{
    protected const PROVIDER_TYPES = [
        'open_ai' => 'local_shell',
    ];
}
