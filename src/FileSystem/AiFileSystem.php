<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

use Generator;
use InvalidArgumentException;
use LogicException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\Edit\TextEditEngine;
use Phore\AiHarness\FileSystem\Dto\FileChange;
use Phore\AiHarness\FileSystem\Helper\ChangeSetTracker;
use RuntimeException;
use Throwable;

final class AiFileSystem
{
    private ?string $root = null;
    private readonly AiContext $context;

    /** @var array<string, string> */
    private array $files = [];

    private FileSystemPolicy $policy;
    private RevisionStoreInterface $revisionStore;
    private readonly ChangeSetTracker $changeSet;

    /** @var array<string, list<callable(FileOperationContext): void>> */
    private array $hooks = [
        'beforeCreate' => [],
        'afterCreate' => [],
        'beforeEdit' => [],
        'afterEdit' => [],
        'beforeDelete' => [],
        'afterDelete' => [],
        'beforeMove' => [],
        'afterMove' => [],
        'change' => [],
        'read' => [],
    ];

    public readonly string $id;
    public readonly ?string $alias;
    public readonly ?string $description;

    /**
     * Create a controlled filesystem and bind it to an AI context.
     *
     * The root is the primary argument. When no context is supplied, the
     * filesystem creates its own AiContext; pass an existing context when
     * several AI capabilities should share one conversation. The filesystem
     * registers itself in that context's shared AiFileSystemToolSet.
     *
     * @param string|null $root Optional root directory; may also be set later with addRoot().
     * @param AiContext|null $context Optional existing context; omitted creates a new context.
     * @param string|null $id Stable routing ID; generated when omitted.
     * @param string|null $alias Optional human-readable name.
     * @param string|null $description Optional purpose shown by filesystem_list_systems.
     * @param FileSystemPolicy|null $policy Optional path policy; omitted permits reads and edits only.
     * @param RevisionStoreInterface|null $revisionStore Optional history backend; defaults to memory.
     * @throws InvalidArgumentException For invalid IDs, aliases or descriptions.
     * @param int|null $umask Optional creation mask applied to 0777 for new files; null preserves temporary-file defaults.
     *                       Existing files retain their permissions. The process umask is never changed.
     * @example $fs = new AiFileSystem('/srv/app', id: 'app');
     * @see getContext()
     * @see addRoot()
     * @see addFile()
     */
    public function __construct(
        ?string $root = null,
        ?AiContext $context = null,
        ?string $id = null,
        ?string $alias = null,
        ?string $description = null,
        ?FileSystemPolicy $policy = null,
        ?RevisionStoreInterface $revisionStore = null,
        private readonly ?int $umask = null,
    ) {
        if ($umask !== null && ($umask < 0 || $umask > 0777)) {
            throw new InvalidArgumentException('AI filesystem umask must be between 0000 and 0777.');
        }
        $this->context = $context ?? new AiContext();
        $this->id = $this->normalizeId($id ?? 'fs_' . bin2hex(random_bytes(8)));
        $this->alias = $this->normalizeOptionalText($alias, 'alias');
        $this->description = $this->normalizeOptionalText($description, 'description');
        $this->policy = $policy ?? new FileSystemPolicy();
        $this->revisionStore = $revisionStore ?? new MemoryRevisionStore();
        $this->changeSet = new ChangeSetTracker();

        if ($root !== null) {
            $this->addRoot($root);
        }

        $toolSet = $this->context->getToolSet(AiFileSystemToolSet::class);
        if ($toolSet === null) {
            $toolSet = new AiFileSystemToolSet();
            $this->context->addToolSet($toolSet);
        }
        $toolSet->addFileSystem($this);
    }

