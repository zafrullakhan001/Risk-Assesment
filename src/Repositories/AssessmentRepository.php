<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RiskAssessment\Models\Assessment;

final class AssessmentRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function save(Assessment $assessment, string $filePath, string $originalFilename): int
    {
        $metadata = $assessment->metadata;

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO assessments (
                    solution_name, vendor, scope, architecture_model, reviewer,
                    assessment_date, file_path, original_filename, workbook_json, uploaded_at
                ) VALUES (
                    :solution_name, :vendor, :scope, :architecture_model, :reviewer,
                    :assessment_date, :file_path, :original_filename, :workbook_json, datetime(\'now\')
                )'
            );

            $statement->execute([
                ':solution_name' => $metadata['solution_name'] ?? '',
                ':vendor' => $metadata['vendor'] ?? '',
                ':scope' => $metadata['scope'] ?? '',
                ':architecture_model' => $metadata['architecture_model'] ?? '',
                ':reviewer' => $metadata['reviewer'] ?? '',
                ':assessment_date' => $metadata['date'] ?? '',
                ':file_path' => $filePath,
                ':original_filename' => $originalFilename,
                ':workbook_json' => json_encode($assessment->workbook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ]);

            $assessmentId = (int) $this->pdo->lastInsertId();

            $itemStatement = $this->pdo->prepare(
                'INSERT INTO assessment_items (
                    assessment_id, item_type, section, check_name, status, risk_level,
                    notes, mitigation, owner, remediation_timeline, review_question,
                    source_reference, sort_order
                ) VALUES (
                    :assessment_id, :item_type, :section, :check_name, :status, :risk_level,
                    :notes, :mitigation, :owner, :remediation_timeline, :review_question,
                    :source_reference, :sort_order
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

    /** @return array{assessment: Assessment, source_filename: string, uploaded_at: string}|null */
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
        ];
    }

    /** @return list<array<string, mixed>> */
    public function searchByProjectName(string $query, int $limit = 25): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->listRecent($limit);
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::projectListColumns() . '
             FROM ' . self::projectListFrom() . '
             WHERE a.solution_name LIKE :query
                OR a.vendor LIKE :query
                OR a.scope LIKE :query
             ORDER BY a.uploaded_at DESC
             LIMIT :limit'
        );

        $statement->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listRecent(int $limit = 10): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::projectListColumns() . '
             FROM ' . self::projectListFrom() . '
             ORDER BY a.uploaded_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM assessments')->fetchColumn();
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

            $deleteFindingStatuses = $this->pdo->prepare('DELETE FROM finding_statuses WHERE assessment_id = :id');
            $deleteFindingStatuses->execute([':id' => $id]);

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

    /** @return list<array<string, string>> */
    private function fetchItems(int $assessmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT item_type, section, check_name, status, risk_level, notes, mitigation, owner,
                    remediation_timeline, review_question, source_reference, sort_order
             FROM assessment_items
             WHERE assessment_id = :assessment_id
             ORDER BY item_type ASC, sort_order ASC, id ASC'
        );
        $statement->execute([':assessment_id' => $assessmentId]);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $items[] = [
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
                'sort_order' => (string) ($row['sort_order'] ?? '0'),
            ];
        }

        return $items;
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
                    'technologyriskassessment' => 'vra_id',
                    'businessunit' => 'business_unit',
                    'assessmenttypetier' => 'assessment_tier',
                    'overallriskrating' => 'overall_risk_rating',
                    'tprmrecommendation' => 'tprm_recommendation',
                    'technologyrecommendation' => 'technology_recommendation',
                    'facilityregion' => 'facility_region',
                    'datahosting' => 'data_hosting',
                    'vendoraccessai' => 'vendor_access_ai',
                    'requiredgovernanceaction' => 'governance_action',
                ];

                if (isset($map[$label])) {
                    $metadata[$map[$label]] = $value;
                }
            }
        }

        return $metadata;
    }
}
