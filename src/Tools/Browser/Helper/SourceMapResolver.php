<?php

declare(strict_types=1);

namespace Phore\AiHarness\Tools\Browser\Helper;

use Closure;
use Exception;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * Resolves individual generated CSS/JavaScript positions without returning map data.
 *
 * Only ordinary version 3 maps are supported. Maps are limited to 2 MiB and
 * 100,000 segments; indexed maps explicitly report that they are unsupported.
 */
final class SourceMapResolver
{
    private const MAX_MAP_BYTES = 2097152;
    private const MAX_CACHE_ENTRIES = 8;
    private const MAX_SEGMENTS = 100000;
    private const MAX_TABLE_ENTRIES = 20000;
    private const MAX_URL_BYTES = 8192;
    private const MAX_NAME_BYTES = 256;
    private const MAX_COORDINATE = 2147483647;
    private const BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private ?Closure $loader;

    /** @var array<string, array> Validated map metadata or a bounded cached failure. */
    private array $cache = [];

    /**
     * Creates an isolated source-map resolver with an optional authorized loader.
     *
     * The loader receives the resolved external map URL and must return its JSON
     * as a string. It owns URL authorization, timeouts and the download-size limit;
     * this class performs no network or filesystem access. Inline data URLs work
     * without a loader. Recoverable loader exceptions become an unavailable result;
     * PHP Errors (including programming/type errors) are allowed to propagate.
     *
     * @param (Closure(string): string)|null $loader Optional external-map loader.
     * @see resolve() Resolve a generated location using this loader.
     * @example new SourceMapResolver(fn (string $url): string => $authorizedLoader($url));
     */
    public function __construct(?Closure $loader = null)
    {
        $this->loader = $loader;
    }

    /**
     * Resolves a zero-based CDP location to a one-based original source location.
     *
     * Uses the greatest mapped column at or before the requested column on the
     * same generated line. Explicit unmapped segments are respected. A location
     * is a source-map association, not a claim about which CSS declaration wins.
     * Unknown/oversized/invalid maps return unavailable; an unrepresented location
     * returns unmapped. Neither result contains map JSON, sourcesContent or data
     * URLs. Names longer than 256 bytes are omitted. Differing source locations at
     * the same generated point are reported as ambiguous rather than guessed.
     *
     * @param string $sourceMapUrl Source-map reference reported by the browser.
     * @param string $generatedUrl URL of the generated stylesheet or script.
     * @param int $line Zero-based generated line.
     * @param int $column Zero-based generated column (UTF-16 for CSS/JavaScript).
     * @return array{status: string, file?: string, line?: int, column?: int, name?: string, reason?: string, errorType?: string}
     * @throws InvalidArgumentException If a requested coordinate is out of range.
     * @see clear() Invalidate cached maps after navigation or a development build.
     * @example $resolver->resolve('app.css.map', 'https://example.test/app.css', 0, 12);
     */
    public function resolve(string $sourceMapUrl, string $generatedUrl, int $line, int $column): array
    {
        if ($line < 0 || $column < 0 || $line > self::MAX_COORDINATE || $column > self::MAX_COORDINATE) {
            throw new InvalidArgumentException('Source-map coordinates must be non-negative 32-bit integers.');
        }
        if ($sourceMapUrl === '') {
            return ['status' => 'unavailable', 'reason' => 'No source map was provided.'];
        }

        $key = null;
        try {
            $inline = strncasecmp($sourceMapUrl, 'data:', 5) === 0;
            if (strlen($sourceMapUrl) > ($inline ? self::MAX_MAP_BYTES * 4 + 256 : self::MAX_URL_BYTES)) {
                throw new UnexpectedValueException('Source-map reference exceeds the size limit.');
            }
            if (strlen($generatedUrl) > self::MAX_URL_BYTES) {
                throw new UnexpectedValueException('Generated source URL exceeds the size limit.');
            }
            $mapUrl = $inline ? $sourceMapUrl : self::resolveUrl($generatedUrl, $sourceMapUrl);
            $baseUrl = $inline ? $generatedUrl : $mapUrl;
            $key = hash('sha256', $mapUrl . "\0" . $baseUrl);

            if (isset($this->cache[$key])) {
                $map = $this->cache[$key];
                unset($this->cache[$key]);
                $this->cache[$key] = $map;
                if (isset($map['failure'])) {
                    return $map['failure'];
                }
            } else {
                if ($inline) {
                    $json = $this->decodeDataUrl($mapUrl);
                } else {
                    if ($this->loader === null) {
                        return ['status' => 'unavailable', 'reason' => 'No external source-map loader is configured.'];
                    }
                    try {
                        $json = ($this->loader)($mapUrl);
                    } catch (Exception $exception) {
                        // Fremde Fehlermeldungen können Zugangsdaten oder ganze Data-URLs enthalten.
                        $failure = [
                            'status' => 'unavailable',
                            'reason' => 'The authorized source-map loader failed.',
                            'errorType' => substr(get_class($exception), 0, 160),
                        ];
                        $this->remember($key, ['failure' => $failure]);
                        return $failure;
                    }
                    if (!is_string($json)) {
                        throw new UnexpectedValueException('The source-map loader did not return JSON text.');
                    }
                }
                $map = $this->parseMap($json, $baseUrl);
                $this->remember($key, $map);
            }

            return $this->lookup($map, $line, $column);
        } catch (JsonException $exception) {
            $failure = ['status' => 'unavailable', 'reason' => 'Source map is not valid JSON within the supported depth limit.'];
        } catch (UnexpectedValueException | InvalidArgumentException $exception) {
            // Hier kommen nur die festen, datenfreien Diagnosen dieses Resolvers an.
            $failure = ['status' => 'unavailable', 'reason' => $exception->getMessage()];
        }
        if ($key !== null) {
            $this->remember($key, ['failure' => $failure]);
        }
        return $failure;
    }

