<?php

declare(strict_types=1);

namespace Phore\AiHarness\ToolType;

use RuntimeException;
use Throwable;

/**
 * Signals a callback-tool error that should be returned to the model.
 * All other exceptions keep their normal fatal semantics and abort the run.
 */
final class RecoverableToolException extends RuntimeException
{
    /**
     * Return a correctable tool failure with optional partial-result metadata.
     * Metadata must be JSON-serializable; it is sent to the model, not logged.
     * The ordinary exception constructor's first three arguments are preserved.
     *
     * @param array<string, mixed>|null $result Successful/failed batch outcomes.
     * @example throw new RecoverableToolException('Retry failed files only.', result: ['files' => $outcomes]);
     * @see \Phore\AiHarness\PhoreAi::MAX_CALLBACK_ROUNDS
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly ?array $result = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
