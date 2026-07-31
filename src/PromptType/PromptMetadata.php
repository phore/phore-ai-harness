<?php

declare(strict_types=1);

namespace Phore\AiHarness\PromptType;

use InvalidArgumentException;

final class PromptMetadata
{
    private function __construct()
    {
    }

    public static function validateAlias(?string $alias, string $promptType): ?string
    {
        if ($alias === null) {
            return null;
        }

        if (trim($alias) === '') {
            throw new InvalidArgumentException($promptType . ' alias must not be empty.');
        }

        return $alias;
    }

    public static function validateInstructions(?string $instructions, string $promptType): ?string
    {
        if ($instructions === null) {
            return null;
        }

        if (trim($instructions) === '') {
            throw new InvalidArgumentException($promptType . ' instructions must not be empty.');
        }

        return $instructions;
    }

    public static function validateContentType(?string $type, string $promptType): ?string
    {
        if ($type === null) {
            return null;
        }

        if (trim($type) === '') {
            throw new InvalidArgumentException($promptType . ' type must not be empty.');
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function addToArray(array &$array, ?string $alias, ?string $instructions, ?string $type): void
    {
        if ($alias !== null) {
            $array['alias'] = $alias;
        }
        if ($instructions !== null) {
            $array['instructions'] = $instructions;
        }
        if ($type !== null) {
            $array['contentFormat'] = $type;
        }
    }
}
