<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

interface RevisionStoreInterface
{
    public function save(string $fileSystemId, string $path, string $content): int;

    /** @return list<array{id:int, createdAt:string}> */
    public function history(string $fileSystemId, string $path, int $limit = 20): array;

    public function get(string $fileSystemId, string $path, int $revisionId): ?string;
}
