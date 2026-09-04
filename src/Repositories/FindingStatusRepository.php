<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class FindingStatusRepository
{
    /** @var list<string> */
    public const STATUSES = ['Open', 'Approved', 'Closed', 'Expired'];

    public const MAX_COMMENT_LENGTH = 10000;

    public const MAX_LINKS = 5;

    public const MAX_LINK_LENGTH = 2000;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array<string, array{status: string, comment: string, servicenow_links: list<string>}>
     */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT finding_id, status, comment, servicenow_links
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
            $statuses[$findingId] = [
                'status' => self::normalizeStatus((string) ($row['status'] ?? 'Open')),
                'comment' => (string) ($row['comment'] ?? ''),
                'servicenow_links' => self::normalizeLinks($row['servicenow_links'] ?? '[]'),
            ];
        }

        return $statuses;
    }

    /**
     * @param list<string>|string|null $links
     */
    public function upsert(
        int $assessmentId,
        string $findingId,
        string $status,
        ?string $comment = null,
        array|string|null $links = null
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

        $statement = $this->pdo->prepare(
            'INSERT INTO finding_statuses (assessment_id, finding_id, status, comment, servicenow_links, updated_at)
             VALUES (:assessment_id, :finding_id, :status, :comment, :servicenow_links, datetime(\'now\'))
             ON CONFLICT(assessment_id, finding_id) DO UPDATE SET
                status = excluded.status,
                comment = excluded.comment,
                servicenow_links = excluded.servicenow_links,
                updated_at = datetime(\'now\')'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':finding_id' => $findingId,
            ':status' => $status,
            ':comment' => $resolvedComment,
            ':servicenow_links' => $linksJson,
        ]);

        return true;
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
                $record['servicenow_links'] ?? []
            )) {
                $copied++;
            }
        }

        return $copied;
    }

    /**
     * @return array{status: string, comment: string, servicenow_links: list<string>}|null
     */
    private function findOne(int $assessmentId, string $findingId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, comment, servicenow_links
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

        return [
            'status' => self::normalizeStatus((string) ($row['status'] ?? 'Open')),
            'comment' => (string) ($row['comment'] ?? ''),
            'servicenow_links' => self::normalizeLinks($row['servicenow_links'] ?? '[]'),
        ];
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
}
