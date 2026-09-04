<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class AssessmentChangeLogRepository
{
    public const ENTITY_ITEM_RESPONSE = 'item_response';
    public const ENTITY_FINAL_EVALUATION = 'final_evaluation';

    private const MAX_PER_ENTITY = 20;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param array{
     *   user_id: int,
     *   username: string,
     *   display_name: string,
     *   auth_source: string
     * } $actor
     * @param array<string, mixed> $details
     */
    public function record(
        int $assessmentId,
        string $entityType,
        string $entityKey,
        array $actor,
        string $summary,
        array $details = []
    ): bool {
        if ($assessmentId <= 0) {
            return false;
        }

        $entityType = trim($entityType);
        if ($entityType !== self::ENTITY_ITEM_RESPONSE && $entityType !== self::ENTITY_FINAL_EVALUATION) {
            return false;
        }

        $summary = trim($summary);
        if ($summary === '') {
            return false;
        }
        if (mb_strlen($summary) > 500) {
            $summary = mb_substr($summary, 0, 500);
        }

        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO assessment_change_log (
                assessment_id, entity_type, entity_key,
                actor_id, actor_username, actor_display_name, actor_auth_source,
                summary, details, created_at
             ) VALUES (
                :assessment_id, :entity_type, :entity_key,
                :actor_id, :actor_username, :actor_display_name, :actor_auth_source,
                :summary, :details, datetime(\'now\')
             )'
        );

        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':entity_type' => $entityType,
            ':entity_key' => $entityKey,
            ':actor_id' => (int) ($actor['user_id'] ?? 0) ?: null,
            ':actor_username' => (string) ($actor['username'] ?? ''),
            ':actor_display_name' => (string) ($actor['display_name'] ?? ''),
            ':actor_auth_source' => (string) ($actor['auth_source'] ?? 'local'),
            ':summary' => $summary,
            ':details' => $json,
        ]);

        return true;
    }

    /**
     * History for one entity, newest first.
     *
     * @return list<array{
     *   id: int,
     *   summary: string,
     *   details: array<string, mixed>,
     *   actor_id: int|null,
     *   actor_username: string,
     *   actor_display_name: string,
     *   actor_auth_source: string,
     *   created_at: string
     * }>
     */
    public function listForEntity(
        int $assessmentId,
        string $entityType,
        string $entityKey = '',
        int $limit = self::MAX_PER_ENTITY
    ): array {
        $result = $this->searchForEntity($assessmentId, $entityType, $entityKey, '', 1, $limit);

        return $result['entries'];
    }

    /**
     * @return array{
     *   entries: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int
     * }
     */
    public function searchForEntity(
        int $assessmentId,
        string $entityType,
        string $entityKey = '',
        string $query = '',
        int $page = 1,
        int $perPage = 5
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $empty = [
            'entries' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => $perPage,
            'total_pages' => 1,
        ];

        if ($assessmentId <= 0) {
            return $empty;
        }

        $entityType = trim($entityType);
        if ($entityType !== self::ENTITY_ITEM_RESPONSE && $entityType !== self::ENTITY_FINAL_EVALUATION) {
            return $empty;
        }

        $total = $this->countForEntity($assessmentId, $entityType, $entityKey, $query);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->entitySearchWhere($assessmentId, $entityType, $entityKey, $query);

        $statement = $this->pdo->prepare(
            'SELECT id, summary, details, actor_id, actor_username, actor_display_name,
                    actor_auth_source, created_at
             FROM assessment_change_log
             ' . $whereSql . '
             ORDER BY id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return [
            'entries' => $this->mapRows($statement->fetchAll()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    public function countForEntity(
        int $assessmentId,
        string $entityType,
        string $entityKey = '',
        string $query = ''
    ): int {
        if ($assessmentId <= 0) {
            return 0;
        }

        $entityType = trim($entityType);
        if ($entityType !== self::ENTITY_ITEM_RESPONSE && $entityType !== self::ENTITY_FINAL_EVALUATION) {
            return 0;
        }

        [$whereSql, $params] = $this->entitySearchWhere($assessmentId, $entityType, $entityKey, $query);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM assessment_change_log ' . $whereSql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function entitySearchWhere(
        int $assessmentId,
        string $entityType,
        string $entityKey,
        string $query
    ): array {
        $where = [
            'assessment_id = :assessment_id',
            'entity_type = :entity_type',
            'entity_key = :entity_key',
        ];
        $params = [
            ':assessment_id' => $assessmentId,
            ':entity_type' => $entityType,
            ':entity_key' => $entityKey,
        ];

        $query = trim($query);
        if ($query !== '') {
            $where[] = '(summary LIKE :q
                OR actor_username LIKE :q
                OR actor_display_name LIKE :q
                OR actor_auth_source LIKE :q
                OR created_at LIKE :q
                OR details LIKE :q)';
            $params[':q'] = '%' . $query . '%';
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * All item-response history for an assessment, keyed by item_key.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function listItemResponseHistory(int $assessmentId, int $perEntity = self::MAX_PER_ENTITY): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $perEntity = max(1, min(100, $perEntity));

        // Fetch a generous window then cap per key in PHP (SQLite lacks PARTITION BY easily).
        $statement = $this->pdo->prepare(
            'SELECT id, entity_key, summary, details, actor_id, actor_username, actor_display_name,
                    actor_auth_source, created_at
             FROM assessment_change_log
             WHERE assessment_id = :assessment_id
               AND entity_type = :entity_type
             ORDER BY id DESC
             LIMIT 2000'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':entity_type' => self::ENTITY_ITEM_RESPONSE,
        ]);

        $byKey = [];
        foreach ($this->mapRows($statement->fetchAll()) as $row) {
            $key = (string) ($row['entity_key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = [];
            }
            if (count($byKey[$key]) >= $perEntity) {
                continue;
            }
            unset($row['entity_key']);
            $byKey[$key][] = $row;
        }

        return $byKey;
    }

    public function deleteForAssessment(int $assessmentId): void
    {
        if ($assessmentId <= 0) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM assessment_change_log WHERE assessment_id = :id');
        $statement->execute([':id' => $assessmentId]);
    }

    public function deleteForEntity(int $assessmentId, string $entityType, string $entityKey): void
    {
        $entityKey = trim($entityKey);
        if ($assessmentId <= 0 || $entityKey === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM assessment_change_log
             WHERE assessment_id = :assessment_id
               AND entity_type = :entity_type
               AND entity_key = :entity_key'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':entity_type' => $entityType,
            ':entity_key' => $entityKey,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function mapRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $detailsRaw = (string) ($row['details'] ?? '{}');
            $decoded = json_decode($detailsRaw, true);
            $mapped = [
                'id' => (int) ($row['id'] ?? 0),
                'summary' => (string) ($row['summary'] ?? ''),
                'details' => is_array($decoded) ? $decoded : [],
                'actor_id' => isset($row['actor_id']) && $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
                'actor_username' => (string) ($row['actor_username'] ?? ''),
                'actor_display_name' => (string) ($row['actor_display_name'] ?? ''),
                'actor_auth_source' => (string) ($row['actor_auth_source'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
            if (array_key_exists('entity_key', $row)) {
                $mapped['entity_key'] = (string) ($row['entity_key'] ?? '');
            }
            $out[] = $mapped;
        }

        return $out;
    }
}
