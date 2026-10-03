<?php

declare(strict_types=1);

namespace Phore\AiHarness\Context;

use InvalidArgumentException;
use Phore\AiHarness\AiContext;

/** Explicit process-local convenience registry; no implicit global default. */
final class AiContextRegistry
{
    /** @var array<string, AiContext> */
    private static array $contexts = [];

    /**
     * Resolve options['ai_context']: null/absent creates an unregistered context,
     * a non-empty string gets or creates a named context, an object is used as-is.
     * Call-specific input and output settings are never stored as defaults.
     *
     * @param array<string, mixed> $options Existing helper options plus ai_context.
     * @throws InvalidArgumentException For an invalid selector or empty ID.
     * @example $context = AiContextRegistry::resolve(['ai_context' => 'default']);
     * @see AiContext
     */
    public static function resolve(array $options = []): AiContext
    {
        $selection = $options['ai_context'] ?? null;
        if ($selection instanceof AiContext) {
            return $selection;
        }
        if ($selection === null) {
            return new AiContext($options);
        }
        if (!is_string($selection) || trim($selection) === '') {
            throw new InvalidArgumentException('ai_context must be a non-empty ID, an AiContext instance or null.');
        }
        return self::$contexts[$selection] ??= new AiContext($options);
    }

    /**
     * Find an existing named context without creating one or making an API call.
     *
     * @return AiContext|null The live instance, or null for an unknown ID.
     * @example AiContextRegistry::get('default')?->setCheckpoint();
     * @see resolve()
     */
    public static function get(string $id): ?AiContext
    {
        return self::$contexts[$id] ?? null;
    }

    /**
     * Remove a registry entry; references already held by callers remain valid.
     * Use at a request/job boundary in long-lived workers to isolate users/jobs.
     *
     * @example AiContextRegistry::forget('job-42');
     * @see clear()
     */
    public static function forget(string $id): void
    {
        unset(self::$contexts[$id]);
    }

    /**
     * Drop all process-local registrations without resetting usage or files.
     *
     * @example AiContextRegistry::clear();
     * @see forget()
     */
    public static function clear(): void
    {
        self::$contexts = [];
    }
}
