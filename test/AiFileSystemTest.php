<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use PDO;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\FileSystem\AiFileSystem;
use Phore\AiHarness\FileSystem\AiFileSystemToolSet;
use Phore\AiHarness\FileSystem\FileOperationContext;
use Phore\AiHarness\FileSystem\FileSystemPolicy;
use Phore\AiHarness\FileSystem\SqliteRevisionStore;
use Phore\AiHarness\ToolType\RecoverableToolException;
use PHPUnit\Framework\TestCase;

final class AiFileSystemTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phore-ai-fs-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/docs', 0777, true);
        mkdir($this->root . '/vendor', 0777, true);
        file_put_contents($this->root . '/docs/a.md', "# Title\nneedle alpha\nlast\n");
        file_put_contents($this->root . '/docs/b.txt', "beta\nneedle beta\n");
        file_put_contents($this->root . '/vendor/ignored.txt', "needle ignored\n");
        file_put_contents($this->root . '/binary.bin', "abc\0def");
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testCreateAndEditWithStrictErrorHandlerPreserveExistingPermissions(): void
    {
        $fs = new AiFileSystem($this->root, policy: new FileSystemPolicy(creatable: ['docs/*']));
        chmod($this->root . '/docs/a.md', 0640);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            // Match hosts that also convert warnings suppressed with @ into exceptions.
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $fs->create('docs/new.md', "New file\n");
            $fs->edit('docs/a.md', [['search' => 'Title', 'replacement' => 'Changed']]);
        } finally {
            restore_error_handler();
        }
        self::assertSame("New file\n", file_get_contents($this->root . '/docs/new.md'));
        self::assertStringContainsString('Changed', file_get_contents($this->root . '/docs/a.md'));
        clearstatcache(true, $this->root . '/docs/a.md');
        self::assertSame(0640, fileperms($this->root . '/docs/a.md') & 0777);
        self::assertSame([], glob($this->root . '/docs/.phore-ai-fs-*'));
    }

    public function testOptionalCreationMaskDoesNotChangeProcessMaskOrExistingPermissions(): void
    {
        $processMask = umask();
        foreach ([0000 => 0777, 0027 => 0750] as $mask => $permissions) {
            $path = 'docs/mask-' . $mask . '.md';
            $fs = new AiFileSystem(
                $this->root,
                policy: new FileSystemPolicy(creatable: ['docs/*']),
                umask: $mask,
            );
            $fs->create($path, 'New');
            clearstatcache(true, $this->root . '/' . $path);
            self::assertSame($permissions, fileperms($this->root . '/' . $path) & 0777);
            self::assertSame($processMask, umask());
            chmod($this->root . '/' . $path, 0640);
            clearstatcache(true, $this->root . '/' . $path);
            $fs->edit($path, [['search' => 'New', 'replacement' => 'Edited']]);
            clearstatcache(true, $this->root . '/' . $path);
            self::assertSame(0640, fileperms($this->root . '/' . $path) & 0777);
        }
    }

    public function testInvalidCreationMaskIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiFileSystem($this->root, umask: 01000);
    }

    public function testReadHooksReportOnlySuccessfulExplicitReadsAndGrep(): void
    {
        $fs = new AiFileSystem($this->root, id: 'activity', policy: new FileSystemPolicy(ignore: ['vendor']));
        $events = [];
        $fs->onRead(static function (FileOperationContext $event) use (&$events): void {
            $events[] = [$event->fileSystemId, $event->operation, $event->path, $event->beforeContent, $event->afterContent];
        });
        $fs->read('docs/a.md', 2, 1);
        $fs->grep('needle', 'docs');
        $fs->edit('docs/a.md', [['search' => 'Title', 'replacement' => 'Changed']]);
        try {
            $fs->read('vendor/ignored.txt');
            self::fail('Expected denied read.');
        } catch (\RuntimeException) {
        }
        self::assertSame(['activity', 'read', 'docs/a.md', null, null], $events[0]);
        self::assertCount(3, $events);
        $paths = array_column(array_slice($events, 1), 2);
        sort($paths);
        self::assertSame(['docs/a.md', 'docs/b.txt'], $paths);
        self::assertCount(1, $fs->getChangeSet());
    }

    public function testContextReusesOneFilesystemToolSetForSeveralFilesystems(): void
    {
        $context = new AiContext();
        $first = new AiFileSystem(context: $context, id: 'first');
        $second = new AiFileSystem(context: $context, id: 'second');

        $toolSet = $context->getToolSet(AiFileSystemToolSet::class);
        self::assertInstanceOf(AiFileSystemToolSet::class, $toolSet);
        self::assertSame($first, $toolSet->getFileSystem('first'));
        self::assertSame($second, $toolSet->getFileSystem('second'));
        self::assertCount(2, $toolSet->listFileSystems());
    }

    public function testFilesystemCreatesOwnContextWhenNoneIsSupplied(): void
    {
        $fileSystem = new AiFileSystem($this->root, id: 'standalone');
        $context = $fileSystem->getContext();

        self::assertInstanceOf(AiContext::class, $context);

        $toolSet = $context->getToolSet(AiFileSystemToolSet::class);
        self::assertInstanceOf(AiFileSystemToolSet::class, $toolSet);
        self::assertSame($fileSystem, $toolSet->getFileSystem('standalone'));
    }

    public function testRootCanBePassedToConstructorOrAddedLaterButOnlyOnce(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem($this->root, context: $context, id: 'constructor-root');
        self::assertSame('needle alpha', $fileSystem->read('docs/a.md', 2, 1)['content']);

        try {
            $fileSystem->addRoot($this->root);
            self::fail('Expected a second root to be rejected.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('already configured', $error->getMessage());
        }

        $later = new AiFileSystem(context: $context, id: 'later-root');
        $later->addRoot($this->root);
        self::assertSame('needle beta', $later->read('docs/b.txt', 2, 1)['content']);
    }

    public function testExplicitFilesUseBasenameAndWorkWithoutRoot(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem(context: $context, id: 'files-only');
        $fileSystem->addFile($this->root . '/docs/a.md');

        self::assertSame('needle alpha', $fileSystem->read('a.md', 2, 1)['content']);
        self::assertSame(
            'applied',
            $fileSystem->edit('a.md', [['search' => 'needle alpha', 'replacement' => 'changed']])['status'],
        );
        self::assertCount(2, $fileSystem->history('a.md')['revisions']);

        try {
            $fileSystem->create('new.md', "# New\n");
            self::fail('Expected create without a root to fail.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('root is not configured', $error->getMessage());
        }

        try {
            $fileSystem->delete('a.md');
            self::fail('Expected explicit files to be non-deletable.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('cannot be deleted', $error->getMessage());
        }

        mkdir($this->root . '/other');
        file_put_contents($this->root . '/other/a.md', "duplicate\n");
        $this->expectException(\RuntimeException::class);
        $fileSystem->addFile($this->root . '/other/a.md');
    }

    public function testFilesystemToolSchemasExposeRoutingAndEditShape(): void
    {
        $context = new AiContext();
        new AiFileSystem(context: $context, id: 'project');

        $toolSet = $context->getToolSet(AiFileSystemToolSet::class);
        self::assertInstanceOf(AiFileSystemToolSet::class, $toolSet);

        $schemas = [];
        foreach ($toolSet->getTools() as $tool) {
            $schemas[$tool->name()] = $tool->toArray()['parameters'];
        }

        self::assertArrayHasKey('filesystem_list_systems', $schemas);
        self::assertArrayHasKey('filesystem_create', $schemas);
        self::assertArrayHasKey('filesystem_delete', $schemas);
        self::assertArrayHasKey('filesystem_move', $schemas);
        self::assertArrayHasKey('targetPath', $schemas['filesystem_move']['properties']);
        self::assertArrayHasKey('fileSystemId', $schemas['filesystem_read']['properties']);

        $editSchema = json_encode($schemas['filesystem_edit'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"search"', $editSchema);
        self::assertStringContainsString('"replacement"', $editSchema);
    }

    public function testListSearchReadAndPolicyStayInsideRoot(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem(
            $this->root,
            context: $context,
            id: 'project',
            policy: new FileSystemPolicy(
                ignore: ['vendor/*'],
                editable: ['docs/*'],
                maxListLimit: 10,
            ),
        );

        $page = $fileSystem->list('docs', recursive: true, limit: 1);
        self::assertCount(1, $page['items']);
        self::assertSame(1, $page['nextOffset']);
        self::assertCount(1, $fileSystem->list('docs', true, 1, 10)['items']);

        $matches = $fileSystem->grep('needle');
        self::assertCount(2, $matches['matches']);
        self::assertStringNotContainsString('vendor', json_encode($matches, JSON_THROW_ON_ERROR));
        self::assertSame('needle alpha', $fileSystem->read('docs/a.md', 2, 1)['content']);

        $this->expectException(\InvalidArgumentException::class);
        $fileSystem->read('../outside.txt');
    }

    public function testPolicyCanPreventEditsAndBinaryFilesCannotBeEdited(): void
    {
        $context = new AiContext();
        $readonly = new AiFileSystem(
            $this->root,
            context: $context,
            id: 'readonly',
            policy: new FileSystemPolicy(editable: []),
        );

        try {
            $readonly->edit('docs/a.md', [['search' => 'Title', 'replacement' => 'Changed']]);
            self::fail('Expected policy-protected edit to fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not editable', $error->getMessage());
        }

        $binary = new AiFileSystem(context: $context, id: 'binary');
        $binary->addFile($this->root . '/binary.bin');

        $this->expectException(\RuntimeException::class);
        $binary->edit('binary.bin', [['search' => 'abc', 'replacement' => 'ABC']]);
    }

    public function testExplicitPolicyAndMemoryHistorySupportFullLifecycle(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem(
            $this->root,
            context: $context,
            id: 'lifecycle',
            policy: new FileSystemPolicy(creatable: ['docs/*'], deletable: ['docs/*']),
        );

        $events = [];
        $fileSystem
            ->onBeforeCreate(function (FileOperationContext $event) use (&$events): void {
                $events[] = 'before-create:' . $event->path;
            })
            ->onAfterCreate(function (FileOperationContext $event) use (&$events): void {
                $events[] = 'after-create:' . $event->path;
            })
            ->onBeforeDelete(function (FileOperationContext $event) use (&$events): void {
                $events[] = 'before-delete:' . $event->path;
            })
            ->onAfterDelete(function (FileOperationContext $event) use (&$events): void {
                $events[] = 'after-delete:' . $event->path;
            })
            ->onAfterEdit(function (FileOperationContext $event): void {
                if (str_contains($event->afterContent ?? '', 'INVALID')) {
                    throw new \RuntimeException('Validation failed.');
                }
            });

        $path = 'docs/generated.txt';
        $fileSystem->create($path, "created\n");
        $createHistory = $fileSystem->history($path)['revisions'];
        self::assertFalse($createHistory[1]['exists']);
        $fileSystem->restore($path, $createHistory[1]['id']);
        self::assertFileDoesNotExist($this->root . '/docs/generated.txt');

        $fileSystem->create($path, "created\n");
        $fileSystem->delete($path);
        $existingRevision = current(array_filter(
            $fileSystem->history($path)['revisions'],
            static fn (array $revision): bool => $revision['exists'],
        ));
        self::assertIsArray($existingRevision);
        $fileSystem->restore($path, $existingRevision['id']);

        $toolSet = $context->getToolSet(AiFileSystemToolSet::class);
        self::assertInstanceOf(AiFileSystemToolSet::class, $toolSet);

        try {
            $toolSet->editFile('lifecycle', $path, [[
                'search' => 'created',
                'replacement' => 'INVALID',
            ]]);
            self::fail('Expected after-edit validation to fail.');
        } catch (RecoverableToolException $error) {
            self::assertSame('Validation failed.', $error->getMessage());
        }
        self::assertSame("created\n", file_get_contents($this->root . '/docs/generated.txt'));

        $result = $toolSet->editFile('lifecycle', $path, [[
            'search' => null,
            'replacement' => "rewritten\n",
        ]]);
        self::assertSame('applied', $result['status']);
        self::assertSame("rewritten\n", file_get_contents($this->root . '/docs/generated.txt'));
        self::assertCount(6, $events);
    }

    public function testChangeSetTracksNetChangesAndReset(): void
    {
        $fs = new AiFileSystem(
            $this->root,
            id: 'changes',
            policy: new FileSystemPolicy(creatable: ['*'], deletable: ['*'], movable: ['*']),
        );

        $fs->edit('docs/a.md', [['search' => 'Title', 'replacement' => 'Updated']]);
        $fs->move('docs/a.md', 'docs/moved.md');
        $fs->edit('docs/moved.md', [['search' => 'Updated', 'replacement' => 'Final']]);
        $fs->create('docs/temporary.md', '');
        $fs->delete('docs/temporary.md');
        $fs->create('docs/new.md', '');
        $fs->delete('docs/b.txt');

        $changes = json_decode(json_encode($fs->getChangeSet(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            [
                'path' => 'docs/moved.md',
                'status' => 'moved',
                'oldPath' => 'docs/a.md',
                'oldContent' => "# Title\nneedle alpha\nlast\n",
                'newContent' => "# Final\nneedle alpha\nlast\n",
            ],
            [
                'path' => 'docs/new.md',
                'status' => 'created',
                'oldPath' => null,
                'oldContent' => null,
                'newContent' => '',
            ],
            [
                'path' => 'docs/b.txt',
                'status' => 'deleted',
                'oldPath' => null,
                'oldContent' => "beta\nneedle beta\n",
                'newContent' => null,
            ],
        ], $changes);

        $fs->resetChangeSet();
        self::assertSame([], $fs->getChangeSet());
        self::assertNotEmpty($fs->history('docs/moved.md')['revisions']);

        $fs->onAfterEdit(static function (): void {
            throw new \RuntimeException('Reject change');
        });
        try {
            $fs->edit('docs/moved.md', [['search' => 'Final', 'replacement' => 'Rejected']]);
            self::fail('Expected edit to roll back.');
        } catch (\RuntimeException $error) {
            self::assertSame('Reject change', $error->getMessage());
        }
        self::assertSame([], $fs->getChangeSet());
    }

    public function testRecursiveAndLiteralWritePolicies(): void
    {
        $policy = new FileSystemPolicy(
            ignore: ['.git'],
            editable: ['docs/**/*.md'],
            creatable: ['**'],
            deletable: ['**'],
            movable: ['**'],
            writeIgnore: ['node_modules'],
        );
        self::assertTrue($policy->isEditable('docs/a.md'));
        self::assertTrue($policy->isEditable('docs/deep/a.md'));
        self::assertFalse($policy->isEditable('other/docs/a.md'));
        self::assertTrue($policy->isSearchable('docs/node_modules/a.md'));
        self::assertFalse($policy->isEditable('docs/node_modules/a.md'));
        self::assertFalse($policy->isCreatable('node_modules/new.md'));
        self::assertFalse($policy->isDeletable('nested/node_modules/a.md'));
        self::assertFalse($policy->isMovable('docs/a.md', 'node_modules/a.md'));
        self::assertFalse($policy->isMovable('node_modules/a.md', 'docs/a.md'));
        self::assertFalse($policy->isSearchable('nested/.git/config'));

        $literal = new FileSystemPolicy(editable: ['a[1].md'], literalWritePaths: true);
        self::assertTrue($literal->isEditable('a[1].md'));
        self::assertFalse($literal->isEditable('nested/a[1].md'));
        self::assertFalse($literal->isEditable('a1.md'));
    }

    public function testSymlinkAliasesCannotBypassWriteOrIgnorePolicy(): void
    {
        mkdir($this->root . '/node_modules');
        file_put_contents($this->root . '/node_modules/locked.md', 'original');
        $alias = $this->root . '/docs/alias.md';
        if (!@symlink($this->root . '/node_modules/locked.md', $alias)) {
            self::markTestSkipped('Symlinks are unavailable.');
        }
        try {
            $fs = new AiFileSystem($this->root, policy: new FileSystemPolicy(
                ignore: ['vendor'],
                writeIgnore: ['node_modules'],
            ));
            foreach (['docs/alias.md', 'node_modules/locked.md'] as $path) {
                try {
                    $fs->edit($path, [['search' => 'original', 'replacement' => 'changed']]);
                    self::fail('Protected file must not be editable.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString($path, $error->getMessage());
                }
            }
            self::assertSame('original', file_get_contents($this->root . '/node_modules/locked.md'));
            self::assertSame('original', $fs->read('node_modules/locked.md')['content']);
            $this->expectException(\RuntimeException::class);
            $fs->list('vendor');
        } finally {
            unlink($alias);
        }
    }

    public function testChangeObserverSeesPersistedOperationsAndRestore(): void
    {
        $fs = new AiFileSystem($this->root, policy: new FileSystemPolicy(
            creatable: ['*'], deletable: ['*'], movable: ['*'],
        ));
        $events = [];
        $fs->onChange(static function (FileOperationContext $event) use (&$events): void {
            if ($event->afterContent !== null) {
                self::assertSame($event->afterContent, file_get_contents($event->targetRealPath ?? $event->realPath));
            } else {
                self::assertFileDoesNotExist($event->realPath);
            }
            $events[] = $event->operation;
        });
        $created = $fs->create('docs/new.md', 'new');
        $edit = $fs->edit('docs/new.md', [['search' => 'new', 'replacement' => 'edited']]);
        $fs->edit('docs/new.md', [['search' => 'edited', 'replacement' => 'edited']]);
        $fs->restore('docs/new.md', $edit['revisionId']);
        $fs->restore('docs/new.md', $created['revisionId']);
        $fs->move('docs/new.md', 'docs/moved.md');
        $fs->delete('docs/moved.md');
        self::assertSame(['create', 'edit', 'restore', 'move', 'delete'], $events);
    }

    public function testSqliteRevisionStorePersistsAcrossInstances(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }

        $database = $this->root . '/history.sqlite';
        $context = new AiContext();
        $fileSystem = new AiFileSystem(
            $this->root,
            context: $context,
            id: 'persistent',
            revisionStore: new SqliteRevisionStore($database),
        );
        $fileSystem->edit('docs/a.md', [[
            'search' => 'needle alpha',
            'replacement' => 'needle changed',
        ]]);

        $nextSession = new AiFileSystem(
            $this->root,
            context: new AiContext(),
            id: 'persistent',
            revisionStore: new SqliteRevisionStore($database),
        );
        $history = $nextSession->history('docs/a.md');
        self::assertCount(2, $history['revisions']);

        $nextSession->restore('docs/a.md', $history['revisions'][1]['id']);
        self::assertSame(
            "# Title\nneedle alpha\nlast\n",
            file_get_contents($this->root . '/docs/a.md'),
        );
    }
}
