<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

final readonly class FileSystemPolicy
{
    /**
     * @param list<string> $ignore Virtual-path patterns that are neither listed nor traversed.
     * @param list<string> $searchable Virtual-path allowlist for read and grep operations.
     * @param list<string> $editable Virtual-path allowlist for edit operations.
     * @param list<string> $creatable Virtual-path allowlist for create operations.
     * @param list<string> $deletable Virtual-path allowlist for delete operations.
     */
    public function __construct(
        public array $ignore = [],
        public array $searchable = ['*'],
        public array $editable = ['*'],
        public array $creatable = ['*'],
        public array $deletable = ['*'],
        public int $maxListLimit = 100,
        public int $maxSearchResults = 100,
        public int $maxReadBytes = 1048576,
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

    /**
     * Check whether a virtual path is allowed for file creation.
     *
     * @example $policy->isCreatable('app/docs/generated.md');
     * @see AiFileSystem::create()
     */
    public function isCreatable(string $path): bool
    {
        return !$this->isIgnored($path) && $this->matches($path, $this->creatable);
    }

    /**
     * Check whether a virtual path is allowed for file deletion.
     *
     * @example $policy->isDeletable('app/docs/generated/old.md');
     * @see AiFileSystem::delete()
     */
    public function isDeletable(string $path): bool
    {
        return !$this->isIgnored($path) && $this->matches($path, $this->deletable);
    }

    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path, FNM_PATHNAME) || fnmatch($pattern, basename($path))) {
                return true;
            }
            if (str_ends_with($pattern, '/*') && substr($pattern, 0, -2) === $path) {
                return true;
            }
        }

        return false;
    }
}
