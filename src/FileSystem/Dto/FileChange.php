<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem\Dto;

/**
 * Net change of one file since change-set tracking began or was reset.
 *
 * Public properties are directly serializable with json_encode().
 * Null content means the file did not exist, not that it was empty.
 * For a move, path is the destination and oldPath is the original source.
 *
 * @see \Phore\AiHarness\FileSystem\AiFileSystem::getChangeSet()
 */
final readonly class FileChange
{
    /**
     * @param 'created'|'modified'|'deleted'|'moved' $status
     */
    public function __construct(
        public string $path,
        public string $status,
        public ?string $oldPath,
        public ?string $oldContent,
        public ?string $newContent,
    ) {
    }
}
