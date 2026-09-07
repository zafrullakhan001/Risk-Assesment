<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RiskAssessment\Actor;
use RiskAssessment\AssessmentDate;
use RiskAssessment\Models\Assessment;

final class AssessmentRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param array{user_id?: int, username?: string, display_name?: string, auth_source?: string}|null $owner
     */
    public function save(Assessment $assessment, string $filePath, string $originalFilename, ?array $owner = null): int
    {
        $metadata = $assessment->metadata;
        $ownerUserId = (int) ($owner['user_id'] ?? 0);
        $ownerUsername = trim((string) ($owner['username'] ?? ''));
        $ownerDisplayName = trim((string) ($owner['display_name'] ?? ''));
        $ownerAuthSource = strtolower(trim((string) ($owner['auth_source'] ?? '')));
        if ($ownerAuthSource !== 'ldap') {
            $ownerAuthSource = $ownerUsername !== '' || $ownerDisplayName !== '' ? 'local' : '';
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO assessments (
                    solution_name, vendor, scope, architecture_model, reviewer,
                    assessment_date, file_path, original_filename, workbook_json,
                    owner_user_id, owner_username, owner_display_name, owner_auth_source, uploaded_at
                ) VALUES (
                    :solution_name, :vendor, :scope, :architecture_model, :reviewer,
                    :assessment_date, :file_path, :original_filename, :workbook_json,
                    :owner_user_id, :owner_username, :owner_display_name, :owner_auth_source, datetime(\'now\')
                )'
            );

            $statement->execute([
                ':solution_name' => $metadata['solution_name'] ?? '',
                ':vendor' => $metadata['vendor'] ?? '',
                ':scope' => $metadata['scope'] ?? '',
                ':architecture_model' => $metadata['architecture_model'] ?? '',
                ':reviewer' => $metadata['reviewer'] ?? '',
                ':assessment_date' => AssessmentDate::normalize((string) ($metadata['date'] ?? '')),
                ':file_path' => $filePath,
                ':original_filename' => $originalFilename,
                ':workbook_json' => json_encode($assessment->workbook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                ':owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
                ':owner_username' => $ownerUsername,
                ':owner_display_name' => $ownerDisplayName,
                ':owner_auth_source' => $ownerAuthSource,
            ]);

            $assessmentId = (int) $this->pdo->lastInsertId();

            $itemStatement = $this->pdo->prepare(
                'INSERT INTO assessment_items (
                    assessment_id, item_type, section, check_name, status, risk_level,
                    notes, mitigation, owner, remediation_timeline, review_question,
                    source_reference, origin, sort_order
                ) VALUES (
                    :assessment_id, :item_type, :section, :check_name, :status, :risk_level,
                    :notes, :mitigation, :owner, :remediation_timeline, :review_question,
                    :source_reference, :origin, :sort_order
                )'
            );

            $allItems = array_merge($assessment->items, $assessment->dueDiligenceItems);
            foreach ($allItems as $index => $item) {
                $itemStatement->execute([
                    ':assessment_id' => $assessmentId,
                    ':item_type' => $item['item_type'] ?? 'architecture',
                    ':section' => $item['section'] ?? '',
                    ':check_name' => $item['check'] ?? '',
                    ':status' => $item['status'] ?? '',
                    ':risk_level' => $item['risk_level'] ?? '',
                    ':notes' => $item['notes'] ?? '',
                    ':mitigation' => $item['mitigation'] ?? '',
                    ':owner' => $item['owner'] ?? '',
                    ':remediation_timeline' => $item['remediation_timeline'] ?? '',
                    ':review_question' => $item['review_question'] ?? '',
                    ':source_reference' => $item['source_reference'] ?? '',
                    ':origin' => 'excel',
                    ':sort_order' => (int) ($item['sort_order'] ?? $index),
                ]);
            }

            $this->pdo->commit();

            return $assessmentId;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array{assessment: Assessment, source_filename: string, uploaded_at: string, executive_override: array{verdict: string, summary: string}}|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM assessments WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $allItems = $this->fetchItems($id);
        $architectureItems = [];
        $dueDiligenceItems = [];

        foreach ($allItems as $item) {
            if (($item['item_type'] ?? 'architecture') === 'due_diligence') {
                $dueDiligenceItems[] = $item;
            } else {
                $architectureItems[] = $item;
            }
        }

        $workbook = json_decode((string) ($row['workbook_json'] ?? '{}'), true);
        if (!is_array($workbook)) {
            $workbook = [];
        }

        $metadata = $this->mapMetadata($row);

        return [
            'assessment' => Assessment::fromParsedData($metadata, $architectureItems, $dueDiligenceItems, $workbook),
            'source_filename' => (string) ($row['original_filename'] ?? ''),
            'uploaded_at' => (string) ($row['uploaded_at'] ?? ''),
            'executive_override' => [
                'verdict' => trim((string) ($row['custom_executive_verdict'] ?? '')),
                'summary' => trim((string) ($row['custom_executive_summary'] ?? '')),
            ],
        ];
    }

    /**
     * @return array{verdict: string, summary: string}
     */
    public function findExecutiveOverride(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return ['verdict' => '', 'summary' => ''];
        }

        $statement = $this->pdo->prepare(
            'SELECT custom_executive_verdict, custom_executive_summary
             FROM assessments
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([':id' => $assessmentId]);
        $row = $statement->fetch();
        if ($row === false) {
            return ['verdict' => '', 'summary' => ''];
        }

        return [
            'verdict' => trim((string) ($row['custom_executive_verdict'] ?? '')),
            'summary' => trim((string) ($row['custom_executive_summary'] ?? '')),
        ];
    }

    public function saveExecutiveOverride(int $assessmentId, string $verdict, string $summary): bool
    {
        if ($assessmentId <= 0) {
            return false;
        }

        $verdict = trim($verdict);
        $summary = trim($summary);
        if (mb_strlen($verdict) > 200) {
            $verdict = mb_substr($verdict, 0, 200);
        }
        if (mb_strlen($summary) > 2000) {
            $summary = mb_substr($summary, 0, 2000);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE assessments
             SET custom_executive_verdict = :verdict,
                 custom_executive_summary = :summary
             WHERE id = :id'
        );
        $statement->execute([
            ':verdict' => $verdict,
            ':summary' => $summary,
            ':id' => $assessmentId,
        ]);

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function searchByProjectName(string $query, int $limit = 25): array
    {
        return $this->searchProjects($query, 1, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function listRecent(int $limit = 10): array
    {
        return $this->searchProjects('', 1, $limit);
    }

    /**
     * Search across project/assessment fields with pagination, sorting, and column filters.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function searchProjects(
        string $query,
        int $page = 1,
        int $perPage = 10,
        string $sort = 'uploaded',
        string $dir = 'desc',
        array $filters = []
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->projectSearchWhere($query, $filters);
        $orderSql = $this->projectListOrderBy($sort, $dir);

        $sql = 'SELECT ' . self::projectListColumns() . '
             FROM ' . self::projectListFrom() . '
             ' . $whereSql . '
             ORDER BY ' . $orderSql . '
             LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param array<string, string> $filters
     */
    public function countProjects(string $query = '', array $filters = []): int
    {
        [$whereSql, $params] = $this->projectSearchWhere($query, $filters);

        $sql = 'SELECT COUNT(*) FROM ' . self::projectListFrom() . ' ' . $whereSql;
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function countAll(): int
    {
        return $this->countProjects('');
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function projectSearchWhere(string $query, array $filters = []): array
    {
        $conditions = [];
        $params = [];

        $query = trim($query);
        if ($query !== '') {
            $like = '%' . $query . '%';
            $searchConditions = [
                'a.solution_name LIKE :q',
                'a.vendor LIKE :q',
                'IFNULL(a.scope, \'\') LIKE :q',
                'IFNULL(a.architecture_model, \'\') LIKE :q',
                'a.reviewer LIKE :q',
                'a.original_filename LIKE :q',
                'a.custom_executive_verdict LIKE :q',
                'a.custom_executive_summary LIKE :q',
                'IFNULL(a.assessment_date, \'\') LIKE :q',
                'a.uploaded_at LIKE :q',
                'IFNULL(e.evaluator_name, \'\') LIKE :q',
                'IFNULL(e.evaluator_email, \'\') LIKE :q',
                'IFNULL(e.notes, \'\') LIKE :q',
                'LOWER(COALESCE(json_extract(a.workbook_json, \'$.format\'), \'classic\')) LIKE :q',
                'IFNULL(a.owner_username, \'\') LIKE :q',
                'IFNULL(a.owner_display_name, \'\') LIKE :q',
            ];
            $params[':q'] = $like;

            if (ctype_digit($query)) {
                $searchConditions[] = 'a.id = :exact_id';
                $params[':exact_id'] = (int) $query;
            }

            $normalized = strtolower(preg_replace('/\s+/', ' ', $query) ?? $query);
            if (in_array($normalized, ['ready', 'go-live', 'golive', 'ready to go-live', 'ready to golive'], true)) {
                $searchConditions[] = '(e.ready_to_golive = 1 AND e.updated_at IS NOT NULL AND e.updated_at != \'\')';
            } elseif (in_array($normalized, ['not ready', 'not ready to go-live', 'not ready to golive'], true)) {
                $searchConditions[] = '(e.updated_at IS NOT NULL AND e.updated_at != \'\' AND IFNULL(e.ready_to_golive, 0) = 0)';
            } elseif (in_array($normalized, ['no final', 'no final assessment', 'unevaluated'], true)) {
                $searchConditions[] = '(e.assessment_id IS NULL OR e.updated_at IS NULL OR e.updated_at = \'\')';
            } elseif (in_array($normalized, ['adaptive', 'adaptive template'], true)) {
                $searchConditions[] = "LOWER(COALESCE(json_extract(a.workbook_json, '$.format'), '')) = 'adaptive'";
            } elseif (in_array($normalized, ['classic', 'classic template', 'matured', 'matured template', 'mature', 'table template'], true)) {
                $searchConditions[] = "LOWER(COALESCE(json_extract(a.workbook_json, '$.format'), 'classic')) != 'adaptive'";
            }

            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
        }

        $filterMap = [
            'project' => 'a.solution_name LIKE :f_project',
            'vendor' => 'a.vendor LIKE :f_vendor',
            'id' => "CAST(a.id AS TEXT) LIKE :f_id",
            'template' => "LOWER(COALESCE(json_extract(a.workbook_json, '$.format'), 'classic')) LIKE :f_template",
            'owner' => "(IFNULL(a.owner_display_name, '') LIKE :f_owner OR IFNULL(a.owner_username, '') LIKE :f_owner)",
            'status' => '',
            'assessed' => 'IFNULL(a.assessment_date, \'\') LIKE :f_assessed',
            'uploaded' => 'a.uploaded_at LIKE :f_uploaded',
        ];

        foreach ($filterMap as $key => $sql) {
            $raw = trim((string) ($filters[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $param = ':f_' . $key;
            if ($key === 'status') {
                $statusSql = $this->statusFilterCondition($raw, $param);
                if ($statusSql === null) {
                    continue;
                }
                $conditions[] = $statusSql['sql'];
                if (str_contains($statusSql['sql'], $param)) {
                    $params[$param] = $statusSql['value'];
                }
                continue;
            }
            if ($key === 'template') {
                $templateSql = $this->templateFilterCondition($raw, $param);
                if ($templateSql === null) {
                    continue;
                }
                $conditions[] = $templateSql['sql'];
                if (str_contains($templateSql['sql'], $param)) {
                    $params[$param] = $templateSql['value'];
                }
                continue;
            }
            $conditions[] = $sql;
            $params[$param] = '%' . $raw . '%';
        }

        if ($conditions === []) {
            return ['', []];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return array{sql: string, value: string}|null */
    private function statusFilterCondition(string $raw, string $param): ?array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($raw)) ?? trim($raw));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['not ready', 'not ready to go-live', 'not ready to golive'], true)
            || str_starts_with($normalized, 'not ready')) {
            return [
                'sql' => '(e.updated_at IS NOT NULL AND e.updated_at != \'\' AND IFNULL(e.ready_to_golive, 0) = 0)',
                'value' => $normalized,
            ];
        }
        if (in_array($normalized, ['no final', 'no final assessment', 'unevaluated', 'none'], true)
            || str_starts_with($normalized, 'no final')) {
            return [
                'sql' => '(e.assessment_id IS NULL OR e.updated_at IS NULL OR e.updated_at = \'\')',
                'value' => $normalized,
            ];
        }
        if (in_array($normalized, ['ready', 'go-live', 'golive', 'ready to go-live', 'ready to golive'], true)
            || str_starts_with($normalized, 'ready')) {
            return [
                'sql' => '(e.ready_to_golive = 1 AND e.updated_at IS NOT NULL AND e.updated_at != \'\')',
                'value' => $normalized,
            ];
        }

        return [
            'sql' => "(CASE
                WHEN e.updated_at IS NULL OR e.updated_at = '' THEN 'No final assessment'
                WHEN e.ready_to_golive = 1 THEN 'Ready to go-live'
                ELSE 'Not ready to go-live'
             END) LIKE {$param}",
            'value' => '%' . $raw . '%',
        ];
    }

    /** @return array{sql: string, value: string}|null */
    private function templateFilterCondition(string $raw, string $param): ?array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($raw)) ?? trim($raw));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['adaptive', 'adaptive template'], true) || str_contains($normalized, 'adaptive')) {
            return [
                'sql' => "LOWER(COALESCE(json_extract(a.workbook_json, '$.format'), '')) = 'adaptive'",
                'value' => $normalized,
            ];
        }
        if (in_array($normalized, ['classic', 'classic template', 'matured', 'matured template', 'mature', 'table'], true)
            || str_contains($normalized, 'matured')
            || str_contains($normalized, 'classic')) {
            return [
                'sql' => "LOWER(COALESCE(json_extract(a.workbook_json, '$.format'), 'classic')) != 'adaptive'",
                'value' => $normalized,
            ];
        }

        return [
            'sql' => "LOWER(COALESCE(json_extract(a.workbook_json, '$.format'), 'classic')) LIKE {$param}",
            'value' => '%' . $normalized . '%',
        ];
    }

    private function projectListOrderBy(string $sort, string $dir): string
    {
        $dirSql = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $sort = strtolower(trim($sort));

        $columns = [
            'project' => 'LOWER(a.solution_name)',
            'vendor' => 'LOWER(a.vendor)',
            'id' => 'a.id',
            'template' => "LOWER(COALESCE(json_extract(a.workbook_json, '$.format'), 'classic'))",
            'owner' => "LOWER(COALESCE(NULLIF(a.owner_display_name, ''), NULLIF(a.owner_username, ''), ''))",
            'status' => "CASE
                WHEN e.updated_at IS NULL OR e.updated_at = '' THEN 0
                WHEN e.ready_to_golive = 1 THEN 2
                ELSE 1
             END",
            'assessed' => "IFNULL(a.assessment_date, '')",
            'uploaded' => 'a.uploaded_at',
        ];

        $orderExpr = $columns[$sort] ?? 'a.uploaded_at';
        $tieBreak = $sort === 'id' ? '' : ', a.id DESC';

        return $orderExpr . ' ' . $dirSql . $tieBreak;
    }

    /** @return array{assessment: Assessment, source_filename: string, uploaded_at: string, id: int}|null */
    public function findPreviousVersion(string $solutionName, int $currentId): ?array
    {
        $solutionName = trim($solutionName);
        if ($solutionName === '' || $currentId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id FROM assessments
             WHERE solution_name = :solution_name AND id < :current_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            ':solution_name' => $solutionName,
            ':current_id' => $currentId,
        ]);
        $priorId = (int) ($statement->fetchColumn() ?: 0);
        if ($priorId <= 0) {
            return null;
        }

        $record = $this->findById($priorId);
        if ($record === null) {
            return null;
        }

        $record['id'] = $priorId;

        return $record;
    }

    /** @return list<array<string, mixed>> */
    public function listVersionsBySolutionName(string $solutionName, int $limit = 25): array
    {
        $solutionName = trim($solutionName);
        if ($solutionName === '') {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::projectListColumns() . '
             FROM ' . self::projectListFrom() . '
             WHERE a.solution_name = :solution_name
             ORDER BY a.uploaded_at DESC, a.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':solution_name', $solutionName, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Latest saved version for a solution name (any owner), or null when none exists.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestBySolutionName(string $solutionName): ?array
    {
        $versions = $this->listVersionsBySolutionName($solutionName, 1);

        return $versions[0] ?? null;
    }

    public function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare('SELECT file_path FROM assessments WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $deleteItems = $this->pdo->prepare('DELETE FROM assessment_items WHERE assessment_id = :id');
            $deleteItems->execute([':id' => $id]);

            $deleteResponses = $this->pdo->prepare('DELETE FROM item_responses WHERE assessment_id = :id');
            $deleteResponses->execute([':id' => $id]);

            $deleteEvaluation = $this->pdo->prepare('DELETE FROM final_evaluations WHERE assessment_id = :id');
            $deleteEvaluation->execute([':id' => $id]);

            $deleteLinks = $this->pdo->prepare('DELETE FROM project_links WHERE assessment_id = :id');
            $deleteLinks->execute([':id' => $id]);

            $deleteMermaid = $this->pdo->prepare('DELETE FROM project_mermaid_diagrams WHERE assessment_id = :id');
            $deleteMermaid->execute([':id' => $id]);

            $deletePictures = $this->pdo->prepare('DELETE FROM project_pictures WHERE assessment_id = :id');
            $deletePictures->execute([':id' => $id]);

            $deleteFindingStatuses = $this->pdo->prepare('DELETE FROM finding_statuses WHERE assessment_id = :id');
            $deleteFindingStatuses->execute([':id' => $id]);

            $deleteChangeLog = $this->pdo->prepare('DELETE FROM assessment_change_log WHERE assessment_id = :id');
            $deleteChangeLog->execute([':id' => $id]);

            $deleteAssessment = $this->pdo->prepare('DELETE FROM assessments WHERE id = :id');
            $deleteAssessment->execute([':id' => $id]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $filePath = (string) ($row['file_path'] ?? '');
        if ($filePath !== '' && is_file($filePath)) {
            @unlink($filePath);
        }

        return true;
    }

    /**
     * Delete every saved version for a project except the one to keep.
     *
     * @return int Number of versions removed
     */
    public function deleteOlderVersions(string $solutionName, int $keepId): int
    {
        $solutionName = trim($solutionName);
        if ($solutionName === '' || $keepId <= 0) {
            return 0;
        }

        $keep = $this->findById($keepId);
        if ($keep === null) {
            return 0;
        }

        $keepName = trim((string) $keep['assessment']->getMetadata('solution_name'));
        if ($keepName === '' || strcasecmp($keepName, $solutionName) !== 0) {
            return 0;
        }

        $versions = $this->listVersionsBySolutionName($solutionName, 500);
        $removed = 0;
        foreach ($versions as $version) {
            $versionId = (int) ($version['id'] ?? 0);
            if ($versionId <= 0 || $versionId === $keepId) {
                continue;
            }
            if ($this->deleteById($versionId)) {
                $removed++;
            }
        }

        return $removed;
    }

    private static function projectListColumns(): string
    {
        return 'a.id, a.solution_name, a.vendor, a.assessment_date, a.uploaded_at, a.original_filename,
                LOWER(COALESCE(json_extract(a.workbook_json, \'$.format\'), \'classic\')) AS workbook_format,
                a.owner_user_id, a.owner_username, a.owner_display_name, a.owner_auth_source, a.is_locked,
                e.ready_to_golive, e.evaluator_name, e.updated_at AS evaluation_updated_at';
    }

    private static function projectListFrom(): string
    {
        return 'assessments a LEFT JOIN final_evaluations e ON e.assessment_id = a.id';
    }

    /**
     * Compact go-live status for project list cards.
     *
     * @param array<string, mixed> $project
     * @return array{key: string, label: string, title: string}
     */
    public static function goliveCardStatus(array $project): array
    {
        $evaluatedAt = trim((string) ($project['evaluation_updated_at'] ?? ''));
        $evaluator = trim((string) ($project['evaluator_name'] ?? ''));
        $ready = ((int) ($project['ready_to_golive'] ?? 0)) === 1 && $evaluatedAt !== '';

        if ($evaluatedAt === '') {
            return [
                'key' => 'none',
                'label' => 'No final assessment',
                'title' => 'Final go-live assessment has not been saved yet.',
            ];
        }

        if ($ready) {
            return [
                'key' => 'ready',
                'label' => 'Ready to go-live',
                'title' => $evaluator !== ''
                    ? 'Final assessment: ready to go-live · ' . $evaluator
                    : 'Final assessment: ready to go-live',
            ];
        }

        return [
            'key' => 'not-ready',
            'label' => 'Not ready to go-live',
            'title' => $evaluator !== ''
                ? 'Final assessment: not ready to go-live · ' . $evaluator
                : 'Final assessment: not ready to go-live',
        ];
    }

    /**
     * Compact workbook template label for project list cards, strips, and table rows.
     *
     * @param array<string, mixed> $project
     * @return array{key: string, label: string, title: string}
     */
    public static function templateCardStatus(array $project): array
    {
        $format = strtolower(trim((string) ($project['workbook_format'] ?? '')));
        if ($format === 'adaptive') {
            return [
                'key' => 'adaptive',
                'label' => 'Adaptive template',
                'title' => 'Adaptive Architecture template (classify → route → material findings).',
            ];
        }

        if ($format === '' || $format === 'classic' || $format === 'table') {
            return [
                'key' => 'classic',
                'label' => 'Matured template',
                'title' => 'Matured table-based Risk Register template.',
            ];
        }

        $safeKey = preg_replace('/[^a-z0-9]+/', '-', $format) ?: 'other';

        return [
            'key' => $safeKey,
            'label' => ucfirst($format) . ' template',
            'title' => ucfirst($format) . ' workbook template.',
        ];
    }

    /**
     * Project owner is the user who first uploaded the workbook.
     *
     * @param array<string, mixed> $project
     * @return array{key: string, label: string, title: string}
     */
    public static function ownerCardStatus(array $project): array
    {
        $label = Actor::labelFromRow($project, 'owner');
        if ($label === '') {
            return [
                'key' => 'none',
                'label' => 'Unknown owner',
                'title' => 'Owner was not recorded when this workbook was uploaded.',
            ];
        }

        $short = trim((string) ($project['owner_display_name'] ?? ''));
        if ($short === '') {
            $short = trim((string) ($project['owner_username'] ?? ''));
        }
        if ($short === '') {
            $short = $label;
        }

        return [
            'key' => 'known',
            'label' => $short,
            'title' => 'Project owner (initial uploader): ' . $label,
        ];
    }

    /** @return list<array<string, string>> */
    private function fetchItems(int $assessmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, item_type, section, check_name, status, risk_level, notes, mitigation, owner,
                    remediation_timeline, review_question, source_reference, origin, sort_order
             FROM assessment_items
             WHERE assessment_id = :assessment_id
             ORDER BY item_type ASC, sort_order ASC, id ASC'
        );
        $statement->execute([':assessment_id' => $assessmentId]);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = $this->mapItemRow($row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public function addManualItem(int $assessmentId, array $fields): array
    {
        if ($assessmentId <= 0) {
            throw new \InvalidArgumentException('Invalid assessment.');
        }

        $itemType = trim((string) ($fields['item_type'] ?? 'architecture'));
        if (!in_array($itemType, ['architecture', 'due_diligence'], true)) {
            throw new \InvalidArgumentException('Invalid item type.');
        }

        $section = trim((string) ($fields['section'] ?? ''));
        $check = trim((string) ($fields['check'] ?? ''));
        if ($section === '' || $check === '') {
            throw new \InvalidArgumentException('Section and check are required.');
        }

        $status = $this->normalizeStatus((string) ($fields['status'] ?? ''));
        $riskLevel = $this->normalizeRiskLevel((string) ($fields['risk_level'] ?? ''));

        $sortStatement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort
             FROM assessment_items
             WHERE assessment_id = :assessment_id AND item_type = :item_type'
        );
        $sortStatement->execute([
            ':assessment_id' => $assessmentId,
            ':item_type' => $itemType,
        ]);
        $nextSort = (int) ($sortStatement->fetchColumn() ?: 0);

        $statement = $this->pdo->prepare(
            'INSERT INTO assessment_items (
                assessment_id, item_type, section, check_name, status, risk_level,
                notes, mitigation, owner, remediation_timeline, review_question,
                source_reference, origin, sort_order
            ) VALUES (
                :assessment_id, :item_type, :section, :check_name, :status, :risk_level,
                :notes, :mitigation, :owner, :remediation_timeline, :review_question,
                :source_reference, :origin, :sort_order
            )'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':item_type' => $itemType,
            ':section' => $section,
            ':check_name' => $check,
            ':status' => $status,
            ':risk_level' => $riskLevel,
            ':notes' => trim((string) ($fields['notes'] ?? '')),
            ':mitigation' => trim((string) ($fields['mitigation'] ?? '')),
            ':owner' => trim((string) ($fields['owner'] ?? '')),
            ':remediation_timeline' => trim((string) ($fields['remediation_timeline'] ?? '')),
            ':review_question' => $itemType === 'due_diligence' ? trim((string) ($fields['review_question'] ?? '')) : '',
            ':source_reference' => $itemType === 'due_diligence' ? trim((string) ($fields['source_reference'] ?? '')) : '',
            ':origin' => 'manual',
            ':sort_order' => $nextSort,
        ]);

        $itemId = (int) $this->pdo->lastInsertId();
        $item = $this->findItemById($assessmentId, $itemId);
        if ($item === null) {
            throw new \RuntimeException('Unable to load the new row.');
        }

        return $item;
    }

    /** @return array<string, string>|null */
    public function findItemById(int $assessmentId, int $itemId): ?array
    {
        if ($assessmentId <= 0 || $itemId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, item_type, section, check_name, status, risk_level, notes, mitigation, owner,
                    remediation_timeline, review_question, source_reference, origin, sort_order
             FROM assessment_items
             WHERE assessment_id = :assessment_id AND id = :id
             LIMIT 1'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':id' => $itemId,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return $this->mapItemRow($row);
    }

    /** @return array<string, string>|null Deleted item, or null if not found */
    public function deleteItem(int $assessmentId, int $itemId): ?array
    {
        $item = $this->findItemById($assessmentId, $itemId);
        if ($item === null) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM assessment_items
             WHERE assessment_id = :assessment_id AND id = :id'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':id' => $itemId,
        ]);

        return $item;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public function addFinding(int $assessmentId, array $fields): array
    {
        if ($assessmentId <= 0) {
            throw new \InvalidArgumentException('Invalid assessment.');
        }

        $finding = trim((string) ($fields['finding'] ?? ''));
        if ($finding === '') {
            throw new \InvalidArgumentException('Finding text is required.');
        }
        if (mb_strlen($finding) > 4000) {
            $finding = mb_substr($finding, 0, 4000);
        }

        $workbook = $this->loadWorkbook($assessmentId);
        $findings = array_values($workbook['findings'] ?? []);
        $findingId = 'manual-' . bin2hex(random_bytes(8));

        $row = [
            'id' => $findingId,
            'finding' => $finding,
            'policy_reference' => $this->clipField((string) ($fields['policy_reference'] ?? ''), 500),
            'impact' => $this->clipField((string) ($fields['impact'] ?? ''), 2000),
            'mitigation' => $this->clipField((string) ($fields['mitigation'] ?? ''), 2000),
            'owner' => $this->clipField((string) ($fields['owner'] ?? ''), 200),
            'timeline' => $this->clipField((string) ($fields['timeline'] ?? ''), 200),
            'origin' => 'manual',
        ];
        $findings[] = $row;
        $workbook['findings'] = $findings;
        $this->saveWorkbook($assessmentId, $workbook);

        return $row;
    }

    /** @return array<string, mixed>|null Deleted finding, or null if not found */
    public function deleteFinding(int $assessmentId, string $findingId): ?array
    {
        if ($assessmentId <= 0 || trim($findingId) === '') {
            return null;
        }

        $findingId = trim($findingId);
        $workbook = $this->loadWorkbook($assessmentId);
        $findings = array_values($workbook['findings'] ?? []);
        $deleted = null;
        $remaining = [];

        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $id = trim((string) ($finding['id'] ?? ('finding-' . $index)));
            if ($deleted === null && $id === $findingId) {
                $deleted = $finding;
                $deleted['id'] = $id;
                continue;
            }
            $remaining[] = $finding;
        }

        if ($deleted === null) {
            return null;
        }

        $workbook['findings'] = $remaining;
        $this->saveWorkbook($assessmentId, $workbook);

        return $deleted;
    }

    /** @return array<string, mixed> */
    private function loadWorkbook(int $assessmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT workbook_json FROM assessments WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $assessmentId]);
        $raw = $statement->fetchColumn();
        if ($raw === false) {
            throw new \InvalidArgumentException('Assessment not found.');
        }

        $workbook = json_decode((string) $raw, true);
        if (!is_array($workbook)) {
            $workbook = [];
        }
        if (!isset($workbook['findings']) || !is_array($workbook['findings'])) {
            $workbook['findings'] = [];
        }

        return $workbook;
    }

    /** @param array<string, mixed> $workbook */
    private function saveWorkbook(int $assessmentId, array $workbook): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE assessments
             SET workbook_json = :workbook_json
             WHERE id = :id'
        );
        $statement->execute([
            ':workbook_json' => json_encode($workbook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ':id' => $assessmentId,
        ]);
    }

    private function clipField(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            return mb_substr($value, 0, $max);
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    /** @return array<string, string> */
    private function mapItemRow(array $row): array
    {
        $origin = strtolower(trim((string) ($row['origin'] ?? 'excel')));
        if ($origin !== 'manual') {
            $origin = 'excel';
        }

        return [
            'id' => (string) ((int) ($row['id'] ?? 0)),
            'item_type' => (string) ($row['item_type'] ?? 'architecture'),
            'section' => (string) ($row['section'] ?? ''),
            'check' => (string) ($row['check_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'risk_level' => (string) ($row['risk_level'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'mitigation' => (string) ($row['mitigation'] ?? ''),
            'owner' => (string) ($row['owner'] ?? ''),
            'remediation_timeline' => (string) ($row['remediation_timeline'] ?? ''),
            'review_question' => (string) ($row['review_question'] ?? ''),
            'source_reference' => (string) ($row['source_reference'] ?? ''),
            'origin' => $origin,
            'sort_order' => (string) ($row['sort_order'] ?? '0'),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $status = trim($status);
        $allowed = ['Pass', 'Gap', 'Risk', 'TBD', 'N/A'];
        foreach ($allowed as $option) {
            if (strcasecmp($status, $option) === 0) {
                return $option;
            }
        }

        return $status === '' ? 'TBD' : $status;
    }

    private function normalizeRiskLevel(string $riskLevel): string
    {
        $riskLevel = trim($riskLevel);
        $allowed = ['High', 'Med', 'Low'];
        foreach ($allowed as $option) {
            if (strcasecmp($riskLevel, $option) === 0) {
                return $option;
            }
        }
        if (strcasecmp($riskLevel, 'medium') === 0) {
            return 'Med';
        }

        return $riskLevel;
    }

    /** @param array<string, mixed> $row */
    /** @return array<string, string> */
    private function mapMetadata(array $row): array
    {
        $metadata = [
            'solution_name' => (string) ($row['solution_name'] ?? ''),
            'vendor' => (string) ($row['vendor'] ?? ''),
            'scope' => (string) ($row['scope'] ?? ''),
            'architecture_model' => (string) ($row['architecture_model'] ?? ''),
            'reviewer' => (string) ($row['reviewer'] ?? ''),
            'date' => (string) ($row['assessment_date'] ?? ''),
        ];

        $workbook = json_decode((string) ($row['workbook_json'] ?? '{}'), true);
        if (is_array($workbook)) {
            foreach (($workbook['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $label = strtolower(preg_replace('/[^a-z0-9]+/', '', (string) ($field['label'] ?? '')) ?? '');
                $value = trim((string) ($field['value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $map = [
                    'duediligencerequest' => 'ddr_id',
                    'duediligenceid' => 'ddr_id',
                    'technologyriskassessment' => 'vra_id',
                    'technologyriskid' => 'vra_id',
                    'businessunit' => 'business_unit',
                    'assessmenttypetier' => 'assessment_tier',
                    'overallriskrating' => 'overall_risk_rating',
                    'tprmrecommendation' => 'tprm_recommendation',
                    'technologyrecommendation' => 'technology_recommendation',
                    'overallrecommendation' => 'technology_recommendation',
                    'facilityregion' => 'facility_region',
                    'facilitiesregion' => 'facility_region',
                    'datahosting' => 'data_hosting',
                    'hostingdeployment' => 'data_hosting',
                    'vendoraccessai' => 'vendor_access_ai',
                    'requiredgovernanceaction' => 'governance_action',
                    'decisiongate' => 'decision_gate',
                    'primaryarchitecturetype' => 'architecture_model',
                    'dataclassification' => 'data_classification',
                    'clinicalbusinesscriticality' => 'clinical_criticality',
                    'classificationconfidence' => 'classification_confidence',
                    'secondarytypes' => 'secondary_types',
                    'targetgolive' => 'target_go_live',
                ];

                if (isset($map[$label])) {
                    $metadata[$map[$label]] = $value;
                }
            }
        }

        return $metadata;
    }
}
