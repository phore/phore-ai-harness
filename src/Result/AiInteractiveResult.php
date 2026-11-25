<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/** A continuation, never a final operation result. May be extended with application metadata. */
class AiInteractiveResult
{
    public const TYPE = 'ai_continue';
    public final readonly string $type;

    /**
     * @param string $continueId Opaque encrypted continuation token.
     * @param list<array{name: string, callId: string}> $toolCalls Executed local calls (including recoverable failures).
     * @param string|null $message Optional model text.
     */
    public function __construct(
        public string $continueId,
        public array $toolCalls = [],
        public ?string $message = null,
    ) {
        $this->type = self::TYPE;
    }
}
