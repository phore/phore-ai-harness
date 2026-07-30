<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

final readonly class WebAccessTool extends AbstractToolType
{
    protected const PROVIDER_TYPES = [
        'open_ai' => 'web_search_preview',
    ];
}
