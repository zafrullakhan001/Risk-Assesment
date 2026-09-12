<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class FindingStatusRepository
{
    /** @var list<string> */
    public const STATUSES = ['Open', 'Approved', 'Closed', 'Expired'];

    /** @var list<string> */
    public const ACTIONABLE_STATUSES = ['Open', 'Approved'];

    public const MAX_COMMENT_LENGTH = 10000;

    public const MAX_LINKS = 5;

    public const MAX_LINK_LENGTH = 2000;

    public const MAX_NOTIFY_EMAILS = 20;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array<string, array{
     *   status: string,
     *   comment: string,
     *   servicenow_links: list<string>,
     *   notify_emails: list<string>,
     *   expires_at: string|null,
     *   reminded_at: string|null
     * }>
     */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT finding_id, status, comment, servicenow_links, notify_emails, expires_at, reminded_at
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
            $statuses[$findingId] = $this->normalizeRow($row);
        }

        return $statuses;
    }

    /**
     * @param list<string>|string|null $links
     * @param string|null $expiresAt Pass null to leave unchanged; '' to clear; Y-m-d to set
     * @param list<string>|string|null $notifyEmails Pass null to leave unchanged
     */
    public function upsert(
        int $assessmentId,
        string $findingId,
        string $status,
        ?string $comment = null,
        array|string|null $links = null,
        ?string $expiresAt = null,
        bool $touchExpires = false,
        array|string|null $notifyEmails = null
    ): bool {
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

        $existing = $this->findOne($assessmentId, $findingId);
        $resolvedComment = $comment !== null
            ? self::normalizeComment($comment)
            : (string) ($existing['comment'] ?? '');
        $resolvedLinks = $links !== null
            ? self::normalizeLinks($links)
            : ($existing['servicenow_links'] ?? []);
        $linksJson = json_encode(array_values($resolvedLinks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $resolvedNotify = $notifyEmails !== null
            ? self::normalizeNotifyEmails($notifyEmails)
            : ($existing['notify_emails'] ?? []);
        $notifyJson = json_encode(array_values($resolvedNotify), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        $previousExpires = $existing['expires_at'] ?? null;
        if ($touchExpires) {
            $resolvedExpires = self::normalizeExpiresAt($expiresAt);
        } else {
            $resolvedExpires = $previousExpires;
        }

        $expiresChanged = ($resolvedExpires ?? '') !== ($previousExpires ?? '');
        $resolvedReminded = $expiresChanged ? null : ($existing['reminded_at'] ?? null);

        $statement = $this->pdo->prepare(
            'INSERT INTO finding_statuses (
                assessment_id, finding_id, status, comment, servicenow_links, notify_emails,
                expires_at, reminded_at, updated_at
             ) VALUES (
                :assessment_id, :finding_id, :status, :comment, :servicenow_links, :notify_emails,
                :expires_at, :reminded_at, datetime(\'now\')
             )
             ON CONFLICT(assessment_id, finding_id) DO UPDATE SET
                status = excluded.status,
                comment = excluded.comment,
                servicenow_links = excluded.servicenow_links,
                notify_emails = excluded.notify_emails,
                expires_at = excluded.expires_at,
                reminded_at = excluded.reminded_at,
                updated_at = datetime(\'now\')'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':finding_id' => $findingId,
            ':status' => $status,
            ':comment' => $resolvedComment,
            ':servicenow_links' => $linksJson,
            ':notify_emails' => $notifyJson,
            ':expires_at' => $resolvedExpires,
            ':reminded_at' => $resolvedReminded,
        ]);

        return true;
    }

    /**
     * Extend expiry to a future date. Clears reminded_at; Expired → Open.
     */
    public function extend(int $assessmentId, string $findingId, string $newExpiresAt): bool
    {
        $newExpiresAt = self::normalizeExpiresAt($newExpiresAt);
        if ($assessmentId <= 0 || trim($findingId) === '' || $newExpiresAt === null) {
            return false;
        }

        $today = date('Y-m-d');
        if ($newExpiresAt <= $today) {
            return false;
        }

        $existing = $this->findOne($assessmentId, trim($findingId));
        $status = $existing !== null
            ? self::normalizeStatus((string) ($existing['status'] ?? 'Open'))
            : 'Open';
        if ($status === 'Expired') {
            $status = 'Open';
        }

        return $this->upsert(
            $assessmentId,
            trim($findingId),
            $status,
            $existing['comment'] ?? null,
            $existing['servicenow_links'] ?? null,
            $newExpiresAt,
            true
        );
    }

    public function markReminded(int $assessmentId, string $findingId): bool
    {
        if ($assessmentId <= 0 || trim($findingId) === '') {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE finding_statuses
             SET reminded_at = datetime(\'now\'), updated_at = datetime(\'now\')
             WHERE assessment_id = :assessment_id AND finding_id = :finding_id'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':finding_id' => trim($findingId),
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Due/overdue actionable exceptions for projects the user owns or edits.
     * Admins/superadmins also see due items on any project they can view (all non-hidden).
     *
     * @param array<string, mixed> $user Current user row (id, is_admin, is_superadmin)
     * @return list<array{
     *   assessment_id: int,
     *   finding_id: string,
     *   status: string,
     *   expires_at: string,
     *   reminded_at: string|null,
     *   comment: string,
     *   project_name: string,
     *   finding_text: string,
     *   owner: string,
     *   can_edit: bool
     * }>
     */
    public function listDueForUser(array $user, int $limit = 50): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $isAdmin = !empty($user['is_admin']) || !empty($user['is_superadmin']);
        $today = date('Y-m-d');

        $statusPlaceholders = implode(',', array_fill(0, count(self::ACTIONABLE_STATUSES), '?'));
        $params = array_merge([$today], self::ACTIONABLE_STATUSES);

        if ($isAdmin) {
            $accessSql = '1 = 1';
        } else {
            $accessSql = '(a.owner_user_id = ? OR EXISTS (
                SELECT 1 FROM assessment_editors e
                WHERE e.assessment_id = a.id AND e.user_id = ?
            ))';
            $params[] = $userId;
            $params[] = $userId;
        }

        $params[] = $limit;

        $sql = "SELECT fs.assessment_id, fs.finding_id, fs.status, fs.comment,
                       fs.expires_at, fs.reminded_at, fs.servicenow_links,
                       a.solution_name, a.owner_user_id, a.workbook_json
                FROM finding_statuses fs
                INNER JOIN assessments a ON a.id = fs.assessment_id
                WHERE fs.expires_at IS NOT NULL
                  AND fs.expires_at != ''
                  AND fs.expires_at <= ?
                  AND fs.status IN ({$statusPlaceholders})
                  AND {$accessSql}
                ORDER BY fs.expires_at ASC, fs.assessment_id ASC
                LIMIT ?";

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $i => $value) {
            $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assessmentId = (int) ($row['assessment_id'] ?? 0);
            $findingId = trim((string) ($row['finding_id'] ?? ''));
            $expiresAt = self::normalizeExpiresAt((string) ($row['expires_at'] ?? ''));
            if ($assessmentId <= 0 || $findingId === '' || $expiresAt === null) {
                continue;
            }

            $snippet = $this->findingSnippetFromWorkbook(
                (string) ($row['workbook_json'] ?? '{}'),
                $findingId
            );
            $ownerId = (int) ($row['owner_user_id'] ?? 0);
            $canEdit = !empty($user['is_superadmin'])
                || ($ownerId > 0 && $ownerId === $userId)
                || $this->userIsEditor($assessmentId, $userId);

            $items[] = [
                'assessment_id' => $assessmentId,
                'finding_id' => $findingId,
                'status' => self::normalizeStatus((string) ($row['status'] ?? 'Open')),
                'expires_at' => $expiresAt,
                'reminded_at' => self::nullableTimestamp($row['reminded_at'] ?? null),
                'comment' => (string) ($row['comment'] ?? ''),
                'project_name' => trim((string) ($row['solution_name'] ?? '')) !== ''
                    ? trim((string) $row['solution_name'])
                    : 'Untitled project',
                'finding_text' => $snippet['finding'],
                'owner' => $snippet['owner'],
                'can_edit' => $canEdit,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function countDueForUser(array $user): int
    {
        return count($this->listDueForUser($user, 200));
    }

    /**
     * Due rows that still need a reminder email/in-app digest.
     *
     * @return list<array{
     *   assessment_id: int,
     *   finding_id: string,
     *   status: string,
     *   expires_at: string,
     *   comment: string,
     *   notify_emails: list<string>,
     *   servicenow_links: list<string>,
     *   project_name: string,
     *   finding_text: string,
     *   owner_user_id: int,
     *   solution_name: string
     * }>
     */
    public function listDueForReminder(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $today = date('Y-m-d');
        $statusPlaceholders = implode(',', array_fill(0, count(self::ACTIONABLE_STATUSES), '?'));
        $params = array_merge([$today], self::ACTIONABLE_STATUSES, [$limit]);

        $sql = "SELECT fs.assessment_id, fs.finding_id, fs.status, fs.comment,
                       fs.expires_at, fs.notify_emails, fs.servicenow_links,
                       a.solution_name, a.owner_user_id, a.workbook_json
                FROM finding_statuses fs
                INNER JOIN assessments a ON a.id = fs.assessment_id
                WHERE fs.expires_at IS NOT NULL
                  AND fs.expires_at != ''
                  AND fs.expires_at <= ?
                  AND fs.status IN ({$statusPlaceholders})
                  AND fs.reminded_at IS NULL
                ORDER BY fs.expires_at ASC, fs.assessment_id ASC
                LIMIT ?";

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $i => $value) {
            $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assessmentId = (int) ($row['assessment_id'] ?? 0);
            $findingId = trim((string) ($row['finding_id'] ?? ''));
            $expiresAt = self::normalizeExpiresAt((string) ($row['expires_at'] ?? ''));
            if ($assessmentId <= 0 || $findingId === '' || $expiresAt === null) {
                continue;
            }
            $snippet = $this->findingSnippetFromWorkbook(
                (string) ($row['workbook_json'] ?? '{}'),
                $findingId
            );
            $items[] = [
                'assessment_id' => $assessmentId,
                'finding_id' => $findingId,
                'status' => self::normalizeStatus((string) ($row['status'] ?? 'Open')),
                'expires_at' => $expiresAt,
                'comment' => (string) ($row['comment'] ?? ''),
                'notify_emails' => self::normalizeNotifyEmails($row['notify_emails'] ?? '[]'),
                'servicenow_links' => self::normalizeLinks($row['servicenow_links'] ?? '[]'),
                'project_name' => trim((string) ($row['solution_name'] ?? '')) !== ''
                    ? trim((string) $row['solution_name'])
                    : 'Untitled project',
                'finding_text' => $snippet['finding'],
                'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
                'solution_name' => trim((string) ($row['solution_name'] ?? '')),
            ];
        }

        return $items;
    }

    /**
     * Seed or fill expires_at from workbook findings when missing.
     *
     * @param list<array<string, mixed>> $findings
     */
    public function seedExpiresFromFindings(int $assessmentId, array $findings): int
    {
        if ($assessmentId <= 0) {
            return 0;
        }

        $existing = $this->listForAssessment($assessmentId);
        $seeded = 0;
        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $findingId = trim((string) ($finding['id'] ?? ('finding-' . $index)));
            if ($findingId === '') {
                continue;
            }

            $parsed = self::parseExpiresAt(
                (string) ($finding['timeline'] ?? ''),
                (string) ($finding['expiration_date'] ?? '')
            );
            if ($parsed === null) {
                continue;
            }

            $current = $existing[$findingId] ?? null;
            if ($current !== null && ($current['expires_at'] ?? null) !== null) {
                continue;
            }

            $status = $current['status'] ?? (string) ($finding['status'] ?? 'Open');
            $comment = $current['comment'] ?? '';
            $links = $current['servicenow_links'] ?? [];
            if ($this->upsert($assessmentId, $findingId, $status, $comment, $links, $parsed, true)) {
                $seeded++;
                $existing[$findingId] = [
                    'status' => self::normalizeStatus($status),
                    'comment' => (string) $comment,
                    'servicenow_links' => self::normalizeLinks($links),
                    'expires_at' => $parsed,
                    'reminded_at' => null,
                ];
            }
        }

        return $seeded;
    }

    public function deleteOne(int $assessmentId, string $findingId): void
    {
        if ($assessmentId <= 0 || trim($findingId) === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM finding_statuses
             WHERE assessment_id = :assessment_id AND finding_id = :finding_id'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':finding_id' => trim($findingId),
        ]);
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
        foreach ($this->listForAssessment($sourceId) as $findingId => $record) {
            if (isset($existing[$findingId])) {
                continue;
            }
            if ($this->upsert(
                $targetId,
                $findingId,
                (string) ($record['status'] ?? 'Open'),
                (string) ($record['comment'] ?? ''),
                $record['servicenow_links'] ?? [],
                $record['expires_at'] ?? null,
                true,
                $record['notify_emails'] ?? []
            )) {
                $copied++;
            }
        }

        return $copied;
    }

    /**
     * @return array{
     *   status: string,
     *   comment: string,
     *   servicenow_links: list<string>,
     *   notify_emails: list<string>,
     *   expires_at: string|null,
     *   reminded_at: string|null
     * }|null
     */
    public function findOne(int $assessmentId, string $findingId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, comment, servicenow_links, notify_emails, expires_at, reminded_at
             FROM finding_statuses
             WHERE assessment_id = :assessment_id AND finding_id = :finding_id
             LIMIT 1'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':finding_id' => $findingId,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return $this->normalizeRow($row);
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

    public static function normalizeComment(string $comment): string
    {
        $comment = trim($comment);
        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            return mb_substr($comment, 0, self::MAX_COMMENT_LENGTH);
        }

        return $comment;
    }

    /**
     * @param list<mixed>|string|null $links
     * @return list<string>
     */
    public static function normalizeLinks(array|string|null $links): array
    {
        if (is_string($links)) {
            $decoded = json_decode($links, true);
            $links = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($links)) {
            return [];
        }

        $clean = [];
        foreach ($links as $link) {
            if (!is_string($link) && !is_numeric($link)) {
                continue;
            }
            $url = trim((string) $link);
            if ($url === '') {
                continue;
            }
            if (mb_strlen($url) > self::MAX_LINK_LENGTH) {
                $url = mb_substr($url, 0, self::MAX_LINK_LENGTH);
            }
            if (!self::isAllowedUrl($url)) {
                continue;
            }
            if (in_array($url, $clean, true)) {
                continue;
            }
            $clean[] = $url;
            if (count($clean) >= self::MAX_LINKS) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Client / stakeholder emails for scheduler due reminders.
     *
     * @param list<mixed>|string|null $emails
     * @return list<string>
     */
    public static function normalizeNotifyEmails(array|string|null $emails): array
    {
        if (is_string($emails)) {
            $trimmed = trim($emails);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                $emails = is_array($decoded) ? $decoded : (preg_split('/[,\n;]+/', $trimmed) ?: []);
            } else {
                $emails = preg_split('/[,\n;]+/', $trimmed) ?: [];
            }
        }
        if (!is_array($emails)) {
            return [];
        }

        $clean = [];
        $seen = [];
        foreach ($emails as $email) {
            if (!is_string($email) && !is_numeric($email)) {
                continue;
            }
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)
                && !preg_match('/^[^\s@]+@([^\s@]+\.[^\s@]+|localhost|127\.0\.0\.1)$/i', $email)
            ) {
                continue;
            }
            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = $email;
            if (count($clean) >= self::MAX_NOTIFY_EMAILS) {
                break;
            }
        }

        return $clean;
    }

    public static function isAllowedUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @param array{status?: string, comment?: string, servicenow_links?: list<string>}|string $record
     */
    public static function statusFromRecord(array|string $record): string
    {
        if (is_string($record)) {
            return self::normalizeStatus($record);
        }

        return self::normalizeStatus((string) ($record['status'] ?? 'Open'));
    }

    /** Normalize to Y-m-d or null. Empty string clears. */
    public static function normalizeExpiresAt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
            }
        }

        return self::parseExpiresAt($value);
    }

    /** Try to parse free-text timeline / expiration into Y-m-d. */
    public static function parseExpiresAt(string ...$candidates): ?string
    {
        foreach ($candidates as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1) {
                if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
                }
            }
            if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m) === 1) {
                $a = (int) $m[1];
                $b = (int) $m[2];
                $year = (int) $m[3];
                // Prefer MDY when first part > 12; otherwise MDY (US-leaning).
                if ($a > 12 && $b <= 12) {
                    $day = $a;
                    $month = $b;
                } else {
                    $month = $a;
                    $day = $b;
                }
                if (checkdate($month, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
            if (preg_match('/\b(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\b/', $raw, $m) === 1) {
                $year = (int) $m[1];
                $month = (int) $m[2];
                $day = (int) $m[3];
                if (checkdate($month, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
            // Strict strtotime only when the whole string looks date-like.
            if (preg_match('/\d{4}|\d{1,2}[\/\-.]\d{1,2}/', $raw) === 1) {
                $ts = strtotime($raw);
                if ($ts !== false) {
                    $year = (int) date('Y', $ts);
                    if ($year >= 2000 && $year <= 2100) {
                        return date('Y-m-d', $ts);
                    }
                }
            }
        }

        return null;
    }

    public static function isDue(?string $expiresAt, string $status, ?string $today = null): bool
    {
        $expiresAt = self::normalizeExpiresAt($expiresAt);
        if ($expiresAt === null) {
            return false;
        }
        $status = self::normalizeStatus($status);
        if (!in_array($status, self::ACTIONABLE_STATUSES, true)) {
            return false;
        }
        $today ??= date('Y-m-d');

        return $expiresAt <= $today;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   status: string,
     *   comment: string,
     *   servicenow_links: list<string>,
     *   notify_emails: list<string>,
     *   expires_at: string|null,
     *   reminded_at: string|null
     * }
     */
    private function normalizeRow(array $row): array
    {
        return [
            'status' => self::normalizeStatus((string) ($row['status'] ?? 'Open')),
            'comment' => (string) ($row['comment'] ?? ''),
            'servicenow_links' => self::normalizeLinks($row['servicenow_links'] ?? '[]'),
            'notify_emails' => self::normalizeNotifyEmails($row['notify_emails'] ?? '[]'),
            'expires_at' => self::normalizeExpiresAt(
                isset($row['expires_at']) && $row['expires_at'] !== null
                    ? (string) $row['expires_at']
                    : null
            ),
            'reminded_at' => self::nullableTimestamp($row['reminded_at'] ?? null),
        ];
    }

    private static function nullableTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function userIsEditor(int $assessmentId, int $userId): bool
    {
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
     * @return array{finding: string, owner: string}
     */
    private function findingSnippetFromWorkbook(string $workbookJson, string $findingId): array
    {
        $workbook = json_decode($workbookJson, true);
        if (!is_array($workbook)) {
            return ['finding' => $findingId, 'owner' => ''];
        }
        $findings = $workbook['findings'] ?? [];
        if (!is_array($findings)) {
            return ['finding' => $findingId, 'owner' => ''];
        }
        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $id = trim((string) ($finding['id'] ?? ('finding-' . $index)));
            if ($id !== $findingId) {
                continue;
            }
            $text = trim((string) ($finding['finding'] ?? ''));
            if (mb_strlen($text) > 160) {
                $text = mb_substr($text, 0, 157) . '…';
            }

            return [
                'finding' => $text !== '' ? $text : $findingId,
                'owner' => trim((string) ($finding['owner'] ?? '')),
            ];
        }

        return ['finding' => $findingId, 'owner' => ''];
    }
}
