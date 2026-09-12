<?php

declare(strict_types=1);

/**
 * One-time move of Ticket Dossier SQLite + uploaded files into /database
 * so the GitHub updater preserves them (same as risk_assessment.sqlite).
 */
function migrateTicketDossierDataIfNeeded(): void
{
    if (!is_dir(TD_DATABASE_DIR)) {
        mkdir(TD_DATABASE_DIR, 0755, true);
    }

    if (!is_file(TD_SQLITE_PATH) && is_file(TD_LEGACY_SQLITE_PATH)) {
        if (!@copy(TD_LEGACY_SQLITE_PATH, TD_SQLITE_PATH)) {
            throw new RuntimeException('Unable to move Ticket Dossier database into /database.');
        }
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            $side = TD_LEGACY_SQLITE_PATH . $suffix;
            if (is_file($side)) {
                @copy($side, TD_SQLITE_PATH . $suffix);
            }
        }
    }

    if (!is_dir(TD_STORAGE_DIR)) {
        mkdir(TD_STORAGE_DIR, 0755, true);
    }

    if (!is_dir(TD_LEGACY_STORAGE_DIR)) {
        return;
    }

    $legacyProjects = glob(TD_LEGACY_STORAGE_DIR . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($legacyProjects as $legacyDir) {
        $name = basename($legacyDir);
        if ($name === '' || $name === '.' || $name === '..') {
            continue;
        }
        $dest = TD_STORAGE_DIR . '/' . $name;
        if (is_dir($dest)) {
            continue;
        }
        renameTicketDossierDirectory($legacyDir, $dest);
    }
}

function renameTicketDossierDirectory(string $from, string $to): void
{
    if (@rename($from, $to)) {
        return;
    }

    if (!mkdir($to, 0755, true) && !is_dir($to)) {
        throw new RuntimeException('Unable to create Ticket Dossier storage folder.');
    }

    $items = scandir($from) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $src = $from . '/' . $item;
        $dst = $to . '/' . $item;
        if (is_dir($src)) {
            renameTicketDossierDirectory($src, $dst);
            continue;
        }
        if (!@copy($src, $dst)) {
            throw new RuntimeException('Unable to copy Ticket Dossier file: ' . $item);
        }
    }
}

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    migrateTicketDossierDataIfNeeded();

    if (!is_dir(TD_DATABASE_DIR)) {
        mkdir(TD_DATABASE_DIR, 0755, true);
    }

    if (!is_dir(TD_STORAGE_DIR)) {
        mkdir(TD_STORAGE_DIR, 0755, true);
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
            owner_user_id INTEGER,
            owner_username TEXT NOT NULL DEFAULT \'\',
            owner_display_name TEXT NOT NULL DEFAULT \'\',
            owner_auth_source TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    ensureTicketDossierColumn($pdo, 'projects', 'owner_user_id', 'INTEGER');
    ensureTicketDossierColumn($pdo, 'projects', 'owner_username', "TEXT NOT NULL DEFAULT ''");
    ensureTicketDossierColumn($pdo, 'projects', 'owner_display_name', "TEXT NOT NULL DEFAULT ''");
    ensureTicketDossierColumn($pdo, 'projects', 'owner_auth_source', "TEXT NOT NULL DEFAULT ''");
    ensureTicketDossierColumn($pdo, 'projects', 'ai_reasoning_json', "TEXT NOT NULL DEFAULT ''");

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

function ensureTicketDossierColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $allowedTables = ['projects' => true];
    $allowedColumns = [
        'owner_user_id' => true,
        'owner_username' => true,
        'owner_display_name' => true,
        'owner_auth_source' => true,
        'ai_reasoning_json' => true,
    ];
    if (!isset($allowedTables[$table], $allowedColumns[$column])) {
        return;
    }

    $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
    $columns = $statement === false ? [] : $statement->fetchAll();
    foreach ($columns as $info) {
        if (($info['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}
