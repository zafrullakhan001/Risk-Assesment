<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class FindingStatusRepository
{
    /** @var list<string> */
    public const STATUSES = ['Open', 'Approved', 'Closed', 'Expired'];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @return array<string, string> finding_id => status */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT finding_id, status
             FROM finding_statuses
             WHERE assessment_id = :assessment_id'
        );
        $statement->execute([':assessment_id' => $assessmentId]);
        $rows = $statement->fetchAll();

        $statuses = [];
        foreach ($rows as $row) {
            $findingId = trim((string) ($row['finding_id'] ?? ''));
            if ($findingId === '') {
                continue;
            }
            $statuses[$findingId] = self::normalizeStatus((string) ($row['status'] ?? 'Open'));
        }

        return $statuses;
    }

    public function upsert(int $assessmentId, string $findingId, string $status): bool
    {
        if ($assessmentId <= 0 || trim($findingId) === '') {
            return false;
        }

        $status = self::normalizeStatus($status);
        $findingId = trim($findingId);
        if (mb_strlen($findingId) > 200) {
            $findingId = mb_substr($findingId, 0, 200);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO finding_statuses (assessment_id, finding_id, status, updated_at)
             VALUES (:assessment_id, :finding_id, :status, datetime(\'now\'))
             ON CONFLICT(assessment_id, finding_id) DO UPDATE SET
                status = excluded.status,
                updated_at = datetime(\'now\')'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':finding_id' => $findingId,
            ':status' => $status,
        ]);

        return true;
    }

    public function deleteForAssessment(int $assessmentId): void
    {
        if ($assessmentId <= 0) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM finding_statuses WHERE assessment_id = :id');
        $statement->execute([':id' => $assessmentId]);
    }

    public function copyMissingFromAssessment(int $sourceId, int $targetId): int
    {
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            return 0;
        }

        $existing = $this->listForAssessment($targetId);
        $copied = 0;
        foreach ($this->listForAssessment($sourceId) as $findingId => $status) {
            if (isset($existing[$findingId])) {
                continue;
            }
            if ($this->upsert($targetId, $findingId, $status)) {
                $copied++;
            }
        }

        return $copied;
    }

    public static function normalizeStatus(string $status): string
    {
        $status = trim($status);
        foreach (self::STATUSES as $allowed) {
            if (strcasecmp($status, $allowed) === 0) {
                return $allowed;
            }
        }
        if (strcasecmp($status, 'close') === 0) {
            return 'Closed';
        }

        return 'Open';
    }
}
