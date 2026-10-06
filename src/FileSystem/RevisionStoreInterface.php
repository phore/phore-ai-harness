<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

interface RevisionStoreInterface
{
    /**
     * Save one filesystem state.
     *
     * A null content value represents a missing file and allows create/delete
     * operations to participate in the same restorable history as edits.
     *
     * @return int Stable revision ID.
     * @example $revisionId = $store->save('backend', 'app/docs/a.md', null);
     * @see AiFileSystem::restore()
     */
    public function save(string $fileSystemId, string $path, ?string $content): int;

    /**
     * List recent states for one virtual file path, newest first.
     *
     * @return list<array{id: int, createdAt: string, exists: bool}>
     * @example $history = $store->history('backend', 'app/docs/a.md');
     * @see get()
     */
    public function history(string $fileSystemId, string $path, int $limit = 20): array;

    /**
     * Load one exact historical state.
     *
     * The returned exists flag distinguishes an empty file from a historical
     * state where the file did not exist.
     *
     * @return array{exists: bool, content: ?string}|null
     * @example $state = $store->get('backend', 'app/docs/a.md', 42);
     * @see save()
     */
    public function get(string $fileSystemId, string $path, int $revisionId): ?array;
}
