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

    /** Release the shared PDO handle so the SQLite file can be replaced (e.g. restore). */
    public static function disconnect(): void
    {
        self::$connection = null;
    }

    private static function migrate(PDO $pdo): void
    {
        $schemaPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sqlite.sql';
        if (!is_readable($schemaPath)) {
            throw new \RuntimeException('SQLite schema file is missing.');
        }

        $pdo->exec((string) file_get_contents($schemaPath));
        self::ensureColumn($pdo, 'assessments', 'workbook_json', "TEXT NOT NULL DEFAULT '{}'");
        self::ensureColumn($pdo, 'assessments', 'custom_executive_verdict', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'assessments', 'custom_executive_summary', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'assessments', 'owner_user_id', 'INTEGER');
        self::ensureColumn($pdo, 'assessments', 'owner_username', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'assessments', 'owner_display_name', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'assessments', 'owner_auth_source', "TEXT NOT NULL DEFAULT ''");
        self::backfillMissingProjectOwners($pdo);
        self::ensureColumn($pdo, 'assessment_items', 'item_type', "TEXT NOT NULL DEFAULT 'architecture'");
        self::ensureColumn($pdo, 'assessment_items', 'review_question', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'assessment_items', 'source_reference', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'assessment_items', 'origin', "TEXT NOT NULL DEFAULT 'excel'");
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
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS project_pictures (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                assessment_id INTEGER NOT NULL,
                title TEXT NOT NULL DEFAULT \'\',
                mime_type TEXT NOT NULL DEFAULT \'image/png\',
                image_base64 TEXT NOT NULL DEFAULT \'\',
                original_filename TEXT NOT NULL DEFAULT \'\',
                sort_order INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_project_pictures_assessment_id ON project_pictures (assessment_id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS finding_statuses (
                assessment_id INTEGER NOT NULL,
                finding_id TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'Open\',
                comment TEXT NOT NULL DEFAULT \'\',
                servicenow_links TEXT NOT NULL DEFAULT \'[]\',
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                PRIMARY KEY (assessment_id, finding_id),
                FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_finding_statuses_assessment_id ON finding_statuses (assessment_id)');
        self::ensureColumn($pdo, 'finding_statuses', 'comment', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'finding_statuses', 'servicenow_links', "TEXT NOT NULL DEFAULT '[]'");
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                is_approved INTEGER NOT NULL DEFAULT 0,
                is_disabled INTEGER NOT NULL DEFAULT 0,
                auth_source TEXT NOT NULL DEFAULT \'local\',
                display_name TEXT NOT NULL DEFAULT \'\',
                notes TEXT NOT NULL DEFAULT \'\',
                last_login TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                created_by_user_id INTEGER,
                created_by_username TEXT NOT NULL DEFAULT \'\',
                UNIQUE (username),
                UNIQUE (email)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_username ON users (username)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_auth_source ON users (auth_source)');
        self::ensureColumn($pdo, 'users', 'created_by_user_id', 'INTEGER');
        self::ensureColumn($pdo, 'users', 'created_by_username', "TEXT NOT NULL DEFAULT ''");
        self::backfillUserCreatedBy($pdo);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event TEXT NOT NULL,
                actor_id INTEGER,
                actor_username TEXT,
                target_user_id INTEGER,
                target_username TEXT,
                details TEXT NOT NULL DEFAULT \'\',
                ip_address TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_audit_log_created_at ON user_audit_log (created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_audit_log_event ON user_audit_log (event)');
        self::ensureColumn($pdo, 'item_responses', 'updated_by_user_id', 'INTEGER');
        self::ensureColumn($pdo, 'item_responses', 'updated_by_username', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'item_responses', 'updated_by_display_name', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'item_responses', 'updated_by_auth_source', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'final_evaluations', 'updated_by_user_id', 'INTEGER');
        self::ensureColumn($pdo, 'final_evaluations', 'updated_by_username', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'final_evaluations', 'updated_by_display_name', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'final_evaluations', 'updated_by_auth_source', "TEXT NOT NULL DEFAULT ''");
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS assessment_change_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                assessment_id INTEGER NOT NULL,
                entity_type TEXT NOT NULL,
                entity_key TEXT NOT NULL DEFAULT \'\',
                actor_id INTEGER,
                actor_username TEXT NOT NULL DEFAULT \'\',
                actor_display_name TEXT NOT NULL DEFAULT \'\',
                actor_auth_source TEXT NOT NULL DEFAULT \'\',
                summary TEXT NOT NULL DEFAULT \'\',
                details TEXT NOT NULL DEFAULT \'{}\',
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assessment_change_log_assessment_id ON assessment_change_log (assessment_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assessment_change_log_entity ON assessment_change_log (assessment_id, entity_type, entity_key)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS assessment_share_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                assessment_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                label TEXT NOT NULL DEFAULT \'\',
                created_by_user_id INTEGER,
                created_by_username TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                expires_at TEXT,
                revoked_at TEXT,
                last_accessed_at TEXT,
                FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assessment_share_links_assessment_id ON assessment_share_links (assessment_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assessment_share_links_token_hash ON assessment_share_links (token_hash)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS template_workbooks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT \'\',
                workbook_path TEXT NOT NULL,
                workbook_filename TEXT NOT NULL DEFAULT \'\',
                workbook_size INTEGER NOT NULL DEFAULT 0,
                prompt_path TEXT NOT NULL DEFAULT \'\',
                prompt_filename TEXT NOT NULL DEFAULT \'\',
                prompt_size INTEGER NOT NULL DEFAULT 0,
                mermaid_title TEXT NOT NULL DEFAULT \'\',
                mermaid_source TEXT NOT NULL DEFAULT \'\',
                uploaded_by_user_id INTEGER,
                uploaded_by_username TEXT NOT NULL DEFAULT \'\',
                uploaded_by_display_name TEXT NOT NULL DEFAULT \'\',
                uploaded_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_template_workbooks_uploaded_at ON template_workbooks (uploaded_at)');
        self::ensureColumn($pdo, 'template_workbooks', 'mermaid_title', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'template_workbooks', 'mermaid_source', "TEXT NOT NULL DEFAULT ''");
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS template_images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_id INTEGER NOT NULL,
                title TEXT NOT NULL DEFAULT \'\',
                mime_type TEXT NOT NULL DEFAULT \'image/png\',
                image_base64 TEXT NOT NULL DEFAULT \'\',
                original_filename TEXT NOT NULL DEFAULT \'\',
                sort_order INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (template_id) REFERENCES template_workbooks (id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_template_images_template_id ON template_images (template_id)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sharepoint_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_key TEXT NOT NULL DEFAULT \'default\',
                item_key TEXT NOT NULL,
                parent_item_key TEXT NOT NULL DEFAULT \'\',
                project_name TEXT NOT NULL DEFAULT \'\',
                name TEXT NOT NULL DEFAULT \'\',
                item_type TEXT NOT NULL DEFAULT \'file\',
                web_url TEXT NOT NULL DEFAULT \'\',
                relative_path TEXT NOT NULL DEFAULT \'\',
                mime_type TEXT NOT NULL DEFAULT \'\',
                size_bytes INTEGER NOT NULL DEFAULT 0,
                last_modified TEXT NOT NULL DEFAULT \'\',
                modified_by TEXT NOT NULL DEFAULT \'\',
                person TEXT NOT NULL DEFAULT \'\',
                synced_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (source_key, item_key)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sharepoint_items_project_name ON sharepoint_items (project_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sharepoint_items_name ON sharepoint_items (name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sharepoint_items_source ON sharepoint_items (source_key)');
        // Composite indexes used by per-folder catalog browse / project detail / search.
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_sharepoint_items_source_project
             ON sharepoint_items (source_key, project_name)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_sharepoint_items_source_name
             ON sharepoint_items (source_key, name)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_sharepoint_items_source_type
             ON sharepoint_items (source_key, item_type)'
        );
        self::ensureColumn($pdo, 'sharepoint_items', 'modified_by', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'sharepoint_items', 'person', "TEXT NOT NULL DEFAULT ''");
        self::ensureSharePointFts($pdo);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sharepoint_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_key TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL DEFAULT \'\',
                folder_url TEXT NOT NULL DEFAULT \'\',
                site_host TEXT NOT NULL DEFAULT \'\',
                site_path TEXT NOT NULL DEFAULT \'\',
                folder_path TEXT NOT NULL DEFAULT \'\',
                last_synced_at TEXT NOT NULL DEFAULT \'\',
                last_sync_status TEXT NOT NULL DEFAULT \'\',
                last_sync_error TEXT NOT NULL DEFAULT \'\',
                last_item_count INTEGER NOT NULL DEFAULT 0,
                sync_token_hash TEXT NOT NULL DEFAULT \'\',
                sync_token_expires TEXT NOT NULL DEFAULT \'0\',
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        self::seedAuthSettings($pdo);
        self::seedSharePointSettings($pdo);
        self::seedDefaultSharePointSource($pdo);
        self::seedDefaultAdmin($pdo);
        self::migrateLegacyMermaidDiagrams($pdo);
        self::normalizeExcelSerialAssessmentDates($pdo);
    }

    private static function seedDefaultSharePointSource(PDO $pdo): void
    {
        $count = $pdo->query('SELECT COUNT(*) FROM sharepoint_sources');
        if ($count !== false && (int) $count->fetchColumn() > 0) {
            return;
        }

        $get = static function (string $key, string $fallback = '') use ($pdo): string {
            $statement = $pdo->prepare('SELECT value FROM app_settings WHERE key = :k LIMIT 1');
            $statement->execute([':k' => $key]);
            $value = $statement->fetchColumn();

            return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
        };

        $host = $get('sharepoint_site_host', 'ahsonline.sharepoint.com');
        $sitePath = $get('sharepoint_site_path', '/teams/AITTechnologyEngagement');
        $folderPath = $get('sharepoint_folder_path', 'Architectural Projects [Public]');
        $folderUrl = $get('sharepoint_folder_url', '');
        if ($folderUrl === '') {
            $folderUrl = 'https://' . $host . rtrim($sitePath, '/')
                . '/Shared%20Documents/Forms/AllItems.aspx?id='
                . rawurlencode(rtrim($sitePath, '/') . '/Shared Documents/' . $folderPath)
                . '&p=true';
        }

        $insert = $pdo->prepare(
            'INSERT INTO sharepoint_sources (
                source_key, title, folder_url, site_host, site_path, folder_path,
                last_synced_at, last_sync_status, last_item_count, sort_order
             ) VALUES (
                \'default\', :title, :folder_url, :site_host, :site_path, :folder_path,
                :last_synced_at, :last_sync_status, :last_item_count, 1
             )'
        );
        $insert->execute([
            ':title' => 'Architectural Projects [Public]',
            ':folder_url' => $folderUrl,
            ':site_host' => $host,
            ':site_path' => $sitePath,
            ':folder_path' => $folderPath,
            ':last_synced_at' => $get('sharepoint_last_synced_at'),
            ':last_sync_status' => $get('sharepoint_last_sync_status'),
            ':last_item_count' => (int) $get('sharepoint_last_item_count', '0'),
        ]);
    }

    private static function normalizeExcelSerialAssessmentDates(PDO $pdo): void
    {
        $statement = $pdo->query(
            "SELECT id, assessment_date
             FROM assessments
             WHERE assessment_date GLOB '[0-9]*'
               AND assessment_date NOT LIKE '%-%'
               AND assessment_date != ''"
        );
        if ($statement === false) {
            return;
        }

        $update = $pdo->prepare('UPDATE assessments SET assessment_date = :date WHERE id = :id');
        foreach ($statement->fetchAll() as $row) {
            $normalized = \RiskAssessment\AssessmentDate::normalize((string) ($row['assessment_date'] ?? ''));
            if ($normalized === '' || $normalized === (string) $row['assessment_date']) {
                continue;
            }
            $update->execute([
                ':date' => $normalized,
                ':id' => (int) $row['id'],
            ]);
        }
    }

    private static function backfillMissingProjectOwners(PDO $pdo): void
    {
        $empty = $pdo->query("SELECT COUNT(*) FROM assessments WHERE IFNULL(owner_username, '') = ''");
        if ($empty === false || (int) $empty->fetchColumn() === 0) {
            return;
        }

        $users = $pdo->query(
            'SELECT id, username, display_name, auth_source
             FROM users
             WHERE is_disabled = 0 AND is_approved = 1
             ORDER BY is_admin DESC, id ASC
             LIMIT 2'
        );
        $rows = $users === false ? [] : $users->fetchAll();
        if (count($rows) !== 1) {
            return;
        }

        $user = $rows[0];
        $authSource = strtolower(trim((string) ($user['auth_source'] ?? 'local')));
        if ($authSource !== 'ldap') {
            $authSource = 'local';
        }

        $update = $pdo->prepare(
            "UPDATE assessments
             SET owner_user_id = :id,
                 owner_username = :username,
                 owner_display_name = :display_name,
                 owner_auth_source = :auth_source
             WHERE IFNULL(owner_username, '') = ''"
        );
        $update->execute([
            ':id' => (int) ($user['id'] ?? 0),
            ':username' => (string) ($user['username'] ?? ''),
            ':display_name' => (string) ($user['display_name'] ?? ''),
            ':auth_source' => $authSource,
        ]);
    }

    private static function seedAuthSettings(PDO $pdo): void
    {
        $defaults = [
            'local_auth_enabled' => '1',
            'local_registration_enabled' => '0',
            'ldap_enabled' => '0',
            'ldap_auto_create_users' => '1',
            'ldap_auto_update_users' => '1',
            'ldap_auto_approve' => '1',
            'ldap_servers' => '[]',
        ];

        self::seedSettingsDefaults($pdo, $defaults);
    }

    private static function seedSharePointSettings(PDO $pdo): void
    {
        $defaults = [
            'sharepoint_site_host' => 'ahsonline.sharepoint.com',
            'sharepoint_site_path' => '/teams/AITTechnologyEngagement',
            'sharepoint_folder_path' => 'Architectural Projects [Public]',
            'sharepoint_folder_url' => '',
            'sharepoint_browser_sync_token_hash' => '',
            'sharepoint_browser_sync_token_expires' => '0',
            'sharepoint_last_synced_at' => '',
            'sharepoint_last_sync_status' => '',
            'sharepoint_last_sync_error' => '',
            'sharepoint_last_item_count' => '0',
        ];

        self::seedSettingsDefaults($pdo, $defaults);
    }

    /** @param array<string, string> $defaults */
    private static function seedSettingsDefaults(PDO $pdo, array $defaults): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO app_settings (key, value, updated_at)
             SELECT :key, :value, datetime(\'now\')
             WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE key = :exists_key)'
        );
        foreach ($defaults as $key => $value) {
            $statement->execute([
                ':key' => $key,
                ':value' => $value,
                ':exists_key' => $key,
            ]);
        }
    }

    private static function seedDefaultAdmin(PDO $pdo): void
    {
        $count = $pdo->query('SELECT COUNT(*) FROM users');
        if ($count !== false && (int) $count->fetchColumn() > 0) {
            return;
        }

        $hash = password_hash(\RiskAssessment\Auth::DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT);
        if ($hash === false) {
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, is_admin, is_approved, is_disabled,
                                auth_source, display_name, notes, created_at, created_by_username)
             VALUES (:username, :email, :password_hash, 1, 1, 0, \'local\', :display_name, :notes, datetime(\'now\'), :created_by_username)'
        );
        $statement->execute([
            ':username' => \RiskAssessment\Auth::DEFAULT_ADMIN_USERNAME,
            ':email' => 'admin@localhost',
            ':password_hash' => $hash,
            ':display_name' => 'Administrator',
            ':notes' => 'Default administrator — change this password after first sign-in.',
            ':created_by_username' => 'system',
        ]);
    }

    private static function backfillUserCreatedBy(PDO $pdo): void
    {
        // Prefer earliest provisioning audit event when one exists (never write NULL into NOT NULL column).
        $pdo->exec(
            "UPDATE users
             SET created_by_user_id = (
                     SELECT actor_id FROM user_audit_log
                     WHERE target_user_id = users.id
                       AND event IN ('user.created', 'user.ldap_provisioned', 'user.registered')
                       AND IFNULL(actor_username, '') != ''
                     ORDER BY id ASC
                     LIMIT 1
                 ),
                 created_by_username = (
                     SELECT actor_username FROM user_audit_log
                     WHERE target_user_id = users.id
                       AND event IN ('user.created', 'user.ldap_provisioned', 'user.registered')
                       AND IFNULL(actor_username, '') != ''
                     ORDER BY id ASC
                     LIMIT 1
                 )
             WHERE IFNULL(created_by_username, '') = ''
               AND EXISTS (
                     SELECT 1 FROM user_audit_log
                     WHERE target_user_id = users.id
                       AND event IN ('user.created', 'user.ldap_provisioned', 'user.registered')
                       AND IFNULL(actor_username, '') != ''
               )"
        );

        $pdo->exec(
            "UPDATE users
             SET created_by_username = CASE
                    WHEN auth_source = 'ldap' THEN 'LDAP login'
                    WHEN notes LIKE 'First administrator%' THEN 'system'
                    WHEN notes LIKE 'Default administrator%' THEN 'system'
                    WHEN notes LIKE 'Recovered via CLI%' THEN 'CLI'
                    ELSE 'unknown'
                 END
             WHERE IFNULL(created_by_username, '') = ''"
        );
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

    /**
     * Create the SharePoint FTS5 search index when SQLite supports it (empty until first reindex/sync).
     */
    private static function ensureSharePointFts(PDO $pdo): void
    {
        try {
            $exists = $pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sharepoint_items_fts' LIMIT 1"
            );
            if ($exists !== false && $exists->fetchColumn() !== false) {
                return;
            }
            $pdo->exec(
                'CREATE VIRTUAL TABLE sharepoint_items_fts USING fts5(
                    project_name,
                    name,
                    relative_path,
                    modified_by,
                    person,
                    source_key UNINDEXED,
                    tokenize = \'unicode61 remove_diacritics 2\'
                )'
            );
        } catch (\Throwable) {
            // FTS5 may be unavailable in some PHP SQLite builds; LIKE search still works.
        }
    }
}
