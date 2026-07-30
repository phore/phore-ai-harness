<?php

declare(strict_types=1);

namespace Phore\AiHarness\Helper;

use JsonException;
use ReflectionClass;
use RuntimeException;

final class Toolkit
{
    private function __construct()
    {
    }

    /**
     * Encodes data as JSON using the project's default flags.
     *
     * @throws JsonException
     */
    public static function jsonEncode(mixed $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }

    /**
     * Decodes a JSON object from raw model output.
     *
     * Markdown fenced JSON blocks are accepted and stripped before decoding.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    public static function decodeJsonOutput(string $output): array
    {
        $json = trim($output);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $json, $matches) === 1) {
            $json = trim($matches[1]);
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Expected JSON object output for casting.');
        }

        return $decoded;
    }

    public static function appendInstructions(?string $instructions, string $addition): string
    {
        if ($instructions === null || $instructions === '') {
            return $addition;
        }

        return $instructions . "\n\n" . $addition;
    }

    /**
     * Creates an OpenAI-compatible schema name from a class name.
     *
     * @param class-string $className
     */
    public static function schemaName(string $className): string
    {
        $shortName = (new ReflectionClass($className))->getShortName();
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $shortName) ?: 'response';

        return substr($name, 0, 64);
    }
}
