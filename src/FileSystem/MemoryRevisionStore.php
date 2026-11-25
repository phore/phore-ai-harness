<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

final class MemoryRevisionStore implements RevisionStoreInterface
{
    /** @var array<string, list<array{id: int, createdAt: string, exists: bool, content: ?string}>> */
    private array $revisions = [];

    private int $nextId = 1;

    /**
     * Save one filesystem state in process memory.
     *
     * Equal consecutive states are deduplicated and return the existing
     * revision ID. The store is intentionally process-local; use
     * SqliteRevisionStore when history must survive between sessions.
     *
     * @return int Stable revision ID for this store instance.
     * @example $revisionId = $store->save('backend', 'docs/a.md', "text\n");
     * @see SqliteRevisionStore
     */
    public function save(string $fileSystemId, string $path, ?string $content): int
    {
        $key = $this->key($fileSystemId, $path);
        $history = $this->revisions[$key] ?? [];
        $latest = $history === [] ? null : $history[array_key_last($history)];

        if (
            $latest !== null
            && $latest['exists'] === ($content !== null)
            && $latest['content'] === $content
        ) {
            return $latest['id'];
        }

        $revision = [
            'id' => $this->nextId++,
            'createdAt' => gmdate('c'),
            'exists' => $content !== null,
            'content' => $content,
        ];
        $this->revisions[$key][] = $revision;

        return $revision['id'];
    }

    /**
     * List recent states for one virtual path, newest first.
     *
     * @return list<array{id: int, createdAt: string, exists: bool}>
     * @example $history = $store->history('backend', 'docs/a.md');
     * @see RevisionStoreInterface::history()
     */
    public function history(string $fileSystemId, string $path, int $limit = 20): array
    {
        $history = array_reverse($this->revisions[$this->key($fileSystemId, $path)] ?? []);
        $history = array_slice($history, 0, max(1, min(100, $limit)));

        return array_map(
            static fn (array $revision): array => [
                'id' => $revision['id'],
                'createdAt' => $revision['createdAt'],
                'exists' => $revision['exists'],
            ],
            $history,
        );
    }

    /**
     * Load one exact historical state.
     *
     * @return array{exists: bool, content: ?string}|null
     * @example $state = $store->get('backend', 'docs/a.md', 1);
     * @see RevisionStoreInterface::get()
     */
    public function get(string $fileSystemId, string $path, int $revisionId): ?array
    {
        foreach ($this->revisions[$this->key($fileSystemId, $path)] ?? [] as $revision) {
            if ($revision['id'] !== $revisionId) {
                continue;
            }

            return [
                'exists' => $revision['exists'],
                'content' => $revision['content'],
            ];
        }

        return null;
    }

    /** @internal Export process-local revisions as authenticated JSON data. */
    public function exportInteractiveState(): array
    {
        return ['revisions' => $this->revisions, 'nextId' => $this->nextId];
    }

    /** @internal Restore revisions from the authenticated continuation. */
    public function importInteractiveState(array $state): void
    {
        $this->revisions = $state['revisions'];
        $this->nextId = $state['nextId'];
    }

    private function key(string $fileSystemId, string $path): string
    {
        return $fileSystemId . "\0" . $path;
    }
}
