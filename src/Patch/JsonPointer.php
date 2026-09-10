<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

final class JsonPointer
{
    /** @return list<string> */
    public static function tokens(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }
        if ($pointer[0] !== '/' || preg_match('/~(?![01])/', $pointer)) {
            throw new PatchValidationException('invalid_pointer');
        }
        return array_map(static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', substr($pointer, 1)));
    }

    public static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    /** Segment-based ancestry, including equality. */
    public static function contains(string $parent, string $child): bool
    {
        $a = self::tokens($parent);
        return array_slice(self::tokens($child), 0, count($a)) === $a;
    }

    public static function index(string $token, int $length, bool $adding = false): int
    {
        if ($adding && $token === '-') {
            return $length;
        }
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $token) || strlen($token) > strlen((string) PHP_INT_MAX)
            || (strlen($token) === strlen((string) PHP_INT_MAX) && strcmp($token, (string) PHP_INT_MAX) > 0)) {
            throw new PatchValidationException('invalid_array_index');
        }
        $index = (int) $token;
        if ($index > $length || (!$adding && $index === $length)) {
            throw new PatchValidationException('missing_path');
        }
        return $index;
    }

    public static function get(mixed $document, string $pointer): mixed
    {
        foreach (self::tokens($pointer) as $token) {
            if ($document instanceof \stdClass && property_exists($document, $token)) {
                $document = $document->{$token};
            } elseif (is_array($document)) {
                $document = $document[self::index($token, count($document))];
            } else {
                throw new PatchValidationException('missing_path');
            }
        }
        return $document;
    }
}
