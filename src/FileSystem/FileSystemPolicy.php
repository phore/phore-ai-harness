<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

final readonly class FileSystemPolicy
{
    /**
     * @param list<string> $ignore
     * @param list<string> $searchable
     * @param list<string> $editable
     */
    public function __construct(
        public array $ignore = [],
        public array $searchable = ['*'],
        public array $editable = [],
    ) {
    }

    public function isIgnored(string $path): bool
    {
        return $this->matches($path, $this->ignore);
    }

    public function isSearchable(string $path): bool
    {
        return !$this->isIgnored($path) && $this->matches($path, $this->searchable);
    }

    public function isEditable(string $path): bool
    {
        return !$this->isIgnored($path) && $this->matches($path, $this->editable);
    }

    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path, FNM_PATHNAME) || fnmatch($pattern, basename($path))) {
                return true;
            }
        }
        return false;
    }
}
