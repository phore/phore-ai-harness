<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem\Helper;

use Phore\AiHarness\FileSystem\Dto\FileChange;

/** @internal Tracks successful mutations without scanning the filesystem. */
final class ChangeSetTracker
{
    /** @var array<int, array{originalPath: string, originalContent: ?string, path: ?string, content: ?string}> */
    private array $files = [];

    /** @var array<string, int> */
    private array $active = [];

    public function reset(): void
    {
        $this->files = [];
        $this->active = [];
    }

    public function record(string $path, ?string $before, ?string $after, ?string $targetPath = null): void
    {
        if ($targetPath === null && $before === $after) {
            return;
        }

        // Eine Datei behaelt ihre Identitaet auch ueber mehrere Moves hinweg.
        $id = $this->active[$path] ?? null;
        if ($id === null && $before === null) {
            foreach ($this->files as $candidateId => $file) {
                if ($file['path'] === null && $file['originalPath'] === $path && $file['originalContent'] !== null) {
                    $id = $candidateId;
                    break;
                }
            }
        }

        if ($id === null) {
            $this->files[] = [
                'originalPath' => $path,
                'originalContent' => $before,
                'path' => $path,
                'content' => $before,
            ];
            $id = array_key_last($this->files);
        }

        // Der erste Snapshot bleibt erhalten, nur der Endzustand aendert sich.
        unset($this->active[$path]);
        $this->files[$id]['path'] = $after === null ? null : ($targetPath ?? $path);
        $this->files[$id]['content'] = $after;
        if ($after !== null) {
            $this->active[$targetPath ?? $path] = $id;
        } elseif ($this->files[$id]['originalContent'] === null) {
            unset($this->files[$id]);
        }
    }

    /** @return list<FileChange> */
    public function changes(): array
    {
        $changes = [];
        foreach ($this->files as $file) {
            $old = $file['originalContent'];
            $new = $file['content'];
            $originalPath = $file['originalPath'];
            $path = $file['path'] ?? $originalPath;

            // Unveraenderte Endzustaende werden nicht exportiert.
            if ($old === $new && $originalPath === $path) {
                continue;
            }

            $status = match (true) {
                $old === null => 'created',
                $new === null => 'deleted',
                $path !== $originalPath => 'moved',
                default => 'modified',
            };
            $changes[] = new FileChange(
                $path,
                $status,
                $status === 'moved' ? $originalPath : null,
                $old,
                $new,
            );
        }

        return $changes;
    }
}