    /**
     * Discards cached map metadata after a navigation, rebuild or source change.
     *
     * At most eight maps or load/decode failures are cached. The caller controls
     * invalidation because an unchanged map URL may point to different content
     * after a development build. Failed loads are retried after this method runs.
     *
     * @return void
     * @see resolve() Reloads external map metadata after this cache is cleared.
     * @example $resolver->clear();
     */
    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Resolves a URI reference against a generated-file or map URL without I/O.
     *
     * Handles root-relative paths, scheme-relative URLs, query/fragment references
     * and dot segments, including virtual schemes such as webpack and file.
     * This is URI resolution only; the caller must authorize schemes and hosts
     * before loading any returned URL. Each input and result is limited to 8 KiB.
     *
     * @param string $base Base URL, normally the source map's external URL.
     * @param string $relative URI reference to resolve.
     * @return string Resolved URI.
     * @throws InvalidArgumentException For oversized, ambiguous or malformed URIs.
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-5 Relative URI resolution.
     * @example SourceMapResolver::resolveUrl('https://example.test/css/app.css', '../src/app.scss');
     */
    public static function resolveUrl(string $base, string $relative): string
    {
        $target = self::splitUrl($relative);
        if ($target['scheme'] === null) {
            $origin = self::splitUrl($base);
            $target['scheme'] = $origin['scheme'];
            if ($target['authority'] === null) {
                $target['authority'] = $origin['authority'];
                if ($target['path'] === '') {
                    $target['path'] = $origin['path'];
                    if ($target['query'] === null) {
                        $target['query'] = $origin['query'];
                    }
                } elseif ($target['path'][0] !== '/') {
                    if ($origin['scheme'] === null && $origin['authority'] === null && ($origin['path'] === '' || $origin['path'][0] !== '/')) {
                        throw new InvalidArgumentException('A relative source URL requires an absolute base URL.');
                    }
                    if ($origin['scheme'] !== null && $origin['authority'] === null && $origin['path'] !== '' && $origin['path'][0] !== '/') {
                        throw new InvalidArgumentException('A relative source URL cannot use an opaque base URL.');
                    }
                    $slash = strrpos($origin['path'], '/');
                    $directory = $slash === false ? '' : substr($origin['path'], 0, $slash + 1);
                    if ($origin['authority'] !== null && $origin['path'] === '') {
                        $directory = '/';
                    }
                    $target['path'] = $directory . $target['path'];
                }
            }
        }
        $url = ($target['scheme'] !== null ? $target['scheme'] . ':' : '')
            . ($target['authority'] !== null ? '//' . $target['authority'] : '')
            . self::removeDotSegments($target['path'])
            . ($target['query'] !== null ? '?' . $target['query'] : '')
            . ($target['fragment'] !== null ? '#' . $target['fragment'] : '');
        if (strlen($url) > self::MAX_URL_BYTES) {
            throw new InvalidArgumentException('Resolved source URL exceeds the size limit.');
        }
        return $url;
    }

