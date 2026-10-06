<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

use Generator;
use InvalidArgumentException;
use LogicException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\Edit\TextEditEngine;
use RuntimeException;
use Throwable;

final class AiFileSystem
{
    /** @var array<string, array{type: 'root'|'file', path: string, searchable: bool, editable: bool, creatable: bool, deletable: bool}> */
    private array $sources = [];

    /** @var array<string, list<callable(FileOperationContext): void>> */
    private array $hooks = [
        'beforeCreate' => [],
        'afterCreate' => [],
        'beforeEdit' => [],
        'afterEdit' => [],
        'beforeDelete' => [],
        'afterDelete' => [],
    ];

    public readonly string $id;
    public readonly ?string $alias;
    public readonly ?string $description;

    /**
     * Bind a controlled filesystem to an AI context.
     *
     * The constructor registers this instance in the context's shared
     * AiFileSystemToolSet. Model-visible paths stay below explicitly added roots
     * or files; arbitrary host paths are never accepted.
     *
     * @param AiContext $context Context that receives the shared filesystem tools.
     * @param string|null $id Stable routing ID; generated when omitted.
     * @param string|null $alias Optional human-readable name.
     * @param string|null $description Optional purpose shown by filesystem_list_systems.
     * @param FileSystemPolicy $policy Pattern and size limits for this filesystem.
     * @param RevisionStoreInterface|null $revisionStore Optional history/restore backend.
     * @throws InvalidArgumentException For invalid IDs, aliases or descriptions.
     * @example $fs = new AiFileSystem($context, id: 'app', alias: 'backend');
     * @see AiFileSystemToolSet
     */
    public function __construct(
        private readonly AiContext $context,
        ?string $id = null,
        ?string $alias = null,
        ?string $description = null,
        private readonly FileSystemPolicy $policy = new FileSystemPolicy(),
        private readonly ?RevisionStoreInterface $revisionStore = null,
    ) {
        $this->id = $this->normalizeId($id ?? 'fs_' . bin2hex(random_bytes(8)));
        $this->alias = $this->normalizeOptionalText($alias, 'alias');
        $this->description = $this->normalizeOptionalText($description, 'description');

        $toolSet = $context->getToolSet(AiFileSystemToolSet::class);
        if ($toolSet === null) {
            $toolSet = new AiFileSystemToolSet();
            $context->addToolSet($toolSet);
        }
        $toolSet->addFileSystem($this);
    }

