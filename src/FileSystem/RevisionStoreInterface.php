<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

interface RevisionStoreInterface
{
    /**
     * Save one filesystem state. A null content value represents a missing file.
     */
    public function save(string $fileSystemId, string $path, ?string $content): int;

    /** @return list<array{id: int, createdAt: string, exists: bool}> */
    public function history(string $fileSystemId, string $path, int $limit = 20): array;

    /** @return array{exists: bool, content: ?string}|null */
    public function get(string $fileSystemId, string $path, int $revisionId): ?array;
}
