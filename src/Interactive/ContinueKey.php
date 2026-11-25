<?php

declare(strict_types=1);

namespace Phore\AiHarness\Interactive;

/** Authenticated continuation data; only its encrypted representation leaves the server. */
final readonly class ContinueKey
{
    /** @param array<string, mixed> $state Explicit JSON-compatible operation state. */
    public function __construct(
        public string $responseId,
        public string $contract,
        public array $state = [],
        public bool $completed = false,
    ) {
    }
}
