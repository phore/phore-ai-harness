<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

use RuntimeException;

final class TaskErrorException extends RuntimeException
{
    public function __construct(
        public readonly string $errorType,
        public readonly string $contradictoryOrAmbiguousStatements,
        public readonly string $missingInformation,
        public readonly string $requestedAt,
        public readonly string $reason,
    ) {
        parent::__construct($this->buildMessage());
    }

    private function buildMessage(): string
    {
        return implode("\n\n", [
            'Task cannot be completed because the model reported unclear, contradictory, or incomplete instructions.',
            'Issue type: ' . $this->errorType,
            "Contradictory or ambiguous statements:\n" . $this->contradictoryOrAmbiguousStatements,
            "Missing information:\n" . $this->missingInformation,
            "Requested at:\n" . $this->requestedAt,
            "Reason:\n" . $this->reason,
        ]);
    }
}
