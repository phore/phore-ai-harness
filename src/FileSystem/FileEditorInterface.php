<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

use Phore\AiHarness\AiContext;

interface FileEditorInterface
{
    public function supports(string $path): bool;

    public function edit(string $path, string $instruction, AiContext $context): string;
}
