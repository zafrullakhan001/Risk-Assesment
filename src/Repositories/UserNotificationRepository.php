<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class UserNotificationRepository
{
    public const TYPE_EDITOR_GRANTED = 'editor_granted';
    public const TYPE_EDITOR_GRANTED_SENT = 'editor_granted_sent';
    public const TYPE_OWNERSHIP_RECEIVED = 'ownership_received';
    public const TYPE_OWNERSHIP_SENT = 'ownership_sent';
    public const TYPE_EXCEPTION_DUE = 'exception_due';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        string $body,
        string $linkUrl = '',
        ?int $assessmentId = null,
        array $payload = []
    ): int {
        if ($userId <= 0 || trim($type) === '') {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO user_notifications (
                user_id, type, assessment_id, title, body, link_url, payload, created_at
             ) VALUES (
                :user_id, :type, :assessment_id, :title, :body, :link_url, :payload, datetime(\'now\')
             )'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':type' => trim($type),
            ':assessment_id' => $assessmentId !== null && $assessmentId > 0 ? $assessmentId : null,
            ':title' => mb_substr(trim($title), 0, 200),
            ':body' => mb_substr(trim($body), 0, 2000),
            ':link_url' => mb_substr(trim($linkUrl), 0, 500),
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countUnread(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_notifications
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute([':user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUnread(int $userId, int $limit = 20): array
    {
        return $this->listForUser($userId, $limit, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $userId, int $limit = 25): array
    {
        return $this->listForUser($userId, $limit, false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listForUser(int $userId, int $limit, bool $unreadOnly): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $sql = 'SELECT id, user_id, type, assessment_id, title, body, link_url, payload, created_at, read_at
             FROM user_notifications
             WHERE user_id = :user_id';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = $this->normalize($row);
            }
        }

        return $rows;
    }

    public function markRead(int $userId, int $notificationId): bool
    {
        if ($userId <= 0 || $notificationId <= 0) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE user_notifications
             SET read_at = datetime(\'now\')
             WHERE id = :id AND user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute([
            ':id' => $notificationId,
            ':user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'UPDATE user_notifications
             SET read_at = datetime(\'now\')
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute([':user_id' => $userId]);

        return max(0, $statement->rowCount());
    }

    /**
     * Mark unread receiver-facing notices as read (for toast-once behavior).
     *
     * @param list<string> $types
     */
    public function markUnreadTypesRead(int $userId, array $types): int
    {
        if ($userId <= 0 || $types === []) {
            return 0;
        }

        $clean = [];
        foreach ($types as $type) {
            $type = trim((string) $type);
            if ($type !== '') {
                $clean[$type] = true;
            }
        }
        $typeList = array_keys($clean);
        if ($typeList === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($typeList), '?'));
        $statement = $this->pdo->prepare(
            'UPDATE user_notifications
             SET read_at = datetime(\'now\')
             WHERE user_id = ? AND read_at IS NULL AND type IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$userId], $typeList));

        return max(0, $statement->rowCount());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $payload = [];
        $raw = trim((string) ($row['payload'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'type' => (string) ($row['type'] ?? ''),
            'assessment_id' => isset($row['assessment_id']) && $row['assessment_id'] !== null
                ? (int) $row['assessment_id']
                : null,
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'link_url' => (string) ($row['link_url'] ?? ''),
            'payload' => $payload,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'read_at' => $row['read_at'] !== null && $row['read_at'] !== ''
                ? (string) $row['read_at']
                : null,
            'is_unread' => empty($row['read_at']),
        ];
    }
}
