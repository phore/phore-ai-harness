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
     * @param list<string> $movable Virtual-path allowlist for both move endpoints.
     * @param list<string> $writeIgnore Paths and descendants excluded from all mutations.
     * @param bool $literalWritePaths Treat mutation allowlists as exact paths rather than globs.
     */
    public function __construct(
        public array $ignore = [],
        public array $searchable = ['*'],
        public array $editable = ['*'],
        public array $creatable = [],
        public array $deletable = [],
        public int $maxListLimit = 100,
        public int $maxSearchResults = 100,
        public int $maxReadBytes = 1048576,
        public array $movable = [],
        public array $writeIgnore = [],
        public bool $literalWritePaths = false,
    ) {
    }

    public function isIgnored(string $path): bool
    {
        return $this->matchesAncestor($path, $this->ignore);
    }

    public function isSearchable(string $path): bool
    {
        return !$this->isIgnored($path) && $this->matches($path, $this->searchable);
    }

    public function isEditable(string $path): bool
    {
        return $this->allowsMutation($path, $this->editable);
    }

    /**
     * Check whether a virtual path is allowed for file creation.
     *
     * @example $policy->isCreatable('app/docs/generated.md');
     * @see AiFileSystem::create()
     */
    public function isCreatable(string $path): bool
    {
        return $this->allowsMutation($path, $this->creatable);
    }

    /**
     * Check whether a virtual path is allowed for file deletion.
     *
     * @example $policy->isDeletable('app/docs/generated/old.md');
     * @see AiFileSystem::delete()
     */
    public function isDeletable(string $path): bool
    {
        return $this->allowsMutation($path, $this->deletable);
    }

    /**
     * Check whether both virtual paths are allowed for a move.
     *
     * @example $policy->isMovable('drafts/a.md', 'pages/a.md');
     * @see AiFileSystem::move()
     */
    public function isMovable(string $sourcePath, string $targetPath): bool
    {
        return $this->allowsMutation($sourcePath, $this->movable)
            && $this->allowsMutation($targetPath, $this->movable);
    }

    private function allowsMutation(string $path, array $patterns): bool
    {
        return !$this->isIgnored($path)
            && !$this->matchesAncestor($path, $this->writeIgnore)
            && ($this->literalWritePaths
                ? in_array($path, $patterns, true)
                : $this->matches($path, $patterns));
    }

    private function matchesAncestor(string $path, array $patterns): bool
    {
        do {
            if ($this->matches($path, $patterns)) {
                return true;
            }
            $path = dirname($path);
        } while ($path !== '.' && $path !== '/');

        return false;
    }

    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (!str_contains($pattern, '/')) {
                if (fnmatch($pattern, basename($path))) {
                    return true;
                }
            } elseif ($this->matchesSegments(explode('/', $pattern), explode('/', $path))) {
                return true;
            }
            if (str_ends_with($pattern, '/*') && substr($pattern, 0, -2) === $path) {
                return true;
            }
        }

        return false;
    }

    /** A standalone ** segment matches zero or more complete path segments. */
    private function matchesSegments(array $pattern, array $path): bool
    {
        if ($pattern === []) {
            return $path === [];
        }

        $part = array_shift($pattern);
        if ($part === '**') {
            do {
                if ($this->matchesSegments($pattern, $path)) {
                    return true;
                }
            } while (array_shift($path) !== null);

            return false;
        }

        return $path !== []
            && fnmatch($part, array_shift($path))
            && $this->matchesSegments($pattern, $path);
    }
}