    /**
     * Add the single directory root exposed by this filesystem.
     *
     * Paths used by list/read/edit/create/delete/move are relative to this root.
     * A second root is rejected; use another AiFileSystem for another root.
     * Explicit files already registered must not be inside the root and must not
     * collide with a top-level path in the root.
     *
     * @return $this
     * @throws RuntimeException When the directory cannot be resolved or collides.
     * @throws LogicException When a root was already configured.
     * @example $fs->addRoot('/srv/app');
     * @see addFile()
     */
    public function addRoot(string $path): self
    {
        if ($this->root !== null) {
            throw new LogicException('AI filesystem root is already configured.');
        }

        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath) || !is_readable($realPath)) {
            throw new RuntimeException('Cannot read AI filesystem root: ' . $path);
        }

        foreach ($this->files as $name => $filePath) {
            if ($this->isInsidePath($filePath, $realPath)) {
                throw new RuntimeException(
                    'Explicit AI filesystem file is already inside the root: ' . $name,
                );
            }
            if (file_exists($realPath . DIRECTORY_SEPARATOR . $name)) {
                throw new RuntimeException('Duplicate AI filesystem path: ' . $name);
            }
        }

        $this->root = $realPath;

        return $this;
    }

    /**
     * Add one explicit real file under its basename.
     *
     * Explicit files work without a root and can be listed, searched, read,
     * edited and restored. Create, delete and move remain root-only operations.
     * Basenames must be unique within one AiFileSystem; aliases and virtual
     * in-memory files are intentionally not supported.
     *
     * @return $this
     * @throws RuntimeException When the file cannot be read or its basename collides.
     * @example $fs->addFile('/srv/shared/architecture.md');
     * @see addRoot()
     */
    public function addFile(string $path): self
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new RuntimeException('Cannot read AI filesystem file: ' . $path);
        }

        $name = $this->normalizeVirtualName(basename($realPath));
        if (isset($this->files[$name])) {
            throw new RuntimeException('Duplicate AI filesystem file name: ' . $name);
        }

        if ($this->root !== null) {
            if ($this->isInsidePath($realPath, $this->root)) {
                throw new RuntimeException(
                    'Explicit AI filesystem file is already inside the root: ' . $name,
                );
            }
            if (file_exists($this->root . DIRECTORY_SEPARATOR . $name)) {
                throw new RuntimeException('Duplicate AI filesystem path: ' . $name);
            }
        }

        $this->files[$name] = $realPath;

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
     * Register a check that can veto a move before any filesystem change.
     *
     * @return $this
     * @example $fs->onBeforeMove(fn (FileOperationContext $event) => protectPath($event->path));
     * @see FileOperationContext
     */
    public function onBeforeMove(callable $hook): self
    {
        return $this->addHook('beforeMove', $hook);
    }

    /**
     * Register a validator after a move; a failing hook moves the file back.
     *
     * @return $this
     * @example $fs->onAfterMove(fn (FileOperationContext $event) => validateFile($event->targetRealPath));
     * @see move()
     */
    public function onAfterMove(callable $hook): self
    {
        return $this->addHook('afterMove', $hook);
    }

    /**
     * Observe each persisted mutation after hooks, revision and change tracking.
     *
     * Includes create, edit, delete, move and restore, but not no-ops or
     * rolled-back validation hooks. Observer exceptions do not undo the write.
     * Observers should handle delivery errors independently of file validation.
     *
     * @param callable(FileOperationContext): void $hook Observer receiving the changed paths and contents.
     * @return $this
     * @throws Throwable If an observer fails after persistence.
     * @example $fs->onChange(fn (FileOperationContext $event) => notify($event->path));
     * @see getChangeSet()
     */
    public function onChange(callable $hook): self
    {
        return $this->addHook('change', $hook);
    }

    /**
     * Observe successful explicit reads and files scanned by grep.
     *
     * The event contains the filesystem ID and virtual path; beforeContent
     * and afterContent are null. Internal mutation reads do not emit events.
     * Failed or denied reads do not emit events.
     *
     * @param callable(FileOperationContext): void $hook Read observer.
     * @return $this
     * @example $fs->onRead(fn (FileOperationContext $event) => reportRead($event->path));
     * @see read()
     * @see grep()
     */
    public function onRead(callable $hook): self
    {
        return $this->addHook('read', $hook);
    }

    /**
     * Return model-visible metadata without exposing the host root path.
     *
     * @return array{id: string, alias: ?string, description: ?string, rootConfigured: bool, files: list<string>}
     * @example $metadata = $fs->describe();
     * @see AiFileSystemToolSet::listFileSystems()
     */
    public function describe(): array
    {
        return [
            'id' => $this->id,
            'alias' => $this->alias,
            'description' => $this->description,
            'rootConfigured' => $this->root !== null,
            'files' => array_keys($this->files),
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

        foreach ($this->files($path) as [$virtualPath, $realPath]) {
            if (!$this->canSearch($virtualPath)) {
                continue;
            }
            try {
                $content = $this->readTextFile($realPath, $virtualPath);
            } catch (RuntimeException) {
                continue;
            }
            $this->runHooks('read', new FileOperationContext($this->id, 'read', $virtualPath, $realPath, null, null));
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
     * @example $source = $fs->read('src/Service.php', startLine: 40, lineCount: 80);
     * @see grep()
     */
    public function read(string $path, int $startLine = 1, int $lineCount = 200): array
    {
        [$virtualPath, $realPath] = $this->resolveFile($path);
        if (!$this->canSearch($virtualPath)) {
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
        $this->runHooks('read', new FileOperationContext($this->id, 'read', $virtualPath, $realPath, null, null));

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
     * @example $fs->edit('README.md', [['search' => 'Old', 'replacement' => 'New']]);
     * @see history()
     */
    public function edit(string $path, array $edits): array
    {
        [$virtualPath, $realPath] = $this->resolveFile($path);
        if (!$this->canEdit($virtualPath)) {
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
     * @example $fs->create('docs/new.md', "# New\n");
     * @see onAfterCreate()
     */
    public function create(string $path, string $content): array
    {
        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new InvalidArgumentException('Created content must be UTF-8 text: ' . $path);
        }

        [$virtualPath, $realPath] = $this->resolveMutationTarget($path, mustExist: false);
        if (isset($this->files[$virtualPath]) || !$this->canCreate($virtualPath)) {
            throw new RuntimeException('AI filesystem file is not creatable: ' . $virtualPath);
        }
        if (file_exists($realPath)) {
            throw new RuntimeException('AI filesystem path already exists: ' . $virtualPath);
        }

        $event = new FileOperationContext($this->id, 'create', $virtualPath, $realPath, null, $content);
        $this->runHooks('beforeCreate', $event);
        $this->revisionStore->save($this->id, $virtualPath, null);
        $this->persistNew($realPath, $content);

        try {
            $this->runHooks('afterCreate', $event);
        } catch (Throwable $error) {
            $this->deletePersisted($realPath, $content);
            throw $error;
        }

        $this->changeSet->record($virtualPath, null, $content);
        $revisionId = $this->revisionStore->save($this->id, $virtualPath, $content);
        $this->runHooks('change', $event);

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
     * @example $fs->delete('docs/obsolete.md');
     * @see restore()
     */
    public function delete(string $path): array
    {
        $virtualPath = $this->normalizeVirtualPath($path);
        if (isset($this->files[$virtualPath])) {
            throw new RuntimeException('Explicit AI filesystem files cannot be deleted: ' . $virtualPath);
        }

        [$virtualPath, $realPath] = $this->resolveMutationTarget($virtualPath, mustExist: true);
        if (!$this->canDelete($virtualPath)) {
            throw new RuntimeException('AI filesystem file is not deletable: ' . $virtualPath);
        }

        $content = $this->readTextFile($realPath, $virtualPath);
        $event = new FileOperationContext($this->id, 'delete', $virtualPath, $realPath, $content, null);
        $this->runHooks('beforeDelete', $event);
        $this->revisionStore->save($this->id, $virtualPath, $content);
        $this->deletePersisted($realPath, $content);

        try {
            $this->runHooks('afterDelete', $event);
        } catch (Throwable $error) {
            $this->persistNew($realPath, $content);
            throw $error;
        }

        $this->changeSet->record($virtualPath, $content, null);
        $revisionId = $this->revisionStore->save($this->id, $virtualPath, null);
        $this->runHooks('change', $event);

        return [
            'path' => $virtualPath,
            'status' => 'deleted',
            'revisionId' => $revisionId,
        ];
    }

    /**
     * Move or rename one UTF-8 file within the root without overwriting.
     *
     * Both paths require movable permission independently of create/delete.
     * The target parent must exist on the same filesystem. Both path states
     * are revisioned and an after-hook failure reverses the move.
     *
     * @return array{path: string, targetPath: string, status: string, sourceRevisionId: ?int, revisionId: ?int}
     * @throws RuntimeException When the move is forbidden or the target exists.
     * @example $fs->move('drafts/contact.md', 'pages/contact.md');
     * @see onAfterMove()
     */
    public function move(string $path, string $targetPath): array
    {
        [$source, $sourceReal] = $this->resolveMutationTarget($path, mustExist: true);
        [$target, $targetReal] = $this->resolveMutationTarget($targetPath, mustExist: false);
        if (isset($this->files[$source]) || isset($this->files[$target])
            || !$this->policy->isMovable($source, $target)
        ) {
            throw new RuntimeException('AI filesystem move is not permitted: ' . $source . ' -> ' . $target);
        }
        if (file_exists($targetReal) || is_link($targetReal)) {
            throw new RuntimeException('AI filesystem target already exists: ' . $target);
        }

        // Snapshot both paths and allow application validation before mutation.
        $content = $this->readTextFile($sourceReal, $source);
        $event = new FileOperationContext(
            $this->id, 'move', $source, $sourceReal, $content, $content, $target, $targetReal,
        );
        $this->runHooks('beforeMove', $event);
        $this->revisionStore->save($this->id, $source, $content);
        $this->revisionStore->save($this->id, $target, null);
        $this->movePersisted($sourceReal, $targetReal, $content);

        try {
            $this->runHooks('afterMove', $event);
        } catch (Throwable $error) {
            $this->movePersisted($targetReal, $sourceReal, $content);
            throw $error;
        }

        $this->changeSet->record($source, $content, $content, $target);
        $sourceRevisionId = $this->revisionStore->save($this->id, $source, null);
        $revisionId = $this->revisionStore->save($this->id, $target, $content);
        $this->runHooks('change', $event);

        return [
            'path' => $source,
            'targetPath' => $target,
            'status' => 'moved',
            'sourceRevisionId' => $sourceRevisionId,
            'revisionId' => $revisionId,
        ];
    }

    /**
     * Return saved revisions for one file, newest first.
     *
     * @return array{enabled: bool, revisions: list<array{id: int, createdAt: string}>}
     * @example $history = $fs->history('README.md');
     * @see restore()
     */
    public function history(string $path, int $limit = 20): array
    {
        [$virtualPath] = $this->resolveRevisionTarget($path);

        return [
            'enabled' => true,
            'revisions' => $this->revisionStore->history($this->id, $virtualPath, $limit),
        ];
    }

    /**
     * Restore one saved revision and append the restored state as a new revision.
     *
     * @return array{path: string, restoredRevisionId: int, revisionId: int}
     * @throws LogicException When no revision store is configured.
     * @example $fs->restore('README.md', 12);
     * @see SqliteRevisionStore
     */
    public function restore(string $path, int $revisionId): array
    {
        [$virtualPath, $realPath] = $this->resolveRevisionTarget($path);
        $target = $this->revisionStore->get($this->id, $virtualPath, $revisionId);
        if ($target === null) {
            throw new RuntimeException('AI filesystem revision does not exist: ' . $revisionId);
        }

        $currentExists = is_file($realPath);
        $explicit = isset($this->files[$virtualPath]);
        $allowed = match (true) {
            $target['exists'] && $currentExists => $this->canEdit($virtualPath),
            $target['exists'] => !$explicit && $this->canCreate($virtualPath),
            $currentExists => !$explicit && $this->canDelete($virtualPath),
            default => !$explicit && ($this->canCreate($virtualPath) || $this->canDelete($virtualPath)),
        };
        if (!$allowed) {
            throw new RuntimeException('AI filesystem file is not restorable: ' . $virtualPath);
        }

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

        $this->changeSet->record($virtualPath, $current, $target['content']);
        $newRevisionId = $this->revisionStore->save(
            $this->id,
            $virtualPath,
            $target['content'],
        );

        if ($current !== $target['content']) {
            $this->runHooks('change', new FileOperationContext(
                $this->id, 'restore', $virtualPath, $realPath, $current, $target['content'],
            ));
        }

        return [
            'path' => $virtualPath,
            'restoredRevisionId' => $revisionId,
            'revisionId' => $newRevisionId,
            'exists' => $target['exists'],
        ];
    }

    /**
     * Return the net file changes made through this instance since its creation
     * or the last reset. Includes complete old/new contents and move paths.
     * The returned DTOs are snapshots; reading them does not clear tracking.
     * External writes and earlier PHP processes are not included.
     *
     * @return list<FileChange> Serializable file changes; empty if unchanged.
     * @example $json = json_encode($fs->getChangeSet(), JSON_THROW_ON_ERROR);
     * @see resetChangeSet()
     */
    public function getChangeSet(): array
    {
        return $this->changeSet->changes();
    }

    /**
     * Start a new change-set interval without modifying files or revisions.
     *
     * @example $fs->resetChangeSet();
     * @see getChangeSet()
     */
    public function resetChangeSet(): void
    {
        $this->changeSet->reset();
    }

    public function getContext(): AiContext
    {
        return $this->context;
    }

    private function entries(string $path, bool $recursive): Generator
    {
        $path = $this->normalizeVirtualPath($path, allowEmpty: true);

        if ($path === '') {
            if ($this->root !== null) {
                yield from $this->walkDirectory($this->root, '', $recursive);
            }

            foreach ($this->files as $name => $realPath) {
                if ($this->policy->isIgnored($name)) {
                    continue;
                }
                yield $this->fileEntry($name, $realPath);
            }

            return;
        }

        [$virtualPath, $realPath] = $this->resolve($path);
        if (is_file($realPath)) {
            yield $this->fileEntry($virtualPath, $realPath);

            return;
        }

        yield from $this->walkDirectory($realPath, $virtualPath, $recursive);
    }

    private function walkDirectory(
        string $realDirectory,
        string $virtualDirectory,
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

            $virtualPath = ltrim($virtualDirectory . '/' . $name, '/');
            if ($this->policy->isIgnored($virtualPath)) {
                continue;
            }

            $entryPath = $realDirectory . DIRECTORY_SEPARATOR . $name;
            if (is_link($entryPath)) {
                continue;
            }
            $candidate = realpath($entryPath);
            if ($candidate === false || !$this->isInsideRoot($candidate)) {
                continue;
            }

            if (is_dir($candidate)) {
                yield ['path' => $virtualPath, 'type' => 'directory'];
                if ($recursive) {
                    yield from $this->walkDirectory($candidate, $virtualPath, true);
                }
                continue;
            }

            if (is_file($candidate)) {
                yield $this->fileEntry($virtualPath, $candidate);
            }
        }
    }

    private function files(string $path): Generator
    {
        foreach ($this->entries($path, true) as $entry) {
            if (($entry['type'] ?? null) !== 'file') {
                continue;
            }

            [$virtualPath, $realPath] = $this->resolveFile($entry['path']);
            yield [$virtualPath, $realPath];
        }
    }

    private function fileEntry(string $virtualPath, string $realPath): array
    {
        return [
            'path' => $virtualPath,
            'type' => 'file',
            'bytes' => filesize($realPath) ?: 0,
            'searchable' => $this->canSearch($virtualPath),
            'editable' => $this->canEdit($virtualPath),
        ];
    }

    private function resolveFile(string $path): array
    {
        [$virtualPath, $realPath] = $this->resolve($path);
        if (!is_file($realPath)) {
            throw new RuntimeException('AI filesystem path is not a file: ' . $virtualPath);
        }

        return [$virtualPath, $realPath];
    }

    private function resolve(string $path): array
    {
        $virtualPath = $this->normalizeVirtualPath($path);

        if (isset($this->files[$virtualPath])) {
            return [$virtualPath, $this->files[$virtualPath]];
        }

        $root = $this->requireRoot();
        if ($this->policy->isIgnored($virtualPath)) {
            throw new RuntimeException('AI filesystem path is ignored: ' . $virtualPath);
        }
        // Symlink aliases must not bypass virtual-path policies or create traversal loops.
        $candidate = $root;
        foreach (explode('/', $virtualPath) as $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) {
                throw new RuntimeException('AI filesystem path cannot follow symlinks: ' . $virtualPath);
            }
        }
        $candidate = realpath($candidate);

        if ($candidate === false || !$this->isInsideRoot($candidate)) {
            throw new RuntimeException('AI filesystem path does not exist or leaves its root: ' . $virtualPath);
        }

        return [$virtualPath, $candidate];
    }

    private function isInsideRoot(string $candidate): bool
    {
        return $this->isInsidePath($candidate, $this->requireRoot());
    }

    private function isInsidePath(string $candidate, string $root): bool
    {
        return $candidate === $root
            || str_starts_with($candidate, $root . DIRECTORY_SEPARATOR);
    }

    private function canSearch(string $virtualPath): bool
    {
        return $this->policy->isSearchable($virtualPath);
    }

    private function canEdit(string $virtualPath): bool
    {
        return $this->policy->isEditable($virtualPath);
    }

    private function canCreate(string $virtualPath): bool
    {
        return $this->policy->isCreatable($virtualPath);
    }

    private function canDelete(string $virtualPath): bool
    {
        return $this->policy->isDeletable($virtualPath);
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
        $this->revisionStore->save($this->id, $virtualPath, $original);
        $this->persist($realPath, $updated, $original);

        try {
            $this->runHooks('afterEdit', $event);
        } catch (Throwable $error) {
            $this->persist($realPath, $original, $updated);
            throw $error;
        }

        $this->changeSet->record($virtualPath, $original, $updated);
        $revisionId = $this->revisionStore->save($this->id, $virtualPath, $updated);
        $this->runHooks('change', $event);

        return [
            'path' => $virtualPath,
            'status' => 'applied',
            'bytes' => strlen($updated),
            'revisionId' => $revisionId,
        ];
    }

    /** Create one directory; its parent must exist. */
    public function createDirectory(string $path): array
    {
        [$path, $real] = $this->resolveMutationTarget($path, false);
        if (!$this->policy->isDirectoryCreatable($path) || file_exists($real)) {
            throw new RuntimeException('AI filesystem directory creation denied or target exists: ' . $path);
        }
        if (!mkdir($real, 0777)) throw new RuntimeException('Cannot create AI filesystem directory: ' . $path);
        if ($this->umask !== null && !chmod($real, 0777 & ~$this->umask)) {
            rmdir($real);
            throw new RuntimeException('Cannot set AI filesystem directory permissions: ' . $path);
        }
        return ['path' => $path, 'status' => 'created'];
    }

    /** Delete only an empty directory, never recursively. */
    public function deleteDirectory(string $path): array
    {
        [$path, $real] = $this->resolveMutationTarget($path, false);
        if (!$this->policy->isDirectoryDeletable($path) || !is_dir($real)) {
            throw new RuntimeException('AI filesystem directory deletion denied or missing: ' . $path);
        }
        $entries = scandir($real);
        if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
            throw new RuntimeException('AI filesystem directory is not empty or readable: ' . $path);
        }
        if (!rmdir($real)) throw new RuntimeException('Cannot delete AI filesystem directory: ' . $path);
        return ['path' => $path, 'status' => 'deleted'];
    }

    /** Atomically rename a directory after validating every descendant and both paths. */
    public function moveDirectory(string $path, string $targetPath): array
    {
        [$source, $real] = $this->resolveMutationTarget($path, false);
        [$target, $destination] = $this->resolveMutationTarget($targetPath, false);
        if (!$this->policy->isDirectoryMovable($source, $target) || !is_dir($real)
            || file_exists($destination) || str_starts_with($target . '/', $source . '/')) {
            throw new RuntimeException('AI filesystem directory move denied: ' . $source . ' -> ' . $target);
        }
        $events = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $suffix = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($real)));
            $from = $source . $suffix;
            $to = $target . $suffix;
            if ($item->isLink() || !$this->policy->isMovable($from, $to)
                || ($item->isDir() && !$this->policy->isDirectoryMovable($from, $to))) {
                throw new RuntimeException('AI filesystem directory contains a protected path: ' . $from);
            }
            if ($item->isDir()) continue;
            if (!$item->isFile()) throw new RuntimeException('AI filesystem directory contains a special file: ' . $from);
            $content = $this->readTextFile($item->getPathname(), $from);
            $events[] = new FileOperationContext(
                $this->id, 'move', $from, $item->getPathname(), $content, $content,
                $to, $destination . str_replace('/', DIRECTORY_SEPARATOR, $suffix),
            );
        }
        foreach ($events as $event) {
            $this->runHooks('beforeMove', $event);
            $this->revisionStore->save($this->id, $event->path, $event->beforeContent);
            $this->revisionStore->save($this->id, $event->targetPath, null);
        }
        if (!rename($real, $destination)) throw new RuntimeException('Cannot move AI filesystem directory: ' . $source);
        try {
            foreach ($events as $event) $this->runHooks('afterMove', $event);
        } catch (Throwable $error) {
            if (!rename($destination, $real)) throw new RuntimeException('Cannot roll back directory move: ' . $source, previous: $error);
            throw $error;
        }
        foreach ($events as $event) {
            $this->changeSet->record($event->path, $event->beforeContent, $event->afterContent, $event->targetPath);
            $this->revisionStore->save($this->id, $event->path, null);
            $this->revisionStore->save($this->id, $event->targetPath, $event->afterContent);
        }
        foreach ($events as $event) $this->runHooks('change', $event);
        return ['path' => $source, 'targetPath' => $target, 'status' => 'moved'];
    }

    private function resolveMutationTarget(string $path, bool $mustExist): array
    {
        $virtualPath = $this->normalizeVirtualPath($path);
        $root = $this->requireRoot();
        $realPath = $root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $virtualPath);

        // Reject symlink aliases so a permitted virtual path cannot mutate a
        // differently protected path, including through a linked directory.
        $candidate = $root;
        foreach (explode('/', $virtualPath) as $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) {
                throw new RuntimeException('AI filesystem mutation cannot follow symlinks: ' . $virtualPath);
            }
        }

        $parent = realpath(dirname($realPath));
        if ($parent === false || !$this->isInsideRoot($parent)) {
            throw new RuntimeException(
                'AI filesystem target parent does not exist or leaves its root: ' . $virtualPath,
            );
        }

        if ($mustExist) {
            $resolved = realpath($realPath);
            if ($resolved === false || !is_file($resolved) || !$this->isInsideRoot($resolved)) {
                throw new RuntimeException('AI filesystem file does not exist: ' . $virtualPath);
            }
            $realPath = $resolved;
        } elseif (file_exists($realPath)) {
            $resolved = realpath($realPath);
            if ($resolved === false || !$this->isInsideRoot($resolved)) {
                throw new RuntimeException('AI filesystem target leaves its root: ' . $virtualPath);
            }
            $realPath = $resolved;
        }

        return [$virtualPath, $realPath];
    }

    private function resolveRevisionTarget(string $path): array
    {
        $virtualPath = $this->normalizeVirtualPath($path);
        if (isset($this->files[$virtualPath])) {
            return [$virtualPath, $this->files[$virtualPath]];
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

    private function movePersisted(string $source, string $target, string $expectedContent): void
    {
        if (is_link($source) || @file_get_contents($source) !== $expectedContent) {
            throw new RuntimeException('AI filesystem move source changed: ' . $source);
        }

        // Unlike rename(), link() fails when the target already exists.
        // Both paths must be on the same filesystem; no overwrite is allowed.
        if (!@link($source, $target)) {
            throw new RuntimeException('Cannot move AI filesystem file to: ' . $target);
        }
        try {
            $this->deletePersisted($source, $expectedContent);
        } catch (Throwable $error) {
            if (!@unlink($target)) {
                throw new RuntimeException('Cannot roll back AI filesystem move: ' . $target, previous: $error);
            }
            throw $error;
        }
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
            $mode = file_exists($path) ? fileperms($path) : false;
            $permissions = $mode !== false ? $mode & 0777
                : ($this->umask === null ? null : 0777 & ~$this->umask);
            if ($permissions !== null && !@chmod($temporary, $permissions)) {
                throw new RuntimeException('Cannot set AI filesystem file permissions: ' . $path);
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

    private function requireRoot(): string
    {
        if ($this->root === null) {
            throw new LogicException('AI filesystem root is not configured.');
        }

        return $this->root;
    }

    private function normalizeVirtualName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        if (
            $name === ''
            || str_contains($name, '/')
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) !== 1
        ) {
            throw new InvalidArgumentException('AI filesystem file name must be one safe path segment.');
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
