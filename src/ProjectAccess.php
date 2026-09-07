<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\AssessmentAccessRepository;

/**
 * Project-level access: owner owns edits; editors may edit; admins may open locked projects.
 */
final class ProjectAccess
{
    public function __construct(
        private readonly AssessmentAccessRepository $access,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $meta Requires owner_user_id; optional is_locked, id/assessment_id
     */
    public function isOwner(array $user, array $meta): bool
    {
        $ownerId = (int) ($meta['owner_user_id'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);

        return $ownerId > 0 && $userId > 0 && $ownerId === $userId;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $meta
     */
    public function isEditor(array $user, array $meta): bool
    {
        $assessmentId = $this->assessmentId($meta);
        $userId = (int) ($user['id'] ?? 0);
        if ($assessmentId <= 0 || $userId <= 0) {
            return false;
        }

        return $this->access->isEditor($assessmentId, $userId);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $meta
     */
    public function canView(array $user, array $meta): bool
    {
        if ($this->isOwner($user, $meta) || $this->isEditor($user, $meta)) {
            return true;
        }

        if (!empty($user['is_admin'])) {
            return true;
        }

        return !$this->isLocked($meta);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $meta
     */
    public function canEdit(array $user, array $meta): bool
    {
        return $this->isOwner($user, $meta) || $this->isEditor($user, $meta);
    }

    /**
     * Owner-only: lock/unlock, editors, delete, public share links.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $meta
     */
    public function canManage(array $user, array $meta): bool
    {
        return $this->isOwner($user, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function isLocked(array $meta): bool
    {
        return ((int) ($meta['is_locked'] ?? 0)) === 1;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function assessmentId(array $meta): int
    {
        $id = (int) ($meta['id'] ?? 0);
        if ($id > 0) {
            return $id;
        }

        return (int) ($meta['assessment_id'] ?? 0);
    }
}
