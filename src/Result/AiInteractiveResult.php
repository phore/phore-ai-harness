<?php

declare(strict_types=1);

namespace Phore\AiHarness\Result;

/**
 * Result of an interactive AI operation, whether paused or completed.
 * Consumers may extend the DTO or add application metadata to its JSON form.
 *
 * @template T
 */
class AiInteractiveResult
{
    public const TYPE = 'ai_continue';
    public final readonly string $type;
    public bool $stop;

    /**
     * @param string|null $continueId Opaque token; null only for a completed operation without a provider response.
     * @param list<array{name: string, callId: string}> $toolCalls Executed local calls (including recoverable failures).
     * @param string|null $message Optional model text from a paused round.
     * @param bool $completed Whether the ordinary operation and its validation have finished.
     * @param T|null $result Ordinary result when completed, including legitimate null, false or empty values.
     * @example if ($step->completed) { $value = $step->result; }
     * @see \Phore\AiHarness\AiContext
     */
    public function __construct(
        public ?string $continueId,
        public array $toolCalls = [],
        public ?string $message = null,
        public readonly bool $completed = false,
        public mixed $result = null,
    ) {
        $this->type = self::TYPE;
        $this->stop = $completed;
    }
}
