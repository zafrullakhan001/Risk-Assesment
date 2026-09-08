<?php
declare(strict_types=1);

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(TD_DATA_DIR)) {
        mkdir(TD_DATA_DIR, 0755, true);
    }

    $pdo = new PDO('sqlite:' . TD_SQLITE_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    try {
        $pdo->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable) {
        // Ignore if WAL is unavailable.
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
    initSchema($pdo);

    return $pdo;
}

function initSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            vendor TEXT,
            demand_number TEXT,
            story_number TEXT,
            task_number TEXT,
            ddr_number TEXT,
            demand_state TEXT,
            story_state TEXT,
            task_state TEXT,
            ddr_state TEXT,
            sources_json TEXT NOT NULL DEFAULT "{}",
            parsed_json TEXT NOT NULL DEFAULT "{}",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS project_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            size_bytes INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_project_files_project
         ON project_files(project_id)'
    );
}
