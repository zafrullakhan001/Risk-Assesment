<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RiskAssessment\Actor;
use RuntimeException;

final class AssessmentAccessRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function isEditor(int $assessmentId, int $userId): bool
    {
        if ($assessmentId <= 0 || $userId <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM assessment_editors
             WHERE assessment_id = :assessment_id AND user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<array{
     *   user_id: int,
     *   username: string,
     *   display_name: string,
     *   email: string,
     *   label: string,
     *   created_at: string
     * }>
     */
    public function listEditors(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT e.user_id, e.created_at,
                    u.username, u.display_name, u.email, u.auth_source
             FROM assessment_editors e
             INNER JOIN users u ON u.id = e.user_id
             WHERE e.assessment_id = :assessment_id
             ORDER BY LOWER(COALESCE(NULLIF(u.display_name, \'\'), u.username)) COLLATE NOCASE ASC'
        );
        $statement->execute([':assessment_id' => $assessmentId]);

        $editors = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $username = trim((string) ($row['username'] ?? ''));
            $displayName = trim((string) ($row['display_name'] ?? ''));
            $authSource = strtolower(trim((string) ($row['auth_source'] ?? 'local')));
            $editors[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'username' => $username,
                'display_name' => $displayName,
                'email' => trim((string) ($row['email'] ?? '')),
                'label' => Actor::formatLabel($displayName, $username, $authSource),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $editors;
    }

    public function grantEditor(int $assessmentId, int $userId, int $grantedByUserId): void
    {
        if ($assessmentId <= 0 || $userId <= 0) {
            throw new RuntimeException('Invalid editor grant.');
        }

        $meta = $this->loadAccessMeta($assessmentId);
        if ($meta === null) {
            throw new RuntimeException('Assessment not found.');
        }

        $ownerId = (int) ($meta['owner_user_id'] ?? 0);
        if ($ownerId > 0 && $ownerId === $userId) {
            throw new RuntimeException('The project owner already has full access.');
        }

        $user = $this->pdo->prepare(
            'SELECT id FROM users
             WHERE id = :id AND is_approved = 1 AND is_disabled = 0
             LIMIT 1'
        );
        $user->execute([':id' => $userId]);
        if ($user->fetchColumn() === false) {
            throw new RuntimeException('That user is not available to grant edit access.');
        }

        $familyIds = $this->familyIdsBySolutionName((string) $meta['solution_name'], $assessmentId);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT OR IGNORE INTO assessment_editors (
                    assessment_id, user_id, granted_by_user_id, created_at
                 ) VALUES (
                    :assessment_id, :user_id, :granted_by_user_id, datetime(\'now\')
                 )'
            );
            foreach ($familyIds as $id) {
                $insert->execute([
                    ':assessment_id' => $id,
                    ':user_id' => $userId,
                    ':granted_by_user_id' => $grantedByUserId > 0 ? $grantedByUserId : null,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function revokeEditor(int $assessmentId, int $userId): void
    {
        if ($assessmentId <= 0 || $userId <= 0) {
            throw new RuntimeException('Invalid editor revoke.');
        }

        $meta = $this->loadAccessMeta($assessmentId);
        if ($meta === null) {
            throw new RuntimeException('Assessment not found.');
        }

        $familyIds = $this->familyIdsBySolutionName((string) $meta['solution_name'], $assessmentId);
        $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
        $statement = $this->pdo->prepare(
            'DELETE FROM assessment_editors
             WHERE user_id = ? AND assessment_id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$userId], $familyIds));
    }

    public function setLocked(int $assessmentId, bool $locked): void
    {
        if ($assessmentId <= 0) {
            throw new RuntimeException('Assessment not found.');
        }

        $meta = $this->loadAccessMeta($assessmentId);
        if ($meta === null) {
            throw new RuntimeException('Assessment not found.');
        }

        $familyIds = $this->familyIdsBySolutionName((string) $meta['solution_name'], $assessmentId);
        $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
        $statement = $this->pdo->prepare(
            'UPDATE assessments SET is_locked = ? WHERE id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$locked ? 1 : 0], $familyIds));
    }

    /**
     * Copy lock flag and editors from one assessment onto another (e.g. new version).
     */
    public function copyAccessFrom(int $sourceAssessmentId, int $targetAssessmentId): void
    {
        if ($sourceAssessmentId <= 0 || $targetAssessmentId <= 0 || $sourceAssessmentId === $targetAssessmentId) {
            return;
        }

        $source = $this->loadAccessMeta($sourceAssessmentId);
        $target = $this->loadAccessMeta($targetAssessmentId);
        if ($source === null || $target === null) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('UPDATE assessments SET is_locked = :is_locked WHERE id = :id');
            $lock->execute([
                ':is_locked' => ((int) ($source['is_locked'] ?? 0)) === 1 ? 1 : 0,
                ':id' => $targetAssessmentId,
            ]);

            $this->pdo->prepare('DELETE FROM assessment_editors WHERE assessment_id = :id')
                ->execute([':id' => $targetAssessmentId]);

            $copy = $this->pdo->prepare(
                'INSERT INTO assessment_editors (assessment_id, user_id, granted_by_user_id, created_at)
                 SELECT :target_id, user_id, granted_by_user_id, created_at
                 FROM assessment_editors
                 WHERE assessment_id = :source_id'
            );
            $copy->execute([
                ':target_id' => $targetAssessmentId,
                ':source_id' => $sourceAssessmentId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array{
     *   id: int,
     *   solution_name: string,
     *   owner_user_id: int|null,
     *   owner_username: string,
     *   owner_display_name: string,
     *   owner_auth_source: string,
     *   is_locked: int
     * }|null
     */
    public function loadAccessMeta(int $assessmentId): ?array
    {
        if ($assessmentId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, solution_name, owner_user_id, owner_username, owner_display_name,
                    owner_auth_source, is_locked
             FROM assessments
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([':id' => $assessmentId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'solution_name' => (string) ($row['solution_name'] ?? ''),
            'owner_user_id' => isset($row['owner_user_id']) ? (int) $row['owner_user_id'] : null,
            'owner_username' => (string) ($row['owner_username'] ?? ''),
            'owner_display_name' => (string) ($row['owner_display_name'] ?? ''),
            'owner_auth_source' => (string) ($row['owner_auth_source'] ?? ''),
            'is_locked' => ((int) ($row['is_locked'] ?? 0)) === 1 ? 1 : 0,
        ];
    }

    /**
     * @return list<int>
     */
    private function familyIdsBySolutionName(string $solutionName, int $fallbackId): array
    {
        $solutionName = trim($solutionName);
        if ($solutionName === '') {
            return $fallbackId > 0 ? [$fallbackId] : [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id FROM assessments WHERE solution_name = :solution_name ORDER BY id ASC'
        );
        $statement->execute([':solution_name' => $solutionName]);
        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[] = $intId;
            }
        }

        if ($ids === [] && $fallbackId > 0) {
            return [$fallbackId];
        }

        return $ids;
    }
}
