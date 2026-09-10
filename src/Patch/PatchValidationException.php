<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

class PatchValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $operationIndex = null,
        public readonly ?string $op = null,
        public readonly ?string $path = null,
    ) {
        // Never include patch values, target data or chained hydration exceptions.
        parent::__construct($errorCode . ($operationIndex === null ? '' : ' at operation ' . $operationIndex));
    }

    public function atOperation(int $index, JsonPatchOperation $operation): static
    {
        return new static($this->errorCode, $index, $operation->op, $operation->path);
    }
}
