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
     * Latest version of each project owned by this user.
     *
     * @return list<array{
     *   id: int,
     *   solution_name: string,
     *   vendor: string,
     *   is_locked: int,
     *   uploaded_at: string,
     *   version_count: int
     * }>
     */
    public function listOwnedProjects(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT a.id, a.solution_name, a.vendor, a.is_locked, a.uploaded_at
             FROM assessments a
             WHERE a.owner_user_id = :user_id
             ORDER BY a.uploaded_at DESC, a.id DESC'
        );
        $statement->execute([':user_id' => $userId]);

        $projects = [];
        $seenFamilies = [];
        $versionCounts = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assessmentId = (int) ($row['id'] ?? 0);
            if ($assessmentId <= 0) {
                continue;
            }
            $solutionName = trim((string) ($row['solution_name'] ?? ''));
            $familyKey = $solutionName !== '' ? mb_strtolower($solutionName) : '#' . $assessmentId;
            $versionCounts[$familyKey] = ($versionCounts[$familyKey] ?? 0) + 1;
            if (isset($seenFamilies[$familyKey])) {
                continue;
            }
            $seenFamilies[$familyKey] = true;
            $projects[] = [
                'id' => $assessmentId,
                'solution_name' => $solutionName !== '' ? $solutionName : 'Untitled project',
                'vendor' => trim((string) ($row['vendor'] ?? '')),
                'is_locked' => ((int) ($row['is_locked'] ?? 0)) === 1 ? 1 : 0,
                'uploaded_at' => (string) ($row['uploaded_at'] ?? ''),
                'family_key' => $familyKey,
                'version_count' => 1,
            ];
        }

        foreach ($projects as &$project) {
            $key = (string) ($project['family_key'] ?? '');
            $project['version_count'] = (int) ($versionCounts[$key] ?? 1);
            unset($project['family_key']);
        }
        unset($project);

        return $projects;
    }

    /**
     * Transfer ownership of one assessment and every version in its solution family.
     *
     * @param array{id?: int, user_id?: int, username?: string, display_name?: string, auth_source?: string} $newOwner
     * @return int Number of assessment rows updated
     */
    public function transferOwnership(
        int $assessmentId,
        array $newOwner,
        int $actingUserId,
        bool $keepFormerAsEditor = true
    ): int {
        if ($assessmentId <= 0) {
            throw new RuntimeException('Assessment not found.');
        }

        $meta = $this->loadAccessMeta($assessmentId);
        if ($meta === null) {
            throw new RuntimeException('Assessment not found.');
        }

        $currentOwnerId = (int) ($meta['owner_user_id'] ?? 0);
        if ($actingUserId <= 0 || $currentOwnerId <= 0 || $currentOwnerId !== $actingUserId) {
            throw new RuntimeException('Only the current project owner can transfer ownership.');
        }

        $newOwnerId = (int) ($newOwner['id'] ?? $newOwner['user_id'] ?? 0);
        if ($newOwnerId <= 0) {
            throw new RuntimeException('Choose a user to receive ownership.');
        }
        if ($newOwnerId === $currentOwnerId) {
            throw new RuntimeException('That user already owns this project.');
        }

        $ownerRow = $this->loadApprovedUser($newOwnerId);
        if ($ownerRow === null) {
            throw new RuntimeException('That user is not available to receive ownership.');
        }

        $familyIds = $this->familyIdsBySolutionName((string) $meta['solution_name'], $assessmentId);
        if ($familyIds === []) {
            return 0;
        }

        $username = trim((string) ($ownerRow['username'] ?? ''));
        $displayName = trim((string) ($ownerRow['display_name'] ?? ''));
        $authSource = strtolower(trim((string) ($ownerRow['auth_source'] ?? 'local')));
        if ($authSource !== 'ldap') {
            $authSource = 'local';
        }

        $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                'UPDATE assessments
                 SET owner_user_id = ?,
                     owner_username = ?,
                     owner_display_name = ?,
                     owner_auth_source = ?
                 WHERE id IN (' . $placeholders . ')
                   AND owner_user_id = ?'
            );
            $update->execute(array_merge(
                [$newOwnerId, $username, $displayName, $authSource],
                $familyIds,
                [$currentOwnerId]
            ));
            $updated = $update->rowCount();

            // New owner no longer needs an editor grant.
            $removeEditor = $this->pdo->prepare(
                'DELETE FROM assessment_editors
                 WHERE user_id = ? AND assessment_id IN (' . $placeholders . ')'
            );
            $removeEditor->execute(array_merge([$newOwnerId], $familyIds));

            if ($keepFormerAsEditor) {
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
                        ':user_id' => $currentOwnerId,
                        ':granted_by_user_id' => $newOwnerId,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return max(0, $updated);
    }

    /**
     * Transfer selected (or all) owned project families to another user.
     *
     * @param list<int>|null $assessmentIds Null = every project owned by $fromUserId
     * @param array{id?: int, user_id?: int, username?: string, display_name?: string, auth_source?: string} $newOwner
     * @return array{projects: int, versions: int}
     */
    public function transferOwnedProjects(
        int $fromUserId,
        array $newOwner,
        ?array $assessmentIds = null,
        bool $keepFormerAsEditor = true
    ): array {
        if ($fromUserId <= 0) {
            throw new RuntimeException('Invalid owner.');
        }

        $owned = $this->listOwnedProjects($fromUserId);
        if ($owned === []) {
            throw new RuntimeException('You do not own any projects to transfer.');
        }

        $selectedIds = null;
        if ($assessmentIds !== null) {
            $selectedIds = [];
            foreach ($assessmentIds as $id) {
                $intId = (int) $id;
                if ($intId > 0) {
                    $selectedIds[$intId] = true;
                }
            }
            if ($selectedIds === []) {
                throw new RuntimeException('Select at least one project to transfer.');
            }
        }

        $projectCount = 0;
        $versionCount = 0;
        foreach ($owned as $project) {
            $id = (int) ($project['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($selectedIds !== null && !isset($selectedIds[$id])) {
                continue;
            }
            $versionCount += $this->transferOwnership($id, $newOwner, $fromUserId, $keepFormerAsEditor);
            $projectCount++;
        }

        if ($projectCount === 0) {
            throw new RuntimeException('None of the selected projects belong to you.');
        }

        return [
            'projects' => $projectCount,
            'versions' => $versionCount,
        ];
    }

    /**
     * @return array{id: int, username: string, display_name: string, auth_source: string, email: string}|null
     */
    private function loadApprovedUser(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, display_name, auth_source, email
             FROM users
             WHERE id = :id AND is_approved = 1 AND is_disabled = 0
             LIMIT 1'
        );
        $statement->execute([':id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => trim((string) ($row['username'] ?? '')),
            'display_name' => trim((string) ($row['display_name'] ?? '')),
            'auth_source' => trim((string) ($row['auth_source'] ?? 'local')),
            'email' => trim((string) ($row['email'] ?? '')),
        ];
    }

    /**
     * Latest version of each project where this user was granted editor access by someone else.
     *
     * @return list<array{
     *   id: int,
     *   solution_name: string,
     *   vendor: string,
     *   is_locked: int,
     *   owner_user_id: int|null,
     *   owner_username: string,
     *   owner_display_name: string,
     *   owner_auth_source: string,
     *   owner_label: string,
     *   granted_at: string,
     *   granted_by_user_id: int|null,
     *   granted_by_label: string
     * }>
     */
    public function listSharedWithUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT a.id, a.solution_name, a.vendor, a.is_locked, a.uploaded_at,
                    a.owner_user_id, a.owner_username, a.owner_display_name, a.owner_auth_source,
                    e.created_at AS granted_at, e.granted_by_user_id,
                    g.username AS granted_by_username, g.display_name AS granted_by_display_name,
                    g.auth_source AS granted_by_auth_source
             FROM assessment_editors e
             INNER JOIN assessments a ON a.id = e.assessment_id
             LEFT JOIN users g ON g.id = e.granted_by_user_id
             WHERE e.user_id = :user_id
               AND (a.owner_user_id IS NULL OR a.owner_user_id <> :owner_user_id)
             ORDER BY a.uploaded_at DESC, a.id DESC'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':owner_user_id' => $userId,
        ]);

        $projects = [];
        $seenFamilies = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $assessmentId = (int) ($row['id'] ?? 0);
            if ($assessmentId <= 0) {
                continue;
            }

            $solutionName = trim((string) ($row['solution_name'] ?? ''));
            $familyKey = $solutionName !== '' ? mb_strtolower($solutionName) : '#' . $assessmentId;
            if (isset($seenFamilies[$familyKey])) {
                continue;
            }
            $seenFamilies[$familyKey] = true;

            $ownerUsername = trim((string) ($row['owner_username'] ?? ''));
            $ownerDisplayName = trim((string) ($row['owner_display_name'] ?? ''));
            $ownerAuthSource = trim((string) ($row['owner_auth_source'] ?? ''));
            $ownerLabel = Actor::formatLabel($ownerDisplayName, $ownerUsername, $ownerAuthSource);
            if ($ownerLabel === '' || $ownerLabel === 'Unknown user') {
                $ownerLabel = 'Unknown owner';
            }

            $grantedByUsername = trim((string) ($row['granted_by_username'] ?? ''));
            $grantedByDisplayName = trim((string) ($row['granted_by_display_name'] ?? ''));
            $grantedByAuthSource = trim((string) ($row['granted_by_auth_source'] ?? ''));
            $grantedByLabel = '';
            if ($grantedByUsername !== '' || $grantedByDisplayName !== '') {
                $grantedByLabel = Actor::formatLabel($grantedByDisplayName, $grantedByUsername, $grantedByAuthSource);
            }
            if ($grantedByLabel === '') {
                $grantedByLabel = $ownerLabel;
            }

            $projects[] = [
                'id' => $assessmentId,
                'solution_name' => $solutionName !== '' ? $solutionName : 'Untitled project',
                'vendor' => trim((string) ($row['vendor'] ?? '')),
                'is_locked' => ((int) ($row['is_locked'] ?? 0)) === 1 ? 1 : 0,
                'owner_user_id' => isset($row['owner_user_id']) ? (int) $row['owner_user_id'] : null,
                'owner_username' => $ownerUsername,
                'owner_display_name' => $ownerDisplayName,
                'owner_auth_source' => $ownerAuthSource,
                'owner_label' => $ownerLabel,
                'granted_at' => (string) ($row['granted_at'] ?? ''),
                'granted_by_user_id' => isset($row['granted_by_user_id']) ? (int) $row['granted_by_user_id'] : null,
                'granted_by_label' => $grantedByLabel,
            ];
        }

        return $projects;
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