    /**
     * Add a directory root under a virtual top-level name.
     *
     * @return $this
     * @throws RuntimeException When the directory cannot be resolved.
     * @example $fs->addRoot('/srv/app', 'app', editable: true);
     * @see addFile()
     */
    public function addRoot(
        string $path,
        ?string $alias = null,
        bool $searchable = true,
        bool $editable = false,
        bool $creatable = false,
        bool $deletable = false,
    ): self {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath) || !is_readable($realPath)) {
            throw new RuntimeException('Cannot read AI filesystem root: ' . $path);
        }

        $this->addSource(
            $alias ?? basename($realPath),
            [
                'type' => 'root',
                'path' => $realPath,
                'searchable' => $searchable,
                'editable' => $editable,
                'creatable' => $creatable,
                'deletable' => $deletable,
            ],
        );

        return $this;
    }

    /**
     * Add one explicit file under a virtual top-level name.
     *
     * @return $this
     * @throws RuntimeException When the file cannot be resolved or read.
     * @example $fs->addFile('/srv/shared/README.md', 'shared-readme', editable: true);
     * @see addRoot()
     */
    public function addFile(
        string $path,
        ?string $alias = null,
        bool $searchable = true,
        bool $editable = false,
        bool $deletable = false,
    ): self {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new RuntimeException('Cannot read AI filesystem file: ' . $path);
        }

        $this->addSource(
            $alias ?? basename($realPath),
            [
                'type' => 'file',
                'path' => $realPath,
                'searchable' => $searchable,
                'editable' => $editable,
                'creatable' => false,
                'deletable' => $deletable,
            ],
        );

        return $this;
    }

    /**
     * Register a check that runs before a new file is created.
     *
     * Throwing a RuntimeException vetoes the operation before any write.
     *
     * @return $this
     * @example $fs->onBeforeCreate(fn (FileOperationContext $event) => assertPathAllowed($event->path));
     * @see FileOperationContext
     */
    public function onBeforeCreate(callable $hook): self
    {
        return $this->addHook('beforeCreate', $hook);
    }

    /**
     * Register a check that runs after a new file was written.
     *
     * When the hook throws, the newly created file is removed again before the
     * exception leaves the filesystem.
     *
     * @return $this
     * @example $fs->onAfterCreate(fn (FileOperationContext $event) => validateFile($event->realPath));
     * @see FileOperationContext
     */
    public function onAfterCreate(callable $hook): self
    {
        return $this->addHook('afterCreate', $hook);
    }

    /**
     * Register a check that runs before an existing file is edited.
     *
     * @return $this
     * @example $fs->onBeforeEdit(fn (FileOperationContext $event) => assertEditable($event->path));
     * @see FileOperationContext
     */
    public function onBeforeEdit(callable $hook): self
    {
        return $this->addHook('beforeEdit', $hook);
    }

    /**
     * Register a validator that runs after an edited file was written.
     *
     * A thrown exception rolls the file back to its previous content. Runtime
     * exceptions become recoverable tool feedback, so the model can retry with
     * a corrected edit.
     *
     * @return $this
     * @example $fs->onAfterEdit(fn (FileOperationContext $event) => validateFile($event->realPath));
     * @see FileOperationContext
     */
    public function onAfterEdit(callable $hook): self
    {
        return $this->addHook('afterEdit', $hook);
    }

    /**
     * Register a check that runs before an existing file is deleted.
     *
     * @return $this
     * @example $fs->onBeforeDelete(fn (FileOperationContext $event) => protectConfig($event->path));
     * @see FileOperationContext
     */
    public function onBeforeDelete(callable $hook): self
    {
        return $this->addHook('beforeDelete', $hook);
    }

    /**
     * Register a check that runs after a file was deleted.
     *
     * A thrown exception recreates the original file before it is propagated.
     *
     * @return $this
     * @example $fs->onAfterDelete(fn (FileOperationContext $event) => auditDelete($event->path));
     * @see FileOperationContext
     */
    public function onAfterDelete(callable $hook): self
    {
        return $this->addHook('afterDelete', $hook);
    }

    /**
     * Return model-visible metadata without traversing any roots.
     *
     * @return array{id: string, alias: ?string, description: ?string, sources: list<array<string, mixed>>}
     * @example $metadata = $fs->describe();
     * @see AiFileSystemToolSet::listFileSystems()
     */
    public function describe(): array
    {
        $sources = [];
        foreach ($this->sources as $alias => $source) {
            $sources[] = [
                'path' => $alias,
                'type' => $source['type'],
                'searchable' => $source['searchable'],
                'editable' => $source['editable'],
                'creatable' => $source['creatable'],
                'deletable' => $source['deletable'],
            ];
        }

        return [
            'id' => $this->id,
            'alias' => $this->alias,
            'description' => $this->description,
            'sources' => $sources,
        ];
    }

    /**
     * List virtual filesystem entries with bounded offset/limit pagination.
     *
     * @return array{items: list<array<string, mixed>>, offset: int, nextOffset: ?int}
     * @example $page = $fs->list('app/src', recursive: true, limit: 50);
     * @see grep()
     */
    public function list(string $path = '', bool $recursive = false, int $offset = 0, int $limit = 50): array
    {
        $offset = max(0, $offset);
        $limit = max(1, min($this->policy->maxListLimit, $limit));
        $items = [];
        $seen = 0;
        $hasMore = false;

        foreach ($this->entries($path, $recursive) as $entry) {
            if ($seen++ < $offset) {
                continue;
            }
            if (count($items) >= $limit) {
                $hasMore = true;
                break;
            }
            $items[] = $entry;
        }

        return [
            'items' => $items,
            'offset' => $offset,
            'nextOffset' => $hasMore ? $offset + count($items) : null,
        ];
    }

    /**
     * Search literal text in searchable UTF-8 files and return line context.
     *
     * @return array{matches: list<array<string, mixed>>, offset: int, nextOffset: ?int}
     * @example $matches = $fs->grep('deprecatedMethod', 'app/src', before: 2, after: 2);
     * @see read()
     */
    public function grep(
        string $query,
        string $path = '',
        int $before = 2,
        int $after = 2,
        int $offset = 0,
        int $limit = 20,
    ): array {
        if ($query === '') {
            throw new InvalidArgumentException('AI filesystem grep query must not be empty.');
        }

        $before = max(0, min(20, $before));
        $after = max(0, min(20, $after));
        $offset = max(0, $offset);
        $limit = max(1, min($this->policy->maxSearchResults, $limit));
        $matches = [];
        $seen = 0;
        $hasMore = false;

        foreach ($this->files($path) as [$virtualPath, $realPath, $source]) {
            if (!$this->canSearch($virtualPath, $source)) {
                continue;
            }
            try {
                $content = $this->readTextFile($realPath, $virtualPath);
            } catch (RuntimeException) {
                continue;
            }
            $lines = preg_split('/\R/u', $content);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $index => $line) {
                if (!str_contains($line, $query)) {
                    continue;
                }
                if ($seen++ < $offset) {
                    continue;
                }
                if (count($matches) >= $limit) {
                    $hasMore = true;
                    break 2;
                }

                $start = max(0, $index - $before);
                $end = min(count($lines) - 1, $index + $after);
                $matches[] = [
                    'path' => $virtualPath,
                    'line' => $index + 1,
                    'excerptStartLine' => $start + 1,
                    'excerpt' => implode("\n", array_slice($lines, $start, $end - $start + 1)),
                ];
            }
        }

        return [
            'matches' => $matches,
            'offset' => $offset,
            'nextOffset' => $hasMore ? $offset + count($matches) : null,
        ];
    }

    /**
     * Read a bounded line range from a searchable UTF-8 file.
     *
     * @return array{path: string, startLine: int, endLine: int, totalLines: int, content: string}
     * @example $source = $fs->read('app/src/Service.php', startLine: 40, lineCount: 80);
     * @see grep()
     */
    public function read(string $path, int $startLine = 1, int $lineCount = 200): array
    {
        [$virtualPath, $realPath, $source] = $this->resolveFile($path);
        if (!$this->canSearch($virtualPath, $source)) {
            throw new RuntimeException('AI filesystem file is not searchable: ' . $virtualPath);
        }

        $content = $this->readTextFile($realPath, $virtualPath);
        $lines = preg_split('/\R/u', $content);
        if ($lines === false) {
            throw new RuntimeException('Cannot split AI filesystem file into lines: ' . $virtualPath);
        }

        $startLine = max(1, $startLine);
        $lineCount = max(1, min(1000, $lineCount));
        $slice = array_slice($lines, $startLine - 1, $lineCount);
        $endLine = $slice === [] ? $startLine - 1 : $startLine + count($slice) - 1;

        return [
            'path' => $virtualPath,
            'startLine' => $startLine,
            'endLine' => $endLine,
            'totalLines' => count($lines),
            'content' => implode("\n", $slice),
        ];
    }

    /**
     * Apply exact deterministic edits to one editable virtual file.
     *
     * @param list<array{search: string|null, replacement: string}> $edits
     * @return array{path: string, status: string, bytes: int, revisionId: ?int}
     * @example $fs->edit('app/README.md', [['search' => 'Old', 'replacement' => 'New']]);
     * @see history()
     */
    public function edit(string $path, array $edits): array
    {
        [$virtualPath, $realPath, $source] = $this->resolveFile($path);
        if (!$this->canEdit($virtualPath, $source)) {
            throw new RuntimeException('AI filesystem file is not editable: ' . $virtualPath);
        }

        $content = $this->readTextFile($realPath, $virtualPath);
        $updated = TextEditEngine::applyEdits($content, $edits);

        return $this->writeEditedContent($virtualPath, $realPath, $content, $updated);
    }

    /**
     * Create a UTF-8 text file inside a root that explicitly allows creation.
     *
     * The target parent directory must already exist. Before/after hooks can
     * veto or validate the operation; a failing after hook removes the new file.
     *
     * @return array{path: string, status: string, bytes: int, revisionId: ?int}
     * @throws RuntimeException When creation is not allowed or the target exists.
     * @example $fs->create('app/docs/new.md', "# New\n");
     * @see onAfterCreate()
     */
    public function create(string $path, string $content): array
    {
        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new InvalidArgumentException('Created content must be UTF-8 text: ' . $path);
        }

        [$virtualPath, $realPath, $source] = $this->resolveMutationTarget($path, mustExist: false);
        if (!$this->canCreate($virtualPath, $source)) {
            throw new RuntimeException('AI filesystem file is not creatable: ' . $virtualPath);
        }
        if (file_exists($realPath)) {
            throw new RuntimeException('AI filesystem path already exists: ' . $virtualPath);
        }

        $event = new FileOperationContext($this->id, 'create', $virtualPath, $realPath, null, $content);
        $this->runHooks('beforeCreate', $event);
        $this->revisionStore?->save($this->id, $virtualPath, null);
        $this->persistNew($realPath, $content);

        try {
            $this->runHooks('afterCreate', $event);
        } catch (Throwable $error) {
            $this->deletePersisted($realPath, $content);
            throw $error;
        }

        $revisionId = $this->revisionStore?->save($this->id, $virtualPath, $content);

        return [
            'path' => $virtualPath,
            'status' => 'created',
            'bytes' => strlen($content),
            'revisionId' => $revisionId,
        ];
    }

    /**
     * Delete one existing text file from a source that explicitly allows it.
     *
     * The original content is revisioned before deletion. A failing after hook
     * recreates the file atomically, so validators never leave a partial delete.
     *
     * @return array{path: string, status: string, revisionId: ?int}
     * @throws RuntimeException When deletion is not allowed.
     * @example $fs->delete('app/docs/obsolete.md');
     * @see restore()
     */
    public function delete(string $path): array
    {
        [$virtualPath, $realPath, $source] = $this->resolveMutationTarget($path, mustExist: true);
        if (!$this->canDelete($virtualPath, $source)) {
            throw new RuntimeException('AI filesystem file is not deletable: ' . $virtualPath);
        }

        $content = $this->readTextFile($realPath, $virtualPath);
        $event = new FileOperationContext($this->id, 'delete', $virtualPath, $realPath, $content, null);
        $this->runHooks('beforeDelete', $event);
        $this->revisionStore?->save($this->id, $virtualPath, $content);
        $this->deletePersisted($realPath, $content);

        try {
            $this->runHooks('afterDelete', $event);
        } catch (Throwable $error) {
            $this->persistNew($realPath, $content);
            throw $error;
        }

        $revisionId = $this->revisionStore?->save($this->id, $virtualPath, null);

        return [
            'path' => $virtualPath,
            'status' => 'deleted',
            'revisionId' => $revisionId,
        ];
    }

    /**
     * Return saved revisions for one file, newest first.
     *
     * @return array{enabled: bool, revisions: list<array{id: int, createdAt: string}>}
     * @example $history = $fs->history('app/README.md');
     * @see restore()
     */
    public function history(string $path, int $limit = 20): array
    {
        [$virtualPath] = $this->resolveRevisionTarget($path);

        return [
            'enabled' => $this->revisionStore !== null,
            'revisions' => $this->revisionStore?->history($this->id, $virtualPath, $limit) ?? [],
        ];
    }

    /**
     * Restore one saved revision and append the restored state as a new revision.
     *
     * @return array{path: string, restoredRevisionId: int, revisionId: int}
     * @throws LogicException When no revision store is configured.
     * @example $fs->restore('app/README.md', 12);
     * @see SqliteRevisionStore
     */
    public function restore(string $path, int $revisionId): array
    {
        if ($this->revisionStore === null) {
            throw new LogicException('AI filesystem revision history is not enabled.');
        }

        [$virtualPath, $realPath, $source] = $this->resolveRevisionTarget($path);
        if (!$this->canRestore($virtualPath, $source)) {
            throw new RuntimeException('AI filesystem file is not restorable: ' . $virtualPath);
        }

        $target = $this->revisionStore->get($this->id, $virtualPath, $revisionId);
        if ($target === null) {
            throw new RuntimeException('AI filesystem revision does not exist: ' . $revisionId);
        }

        $currentExists = is_file($realPath);
        $current = $currentExists ? $this->readTextFile($realPath, $virtualPath) : null;
        $this->revisionStore->save($this->id, $virtualPath, $current);

        // Restore kann bewusst auch den Zustand "Datei existiert nicht" abbilden.
        if ($target['exists']) {
            if ($currentExists) {
                $this->persist($realPath, $target['content'], $current);
            } else {
                $this->persistNew($realPath, $target['content']);
            }
        } elseif ($currentExists) {
            $this->deletePersisted($realPath, $current);
        }

        $newRevisionId = $this->revisionStore->save(
            $this->id,
            $virtualPath,
            $target['content'],
        );

        return [
            'path' => $virtualPath,
            'restoredRevisionId' => $revisionId,
            'revisionId' => $newRevisionId,
            'exists' => $target['exists'],
        ];
    }

    public function getContext(): AiContext
    {
        return $this->context;
    }

    private function addSource(string $alias, array $source): void
    {
        $alias = $this->normalizeVirtualName($alias);
        if (isset($this->sources[$alias])) {
            throw new InvalidArgumentException('Duplicate AI filesystem source alias: ' . $alias);
        }

        $this->sources[$alias] = $source;
    }

    private function entries(string $path, bool $recursive): Generator
    {
        $path = $this->normalizeVirtualPath($path, allowEmpty: true);
        if ($path === '') {
            foreach ($this->sources as $alias => $source) {
                if ($this->policy->isIgnored($alias)) {
                    continue;
                }
                if ($source['type'] === 'file') {
                    if (is_file($source['path'])) {
                        yield $this->fileEntry($alias, $source['path'], $source);
                    }
                    continue;
                }

                yield ['path' => $alias, 'type' => 'directory'];
                if ($recursive) {
                    yield from $this->walkDirectory($source['path'], $alias, $source, true);
                }
            }

            return;
        }

        [$virtualPath, $realPath, $source] = $this->resolve($path);
        if (is_file($realPath)) {
            yield $this->fileEntry($virtualPath, $realPath, $source);

            return;
        }

        yield from $this->walkDirectory($realPath, $virtualPath, $source, $recursive);
    }

    private function walkDirectory(
        string $realDirectory,
        string $virtualDirectory,
        array $source,
        bool $recursive,
    ): Generator {
        $names = @scandir($realDirectory);
        if ($names === false) {
            throw new RuntimeException('Cannot list AI filesystem directory: ' . $virtualDirectory);
        }

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $virtualPath = $virtualDirectory . '/' . $name;
            if ($this->policy->isIgnored($virtualPath)) {
                continue;
            }

            $candidate = realpath($realDirectory . DIRECTORY_SEPARATOR . $name);
            if ($candidate === false || !$this->isInsideSource($candidate, $source)) {
                continue;
            }

            if (is_dir($candidate)) {
                yield ['path' => $virtualPath, 'type' => 'directory'];
                if ($recursive) {
                    yield from $this->walkDirectory($candidate, $virtualPath, $source, true);
                }
                continue;
            }
            if (is_file($candidate)) {
                yield $this->fileEntry($virtualPath, $candidate, $source);
            }
        }
    }

    private function files(string $path): Generator
    {
        foreach ($this->entries($path, true) as $entry) {
            if (($entry['type'] ?? null) !== 'file') {
                continue;
            }

            [$virtualPath, $realPath, $source] = $this->resolveFile($entry['path']);
            yield [$virtualPath, $realPath, $source];
        }
    }

    private function fileEntry(string $virtualPath, string $realPath, array $source): array
    {
        return [
            'path' => $virtualPath,
            'type' => 'file',
            'bytes' => filesize($realPath) ?: 0,
            'searchable' => $this->canSearch($virtualPath, $source),
            'editable' => $this->canEdit($virtualPath, $source),
        ];
    }

    private function resolveFile(string $path): array
    {
        [$virtualPath, $realPath, $source] = $this->resolve($path);
        if (!is_file($realPath)) {
            throw new RuntimeException('AI filesystem path is not a file: ' . $virtualPath);
        }

        return [$virtualPath, $realPath, $source];
    }

    private function resolve(string $path): array
    {
        $virtualPath = $this->normalizeVirtualPath($path);
        [$sourceName, $relative] = array_pad(explode('/', $virtualPath, 2), 2, '');
        $source = $this->sources[$sourceName] ?? null;
        if ($source === null) {
            throw new RuntimeException('Unknown AI filesystem source: ' . $sourceName);
        }

        if ($source['type'] === 'file') {
            if ($relative !== '') {
                throw new RuntimeException('AI filesystem file has no child path: ' . $virtualPath);
            }

            return [$virtualPath, $source['path'], $source];
        }

        $candidate = $relative === ''
            ? $source['path']
            : realpath($source['path'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($candidate === false || !$this->isInsideSource($candidate, $source)) {
            throw new RuntimeException('AI filesystem path does not exist or leaves its root: ' . $virtualPath);
        }

        return [$virtualPath, $candidate, $source];
    }

    private function isInsideSource(string $candidate, array $source): bool
    {
        if ($source['type'] === 'file') {
            return $candidate === $source['path'];
        }

        return $candidate === $source['path']
            || str_starts_with($candidate, $source['path'] . DIRECTORY_SEPARATOR);
    }

    private function canSearch(string $virtualPath, array $source): bool
    {
        return $source['searchable'] && $this->policy->isSearchable($virtualPath);
    }

    private function canEdit(string $virtualPath, array $source): bool
    {
        return $source['editable'] && $this->policy->isEditable($virtualPath);
    }

    private function canCreate(string $virtualPath, array $source): bool
    {
        return $source['type'] === 'root'
            && $source['creatable']
            && $this->policy->isCreatable($virtualPath);
    }

    private function canDelete(string $virtualPath, array $source): bool
    {
        return $source['deletable'] && $this->policy->isDeletable($virtualPath);
    }

    private function canRestore(string $virtualPath, array $source): bool
    {
        return $this->canEdit($virtualPath, $source)
            || $this->canCreate($virtualPath, $source)
            || $this->canDelete($virtualPath, $source);
    }

    private function readTextFile(string $realPath, string $virtualPath): string
    {
        $size = filesize($realPath);
        if ($size === false || $size > $this->policy->maxReadBytes) {
            throw new RuntimeException('AI filesystem file exceeds read limit: ' . $virtualPath);
        }

        $content = @file_get_contents($realPath);
        if ($content === false) {
            throw new RuntimeException('Cannot read AI filesystem file: ' . $virtualPath);
        }
        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new RuntimeException('AI filesystem file is not UTF-8 text: ' . $virtualPath);
        }

        return $content;
    }

    private function writeEditedContent(
        string $virtualPath,
        string $realPath,
        string $original,
        string $updated,
    ): array {
        if (str_contains($updated, "\0") || preg_match('//u', $updated) !== 1) {
            throw new InvalidArgumentException('Edited result must be UTF-8 text: ' . $virtualPath);
        }
        if ($updated === $original) {
            return [
                'path' => $virtualPath,
                'status' => 'unchanged',
                'bytes' => strlen($original),
                'revisionId' => null,
            ];
        }

        $event = new FileOperationContext($this->id, 'edit', $virtualPath, $realPath, $original, $updated);
        $this->runHooks('beforeEdit', $event);
        $this->revisionStore?->save($this->id, $virtualPath, $original);
        $this->persist($realPath, $updated, $original);

        try {
            $this->runHooks('afterEdit', $event);
        } catch (Throwable $error) {
            $this->persist($realPath, $original, $updated);
            throw $error;
        }

        $revisionId = $this->revisionStore?->save($this->id, $virtualPath, $updated);

        return [
            'path' => $virtualPath,
            'status' => 'applied',
            'bytes' => strlen($updated),
            'revisionId' => $revisionId,
        ];
    }

    private function resolveMutationTarget(string $path, bool $mustExist): array
    {
        $virtualPath = $this->normalizeVirtualPath($path);
        [$sourceName, $relative] = array_pad(explode('/', $virtualPath, 2), 2, '');
        $source = $this->sources[$sourceName] ?? null;
        if ($source === null) {
            throw new RuntimeException('Unknown AI filesystem source: ' . $sourceName);
        }

        if ($source['type'] === 'file') {
            if ($relative !== '') {
                throw new RuntimeException('AI filesystem file has no child path: ' . $virtualPath);
            }
            $realPath = $source['path'];
        } else {
            if ($relative === '') {
                throw new RuntimeException('AI filesystem mutation target must be a file: ' . $virtualPath);
            }

            $realPath = $source['path'] . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = realpath(dirname($realPath));
            if ($parent === false || !$this->isInsideSource($parent, $source)) {
                throw new RuntimeException(
                    'AI filesystem target parent does not exist or leaves its root: ' . $virtualPath,
                );
            }
        }

        if ($mustExist) {
            $resolved = realpath($realPath);
            if ($resolved === false || !is_file($resolved) || !$this->isInsideSource($resolved, $source)) {
                throw new RuntimeException('AI filesystem file does not exist: ' . $virtualPath);
            }
            $realPath = $resolved;
        } elseif (file_exists($realPath)) {
            $resolved = realpath($realPath);
            if ($resolved === false || !$this->isInsideSource($resolved, $source)) {
                throw new RuntimeException('AI filesystem target leaves its root: ' . $virtualPath);
            }
            $realPath = $resolved;
        }

        return [$virtualPath, $realPath, $source];
    }

    private function resolveRevisionTarget(string $path): array
    {
        $virtualPath = $this->normalizeVirtualPath($path);
        [$sourceName] = explode('/', $virtualPath, 2);
        $source = $this->sources[$sourceName] ?? null;
        if ($source === null) {
            throw new RuntimeException('Unknown AI filesystem source: ' . $sourceName);
        }

        return $this->resolveMutationTarget($virtualPath, mustExist: false);
    }

    private function addHook(string $event, callable $hook): self
    {
        $this->hooks[$event][] = $hook;

        return $this;
    }

    private function runHooks(string $event, FileOperationContext $context): void
    {
        foreach ($this->hooks[$event] as $hook) {
            $hook($context);
        }
    }

    private function persistNew(string $path, string $content): void
    {
        if (file_exists($path)) {
            throw new RuntimeException('AI filesystem create target already exists: ' . $path);
        }

        $this->persist($path, $content);
    }

    private function deletePersisted(string $path, string $expectedContent): void
    {
        $current = @file_get_contents($path);
        if ($current === false || $current !== $expectedContent) {
            throw new RuntimeException('AI filesystem file changed since it was read: ' . $path);
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Cannot delete AI filesystem file: ' . $path);
        }
    }

    private function persist(string $path, string $content, ?string $expectedContent = null): void
    {
        if ($expectedContent !== null) {
            $current = @file_get_contents($path);
            if ($current === false || $current !== $expectedContent) {
                throw new RuntimeException('AI filesystem file changed since it was read: ' . $path);
            }
        }

        $temporary = @tempnam(dirname($path), '.phore-ai-fs-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot prepare AI filesystem write: ' . $path);
        }

        try {
            if (@file_put_contents($temporary, $content) === false) {
                throw new RuntimeException('Cannot write AI filesystem temporary file: ' . $path);
            }
            $mode = @fileperms($path);
            if ($mode !== false) {
                @chmod($temporary, $mode & 0777);
            }
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Cannot replace AI filesystem file: ' . $path);
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function normalizeId(string $id): string
    {
        $id = trim($id);
        if ($id === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $id) !== 1) {
            throw new InvalidArgumentException(
                'AI filesystem ID may contain only letters, digits, dot, underscore, colon and dash.',
            );
        }

        return $id;
    }

    private function normalizeOptionalText(?string $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('AI filesystem ' . $label . ' must not be empty.');
        }

        return $value;
    }

    private function normalizeVirtualName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        if (
            $name === ''
            || str_contains($name, '/')
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name) !== 1
        ) {
            throw new InvalidArgumentException('AI filesystem source alias must be one safe path segment.');
        }

        return $name;
    }

    private function normalizeVirtualPath(string $path, bool $allowEmpty = false): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' && $allowEmpty) {
            return '';
        }
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('AI filesystem path must not be empty.');
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException('AI filesystem path contains an invalid segment: ' . $path);
            }
        }

        return $path;
    }
}
