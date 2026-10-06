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
        $first = new AiFileSystem($context, id: 'first');
        $second = new AiFileSystem($context, id: 'second');

        $toolSet = $context->getToolSet(AiFileSystemToolSet::class);
        self::assertInstanceOf(AiFileSystemToolSet::class, $toolSet);
        self::assertSame($first, $toolSet->getFileSystem('first'));
        self::assertSame($second, $toolSet->getFileSystem('second'));
        self::assertCount(2, $toolSet->listFileSystems());
    }

    public function testOnlyOneRootCanBeConfigured(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem($context, id: 'project');
        $fileSystem->addRoot($this->root);

        $this->expectException(\LogicException::class);
        $fileSystem->addRoot($this->root);
    }

    public function testFilesystemToolSchemasExposeRoutingAndEditShape(): void
    {
        $context = new AiContext();
        new AiFileSystem($context, id: 'project');

        $toolSet = $context->getToolSet(AiFileSystemToolSet::class);
        self::assertInstanceOf(AiFileSystemToolSet::class, $toolSet);

        $schemas = [];
        foreach ($toolSet->getTools() as $tool) {
            $schemas[$tool->name()] = $tool->toArray()['parameters'];
        }

        self::assertArrayHasKey('filesystem_list_systems', $schemas);
        self::assertArrayHasKey('filesystem_create', $schemas);
        self::assertArrayHasKey('filesystem_delete', $schemas);
        self::assertArrayHasKey(
            'fileSystemId',
            $schemas['filesystem_read']['properties'],
        );

        $editSchema = json_encode($schemas['filesystem_edit'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"search"', $editSchema);
        self::assertStringContainsString('"replacement"', $editSchema);
    }

    public function testListSearchReadAndPolicyStayInsideDeclaredRoots(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem(
            $context,
            id: 'project',
            policy: new FileSystemPolicy(
                ignore: ['vendor/*'],
                editable: ['docs/*'],
                maxListLimit: 10,
            ),
        );
        $fileSystem->addRoot($this->root);

        $page = $fileSystem->list('docs', recursive: true, limit: 1);
        self::assertCount(1, $page['items']);
        self::assertSame(1, $page['nextOffset']);

        $secondPage = $fileSystem->list('docs', recursive: true, offset: 1, limit: 10);
        self::assertCount(1, $secondPage['items']);
        self::assertNull($secondPage['nextOffset']);

        $matches = $fileSystem->grep('needle');
        self::assertCount(2, $matches['matches']);
        self::assertStringNotContainsString('vendor', json_encode($matches, JSON_THROW_ON_ERROR));

        $read = $fileSystem->read('docs/a.md', startLine: 2, lineCount: 1);
        self::assertSame('needle alpha', $read['content']);

        $this->expectException(\InvalidArgumentException::class);
        $fileSystem->read('../outside.txt');
    }

    public function testPolicyCanPreventEditsAndBinaryFilesCannotBeEdited(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem(
            $context,
            id: 'project',
            policy: new FileSystemPolicy(editable: []),
        );
        $fileSystem->addRoot($this->root);

        try {
            $fileSystem->edit(
                'docs/a.md',
                [['search' => 'Title', 'replacement' => 'Changed']],
            );
            self::fail('Expected policy-protected edit to fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not editable', $error->getMessage());
        }

        $editable = new AiFileSystem($context, id: 'editable');
        $editable->addRoot($this->root);

        $this->expectException(\RuntimeException::class);
        $editable->edit('binary.bin', [['search' => 'abc', 'replacement' => 'ABC']]);
    }

    public function testCreateDeleteRestoreAndAfterEditValidationRollback(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem(
            $context,
            id: 'lifecycle',
            policy: new FileSystemPolicy(
                editable: ['docs/*'],
                creatable: ['docs/*'],
                deletable: ['docs/*'],
            ),
        );
        $fileSystem->addRoot($this->root);

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
        self::assertSame("created\n", file_get_contents($this->root . '/docs/generated.txt'));

        $createHistory = $fileSystem->history($path)['revisions'];
        self::assertFalse($createHistory[1]['exists']);
        $fileSystem->restore($path, $createHistory[1]['id']);
        self::assertFileDoesNotExist($this->root . '/docs/generated.txt');

        $fileSystem->create($path, "created\n");
        $fileSystem->delete($path);
        self::assertFileDoesNotExist($this->root . '/docs/generated.txt');

        $deleteHistory = $fileSystem->history($path)['revisions'];
        $existingRevision = current(array_filter(
            $deleteHistory,
            static fn (array $revision): bool => $revision['exists'],
        ));
        self::assertIsArray($existingRevision);
        $fileSystem->restore($path, $existingRevision['id']);
        self::assertSame("created\n", file_get_contents($this->root . '/docs/generated.txt'));

        $toolSet = $context->getToolSet(AiFileSystemToolSet::class);
        self::assertInstanceOf(AiFileSystemToolSet::class, $toolSet);

        try {
            $toolSet->editFile(
                'lifecycle',
                $path,
                [['search' => 'created', 'replacement' => 'INVALID']],
            );
            self::fail('Expected after-edit validation to fail.');
        } catch (RecoverableToolException $error) {
            self::assertSame('Validation failed.', $error->getMessage());
        }
        self::assertSame("created\n", file_get_contents($this->root . '/docs/generated.txt'));

        $result = $toolSet->editFile(
            'lifecycle',
            $path,
            [['search' => 'created', 'replacement' => 'valid']],
        );
        self::assertSame('applied', $result['status']);
        self::assertSame("valid\n", file_get_contents($this->root . '/docs/generated.txt'));

        $rewrite = $toolSet->editFile(
            'lifecycle',
            $path,
            [['search' => null, 'replacement' => "rewritten\n"]],
        );
        self::assertSame('applied', $rewrite['status']);
        self::assertSame("rewritten\n", file_get_contents($this->root . '/docs/generated.txt'));
        self::assertSame(
            [
                'before-create:' . $path,
                'after-create:' . $path,
                'before-create:' . $path,
                'after-create:' . $path,
                'before-delete:' . $path,
                'after-delete:' . $path,
            ],
            $events,
        );
    }

    public function testEditsCreateSqliteHistoryAndCanBeRestored(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }

        $context = new AiContext();
        $store = new SqliteRevisionStore(':memory:');
        $fileSystem = new AiFileSystem($context, id: 'project', revisionStore: $store);
        $fileSystem->addRoot($this->root);

        $result = $fileSystem->edit('docs/a.md', [[
            'search' => 'needle alpha',
            'replacement' => 'needle changed',
        ]]);
        self::assertSame('applied', $result['status']);
        self::assertSame(
            "# Title\nneedle changed\nlast\n",
            file_get_contents($this->root . '/docs/a.md'),
        );

        $history = $fileSystem->history('docs/a.md');
        self::assertTrue($history['enabled']);
        self::assertCount(2, $history['revisions']);

        $oldest = $history['revisions'][1]['id'];
        $fileSystem->restore('docs/a.md', $oldest);
        self::assertSame(
            "# Title\nneedle alpha\nlast\n",
            file_get_contents($this->root . '/docs/a.md'),
        );
        self::assertCount(3, $fileSystem->history('docs/a.md')['revisions']);
    }
}
