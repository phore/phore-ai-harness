<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem\Editor;

final class MarkdownFileEditor extends TextFileEditor
{
    public function supports(string $path, string $content): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['md', 'markdown'], true)
            && parent::supports($path, $content);
    }
}
