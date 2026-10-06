<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem\Editor;

use Phore\AiHarness\Edit\TextEditEngine;
use Phore\AiHarness\FileSystem\FileEditorInterface;

class TextFileEditor implements FileEditorInterface
{
    public function supports(string $path, string $content): bool
    {
        return !str_contains($content, "\0") && preg_match('//u', $content) === 1;
    }

    public function apply(string $path, string $content, array $edits): string
    {
        return TextEditEngine::applyEdits($content, $edits);
    }
}
