<?php

declare(strict_types=1);

namespace RiskAssessment\Database;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    /** @param array{driver?: string, path?: string} $config */
    public static function connection(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $path = (string) ($config['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('SQLite database path is not configured.');
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the database directory.');
        }

        self::$connection = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        self::$connection->exec('PRAGMA foreign_keys = ON');
        self::migrate(self::$connection);

        return self::$connection;
    }

    private static function migrate(PDO $pdo): void
    {
        $schemaPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sqlite.sql';
        if (!is_readable($schemaPath)) {
            throw new \RuntimeException('SQLite schema file is missing.');
        }

        $pdo->exec((string) file_get_contents($schemaPath));
        self::ensureColumn($pdo, 'assessments', 'workbook_json', "TEXT NOT NULL DEFAULT '{}'");
        self::ensureColumn($pdo, 'assessment_items', 'item_type', "TEXT NOT NULL DEFAULT 'architecture'");
        self::ensureColumn($pdo, 'assessment_items', 'review_question', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'assessment_items', 'source_reference', "TEXT NOT NULL DEFAULT ''");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assessment_items_item_type ON assessment_items (item_type)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS item_responses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                assessment_id INTEGER NOT NULL,
                item_key TEXT NOT NULL,
                action TEXT NOT NULL DEFAULT \'open\',
                comment TEXT NOT NULL DEFAULT \'\',
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (assessment_id, item_key),
                FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_responses_assessment_id ON item_responses (assessment_id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS final_evaluations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                assessment_id INTEGER NOT NULL UNIQUE,
                evaluator_name TEXT NOT NULL DEFAULT \'\',
                evaluator_email TEXT NOT NULL DEFAULT \'\',
                notes TEXT NOT NULL DEFAULT \'\',
                ready_to_golive INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_final_evaluations_assessment_id ON final_evaluations (assessment_id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS project_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                assessment_id INTEGER NOT NULL,
                label TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\',
                sort_order INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_links_assessment_id ON project_links (assessment_id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS project_mermaid_diagrams (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                assessment_id INTEGER NOT NULL,
                title TEXT NOT NULL DEFAULT \'\',
                source TEXT NOT NULL DEFAULT \'\',
                sort_order INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_mermaid_diagrams_assessment_id ON project_mermaid_diagrams (assessment_id)');
        self::migrateLegacyMermaidDiagrams($pdo);
    }

    private static function migrateLegacyMermaidDiagrams(PDO $pdo): void
    {
        $legacy = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='project_mermaid'");
        if ($legacy === false || $legacy->fetch() === false) {
            return;
        }

        $rows = $pdo->query(
            'SELECT assessment_id, source, updated_at
             FROM project_mermaid
             WHERE trim(source) != \'\''
        );
        if ($rows === false) {
            return;
        }

        $check = $pdo->prepare(
            'SELECT 1 FROM project_mermaid_diagrams WHERE assessment_id = :assessment_id LIMIT 1'
        );
        $insert = $pdo->prepare(
            'INSERT INTO project_mermaid_diagrams (assessment_id, title, source, sort_order, updated_at)
             VALUES (:assessment_id, :title, :source, 0, :updated_at)'
        );

        foreach ($rows->fetchAll() as $row) {
            $assessmentId = (int) ($row['assessment_id'] ?? 0);
            if ($assessmentId <= 0) {
                continue;
            }
            $check->execute([':assessment_id' => $assessmentId]);
            if ($check->fetchColumn() !== false) {
                continue;
            }
            $insert->execute([
                ':assessment_id' => $assessmentId,
                ':title' => 'Architecture diagram',
                ':source' => (string) ($row['source'] ?? ''),
                ':updated_at' => (string) ($row['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = $statement === false ? [] : $statement->fetchAll();
        foreach ($columns as $info) {
            if (($info['name'] ?? '') === $column) {
                return;
            }
        }

        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
