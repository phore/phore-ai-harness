<?php

declare(strict_types=1);

namespace Phore\AiHarness\Edit;

use RuntimeException;
use Throwable;

/** Failed multi-file operation; successful files are intentionally not rolled back. */
final class FileEditException extends RuntimeException
{
    /**
     * Expose per-file outcomes when edits remain unresolved after an AI run.
     * The files array distinguishes applied/unchanged/failed targets; previous
     * retains an exhausted callback-limit exception when applicable.
     *
     * @param list<array<string, mixed>> $files Detached per-file result records.
     * @example catch (FileEditException $error) { print_r($error->files); }
     * @see FileBatchEditor
     */
    public function __construct(public readonly array $files, ?Throwable $previous = null)
    {
        parent::__construct('File editing did not complete; inspect files for successful and failed targets.', 0, $previous);
    }
}
