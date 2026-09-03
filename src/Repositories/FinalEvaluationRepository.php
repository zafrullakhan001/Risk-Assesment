<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RiskAssessment\Actor;

final class FinalEvaluationRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array{
     *   evaluator_name: string,
     *   evaluator_email: string,
     *   notes: string,
     *   ready_to_golive: bool,
     *   updated_at: string,
     *   updated_by_user_id: int|null,
     *   updated_by_username: string,
     *   updated_by_display_name: string,
     *   updated_by_auth_source: string,
     *   updated_by_label: string
     * }|null
     */
    public function findByAssessmentId(int $assessmentId): ?array
    {
        if ($assessmentId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT evaluator_name, evaluator_email, notes, ready_to_golive, updated_at,
                    updated_by_user_id, updated_by_username, updated_by_display_name, updated_by_auth_source
             FROM final_evaluations
             WHERE assessment_id = :assessment_id
             LIMIT 1'
        );
        $statement->execute([':assessment_id' => $assessmentId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @param array{
     *   user_id?: int,
     *   username?: string,
     *   display_name?: string,
     *   auth_source?: string
     * }|null $actor
     */
    public function upsert(
        int $assessmentId,
        string $evaluatorName,
        string $evaluatorEmail,
        string $notes,
        bool $readyToGolive,
        ?array $actor = null
    ): bool {
        if ($assessmentId <= 0) {
            return false;
        }

        $evaluatorName = trim($evaluatorName);
        $evaluatorEmail = trim($evaluatorEmail);
        $notes = trim($notes);

        if ($evaluatorName === '') {
            throw new \InvalidArgumentException('Evaluator name is required.');
        }
        if (mb_strlen($evaluatorName) > 200) {
            $evaluatorName = mb_substr($evaluatorName, 0, 200);
        }
        if ($evaluatorEmail === '' || !filter_var($evaluatorEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid evaluator email is required.');
        }
        if (mb_strlen($evaluatorEmail) > 254) {
            throw new \InvalidArgumentException('Evaluator email is too long.');
        }
        if (mb_strlen($notes) > 8000) {
            $notes = mb_substr($notes, 0, 8000);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }

        $actorId = isset($actor['user_id']) ? (int) $actor['user_id'] : null;
        if ($actorId !== null && $actorId <= 0) {
            $actorId = null;
        }
        $actorUsername = (string) ($actor['username'] ?? '');
        $actorDisplayName = (string) ($actor['display_name'] ?? '');
        $actorAuthSource = (string) ($actor['auth_source'] ?? '');

        $statement = $this->pdo->prepare(
            'INSERT INTO final_evaluations (
                assessment_id, evaluator_name, evaluator_email, notes, ready_to_golive, updated_at,
                updated_by_user_id, updated_by_username, updated_by_display_name, updated_by_auth_source
             ) VALUES (
                :assessment_id, :evaluator_name, :evaluator_email, :notes, :ready_to_golive, datetime(\'now\'),
                :updated_by_user_id, :updated_by_username, :updated_by_display_name, :updated_by_auth_source
             )
             ON CONFLICT(assessment_id) DO UPDATE SET
                evaluator_name = excluded.evaluator_name,
                evaluator_email = excluded.evaluator_email,
                notes = excluded.notes,
                ready_to_golive = excluded.ready_to_golive,
                updated_at = datetime(\'now\'),
                updated_by_user_id = excluded.updated_by_user_id,
                updated_by_username = excluded.updated_by_username,
                updated_by_display_name = excluded.updated_by_display_name,
                updated_by_auth_source = excluded.updated_by_auth_source'
        );

        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':evaluator_name' => $evaluatorName,
            ':evaluator_email' => $evaluatorEmail,
            ':notes' => $notes,
            ':ready_to_golive' => $readyToGolive ? 1 : 0,
            ':updated_by_user_id' => $actorId,
            ':updated_by_username' => $actorUsername,
            ':updated_by_display_name' => $actorDisplayName,
            ':updated_by_auth_source' => $actorAuthSource,
        ]);

        return true;
    }

    public function deleteForAssessment(int $assessmentId): void
    {
        if ($assessmentId <= 0) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM final_evaluations WHERE assessment_id = :id');
        $statement->execute([':id' => $assessmentId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   evaluator_name: string,
     *   evaluator_email: string,
     *   notes: string,
     *   ready_to_golive: bool,
     *   updated_at: string,
     *   updated_by_user_id: int|null,
     *   updated_by_username: string,
     *   updated_by_display_name: string,
     *   updated_by_auth_source: string,
     *   updated_by_label: string
     * }
     */
    private function mapRow(array $row): array
    {
        $userId = $row['updated_by_user_id'] ?? null;

        return [
            'evaluator_name' => (string) ($row['evaluator_name'] ?? ''),
            'evaluator_email' => (string) ($row['evaluator_email'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'ready_to_golive' => ((int) ($row['ready_to_golive'] ?? 0)) === 1,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'updated_by_user_id' => $userId !== null && $userId !== '' ? (int) $userId : null,
            'updated_by_username' => (string) ($row['updated_by_username'] ?? ''),
            'updated_by_display_name' => (string) ($row['updated_by_display_name'] ?? ''),
            'updated_by_auth_source' => (string) ($row['updated_by_auth_source'] ?? ''),
            'updated_by_label' => Actor::labelFromRow($row, 'updated_by'),
        ];
    }
}
