<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

interface StructuredFileEditorInterface extends FileEditorInterface
{
    /**
     * Return a model-friendly structural view for one text snapshot.
     *
     * @return array<string, mixed>
     */
    public function structure(string $path, string $content): array;

    /**
     * Apply one structural operation addressed by a stable section ID.
     */
    public function applyStructure(
        string $path,
        string $content,
        string $action,
        string $sectionId,
        ?string $markdown = null,
        ?string $referenceId = null,
    ): string;
}
