<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use RuntimeException;
use Throwable;

/**
 * Fachlicher failure reported by AiContext::do() or PhoreAi::do().
 *
 * The message is concise and exception-friendly; details may contain larger
 * diagnostics or relevant excerpts. Data contains short machine-readable
 * diagnostic strings. The constructor is final, so subclasses used through
 * do(throw: SomeClass::class) automatically keep the same data contract.
 *
 * @see AiContext::do()
 * @see PhoreAi::do()
 */
class DoException extends RuntimeException
{
    /**
     * @param mixed $data Additional structured diagnostic data supplied by the operation.
     * @example throw new DoException('Verification failed.', 'No primary source found.', ['source=web']);
     * @see PhoreAi::do()
     */
    final public function __construct(
        string $message,
        public readonly ?string $details = null,
        public readonly mixed $data = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
