<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RiskAssessment\AppUrl;

final class ProjectShareRepository
{
    public const TOKEN_BYTES = 32;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Create a new read-only share link for an assessment. Revokes any existing active links.
     *
     * @param array{id?: int, username?: string}|null $actor
     * @return array{token: string, id: int, created_at: string, url_path: string}
     */
    public function create(int $assessmentId, ?array $actor = null): array
    {
        if ($assessmentId <= 0) {
            throw new \InvalidArgumentException('Invalid assessment.');
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);
        if ($exists->fetchColumn() === false) {
            throw new \InvalidArgumentException('Assessment not found.');
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = $this->hashToken($token);
        $userId = isset($actor['id']) ? (int) $actor['id'] : null;
        $username = trim((string) ($actor['username'] ?? ''));

        $this->pdo->beginTransaction();
        try {
            $revoke = $this->pdo->prepare(
                'UPDATE assessment_share_links
                 SET revoked_at = datetime(\'now\')
                 WHERE assessment_id = :assessment_id
                   AND revoked_at IS NULL'
            );
            $revoke->execute([':assessment_id' => $assessmentId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO assessment_share_links (
                    assessment_id, token_hash, created_by_user_id, created_by_username, created_at
                 ) VALUES (
                    :assessment_id, :token_hash, :created_by_user_id, :created_by_username, datetime(\'now\')
                 )'
            );
            $insert->execute([
                ':assessment_id' => $assessmentId,
                ':token_hash' => $tokenHash,
                ':created_by_user_id' => $userId > 0 ? $userId : null,
                ':created_by_username' => $username,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $createdAt = (string) ($this->pdo->query(
            'SELECT created_at FROM assessment_share_links WHERE id = ' . $id
        )->fetchColumn() ?: gmdate('Y-m-d H:i:s'));

        return [
            'token' => $token,
            'id' => $id,
            'created_at' => $createdAt,
            'url_path' => 'share.php?t=' . rawurlencode($token),
        ];
    }

    public function revokeById(int $shareId, int $assessmentId): bool
    {
        if ($shareId <= 0 || $assessmentId <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE assessment_share_links
             SET revoked_at = datetime(\'now\')
             WHERE id = :id
               AND assessment_id = :assessment_id
               AND revoked_at IS NULL'
        );
        $statement->execute([
            ':id' => $shareId,
            ':assessment_id' => $assessmentId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function revokeAllForAssessment(int $assessmentId): int
    {
        if ($assessmentId <= 0) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'UPDATE assessment_share_links
             SET revoked_at = datetime(\'now\')
             WHERE assessment_id = :assessment_id
               AND revoked_at IS NULL'
        );
        $statement->execute([':assessment_id' => $assessmentId]);

        return $statement->rowCount();
    }

    /**
     * Resolve an active share by raw token. Updates last_accessed_at on success.
     *
     * @return array{
     *   id: int,
     *   assessment_id: int,
     *   created_at: string,
     *   created_by_username: string,
     *   expires_at: ?string
     * }|null
     */
    public function findActiveByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, assessment_id, created_at, created_by_username, expires_at, revoked_at
             FROM assessment_share_links
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $statement->execute([':token_hash' => $this->hashToken($token)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        if (($row['revoked_at'] ?? null) !== null && (string) $row['revoked_at'] !== '') {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt !== null && (string) $expiresAt !== '') {
            $expiresTs = strtotime((string) $expiresAt);
            if ($expiresTs !== false && $expiresTs < time()) {
                return null;
            }
        }

        $touch = $this->pdo->prepare(
            'UPDATE assessment_share_links
             SET last_accessed_at = datetime(\'now\')
             WHERE id = :id'
        );
        $touch->execute([':id' => (int) $row['id']]);

        return [
            'id' => (int) $row['id'],
            'assessment_id' => (int) $row['assessment_id'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_by_username' => (string) ($row['created_by_username'] ?? ''),
            'expires_at' => $expiresAt !== null && (string) $expiresAt !== '' ? (string) $expiresAt : null,
        ];
    }

    /**
     * @return list<array{
     *   id: int,
     *   created_at: string,
     *   created_by_username: string,
     *   expires_at: ?string,
     *   last_accessed_at: ?string,
     *   is_active: bool
     * }>
     */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, created_at, created_by_username, expires_at, last_accessed_at, revoked_at
             FROM assessment_share_links
             WHERE assessment_id = :assessment_id
             ORDER BY id DESC
             LIMIT 20'
        );
        $statement->execute([':assessment_id' => $assessmentId]);
        $rows = $statement->fetchAll();
        $now = time();
        $links = [];
        foreach ($rows as $row) {
            $revoked = ($row['revoked_at'] ?? null) !== null && (string) $row['revoked_at'] !== '';
            $expiresAt = $row['expires_at'] ?? null;
            $expired = false;
            if ($expiresAt !== null && (string) $expiresAt !== '') {
                $expiresTs = strtotime((string) $expiresAt);
                $expired = $expiresTs !== false && $expiresTs < $now;
            }
            $links[] = [
                'id' => (int) ($row['id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'created_by_username' => (string) ($row['created_by_username'] ?? ''),
                'expires_at' => $expiresAt !== null && (string) $expiresAt !== '' ? (string) $expiresAt : null,
                'last_accessed_at' => ($row['last_accessed_at'] ?? null) !== null && (string) $row['last_accessed_at'] !== ''
                    ? (string) $row['last_accessed_at']
                    : null,
                'is_active' => !$revoked && !$expired,
            ];
        }

        return $links;
    }

    public function hasActiveShare(int $assessmentId): bool
    {
        foreach ($this->listForAssessment($assessmentId) as $link) {
            if ($link['is_active']) {
                return true;
            }
        }

        return false;
    }

    /** Build an absolute share URL for the current request host. */
    public static function absoluteUrl(string $token): string
    {
        return AppUrl::absolute('share.php?t=' . rawurlencode($token));
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
