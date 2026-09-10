<?php

declare(strict_types=1);

namespace Phore\AiHarness\Patch;

/** JSON objects are stdClass; JSON arrays are PHP lists, including empty lists. */
final class JsonValue
{
    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            throw new PatchValidationException('invalid_json_value');
        }
    }

    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PatchValidationException('invalid_json');
        }
    }

    public static function copy(mixed $value): mixed
    {
        return self::decode(self::encode($value));
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode(self::canonical(self::copy($value))));
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $result = new \stdClass();
            foreach ($properties as $key => $child) {
                $result->{(string) $key} = self::canonical($child);
            }
            return $result;
        }
        return is_array($value) ? array_map(self::canonical(...), $value) : $value;
    }

    public static function equal(mixed $a, mixed $b): bool
    {
        if ((is_int($a) && is_float($b)) || (is_float($a) && is_int($b))) {
            $integer = is_int($a) ? $a : $b;
            $float = is_float($a) ? $a : $b;
            // PHP's loose comparison rounds large integers to float first.
            return floor($float) === $float && $float >= PHP_INT_MIN && $float < -(float) PHP_INT_MIN && (int) $float === $integer;
        }
        if ($a instanceof \stdClass && $b instanceof \stdClass) {
            $a = get_object_vars($a);
            $b = get_object_vars($b);
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::equal($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!self::equal($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }
}