    private function remember(string $key, array $entry): void
    {
        unset($this->cache[$key]);
        if (count($this->cache) >= self::MAX_CACHE_ENTRIES) {
            unset($this->cache[array_key_first($this->cache)]);
        }
        $this->cache[$key] = $entry;
    }

    private function decodeDataUrl(string $url): string
    {
        $comma = strpos($url, ',');
        if ($comma === false || $comma > 256) {
            throw new UnexpectedValueException('Invalid source-map data URL header.');
        }
        $header = substr($url, 0, $comma);
        if (!preg_match('~^data:(?:application/json|application/octet-stream|text/json)?(?:;charset=[a-z0-9._-]+)?(?:;base64)?$~iD', $header)) {
            throw new UnexpectedValueException('Unsupported source-map data URL format.');
        }
        $payload = rawurldecode(substr($url, $comma + 1));
        if (str_ends_with(strtolower($header), ';base64')) {
            if (strlen($payload) > (int) ceil(self::MAX_MAP_BYTES / 3) * 4) {
                throw new UnexpectedValueException('Source map exceeds the 2 MiB size limit.');
            }
            $payload = base64_decode($payload, true);
            if ($payload === false) {
                throw new UnexpectedValueException('Source-map data URL contains invalid base64.');
            }
        }
        return $payload;
    }

