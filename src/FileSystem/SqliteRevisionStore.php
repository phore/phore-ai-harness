<?php

declare(strict_types=1);

namespace Phore\AiHarness\FileSystem;

use PDO;
use RuntimeException;

final class SqliteRevisionStore implements RevisionStoreInterface
{
    private PDO $db;

    public function __construct(string $databasePath)
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PDO SQLite is required for SqliteRevisionStore.');
        }

        $this->db = new PDO('sqlite:' . $databasePath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ai_file_revisions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, filesystem_id TEXT NOT NULL, '
            . 'path TEXT NOT NULL, content BLOB NOT NULL, exists_flag INTEGER NOT NULL DEFAULT 1, '
            . 'created_at TEXT NOT NULL)'
        );

        $columns = $this->db->query('PRAGMA table_info(ai_file_revisions)')->fetchAll(PDO::FETCH_ASSOC);
        if (!array_filter($columns, static fn (array $column): bool => $column['name'] === 'exists_flag')) {
            $this->db->exec(
                'ALTER TABLE ai_file_revisions ADD COLUMN exists_flag INTEGER NOT NULL DEFAULT 1'
            );
        }

        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS ai_file_revisions_lookup '
            . 'ON ai_file_revisions(filesystem_id, path, id DESC)'
        );
    }

    public function save(string $fileSystemId, string $path, ?string $content): int
    {
        $exists = $content !== null;
        $storedContent = $content ?? '';

        $latest = $this->db->prepare(
            'SELECT id, content, exists_flag FROM ai_file_revisions '
            . 'WHERE filesystem_id = ? AND path = ? ORDER BY id DESC LIMIT 1'
        );
        $latest->execute([$fileSystemId, $path]);
        $row = $latest->fetch(PDO::FETCH_ASSOC);
        if (
            is_array($row)
            && (bool) $row['exists_flag'] === $exists
            && is_string($row['content'])
            && $row['content'] === $storedContent
        ) {
            return (int) $row['id'];
        }

        $statement = $this->db->prepare(
            'INSERT INTO ai_file_revisions('
            . 'filesystem_id, path, content, exists_flag, created_at'
            . ') VALUES(?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $fileSystemId,
            $path,
            $storedContent,
            $exists ? 1 : 0,
            gmdate('c'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function history(string $fileSystemId, string $path, int $limit = 20): array
    {
        $statement = $this->db->prepare(
            'SELECT id, created_at, exists_flag FROM ai_file_revisions '
            . 'WHERE filesystem_id = ? AND path = ? ORDER BY id DESC LIMIT ?'
        );
        $statement->bindValue(1, $fileSystemId);
        $statement->bindValue(2, $path);
        $statement->bindValue(3, max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'createdAt' => (string) $row['created_at'],
                'exists' => (bool) $row['exists_flag'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function get(string $fileSystemId, string $path, int $revisionId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT content, exists_flag FROM ai_file_revisions '
            . 'WHERE filesystem_id = ? AND path = ? AND id = ?'
        );
        $statement->execute([$fileSystemId, $path, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $exists = (bool) $row['exists_flag'];
        $content = $row['content'];
        if ($exists && !is_string($content)) {
            throw new RuntimeException('Invalid revision content for ' . $path);
        }

        return [
            'exists' => $exists,
            'content' => $exists ? $content : null,
        ];
    }
}
