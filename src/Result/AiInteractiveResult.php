<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/**
 * Result of one interactive step. Consumers may extend or wrap this DTO with
 * application-specific metadata. No callback arguments or outputs are exposed.
 */
class AiInteractiveResult
{
    /**
     * @param string $responseId Latest provider cursor, also after completion.
     * @param bool $continue True if the latest response has pending local calls.
     * @param string|null $message Text from the latest model response, if any.
     * @param list<array{name: string, callId: string}> $toolCalls Local calls executed in this step (including recoverable failures).
     */
    public function __construct(
        public string $responseId,
        public bool $continue,
        public ?string $message,
        public array $toolCalls = [],
    ) {
    }
}
