<?php
declare(strict_types=1);

final class ProjectRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $stmt = getDb()->query(
            'SELECT * FROM projects ORDER BY datetime(updated_at) DESC, id DESC'
        );

        return $stmt->fetchAll() ?: [];
    }

    public static function count(): int
    {
        return (int) getDb()->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    }

    /**
     * Search projects with pagination, sorting, and optional column filters.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public static function search(
        string $query = '',
        int $page = 1,
        int $perPage = 10,
        string $sort = 'updated',
        string $dir = 'desc',
        array $filters = []
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = self::searchWhere($query, $filters);
        $orderSql = self::listOrderBy($sort, $dir);

        $sql = 'SELECT * FROM projects'
            . ($whereSql !== '' ? ' ' . $whereSql : '')
            . ' ORDER BY ' . $orderSql
            . ' LIMIT :limit OFFSET :offset';

        $stmt = getDb()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<string, string> $filters
     */
    public static function countSearch(string $query = '', array $filters = []): int
    {
        [$whereSql, $params] = self::searchWhere($query, $filters);

        $sql = 'SELECT COUNT(*) FROM projects' . ($whereSql !== '' ? ' ' . $whereSql : '');
        $stmt = getDb()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: array<string, string|int>}
     */
    private static function searchWhere(string $query, array $filters = []): array
    {
        $conditions = [];
        $params = [];

        $query = trim($query);
        if ($query !== '') {
            $like = '%' . $query . '%';
            $searchConditions = [
                'title LIKE :q',
                'IFNULL(vendor, \'\') LIKE :q',
                'IFNULL(demand_number, \'\') LIKE :q',
                'IFNULL(story_number, \'\') LIKE :q',
                'IFNULL(task_number, \'\') LIKE :q',
                'IFNULL(ddr_number, \'\') LIKE :q',
                'IFNULL(demand_state, \'\') LIKE :q',
                'IFNULL(story_state, \'\') LIKE :q',
                'IFNULL(task_state, \'\') LIKE :q',
                'IFNULL(ddr_state, \'\') LIKE :q',
                'IFNULL(updated_at, \'\') LIKE :q',
                'IFNULL(created_at, \'\') LIKE :q',
                'IFNULL(owner_username, \'\') LIKE :q',
                'IFNULL(owner_display_name, \'\') LIKE :q',
            ];
            $params[':q'] = $like;

            if (ctype_digit($query)) {
                $searchConditions[] = 'id = :exact_id';
                $params[':exact_id'] = (int) $query;
            }

            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
        }

        $filterMap = [
            'id' => 'CAST(id AS TEXT) LIKE :f_id',
            'project' => 'title LIKE :f_project',
            'vendor' => 'IFNULL(vendor, \'\') LIKE :f_vendor',
            'demand' => 'IFNULL(demand_number, \'\') LIKE :f_demand',
            'story' => 'IFNULL(story_number, \'\') LIKE :f_story',
            'task' => 'IFNULL(task_number, \'\') LIKE :f_task',
            'ddr' => 'IFNULL(ddr_number, \'\') LIKE :f_ddr',
            'owner' => "(IFNULL(owner_display_name, '') LIKE :f_owner OR IFNULL(owner_username, '') LIKE :f_owner)",
            'updated' => 'IFNULL(updated_at, \'\') LIKE :f_updated',
        ];

        foreach ($filterMap as $key => $sql) {
            $raw = trim((string) ($filters[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $param = ':f_' . $key;
            $conditions[] = $sql;
            $params[$param] = '%' . $raw . '%';
        }

        if ($conditions === []) {
            return ['', []];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    private static function listOrderBy(string $sort, string $dir): string
    {
        $dirSql = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $map = [
            'id' => 'id',
            'project' => 'LOWER(title)',
            'vendor' => 'LOWER(IFNULL(vendor, \'\'))',
            'demand' => 'LOWER(IFNULL(demand_number, \'\'))',
            'story' => 'LOWER(IFNULL(story_number, \'\'))',
            'task' => 'LOWER(IFNULL(task_number, \'\'))',
            'ddr' => 'LOWER(IFNULL(ddr_number, \'\'))',
            'owner' => "LOWER(COALESCE(NULLIF(owner_display_name, ''), NULLIF(owner_username, ''), ''))",
            'updated' => 'datetime(updated_at)',
        ];

        $column = $map[$sort] ?? $map['updated'];
        if ($sort === 'updated' || $sort === 'id') {
            return $column . ' ' . $dirSql . ', id DESC';
        }

        return $column . ' ' . $dirSql . ', datetime(updated_at) DESC, id DESC';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $stmt = getDb()->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function filesFor(int $projectId): array
    {
        $stmt = getDb()->prepare(
            'SELECT * FROM project_files WHERE project_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$projectId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findFile(int $fileId, int $projectId): ?array
    {
        $stmt = getDb()->prepare(
            'SELECT * FROM project_files WHERE id = ? AND project_id = ?'
        );
        $stmt->execute([$fileId, $projectId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{
     *   title: string,
     *   vendor: string,
     *   demand_number: string,
     *   story_number: string,
     *   task_number: string,
     *   ddr_number: string,
     *   demand_state: string,
     *   story_state: string,
     *   task_state: string,
     *   ddr_state: string,
     *   sources: array<string, bool>,
     *   parsed: array<string, mixed>,
     *   owner_user_id?: int|null,
     *   owner_username?: string,
     *   owner_display_name?: string,
     *   owner_auth_source?: string
     * } $data
     * @param list<array{kind: string, original_name: string, stored_name: string, size_bytes: int}> $files
     */
    public static function create(array $data, array $files): int
    {
        $db = getDb();
        $now = nowUtc();

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO projects (
                    title, vendor,
                    demand_number, story_number, task_number, ddr_number,
                    demand_state, story_state, task_state, ddr_state,
                    sources_json, parsed_json,
                    owner_user_id, owner_username, owner_display_name, owner_auth_source,
                    created_at, updated_at
                ) VALUES (
                    :title, :vendor,
                    :demand_number, :story_number, :task_number, :ddr_number,
                    :demand_state, :story_state, :task_state, :ddr_state,
                    :sources_json, :parsed_json,
                    :owner_user_id, :owner_username, :owner_display_name, :owner_auth_source,
                    :created_at, :updated_at
                )'
            );

            $ownerUserId = (int) ($data['owner_user_id'] ?? 0);

            $stmt->execute([
                ':title' => $data['title'],
                ':vendor' => $data['vendor'],
                ':demand_number' => $data['demand_number'],
                ':story_number' => $data['story_number'],
                ':task_number' => $data['task_number'],
                ':ddr_number' => $data['ddr_number'],
                ':demand_state' => $data['demand_state'],
                ':story_state' => $data['story_state'],
                ':task_state' => $data['task_state'],
                ':ddr_state' => $data['ddr_state'],
                ':sources_json' => json_encode($data['sources'], JSON_UNESCAPED_UNICODE) ?: '{}',
                ':parsed_json' => json_encode($data['parsed'], JSON_UNESCAPED_UNICODE) ?: '{}',
                ':owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
                ':owner_username' => (string) ($data['owner_username'] ?? ''),
                ':owner_display_name' => (string) ($data['owner_display_name'] ?? ''),
                ':owner_auth_source' => (string) ($data['owner_auth_source'] ?? ''),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $projectId = (int) $db->lastInsertId();

            $fileStmt = $db->prepare(
                'INSERT INTO project_files (
                    project_id, kind, original_name, stored_name, size_bytes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?)'
            );

            foreach ($files as $file) {
                $fileStmt->execute([
                    $projectId,
                    $file['kind'],
                    $file['original_name'],
                    $file['stored_name'],
                    $file['size_bytes'],
                    $now,
                ]);
            }

            $db->commit();

            return $projectId;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $dir = TD_STORAGE_DIR . '/' . $id;
        $db = getDb();

        $db->beginTransaction();
        try {
            $fileStmt = $db->prepare('DELETE FROM project_files WHERE project_id = ?');
            $fileStmt->execute([$id]);

            $stmt = $db->prepare('DELETE FROM projects WHERE id = ?');
            $stmt->execute([$id]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        self::removeStorageDirectory($dir);
    }

    /**
     * Persist merged dossier fields after adding/replacing source files.
     *
     * @param array{
     *   title: string,
     *   vendor: string,
     *   demand_number: string,
     *   story_number: string,
     *   task_number: string,
     *   ddr_number: string,
     *   demand_state: string,
     *   story_state: string,
     *   task_state: string,
     *   ddr_state: string,
     *   sources: array<string, bool>,
     *   parsed: array<string, mixed>
     * } $data
     */
    public static function updateParsed(int $id, array $data): void
    {
        $stmt = getDb()->prepare(
            'UPDATE projects SET
                title = :title,
                vendor = :vendor,
                demand_number = :demand_number,
                story_number = :story_number,
                task_number = :task_number,
                ddr_number = :ddr_number,
                demand_state = :demand_state,
                story_state = :story_state,
                task_state = :task_state,
                ddr_state = :ddr_state,
                sources_json = :sources_json,
                parsed_json = :parsed_json,
                updated_at = :updated_at
             WHERE id = :id'
        );

        $stmt->execute([
            ':title' => $data['title'],
            ':vendor' => $data['vendor'],
            ':demand_number' => $data['demand_number'],
            ':story_number' => $data['story_number'],
            ':task_number' => $data['task_number'],
            ':ddr_number' => $data['ddr_number'],
            ':demand_state' => $data['demand_state'],
            ':story_state' => $data['story_state'],
            ':task_state' => $data['task_state'],
            ':ddr_state' => $data['ddr_state'],
            ':sources_json' => json_encode($data['sources'], JSON_UNESCAPED_UNICODE) ?: '{}',
            ':parsed_json' => json_encode($data['parsed'], JSON_UNESCAPED_UNICODE) ?: '{}',
            ':updated_at' => nowUtc(),
            ':id' => $id,
        ]);
    }

    /**
     * Replace any existing file row of the same kind, then insert the new file.
     *
     * @param array{kind: string, original_name: string, stored_name: string, size_bytes: int} $file
     */
    public static function replaceFileOfKind(int $projectId, array $file): void
    {
        $db = getDb();
        $existing = $db->prepare(
            'SELECT id, stored_name FROM project_files WHERE project_id = ? AND kind = ?'
        );
        $existing->execute([$projectId, $file['kind']]);
        $rows = $existing->fetchAll() ?: [];

        $dir = TD_STORAGE_DIR . '/' . $projectId;
        foreach ($rows as $row) {
            $path = $dir . '/' . $row['stored_name'];
            if (is_file($path)) {
                @unlink($path);
            }
            $del = $db->prepare('DELETE FROM project_files WHERE id = ? AND project_id = ?');
            $del->execute([(int) $row['id'], $projectId]);
        }

        $ins = $db->prepare(
            'INSERT INTO project_files (project_id, kind, original_name, stored_name, size_bytes, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $projectId,
            $file['kind'],
            $file['original_name'],
            $file['stored_name'],
            $file['size_bytes'],
            nowUtc(),
        ]);
    }

    /**
     * Update title, vendor, and owner without replacing source files.
     *
     * @param array{
     *   title: string,
     *   vendor?: string,
     *   owner_user_id?: int|null,
     *   owner_username?: string,
     *   owner_display_name?: string,
     *   owner_auth_source?: string
     * } $details
     */
    public static function updateDetails(int $id, array $details): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid project.');
        }

        $project = self::find($id);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        $title = trim((string) ($details['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Project name is required.');
        }
        if (strlen($title) > 200) {
            $title = substr($title, 0, 200);
        }

        $vendor = array_key_exists('vendor', $details)
            ? trim((string) $details['vendor'])
            : (string) ($project['vendor'] ?? '');
        if (strlen($vendor) > 200) {
            $vendor = substr($vendor, 0, 200);
        }

        $ownerUserId = array_key_exists('owner_user_id', $details)
            ? (int) ($details['owner_user_id'] ?? 0)
            : (int) ($project['owner_user_id'] ?? 0);
        $ownerUsername = array_key_exists('owner_username', $details)
            ? (string) $details['owner_username']
            : (string) ($project['owner_username'] ?? '');
        $ownerDisplayName = array_key_exists('owner_display_name', $details)
            ? (string) $details['owner_display_name']
            : (string) ($project['owner_display_name'] ?? '');
        $ownerAuthSource = array_key_exists('owner_auth_source', $details)
            ? (string) $details['owner_auth_source']
            : (string) ($project['owner_auth_source'] ?? '');

        $parsed = json_decode((string) ($project['parsed_json'] ?? ''), true);
        if (!is_array($parsed)) {
            $parsed = [];
        }
        if (!isset($parsed['overview']) || !is_array($parsed['overview'])) {
            $parsed['overview'] = [];
        }
        $parsed['overview']['title'] = $title;
        $parsed['overview']['vendor'] = $vendor;

        $stmt = getDb()->prepare(
            'UPDATE projects SET
                title = :title,
                vendor = :vendor,
                owner_user_id = :owner_user_id,
                owner_username = :owner_username,
                owner_display_name = :owner_display_name,
                owner_auth_source = :owner_auth_source,
                parsed_json = :parsed_json,
                updated_at = :updated_at
             WHERE id = :id'
        );

        $stmt->execute([
            ':title' => $title,
            ':vendor' => $vendor,
            ':owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
            ':owner_username' => $ownerUsername,
            ':owner_display_name' => $ownerDisplayName,
            ':owner_auth_source' => $ownerAuthSource,
            ':parsed_json' => json_encode($parsed, JSON_UNESCAPED_UNICODE) ?: '{}',
            ':updated_at' => nowUtc(),
            ':id' => $id,
        ]);
    }

    /**
     * Persist Gemma reasoning payload for a project.
     *
     * @param array<string, mixed> $reasoning
     */
    public static function saveAiReasoning(int $id, array $reasoning): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid project.');
        }

        $json = json_encode($reasoning, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Unable to encode AI reasoning.');
        }

        $stmt = getDb()->prepare(
            'UPDATE projects SET ai_reasoning_json = :ai_reasoning_json, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            ':ai_reasoning_json' => $json,
            ':updated_at' => nowUtc(),
            ':id' => $id,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getAiReasoning(int $id): ?array
    {
        $project = self::find($id);
        if ($project === null) {
            return null;
        }

        $raw = trim((string) ($project['ai_reasoning_json'] ?? ''));
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function removeStorageDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::removeStorageDirectory($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }
}
