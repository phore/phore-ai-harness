<?php

declare(strict_types=1);

namespace Phore\AiHarness;

use InvalidArgumentException;

/**
 * Controls how AiContext handles an incompatible exported resume state.
 *
 * The default is deliberately strict: provider, state format and prepared
 * prompt/tool setup must match. ON_MISMATCH_RESTART may be used when the
 * application explicitly prefers a blank conversation over an exception.
 *
 * @see AiContext::importState()
 * @see ResumeStateException
 */
final readonly class ResumeOptions
{
    public const ON_MISMATCH_THROW = 'throw';
    public const ON_MISMATCH_RESTART = 'restart';

    private const KEYS = ['on_mismatch'];

    /**
     * @throws InvalidArgumentException For an unsupported mismatch policy.
     * @example $options = new ResumeOptions();
     * @example $options = new ResumeOptions(onMismatch: ResumeOptions::ON_MISMATCH_RESTART);
     * @see fromArray()
     */
    public function __construct(
        public string $onMismatch = self::ON_MISMATCH_THROW,
    ) {
        if (!in_array($onMismatch, [self::ON_MISMATCH_THROW, self::ON_MISMATCH_RESTART], true)) {
            throw new InvalidArgumentException('on_mismatch must be "throw" or "restart".');
        }
    }

    /**
     * Normalize array options or pass an existing ResumeOptions instance through.
     *
     * @param array{on_mismatch?: string}|self $options
     * @throws InvalidArgumentException For unknown keys or invalid values.
     * @example $options = ResumeOptions::fromArray(['on_mismatch' => 'restart']);
     * @see AiContext::importState()
     */
    public static function fromArray(array|self $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        $unknown = array_values(array_diff(array_keys($options), self::KEYS));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown resume option(s): ' . implode(', ', $unknown));
        }

        $onMismatch = $options['on_mismatch'] ?? self::ON_MISMATCH_THROW;
        if (!is_string($onMismatch)) {
            throw new InvalidArgumentException('on_mismatch must be a string.');
        }

        return new self($onMismatch);
    }
}
