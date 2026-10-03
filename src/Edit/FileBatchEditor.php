<?php

declare(strict_types=1);

namespace Phore\AiHarness\Edit;

use InvalidArgumentException;
use Phore\AiHarness\Helper\Toolkit;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\ToolType\RecoverableToolException;
use RuntimeException;

/** @internal One invocation's allowlist, snapshots and per-file write outcomes. */
final class FileBatchEditor
{
    private array $targets = [];
    private array $aliases = [];
    private array $outcomes = [];

    public function __construct(string|array $input)
    {
        $filenames = is_string($input) ? [$input] : array_values($input);
        if ($filenames === []) {
            throw new InvalidArgumentException('At least one target filename is required.');
        }
        // Pfade einmal kanonisieren und nicht lesbare Dateien nicht als leer behandeln.
        foreach ($filenames as $filename) {
            if (!is_string($filename) || trim($filename) === '') {
                throw new InvalidArgumentException('Every target filename must be a non-empty string.');
            }
            $path = realpath($filename);
            $exists = $path !== false;
            if (!$exists) {
                $parent = realpath(dirname($filename));
                if ($parent === false || !is_dir($parent) || is_link($filename)) {
                    throw new RuntimeException('Cannot resolve target file: ' . $filename);
                }
                $path = $parent . DIRECTORY_SEPARATOR . basename($filename);
            }
            if (isset($this->targets[$path])) {
                throw new InvalidArgumentException('Duplicate target file: ' . $filename);
            }
            $content = '';
            if ($exists) {
                if (!is_file($path) || !is_readable($path)) {
                    throw new RuntimeException('Cannot read target file: ' . $filename);
                }
                $content = @file_get_contents($path);
                if ($content === false) {
                    throw new RuntimeException('Cannot read target file: ' . $filename);
                }
            }
            if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
                throw new InvalidArgumentException('Target is not UTF-8 text: ' . $filename);
            }
            $alias = 'targetFile' . (count($this->targets) + 1);
            $this->targets[$path] = ['alias' => $alias, 'content' => $content, 'exists' => $exists];
            $this->aliases[$alias] = $path;
        }
    }

    public function prompts(): array
    {
        $prompts = [];
        foreach ($this->targets as $path => $target) {
            $prompts[] = new FilePrompt($path, $target['content'], 'text/plain', alias: $target['alias'], instructions: 'Editable target file.');
        }
        return $prompts;
    }

    /**
     * The DTO annotation defines the provider schema; callback JSON is passed
     * as arrays and validated explicitly before any file is written.
     *
     * @param \Phore\AiHarness\Edit\FileEdits[] $files Target aliases and all edits per target.
     */
    public function write(array $files): string
    {
        // Unsichere oder mehrdeutige Zielangaben ablehnen, bevor eine Datei geschrieben wird.
        if (!array_is_list($files) || $files === []) {
            throw new RecoverableToolException('files must be a non-empty list.');
        }
        $batch = [];
        foreach ($files as $file) {
            if (!is_array($file) || count($file) !== 2 || !is_string($file['filename'] ?? null) || !is_array($file['edits'] ?? null)) {
                throw new RecoverableToolException('Each file must contain exactly filename and edits.');
            }
            $path = $this->aliases[$file['filename']] ?? $file['filename'];
            if (!isset($this->targets[$path])) {
                throw new RecoverableToolException('Cannot write file outside the supplied targets: ' . $file['filename']);
            }
            if (isset($batch[$path])) {
                throw new RecoverableToolException('Cannot write the same target file twice: ' . $file['filename']);
            }
            $batch[$path] = $file['edits'];
        }

        // Erfolge bleiben erhalten; nur fehlerhafte Dateien duerfen erneut vorgeschlagen werden.
        foreach ($batch as $path => $edits) {
            $previous = $this->outcomes[$path] ?? null;
            if ($previous !== null && ($previous['status'] !== 'failed' || !$previous['retryable'])) {
                continue;
            }
            $target = $this->targets[$path];
            try {
                $content = TextEditEngine::applyEdits($target['content'], $edits);
                if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
                    throw new InvalidArgumentException('Replacement result must be UTF-8 text.');
                }
                $this->assertSnapshot($path, $target);
                $unchanged = $target['exists'] && $content === $target['content'];
                if (!$unchanged) {
                    $this->persist($path, $content, $target);
                }
                $this->outcomes[$path] = ['filename' => $target['alias'], 'path' => $path, 'status' => $unchanged ? 'unchanged' : 'applied', 'edits' => count($edits), 'bytes' => strlen($content)];
            } catch (InvalidArgumentException $error) {
                $this->outcomes[$path] = ['filename' => $target['alias'], 'path' => $path, 'status' => 'failed', 'retryable' => true, 'error' => $error->getMessage()];
            } catch (RuntimeException $error) {
                $this->outcomes[$path] = ['filename' => $target['alias'], 'path' => $path, 'status' => 'failed', 'retryable' => false, 'error' => $error->getMessage()];
            }
        }
        if ($this->hasFailures()) {
            throw new RecoverableToolException(
                'Successful files are already applied; resend ONLY failed, retryable files with corrected edits. Do not retry non-retryable filesystem conflicts.',
                result: ['files' => $this->results()],
            );
        }
        return Toolkit::jsonEncode(['ok' => true, 'files' => $this->results()]);
    }

    public function results(): array
    {
        return array_values($this->outcomes);
    }

    public function assertComplete(): void
    {
        if ($this->outcomes === []) {
            throw new RuntimeException('AI response did not write a target file through write_files.');
        }
        if ($this->hasFailures()) {
            throw new FileEditException($this->results());
        }
    }

    private function hasFailures(): bool
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome['status'] === 'failed') {
                return true;
            }
        }
        return false;
    }

    private function assertSnapshot(string $path, array $target): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || file_exists($path) !== $target['exists']) {
            throw new RuntimeException('Target changed since it was read: ' . $path);
        }
        if ($target['exists']) {
            $current = @file_get_contents($path);
            if ($current === false || $current !== $target['content']) {
                throw new RuntimeException('Target changed or is unreadable: ' . $path);
            }
        }
    }

    private function persist(string $path, string $content, array $target): void
    {
        // Erst vollstaendig in dieselbe Directory schreiben, dann per Rename ersetzen.
        // Fremde Writer benoetigen trotzdem eine anwendungsweite Synchronisation.
        $directory = dirname($path);
        $temporary = @tempnam($directory, '.phore-ai-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot prepare target file: ' . $path);
        }
        try {
            if (realpath(dirname($temporary)) !== realpath($directory)) {
                throw new RuntimeException('Cannot create temporary file beside target: ' . $path);
            }
            $bytes = @file_put_contents($temporary, $content, LOCK_EX);
            if ($bytes === false || $bytes !== strlen($content)) {
                throw new RuntimeException('Could not write complete target file: ' . $path);
            }
            $permissions = $target['exists'] ? @fileperms($path) : (0666 & ~umask());
            if ($permissions === false || !@chmod($temporary, $permissions & 0777)) {
                throw new RuntimeException('Cannot preserve target permissions: ' . $path);
            }
            $this->assertSnapshot($path, $target);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Could not replace target file: ' . $path);
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
