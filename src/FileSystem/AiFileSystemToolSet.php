<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

use InvalidArgumentException;
use LogicException;
use Phore\AiHarness\ToolType\AiToolSet;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\RecoverableToolException;
use RuntimeException;

final class AiFileSystemToolSet implements AiToolSet
{
    /** @var array<string, AiFileSystem> */
    private array $fileSystems = [];

    /** @var list<CallbackTool> */
    private array $tools;

    public function __construct()
    {
        $this->tools = [
            new CallbackTool(
                [$this, 'listFileSystems'],
                'filesystem_list_systems',
                'List AI filesystems with IDs, aliases, descriptions and source roots.',
            ),
            new CallbackTool(
                [$this, 'listFiles'],
                'filesystem_list',
                'List files or directories in one AI filesystem with bounded pagination.',
            ),
            new CallbackTool(
                [$this, 'grep'],
                'filesystem_grep',
                'Search literal text in searchable UTF-8 files with surrounding line context.',
            ),
            new CallbackTool(
                [$this, 'readFile'],
                'filesystem_read',
                'Read a bounded line range from one searchable UTF-8 file.',
            ),
            new CallbackTool(
                [$this, 'editFile'],
                'filesystem_edit',
                'Apply exact search/replacement edits to one editable file.',
            ),
            new CallbackTool(
                [$this, 'history'],
                'filesystem_history',
                'List saved revisions for one file when revision history is configured.',
            ),
            new CallbackTool(
                [$this, 'restore'],
                'filesystem_restore',
                'Restore a saved revision of one editable file.',
            ),
        ];
    }

    /**
     * Return the stable callback tools contributed by this set.
     *
     * @return list<CallbackTool>
     * @example $context->addToolSet(new AiFileSystemToolSet());
     * @see AiToolSet
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Register one filesystem routing target by its stable ID.
     *
     * @return $this
     * @throws InvalidArgumentException When another filesystem already uses the ID.
     * @example $toolSet->addFileSystem($fileSystem);
     * @see AiFileSystem::__construct()
     */
    public function addFileSystem(AiFileSystem $fileSystem): self
    {
        if (isset($this->fileSystems[$fileSystem->id])) {
            if ($this->fileSystems[$fileSystem->id] === $fileSystem) {
                return $this;
            }
            throw new InvalidArgumentException('Duplicate AI filesystem ID: ' . $fileSystem->id);
        }

        $this->fileSystems[$fileSystem->id] = $fileSystem;

        return $this;
    }

    public function getFileSystem(string $id): ?AiFileSystem
    {
        return $this->fileSystems[$id] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function listFileSystems(): array
    {
        return array_map(
            static fn (AiFileSystem $fileSystem): array => $fileSystem->describe(),
            array_values($this->fileSystems),
        );
    }

    /** @return array<string, mixed> */
    public function listFiles(
        string $fileSystemId,
        string $path = '',
        bool $recursive = false,
        int $offset = 0,
        int $limit = 50,
    ): array {
        return $this->call(
            $fileSystemId,
            static fn (AiFileSystem $fileSystem): array => $fileSystem->list(
                $path,
                $recursive,
                $offset,
                $limit,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function grep(
        string $fileSystemId,
        string $query,
        string $path = '',
        int $before = 2,
        int $after = 2,
        int $offset = 0,
        int $limit = 20,
    ): array {
        return $this->call(
            $fileSystemId,
            static fn (AiFileSystem $fileSystem): array => $fileSystem->grep(
                $query,
                $path,
                $before,
                $after,
                $offset,
                $limit,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function readFile(
        string $fileSystemId,
        string $path,
        int $startLine = 1,
        int $lineCount = 200,
    ): array {
        return $this->call(
            $fileSystemId,
            static fn (AiFileSystem $fileSystem): array => $fileSystem->read(
                $path,
                $startLine,
                $lineCount,
            ),
        );
    }

    /**
     * @param \Phore\AiHarness\Edit\TextReplacement[] $edits Exact edits against the original file content.
     * @return array<string, mixed>
     */
    public function editFile(string $fileSystemId, string $path, array $edits): array
    {
        return $this->call(
            $fileSystemId,
            static fn (AiFileSystem $fileSystem): array => $fileSystem->edit($path, $edits),
        );
    }

    /** @return array<string, mixed> */
    public function history(string $fileSystemId, string $path, int $limit = 20): array
    {
        return $this->call(
            $fileSystemId,
            static fn (AiFileSystem $fileSystem): array => $fileSystem->history($path, $limit),
        );
    }

    /** @return array<string, mixed> */
    public function restore(string $fileSystemId, string $path, int $revisionId): array
    {
        return $this->call(
            $fileSystemId,
            static fn (AiFileSystem $fileSystem): array => $fileSystem->restore($path, $revisionId),
        );
    }

    private function call(string $fileSystemId, callable $callback): array
    {
        $fileSystem = $this->fileSystems[$fileSystemId] ?? null;
        if ($fileSystem === null) {
            throw new RecoverableToolException('Unknown AI filesystem ID: ' . $fileSystemId);
        }

        try {
            return $callback($fileSystem);
        } catch (InvalidArgumentException|LogicException|RuntimeException $error) {
            throw new RecoverableToolException($error->getMessage(), previous: $error);
        }
    }
}
