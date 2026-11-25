<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

/**
 * Immutable context passed to AI filesystem mutation hooks.
 *
 * beforeContent is null for create operations and afterContent is null for
 * delete operations. Edit and move operations provide both values. For moves,
 * path/realPath identify the source; targetPath/targetRealPath identify the destination.
 *
 * @example $fs->onAfterEdit(fn (FileOperationContext $event) => validateFile($event->realPath));
 * @see AiFileSystem::onAfterEdit()
 */
final readonly class FileOperationContext
{
    public function __construct(
        public string $fileSystemId,
        public string $operation,
        public string $path,
        public string $realPath,
        public ?string $beforeContent,
        public ?string $afterContent,
        public ?string $targetPath = null,
        public ?string $targetRealPath = null,
    ) {
    }
}
