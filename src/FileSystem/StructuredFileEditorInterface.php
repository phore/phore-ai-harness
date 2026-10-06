<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

/**
 * Optional extension for editors that expose addressable file structure.
 *
 * @see AiFileSystem::structure()
 */
interface StructuredFileEditorInterface extends FileEditorInterface
{
    /**
     * Return a model-friendly structural view for one text snapshot.
     *
     * @return array<string, mixed>
     * @example $tree = $editor->structure('guide.md', $markdown);
     * @see applyStructure()
     */
    public function structure(string $path, string $content): array;

    /**
     * Apply one structural operation addressed by a stable element ID.
     *
     * @example $updated = $editor->applyStructure('guide.md', $markdown, 'delete', 'mdsec_123');
     * @see structure()
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
