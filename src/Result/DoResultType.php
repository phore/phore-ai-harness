<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/**
 * Internal structured completion report for do() operations.
 *
 * @internal
 */
final readonly class DoResultType
{
    /** @var list<string> */
    public array $data;

    /**
     * @param list<string> $data Short machine-readable diagnostic values.
     */
    public function __construct(
        public bool $success,
        public string $message,
        public string $details,
        array $data = [],
    ) {
        $this->data = $data;
    }
}
