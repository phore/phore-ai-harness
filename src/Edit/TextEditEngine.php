<?php

declare(strict_types=1);

namespace Phore\AiHarness\Edit;

use InvalidArgumentException;

final class TextEditEngine
{
    /**
     * @param list<?string> $searches
     * @param list<string> $replacements
     */
    public static function apply(string $original, array $searches, array $replacements): string
    {
        if ($searches === [] || count($searches) !== count($replacements)) {
            throw new InvalidArgumentException('searches and replacements must be non-empty lists of equal length.');
        }

        $rewrite = array_keys(array_filter($searches, static fn ($search): bool => $search === null));
        if ($rewrite !== []) {
            if (count($searches) !== 1) {
                throw new InvalidArgumentException('A full rewrite (search = null) must be the only edit.');
            }
            return $replacements[0];
        }

        $ranges = [];
        foreach ($searches as $index => $search) {
            if (!is_string($search) || $search === '') {
                throw new InvalidArgumentException('Every targeted search must be a non-empty string.');
            }
            $first = strpos($original, $search);
            if ($first === false) {
                throw new InvalidArgumentException('Search text was not found in the original content.');
            }
            if (strpos($original, $search, $first + 1) !== false) {
                throw new InvalidArgumentException('Search text is not unique in the original content.');
            }
            $ranges[] = [$first, $first + strlen($search), $replacements[$index]];
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1, $count = count($ranges); $i < $count; $i++) {
            if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                throw new InvalidArgumentException('Edit search ranges must not overlap.');
            }
        }

        $result = $original;
        for ($i = count($ranges) - 1; $i >= 0; $i--) {
            [$start, $end, $replacement] = $ranges[$i];
            $result = substr($result, 0, $start) . $replacement . substr($result, $end);
        }
        return $result;
    }
}
