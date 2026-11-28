<?php

declare(strict_types=1);

namespace Phore\AiHarness\Tools\Browser\Helper;

/** Projects CDP styles into compact rule parts without cssText or embedded maps. */
final class CssRules
{
    /**
     * Select relevant declarations before caching or paginating matched styles.
     *
     * A large rule is split into parts of eight declarations so continuation never
     * silently loses its remaining declarations. CDP longhand metadata also keeps
     * a shorthand when the requested property is one of its longhands.
     *
     * @param array $matched CSS.getMatchedStylesForNode response.
     * @param list<string> $properties Exact property filter; empty means all.
     * @return array{rows: list<array>, meta: array} Compact rules and scan metadata.
     * @example $data = CssRules::collect($cdpResult, ['margin-top']);
     * @see \Phore\AiHarness\Tools\Browser\BrowserSession::inspectNode()
     */
    public static function collect(array $matched, array $properties): array
    {
        $rows = [];
        $visited = 0;
        $cut = false;
        $append = static function (array $style, array $context, ?array $rule = null) use (&$rows, &$visited, &$cut, $properties): void {
            if (count($rows) >= 1024 || $visited >= 16384) {
                $cut = true;
                return;
            }
            $declarations = [];
            foreach ($style['cssProperties'] ?? [] as $property) {
                if (++$visited > 16384) {
                    $cut = true;
                    break;
                }
                $name = (string) ($property['name'] ?? '');
                $longhands = array_column($property['longhandProperties'] ?? [], 'name');
                if ($properties !== [] && !in_array($name, $properties, true)
                    && array_intersect($longhands, $properties) === []
                    && $name !== 'all'
                ) {
                    continue;
                }
                $item = [
                    'name' => ResultPages::clip($name, 128),
                    'value' => ResultPages::clip((string) ($property['value'] ?? ''), 512),
                    'important' => (bool) ($property['important'] ?? false),
                ];
                foreach (['disabled', 'implicit', 'parsedOk'] as $flag) {
                    if (array_key_exists($flag, $property)) {
                        $item[$flag] = (bool) $property[$flag];
                    }
                }
                if (isset($property['range'])) {
                    $item['location'] = self::location($property['range']);
                }
                if ($properties !== [] && $longhands !== []) {
                    $item['requestedLonghands'] = array_values(array_intersect($properties, $longhands));
                }
                $declarations[] = $item;
            }
            if ($declarations === []) {
                return;
            }
            $base = $context + [
                'ruleId' => 'r' . (count($rows) + 1),
                'styleSheetId' => (string) ($rule['styleSheetId'] ?? $style['styleSheetId'] ?? ''),
                'selector' => ResultPages::clip((string) ($rule['selectorList']['text'] ?? $context['kind']), 512),
                'origin' => (string) ($rule['origin'] ?? 'regular'),
            ];
            $selectorIndex = $rule['_matchingSelectors'][0] ?? 0;
            $range = $rule['selectorList']['selectors'][$selectorIndex]['range'] ?? $style['range'] ?? null;
            if ($range !== null) {
                $base['location'] = self::location($range);
            }
            if ($rule !== null) {
                $base['matchingSelectors'] = array_slice($rule['_matchingSelectors'] ?? [], 0, 16);
                $conditions = [];
                foreach (['media', 'supports', 'containerQueries', 'layers', 'scopes', 'nestingSelectors'] as $kind) {
                    foreach ($rule[$kind] ?? [] as $condition) {
                        if (count($conditions) >= 12) {
                            $base['conditionsTruncated'] = true;
                            break 2;
                        }
                        $entry = ['kind' => $kind, 'text' => ResultPages::clip(is_string($condition) ? $condition : (string) ($condition['text'] ?? $condition['conditionText'] ?? ''), 256)];
                        if (is_array($condition) && array_key_exists('active', $condition)) {
                            $entry['active'] = (bool) $condition['active'];
                        } elseif ($kind === 'media' && isset($condition['mediaList'])) {
                            $entry['active'] = in_array(true, array_column($condition['mediaList'], 'active'), true);
                        }
                        $conditions[] = $entry;
                    }
                }
                if ($conditions !== []) {
                    $base['conditions'] = $conditions;
                }
            }
            $parts = array_chunk($declarations, 8);
            foreach ($parts as $index => $part) {
                if (count($rows) >= 1024) {
                    $cut = true;
                    break;
                }
                $rows[] = $base + ['part' => $index + 1, 'parts' => count($parts), 'declarations' => $part];
            }
        };
        foreach (['inlineStyle' => 'inline', 'attributesStyle' => 'attributes'] as $field => $kind) {
            if (isset($matched[$field])) {
                $append($matched[$field], ['kind' => $kind]);
            }
        }
        $rules = static function (array $matches, array $context) use ($append, &$cut): void {
            if (count($matches) > 4096) {
                $cut = true;
            }
            foreach (array_slice($matches, 0, 4096) as $match) {
                $rule = $match['rule'];
                $rule['_matchingSelectors'] = $match['matchingSelectors'] ?? [];
                $append($rule['style'], $context, $rule);
            }
        };
        $rules($matched['matchedCSSRules'] ?? [], ['kind' => 'matched']);
        foreach (array_slice($matched['pseudoElements'] ?? [], 0, 32) as $pseudo) {
            $rules($pseudo['matches'] ?? [], ['kind' => 'pseudo', 'pseudoType' => $pseudo['pseudoType']]);
        }
        foreach (array_slice($matched['inherited'] ?? [], 0, 64) as $depth => $ancestor) {
            $context = ['kind' => 'ancestor', 'ancestorDepth' => $depth + 1];
            if (isset($ancestor['inlineStyle'])) {
                $append($ancestor['inlineStyle'], $context + ['inline' => true]);
            }
            $rules($ancestor['matchedCSSRules'] ?? [], $context);
        }
        $cut = $cut || count($matched['pseudoElements'] ?? []) > 32 || count($matched['inherited'] ?? []) > 64;
        return [
            'rows' => $rows,
            'meta' => [
                'scanTruncated' => $cut,
                'order' => 'CDP match order; ancestor declarations may not inherit. No cascade winner is inferred.',
                'omittedCategories' => ['inheritedPseudoElements', 'keyframes', 'positionTry', 'propertyRegistrations', 'atRules', 'functions'],
            ],
        ];
    }

    /** @return array{line: int, column: int} One-based stylesheet-relative position. */
    private static function location(array $range): array
    {
        return ['line' => (int) $range['startLine'] + 1, 'column' => (int) $range['startColumn'] + 1];
    }
}
