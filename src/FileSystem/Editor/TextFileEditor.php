<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem\Editor;

use Phore\AiHarness\AiContext;
use Phore\AiHarness\FileSystem\FileEditorInterface;
use RuntimeException;

class TextFileEditor implements FileEditorInterface
{
    public function supports(string $path): bool
    {
        $sample = @file_get_contents($path, false, null, 0, 8192);
        return is_string($sample) && !str_contains($sample, "\0");
    }

    public function edit(string $path, string $instruction, AiContext $context): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read editable file: ' . $path);
        }
        if (str_contains($content, "\0")) {
            throw new RuntimeException('Binary files cannot be edited: ' . $path);
        }

        return $context->text($instruction, input: $content);
    }
}
