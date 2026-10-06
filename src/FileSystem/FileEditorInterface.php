<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

interface FileEditorInterface
{
    public function supports(string $path, string $content): bool;

    /**
     * Apply exact edits to one immutable text snapshot.
     *
     * @param list<array{search: string|null, replacement: string}> $edits
     */
    public function apply(string $path, string $content, array $edits): string;
}