    private function parseMap(string $json, string $baseUrl): array
    {
        if (strlen($json) > self::MAX_MAP_BYTES) {
            throw new UnexpectedValueException('Source map exceeds the 2 MiB size limit.');
        }
        // HTTP-Maps können eine XSSI-Schutzzeile vor dem eigentlichen JSON haben.
        if (preg_match('~^https?://~i', $baseUrl) && str_starts_with($json, ")]}'")) {
            $newline = strcspn($json, "\r\n");
            $json = substr($json, $newline);
        }
        $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        if (!is_object($decoded) || !isset($decoded->version) || ($decoded->version !== 3 && $decoded->version !== 3.0)) {
            throw new UnexpectedValueException('Source map must be a version 3 JSON object.');
        }
        if (property_exists($decoded, 'sections')) {
            throw new UnexpectedValueException('Indexed source maps are not supported.');
        }
        if (!isset($decoded->mappings, $decoded->sources) || !is_string($decoded->mappings) || !is_array($decoded->sources)) {
            throw new UnexpectedValueException('Source map requires mappings text and a sources array.');
        }
        $names = $decoded->names ?? [];
        $root = $decoded->sourceRoot ?? '';
        if (!is_array($names) || !is_string($root) || strlen($root) > self::MAX_URL_BYTES) {
            throw new UnexpectedValueException('Invalid source-map names or sourceRoot.');
        }
        if (count($decoded->sources) > self::MAX_TABLE_ENTRIES || count($names) > self::MAX_TABLE_ENTRIES) {
            throw new UnexpectedValueException('Source-map source/name table exceeds the entry limit.');
        }
        foreach ($decoded->sources as $source) {
            if ($source !== null && (!is_string($source) || strlen($source) > self::MAX_URL_BYTES)) {
                throw new UnexpectedValueException('Invalid or oversized source-map source reference.');
            }
        }
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new UnexpectedValueException('Invalid source-map symbol name.');
            }
        }

        // Nur die kompakten benötigten Felder behalten; sourcesContent und Erweiterungen verwerfen.
        return [
            'mappings' => $decoded->mappings,
            'sources' => $decoded->sources,
            'names' => $names,
            'sourceRoot' => $root,
            'base' => $baseUrl,
        ];
    }

    private function lookup(array $map, int $line, int $column): array
    {
        $mappings = $map['mappings'];
        $length = strlen($mappings);
        $offset = $generatedLine = $generatedColumn = $source = $originalLine = $originalColumn = $name = $segments = 0;
        $candidate = null;
        $candidateColumn = -1;
        $ambiguous = false;
        $afterComma = false;

        // Streaming hält den Speicher unabhängig von der Zahl decodierter Mapping-Segmente klein.
        while ($offset < $length) {
            if ($mappings[$offset] === ';') {
                if ($afterComma) {
                    throw new UnexpectedValueException('Source-map mappings contain an empty segment.');
                }
                $generatedLine++;
                $generatedColumn = 0;
                $offset++;
                continue;
            }
            if (++$segments > self::MAX_SEGMENTS) {
                throw new UnexpectedValueException('Source map exceeds the 100,000-segment processing limit.');
            }
            $fields = [];
            while ($offset < $length && $mappings[$offset] !== ',' && $mappings[$offset] !== ';') {
                if (count($fields) === 5) {
                    throw new UnexpectedValueException('Source-map segment has too many fields.');
                }
                $fields[] = $this->readVlq($mappings, $offset, $length);
            }
            $count = count($fields);
            if ($count !== 1 && $count !== 4 && $count !== 5) {
                throw new UnexpectedValueException('Source-map segment must contain one, four or five fields.');
            }
            $generatedColumn = $this->addCoordinate($generatedColumn, $fields[0]);
            $position = null;
            if ($count > 1) {
                $source = $this->addCoordinate($source, $fields[1]);
                $originalLine = $this->addCoordinate($originalLine, $fields[2]);
                $originalColumn = $this->addCoordinate($originalColumn, $fields[3]);
                if (!array_key_exists($source, $map['sources'])) {
                    throw new UnexpectedValueException('Source-map segment references an unknown source.');
                }
                $position = [$source, $originalLine, $originalColumn, null];
                if ($count === 5) {
                    $name = $this->addCoordinate($name, $fields[4]);
                    if (!array_key_exists($name, $map['names'])) {
                        throw new UnexpectedValueException('Source-map segment references an unknown name.');
                    }
                    $position[3] = $name;
                }
            }
            if ($generatedLine === $line && $generatedColumn <= $column && $generatedColumn >= $candidateColumn) {
                if ($generatedColumn > $candidateColumn) {
                    $candidate = $position;
                    $candidateColumn = $generatedColumn;
                    $ambiguous = false;
                } elseif ($candidate !== $position) {
                    if ($candidate === null || $position === null || array_slice($candidate, 0, 3) !== array_slice($position, 0, 3)) {
                        $ambiguous = true;
                    } else {
                        $candidate[3] = null;
                    }
                }
            }
            $afterComma = $offset < $length && $mappings[$offset] === ',';
            if ($afterComma) {
                $offset++;
            }
        }
        if ($afterComma) {
            throw new UnexpectedValueException('Source-map mappings end with an empty segment.');
        }
        if ($ambiguous) {
            return ['status' => 'unavailable', 'reason' => 'Multiple original positions share the requested generated point.'];
        }
        if ($candidate === null || $map['sources'][$candidate[0]] === null) {
            return ['status' => 'unmapped', 'reason' => 'No original source is mapped at this generated position.'];
        }
        $sourcePath = $map['sources'][$candidate[0]];
        if ($map['sourceRoot'] !== '') {
            $root = $map['sourceRoot'];
            $sourcePath = $root . (str_ends_with($root, '/') ? '' : '/') . $sourcePath;
        }
        $file = self::resolveUrl($map['base'], $sourcePath);
        if (strncasecmp($file, 'data:', 5) === 0) {
            return ['status' => 'unavailable', 'reason' => 'Original source is inline data rather than a source-file location.'];
        }
        $result = ['status' => 'resolved', 'file' => $file, 'line' => $candidate[1] + 1, 'column' => $candidate[2] + 1];
        if ($candidate[3] !== null && strlen($map['names'][$candidate[3]]) <= self::MAX_NAME_BYTES) {
            $result['name'] = $map['names'][$candidate[3]];
        }
        return $result;
    }

    private function readVlq(string $mappings, int &$offset, int $length): int
    {
        $value = $shift = 0;
        do {
            if ($offset >= $length || $shift > 30) {
                throw new UnexpectedValueException('Source-map VLQ value is incomplete or exceeds 32 bits.');
            }
            $digit = strpos(self::BASE64, $mappings[$offset++]);
            if ($digit === false) {
                throw new UnexpectedValueException('Source-map mappings contain an invalid base64 VLQ character.');
            }
            $value += ($digit & 31) * (2 ** $shift);
            $shift += 5;
        } while (($digit & 32) !== 0);
        if ($value > 4294967295) {
            throw new UnexpectedValueException('Source-map VLQ value exceeds 32 bits.');
        }
        if ($value === 1) {
            return -2147483648;
        }
        $magnitude = intdiv((int) $value, 2);
        return ($value & 1) === 1 ? -$magnitude : $magnitude;
    }

    private function addCoordinate(int $previous, int $delta): int
    {
        $value = $previous + $delta;
        if ($value < 0 || $value > self::MAX_COORDINATE) {
            throw new UnexpectedValueException('Source-map coordinate or table index is outside the supported range.');
        }
        return $value;
    }

    private static function splitUrl(string $url): array
    {
        if (strlen($url) > self::MAX_URL_BYTES || preg_match('~[\x00-\x1f\x7f\\\\]~', $url)) {
            throw new InvalidArgumentException('Source URL is oversized or contains unsupported characters.');
        }
        $url = str_replace(' ', '%20', $url);
        $pattern = '~^(?:(?<scheme>[a-z][a-z0-9+.-]*):)?(?://(?<authority>[^/?#]*))?(?<path>[^?#]*)(?:\?(?<query>[^#]*))?(?:#(?<fragment>.*))?$~iD';
        if (!preg_match($pattern, $url, $parts, PREG_UNMATCHED_AS_NULL)) {
            throw new InvalidArgumentException('Source URL is malformed.');
        }
        return $parts;
    }

    private static function removeDotSegments(string $path): string
    {
        $output = '';
        while ($path !== '') {
            if (str_starts_with($path, '../') || str_starts_with($path, './')) {
                $path = substr($path, strpos($path, '/') + 1);
            } elseif (str_starts_with($path, '/./') || $path === '/.') {
                $path = '/' . substr($path, 3);
            } elseif (str_starts_with($path, '/../') || $path === '/..') {
                $path = '/' . substr($path, 4);
                $slash = strrpos($output, '/');
                $output = $slash === false ? '' : substr($output, 0, $slash);
            } elseif ($path === '.' || $path === '..') {
                $path = '';
            } else {
                $slash = strpos($path, '/', $path[0] === '/' ? 1 : 0);
                if ($slash === false) {
                    $output .= $path;
                    $path = '';
                } else {
                    $output .= substr($path, 0, $slash);
                    $path = substr($path, $slash);
                }
            }
        }
        return $output;
    }
}
