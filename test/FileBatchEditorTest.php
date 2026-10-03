<?php

declare(strict_types=1);

namespace Phore\AiHarness\Test;

use Phore\AiHarness\Edit\FileBatchEditor;
use Phore\AiHarness\Edit\FileEditException;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\RecoverableToolException;
use PHPUnit\Framework\TestCase;

final class FileBatchEditorTest extends TestCase
{
    private string $directory;
    private string $first;
    private string $second;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phore-edit-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->first = $this->directory . '/first.txt';
        $this->second = $this->directory . '/second.txt';
        file_put_contents($this->first, 'one');
        file_put_contents($this->second, 'repeat one; repeat two');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '/.phore-ai-*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testPartialSuccessIsKeptAndOnlyFailedFileNeedsCorrection(): void
    {
        $editor = new FileBatchEditor([$this->first, $this->second]);
        try {
            $editor->write([
                ['filename' => 'targetFile1', 'edits' => [['search' => 'one', 'replacement' => 'ONE']]],
                ['filename' => 'targetFile2', 'edits' => [
                    ['search' => 'repeat one', 'replacement' => 'first'],
                    ['search' => 'repeat', 'replacement' => 'second'],
                ]],
            ]);
            self::fail('Expected a recoverable ambiguity.');
        } catch (RecoverableToolException $error) {
            self::assertSame('applied', $error->result['files'][0]['status']);
            self::assertSame('failed', $error->result['files'][1]['status']);
            self::assertStringContainsString('2 matches', $error->result['files'][1]['error']);
        }
        self::assertSame('ONE', file_get_contents($this->first));
        self::assertSame('repeat one; repeat two', file_get_contents($this->second));
        $editor->write([
            ['filename' => 'targetFile2', 'edits' => [['search' => 'repeat two', 'replacement' => 'fixed two']]],
        ]);
        $editor->assertComplete();
        self::assertSame('repeat one; fixed two', file_get_contents($this->second));
        // A repeated successful target cannot cause a second side effect in this invocation.
        $editor->write([['filename' => 'targetFile1', 'edits' => [['search' => null, 'replacement' => 'wrong']]]]);
        self::assertSame('ONE', file_get_contents($this->first));
        self::assertSame([], glob($this->directory . '/.phore-ai-*'));
    }

    public function testUnresolvedFailureCannotBeReportedAsSuccessful(): void
    {
        $editor = new FileBatchEditor($this->second);
        try {
            $editor->write([['filename' => 'targetFile1', 'edits' => [['search' => 'repeat', 'replacement' => 'x']]]]);
        } catch (RecoverableToolException) {
        }
        $this->expectException(FileEditException::class);
        $editor->assertComplete();
    }

    public function testExternalModificationIsNeverOverwritten(): void
    {
        $editor = new FileBatchEditor($this->first);
        file_put_contents($this->first, 'external');
        try {
            $editor->write([['filename' => 'targetFile1', 'edits' => [['search' => null, 'replacement' => 'wrong']]]]);
            self::fail('Expected a conflict.');
        } catch (RecoverableToolException $error) {
            self::assertFalse($error->result['files'][0]['retryable']);
        }
        self::assertSame('external', file_get_contents($this->first));
    }

    public function testMissingFileCanBeCreatedWithEmptyContents(): void
    {
        $path = $this->directory . '/new.txt';
        $editor = new FileBatchEditor($path);
        $editor->write([['filename' => 'targetFile1', 'edits' => [['search' => null, 'replacement' => '']]]]);
        $editor->assertComplete();
        self::assertFileExists($path);
        self::assertSame('', file_get_contents($path));
    }

    public function testUnauthorizedTargetRejectsBatchBeforeAnyWrite(): void
    {
        $editor = new FileBatchEditor($this->first);
        try {
            $editor->write([
                ['filename' => 'targetFile1', 'edits' => [['search' => null, 'replacement' => 'changed']]],
                ['filename' => $this->second, 'edits' => []],
            ]);
            self::fail('Expected an allowlist error.');
        } catch (RecoverableToolException) {
        }
        self::assertSame('one', file_get_contents($this->first));
    }

    public function testFullRewriteCannotBeMixedWithATargetedEdit(): void
    {
        $editor = new FileBatchEditor($this->first);
        try {
            $editor->write([['filename' => 'targetFile1', 'edits' => [
                ['search' => null, 'replacement' => 'new'],
                ['search' => 'one', 'replacement' => 'ONE'],
            ]]]);
            self::fail('Expected mixed-mode rejection.');
        } catch (RecoverableToolException) {
        }
        self::assertSame('one', file_get_contents($this->first));
    }

    public function testDirectoryInputFailsWithItsActualPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($this->directory);
        new FileBatchEditor($this->directory);
    }

    public function testNestedCallbackSchemaContainsSearchAndReplacement(): void
    {
        $tool = new CallbackTool([new FileBatchEditor($this->first), 'write'], 'write_files');
        $schema = json_encode($tool->toArray()['parameters'], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"files"', $schema);
        self::assertStringContainsString('"filename"', $schema);
        self::assertStringContainsString('"edits"', $schema);
        self::assertStringContainsString('"search"', $schema);
        self::assertStringContainsString('"replacement"', $schema);
        self::assertStringContainsString('"null"', $schema);
    }
}
