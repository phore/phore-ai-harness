<?php

declare(strict_types=1);

namespace Phore\AiHarness\Interactive;

/** @internal Normal control flow across typed provider decoding layers. */
final class InteractivePause extends \RuntimeException
{
    public function __construct(
        public readonly string $responseId,
        public readonly string $contract,
        public readonly array $toolCalls,
        public readonly ?string $outputText,
        public readonly array $outputs,
    ) {
        parent::__construct('Interactive callback round paused.');
    }
}
