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
        $this->db = new PDO('sqlite:' . $databasePath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ai_file_revisions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, filesystem_id TEXT NOT NULL, '
            . 'path TEXT NOT NULL, content BLOB NOT NULL, created_at TEXT NOT NULL)'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS ai_file_revisions_lookup '
            . 'ON ai_file_revisions(filesystem_id, path, id DESC)'
        );
    }

    public function save(string $fileSystemId, string $path, string $content): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO ai_file_revisions(filesystem_id, path, content, created_at) VALUES(?, ?, ?, ?)'
        );
        $statement->execute([$fileSystemId, $path, $content, gmdate('c')]);
        return (int) $this->db->lastInsertId();
    }

    public function history(string $fileSystemId, string $path, int $limit = 20): array
    {
        $statement = $this->db->prepare(
            'SELECT id, created_at FROM ai_file_revisions '
            . 'WHERE filesystem_id = ? AND path = ? ORDER BY id DESC LIMIT ?'
        );
        $statement->bindValue(1, $fileSystemId);
        $statement->bindValue(2, $path);
        $statement->bindValue(3, max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'createdAt' => $row['created_at']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function get(string $fileSystemId, string $path, int $revisionId): ?string
    {
        $statement = $this->db->prepare(
            'SELECT content FROM ai_file_revisions WHERE filesystem_id = ? AND path = ? AND id = ?'
        );
        $statement->execute([$fileSystemId, $path, $revisionId]);
        $content = $statement->fetchColumn();
        if ($content === false) {
            return null;
        }
        if (!is_string($content)) {
            throw new RuntimeException('Invalid revision content for ' . $path);
        }
        return $content;
    }
}
