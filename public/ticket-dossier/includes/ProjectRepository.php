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
     *   parsed: array<string, mixed>
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
                    sources_json, parsed_json, created_at, updated_at
                ) VALUES (
                    :title, :vendor,
                    :demand_number, :story_number, :task_number, :ddr_number,
                    :demand_state, :story_state, :task_state, :ddr_state,
                    :sources_json, :parsed_json, :created_at, :updated_at
                )'
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
        $files = self::filesFor($id);
        $dir = TD_STORAGE_DIR . '/' . $id;

        $stmt = getDb()->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$id]);

        foreach ($files as $file) {
            $path = $dir . '/' . $file['stored_name'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
}
