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
 * diagnostic strings. Subclasses used through do(throw: SomeClass::class)
 * must inherit this constructor unchanged.
 *
 * @see AiContext::do()
 * @see PhoreAi::do()
 */
class DoException extends RuntimeException
{
    /**
     * @param list<string> $data Additional diagnostic values supplied by the model.
     * @example throw new DoException('Verification failed.', 'No primary source found.', ['source=web']);
     * @see PhoreAi::do()
     */
    public function __construct(
        string $message,
        public readonly string $details = '',
        public readonly array $data = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
