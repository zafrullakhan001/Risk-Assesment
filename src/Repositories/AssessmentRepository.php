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
            'SELECT id, solution_name, vendor, assessment_date, uploaded_at, original_filename
             FROM assessments
             WHERE solution_name LIKE :query
                OR vendor LIKE :query
                OR scope LIKE :query
             ORDER BY uploaded_at DESC
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
            'SELECT id, solution_name, vendor, assessment_date, uploaded_at, original_filename
             FROM assessments
             ORDER BY uploaded_at DESC
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
