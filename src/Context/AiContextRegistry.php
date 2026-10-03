<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiOptions;

/** Explicit process-local convenience registry; no implicit global default. */
final class AiContextRegistry
{
    /** @var array<string, AiContext> */
    private static array $contexts = [];

    /**
     * Resolve options['ai_context']: null/absent creates an unregistered context,
     * a non-empty string gets or creates a named context, an object is used as-is.
     * Only common AiOptions keys become defaults on a newly created context.
     *
     * @param array<string, mixed> $options Existing helper options plus ai_context.
     * @throws InvalidArgumentException For an invalid selector or empty ID.
     * @example $context = AiContextRegistry::resolve(['ai_context' => 'default']);
     * @see AiContext
     * @see AiOptions
     */
    public static function resolve(array $options = []): AiContext
    {
        $selection = $options['ai_context'] ?? null;
        if ($selection instanceof AiContext) {
            return $selection;
        }

        $defaults = array_intersect_key($options, array_flip(AiOptions::KEYS));
        if ($selection === null) {
            return new AiContext(options: $defaults);
        }
        if (!is_string($selection) || trim($selection) === '') {
            throw new InvalidArgumentException(
                'ai_context must be a non-empty ID, an AiContext instance or null.',
            );
        }

        return self::$contexts[$selection] ??= new AiContext(options: $defaults);
    }

    public static function get(string $id): ?AiContext
    {
        return self::$contexts[$id] ?? null;
    }

    public static function forget(string $id): void
    {
        unset(self::$contexts[$id]);
    }

    public static function clear(): void
    {
        self::$contexts = [];
    }
}
