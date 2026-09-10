<?php

declare(strict_types=1);

namespace Phore\AiHarness\Logging;

/** Redaction happens before events reach any logger, including custom adapters. */
final class Redactor
{
    public static function text(string $text): string
    {
        $text = preg_replace('/\b(?:sk|sess)-[A-Za-z0-9_-]+/', '[REDACTED]', $text) ?? '';
        $text = preg_replace('/\bBearer\s+[^\s"\',;]+/i', 'Bearer [REDACTED]', $text) ?? '';
        $text = preg_replace('/((?:authorization|api[_-]?key|password|secret|token|cookie)\s*["\']?\s*[:=]\s*)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;}]+)/i', '$1[REDACTED]', $text) ?? '';

        // Do not allow model/tool text to inject terminal controls.
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '';
    }

    public static function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[depth limit]';
        }
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 50, true) as $key => $item) {
                $result[$key] = is_string($key) && preg_match('/authorization|api.?key|password|secret|token|cookie|credential|private.?key/i', $key)
                    ? '[REDACTED]'
                    : self::value($item, $depth + 1);
            }
            if (count($value) > 50) {
                $result['...'] = '[truncated]';
            }
            return $result;
        }
        if (is_string($value)) {
            return substr(self::text($value), 0, 500);
        }
        return is_scalar($value) || $value === null ? $value : '[object]';
    }

    public static function arguments(string $json): mixed
    {
        $decoded = json_decode($json, true);
        // Invalid JSON cannot be safely inspected for sensitive fields.
        return json_last_error() === JSON_ERROR_NONE ? self::value($decoded) : '[invalid JSON omitted]';
    }
}
