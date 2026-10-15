<?php

declare(strict_types=1);

namespace Phore\AiHarness\Edit;

use InvalidArgumentException;

/** Exact, byte-preserving replacements shared by text and file operations. */
final class TextEditEngine
{
    public const INSTRUCTIONS = 'Use edit for precise changes (search must match exactly). When changing multiple separate locations in one target, use one write_text call with multiple edits instead of multiple calls. Each non-null search is matched against the original target, not after earlier edits are applied. Do not emit overlapping or nested edits. Merge nearby changes into one edit. Keep search as small as possible while still being unique in the target. Do not pad with large unchanged regions. All bytes outside the explicitly replaced regions must remain exactly identical to the original target, byte for byte. Use search=null only for complete rewrites and as the sole edit. When rewriting existing text, preserve all content not intended to change, byte for byte. An empty edits list means no change.';

    /**
     * Apply pairs to one immutable input snapshot, returning the assembled text.
     * An empty list is a no-op. Validation finishes before any replacement.
     *
     * @param list<array{search: string|null, replacement: string}> $edits
     * @throws InvalidArgumentException For malformed, ambiguous or overlapping edits.
     * @example TextEditEngine::applyEdits('Hello world', [['search' => 'world', 'replacement' => 'team']]);
     * @see apply()
     */
    public static function applyEdits(string $original, array $edits): string
    {
        if (!array_is_list($edits)) {
            throw new InvalidArgumentException('edits must be a list.');
        }
        if ($edits === []) {
            return $original;
        }
        $searches = [];
        $replacements = [];
        foreach ($edits as $index => $edit) {
            if (!is_array($edit) || count($edit) !== 2 || !array_key_exists('search', $edit)
                || !array_key_exists('replacement', $edit)) {
                throw new InvalidArgumentException('Edit #' . ($index + 1) . ' must contain exactly search and replacement.');
            }
            $searches[] = $edit['search'];
            $replacements[] = $edit['replacement'];
        }
        return self::apply($original, $searches, $replacements);
    }

    /**
     * Apply parallel search/replacement lists; retained for existing callers.
     * A null search is allowed only as the single full-rewrite operation.
     * Adjacent edits are allowed; nested/overlapping edits are not. UTF-8, BOMs
     * and line endings outside the selected ranges are copied unchanged.
     *
     * @param list<string|null> $searches
     * @param list<string> $replacements Empty strings delete the selected text.
     * @throws InvalidArgumentException For invalid lists, searches or replacements.
     * @example TextEditEngine::apply('A B', ['A', 'B'], ['B', 'C']); // 'B C'
     * @see applyEdits()
     */
    public static function apply(string $original, array $searches, array $replacements): string
    {
        // Zuerst Form und Typen pruefen, auch beim vollstaendigen Neuschreiben.
        if (!array_is_list($searches) || !array_is_list($replacements) || $searches === []
            || count($searches) !== count($replacements)) {
            throw new InvalidArgumentException('searches and replacements must be non-empty lists of equal length.');
        }
        foreach ($replacements as $index => $replacement) {
            if (!is_string($replacement)) {
                throw new InvalidArgumentException('Replacement #' . ($index + 1) . ' must be a string.');
            }
        }
        if (in_array(null, $searches, true)) {
            if (count($searches) !== 1) {
                throw new InvalidArgumentException('A full rewrite (search = null) must be the only edit.');
            }
            return $replacements[0];
        }

        // Alle Suchstellen gegen denselben Originalstand aufloesen.
        $ranges = [];
        foreach ($searches as $index => $search) {
            $label = 'Edit #' . ($index + 1);
            if (!is_string($search) || $search === '') {
                throw new InvalidArgumentException($label . ': targeted search must be a non-empty string.');
            }
            $first = strpos($original, $search);
            if ($first === false) {
                throw new InvalidArgumentException($label . ': search text was not found in the original content.');
            }
            $matches = 1;
            $position = $first;
            while (($position = strpos($original, $search, $position + 1)) !== false) {
                $matches++;
            }
            if ($matches !== 1) {
                throw new InvalidArgumentException($label . ': search is not unique (' . $matches . ' matches); include more surrounding text.');
            }
            $ranges[] = [$first, $first + strlen($search), $replacements[$index], $index + 1];
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        for ($index = 1, $count = count($ranges); $index < $count; $index++) {
            if ($ranges[$index][0] < $ranges[$index - 1][1]) {
                throw new InvalidArgumentException('Edits #' . $ranges[$index - 1][3] . ' and #' . $ranges[$index][3] . ' overlap.');
            }
        }

        // Rueckwaerts anwenden, damit fruehere Offsets unveraendert bleiben.
        $result = $original;
        for ($index = count($ranges) - 1; $index >= 0; $index--) {
            [$start, $end, $replacement] = $ranges[$index];
            $result = substr($result, 0, $start) . $replacement . substr($result, $end);
        }
        return $result;
    }
}
