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
        file_put_contents(
            $this->root . '/docs/sections.md',
            "# Guide\nIntro\n## Alpha\nA\n### Detail\nD\n## Beta\nB\n",
        );
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
        self::assertArrayHasKey('filesystem_structure_edit', $schemas);
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
                ignore: ['project/vendor/*'],
                editable: ['project/docs/*'],
                maxListLimit: 10,
            ),
        );
        $fileSystem->addRoot($this->root, 'project', editable: true);

        $page = $fileSystem->list('project/docs', recursive: true, limit: 1);
        self::assertCount(1, $page['items']);
        self::assertSame(1, $page['nextOffset']);

        $secondPage = $fileSystem->list('project/docs', recursive: true, offset: 1, limit: 10);
        self::assertCount(2, $secondPage['items']);
        self::assertNull($secondPage['nextOffset']);

        $matches = $fileSystem->grep('needle', 'project');
        self::assertCount(2, $matches['matches']);
        self::assertStringNotContainsString('vendor', json_encode($matches, JSON_THROW_ON_ERROR));

        $read = $fileSystem->read('project/docs/a.md', startLine: 2, lineCount: 1);
        self::assertSame('needle alpha', $read['content']);

        $this->expectException(\InvalidArgumentException::class);
        $fileSystem->read('../outside.txt');
    }

    public function testReadonlyAndBinaryFilesCannotBeEdited(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem($context, id: 'project');
        $fileSystem->addRoot($this->root, 'project', editable: false);

        try {
            $fileSystem->edit(
                'project/docs/a.md',
                [['search' => 'Title', 'replacement' => 'Changed']],
            );
            self::fail('Expected readonly edit to fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not editable', $error->getMessage());
        }

        $editable = new AiFileSystem($context, id: 'editable');
        $editable->addFile($this->root . '/binary.bin', 'binary', editable: true);

        $this->expectException(\RuntimeException::class);
        $editable->edit('binary', [['search' => 'abc', 'replacement' => 'ABC']]);
    }

    public function testMarkdownStructureUsesStableIdsAndCanMoveWholeSections(): void
    {
        $context = new AiContext();
        $fileSystem = new AiFileSystem($context, id: 'project');
        $fileSystem->addRoot($this->root, 'project', editable: true);

        $structure = $fileSystem->structure('project/docs/sections.md');
        self::assertSame('Guide', $structure['sections'][0]['title']);
        self::assertSame('Alpha', $structure['sections'][0]['children'][0]['title']);
        self::assertSame('Detail', $structure['sections'][0]['children'][0]['children'][0]['title']);
        self::assertSame('Beta', $structure['sections'][0]['children'][1]['title']);

        $alphaId = $structure['sections'][0]['children'][0]['id'];
        $betaId = $structure['sections'][0]['children'][1]['id'];

        $result = $fileSystem->structureEdit(
            'project/docs/sections.md',
            'move_before',
            $betaId,
            referenceId: $alphaId,
        );
        self::assertSame('applied', $result['status']);
        self::assertLessThan(
            strpos(file_get_contents($this->root . '/docs/sections.md'), '## Alpha'),
            strpos(file_get_contents($this->root . '/docs/sections.md'), '## Beta'),
        );

        $after = $fileSystem->structure('project/docs/sections.md');
        self::assertSame($betaId, $after['sections'][0]['children'][0]['id']);
        self::assertSame($alphaId, $after['sections'][0]['children'][1]['id']);
    }

    public function testCreateDeleteRestoreAndAfterEditValidationRollback(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }

        $context = new AiContext();
        $fileSystem = new AiFileSystem(
            $context,
            id: 'lifecycle',
            policy: new FileSystemPolicy(
                editable: ['project/docs/*'],
                creatable: ['project/docs/*'],
                deletable: ['project/docs/*'],
            ),
            revisionStore: new SqliteRevisionStore(':memory:'),
        );
        $fileSystem->addRoot(
            $this->root,
            'project',
            editable: true,
            creatable: true,
            deletable: true,
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

        $path = 'project/docs/generated.txt';
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

        try {
            $fileSystem->edit($path, [['search' => 'created', 'replacement' => 'INVALID']]);
            self::fail('Expected after-edit validation to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Validation failed.', $error->getMessage());
        }
        self::assertSame("created\n", file_get_contents($this->root . '/docs/generated.txt'));

        $result = $fileSystem->edit($path, [['search' => 'created', 'replacement' => 'valid']]);
        self::assertSame('applied', $result['status']);
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
        $fileSystem->addRoot($this->root, 'project', editable: true);

        $result = $fileSystem->edit('project/docs/a.md', [[
            'search' => 'needle alpha',
            'replacement' => 'needle changed',
        ]]);
        self::assertSame('applied', $result['status']);
        self::assertSame(
            "# Title\nneedle changed\nlast\n",
            file_get_contents($this->root . '/docs/a.md'),
        );

        $history = $fileSystem->history('project/docs/a.md');
        self::assertTrue($history['enabled']);
        self::assertCount(2, $history['revisions']);

        $oldest = $history['revisions'][1]['id'];
        $fileSystem->restore('project/docs/a.md', $oldest);
        self::assertSame(
            "# Title\nneedle alpha\nlast\n",
            file_get_contents($this->root . '/docs/a.md'),
        );
        self::assertCount(3, $fileSystem->history('project/docs/a.md')['revisions']);
    }
}
