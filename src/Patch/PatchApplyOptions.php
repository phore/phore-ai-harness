<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

final readonly class PatchApplyOptions
{
    /** @param list<string> $allowedOperations @param list<string> $forbiddenPaths */
    public function __construct(
        public bool $atomic = true,
        public int $maxOperations = 100,
        public array $allowedOperations = ['add', 'remove', 'replace', 'test'],
        public int $maxPatchBytes = 65536,
        public int $maxDocumentBytes = 4194304,
        public int $maxDepth = 64,
        public bool $allowRootReplacement = false,
        public array $forbiddenPaths = [],
        public string $requireTests = 'none',
        public ?string $expectedHash = null,
        public bool $dryRun = false,
    ) {
        if (!$atomic || $maxOperations < 0 || $maxPatchBytes < 1 || $maxDocumentBytes < 1 || $maxDepth < 1
            || !in_array($requireTests, ['none', 'arrays', 'all'], true)
            || !array_is_list($allowedOperations) || array_diff($allowedOperations, ['add', 'remove', 'replace', 'move', 'copy', 'test']) !== []) {
            throw new \InvalidArgumentException('Invalid patch policy. Non-atomic application is not supported.');
        }
        if ($expectedHash !== null && !preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
            throw new \InvalidArgumentException('expected_hash must be a lowercase SHA-256 hash.');
        }
        foreach ($forbiddenPaths as $path) {
            if (!is_string($path)) {
                throw new \InvalidArgumentException('Forbidden paths must be JSON Pointers.');
            }
            JsonPointer::tokens($path);
        }
    }

    public static function fromArray(array $options): self
    {
        return new self(
            maxOperations: $options['max_operations'] ?? 100,
            allowedOperations: $options['allowed_operations'] ?? ['add', 'remove', 'replace', 'test'],
            maxPatchBytes: $options['max_patch_bytes'] ?? 65536,
            maxDocumentBytes: $options['max_document_bytes'] ?? 4194304,
            maxDepth: $options['max_depth'] ?? 64,
            allowRootReplacement: $options['allow_root_replacement'] ?? false,
            forbiddenPaths: $options['forbidden_paths'] ?? [],
            requireTests: $options['require_tests'] ?? 'none',
            expectedHash: $options['expected_hash'] ?? null,
            dryRun: $options['dry_run'] ?? false,
        );
    }
}
