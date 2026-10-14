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

    public function testDefaultPolicyAndMemoryHistorySupportFullLifecycle(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem($this->root, context: $context, id: 'lifecycle');

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
