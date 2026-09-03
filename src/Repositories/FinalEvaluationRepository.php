<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

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
     *   updated_at: string
     * }|null
     */
    public function findByAssessmentId(int $assessmentId): ?array
    {
        if ($assessmentId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT evaluator_name, evaluator_email, notes, ready_to_golive, updated_at
             FROM final_evaluations
             WHERE assessment_id = :assessment_id
             LIMIT 1'
        );
        $statement->execute([':assessment_id' => $assessmentId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'evaluator_name' => (string) ($row['evaluator_name'] ?? ''),
            'evaluator_email' => (string) ($row['evaluator_email'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'ready_to_golive' => ((int) ($row['ready_to_golive'] ?? 0)) === 1,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public function upsert(
        int $assessmentId,
        string $evaluatorName,
        string $evaluatorEmail,
        string $notes,
        bool $readyToGolive
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

        $statement = $this->pdo->prepare(
            'INSERT INTO final_evaluations (
                assessment_id, evaluator_name, evaluator_email, notes, ready_to_golive, updated_at
             ) VALUES (
                :assessment_id, :evaluator_name, :evaluator_email, :notes, :ready_to_golive, datetime(\'now\')
             )
             ON CONFLICT(assessment_id) DO UPDATE SET
                evaluator_name = excluded.evaluator_name,
                evaluator_email = excluded.evaluator_email,
                notes = excluded.notes,
                ready_to_golive = excluded.ready_to_golive,
                updated_at = datetime(\'now\')'
        );

        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':evaluator_name' => $evaluatorName,
            ':evaluator_email' => $evaluatorEmail,
            ':notes' => $notes,
            ':ready_to_golive' => $readyToGolive ? 1 : 0,
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
}
