<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

final readonly class PatchApplyResult
{
    /** @param list<string> $changedPaths Paths are in the patched representation. */
    public function __construct(
        public string $status,
        public string $oldHash,
        public string $newHash,
        public int $operationCount,
        public array $changedPaths,
        public mixed $value,
        public bool $documentExists = true,
        public ?JsonPatch $patch = null,
    ) {}
}
