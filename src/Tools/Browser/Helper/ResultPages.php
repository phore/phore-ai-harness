<?php

declare(strict_types=1);

namespace Phore\AiHarness\Tools\Browser\Helper;

use Phore\AiHarness\ToolType\RecoverableToolException;

/** Immutable, bounded result snapshots with opaque cursors bound to their query. */
final class ResultPages
{
    private array $snapshots = [];
    private array $cursors = [];

    /**
     * Read a bounded page; the producer is evaluated only for the first page.
     *
     * @param string $kind Result collection name, such as styles or rules.
     * @param array $scope Query arguments which must remain identical on continuation.
     * @param string $cursor Opaque continuation token, or an empty string.
     * @param int $limit Requested record count.
     * @param int $maximum Maximum permitted record count per page.
     * @param int $maxBytes Maximum encoded JSON result size.
     * @param \Closure $producer Returns ['rows' => list<array>, 'meta' => array].
     * @param \Closure|null $project Project only the requested records, e.g. resolve source maps.
     * @return array Bounded collection, snapshot metadata and continuation cursor.
     * @throws RecoverableToolException For an invalid limit or stale/mismatched cursor.
     * @example $pages->read('styles', [$nodeId], '', 50, 100, 16384, $producer);
     * @see self::clear()
     */
    public function read(string $kind, array $scope, string $cursor, int $limit, int $maximum, int $maxBytes, \Closure $producer, ?\Closure $project = null): array
    {
        if ($limit < 1 || $limit > $maximum) {
            throw new RecoverableToolException('limit must be between 1 and ' . $maximum . '.');
        }
        $fingerprint = hash('sha256', self::json([$kind, $scope]));
        if ($cursor === '') {
            $data = $producer();
            $id = bin2hex(random_bytes(12));
            if (count($this->snapshots) >= 8) {
                $oldest = array_key_first($this->snapshots);
                unset($this->snapshots[$oldest]);
                $this->cursors = array_filter($this->cursors, static fn (array $entry): bool => $entry[0] !== $oldest);
            }
            $this->snapshots[$id] = [
                'fingerprint' => $fingerprint,
                'rows' => $data['rows'],
                'meta' => $data['meta'] ?? [],
                'scanTruncated' => $data['meta']['scanTruncated'] ?? false,
            ];
            $offset = 0;
        } else {
            [$id, $offset] = $this->cursors[$cursor] ?? ['', 0];
            if (!isset($this->snapshots[$id]) || $this->snapshots[$id]['fingerprint'] !== $fingerprint) {
                throw new RecoverableToolException('Cursor expired or query changed. Repeat the query without cursor.');
            }
        }
        $snapshot = $this->snapshots[$id];
        $rows = $snapshot['rows'];
        $result = $snapshot['meta'] + [
            'snapshotId' => $id,
            $kind => [],
            'returned' => 0,
            'total' => count($rows),
            'hasMore' => false,
            'nextCursor' => null,
            'scanTruncated' => $snapshot['scanTruncated'],
            'truncated' => false,
        ];
        $result['scanTruncated'] = $snapshot['scanTruncated'];
        $next = $offset;
        while ($next < count($rows) && count($result[$kind]) < $limit) {
            $row = $project === null ? $rows[$next] : $project($rows[$next]);
            $candidate = $result;
            $candidate[$kind][] = $row;
            if (strlen(self::json($candidate)) > $maxBytes - 256) {
                if ($result[$kind] !== []) {
                    break;
                }
                $row = ['omitted' => true, 'reason' => 'Record exceeds the page budget. Narrow the selector or properties filter.'];
                $result['truncated'] = true;
            }
            $result[$kind][] = $row;
            $next++;
        }
        $result['returned'] = count($result[$kind]);
        $result['hasMore'] = $next < count($rows);
        $result['truncated'] = $result['truncated'] || $result['hasMore'] || $snapshot['scanTruncated'];
        if ($result['hasMore']) {
            // Pro Offset nur einen Cursor speichern, damit Wiederholungen nicht den Cache vergrößern.
            $token = $id . ':' . $next;
            $this->cursors[$token] = [$id, $next];
            $result['nextCursor'] = $token;
        }
        if (strlen(self::json($result)) > $maxBytes) {
            throw new RecoverableToolException('Result metadata exceeds the page budget. Narrow the properties filter.');
        }
        return $result;
    }

    /**
     * Invalidate every cached result and cursor after navigation or a rebuild.
     *
     * @return void
     * @example $pages->clear();
     * @see \Phore\AiHarness\Tools\Browser\BrowserSession::invalidate()
     */
    public function clear(): void
    {
        $this->snapshots = [];
        $this->cursors = [];
    }

    /**
     * Encode browser data without escaping normal Unicode or URL slashes.
     *
     * @param mixed $value Data to encode.
     * @return string Valid JSON, substituting invalid UTF-8 from browser logs.
     * @example $json = ResultPages::json($result);
     * @see self::read()
     */
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Clip a UTF-8 string at a byte boundary and mark omitted content explicitly.
     *
     * @param string $value Browser-provided text.
     * @param int $bytes Maximum output bytes, including the marker.
     * @return string Bounded text.
     * @example $label = ResultPages::clip($value, 512);
     * @see self::json()
     */
    public static function clip(string $value, int $bytes = 512): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }
        $prefix = substr($value, 0, max(0, $bytes - 16));
        while ($prefix !== '' && preg_match('//u', $prefix) !== 1) {
            $prefix = substr($prefix, 0, -1);
        }
        return $prefix . '…[truncated]';
    }
}
