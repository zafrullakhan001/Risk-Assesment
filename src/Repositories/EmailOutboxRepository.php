<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class EmailOutboxRepository
{
    public const KIND_EXCEPTION_DUE = 'exception_due';

    public const DEFAULT_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $kind,
        string $toEmail,
        string $subject,
        string $bodyText,
        string $bodyHtml = '',
        ?int $userId = null,
        ?int $assessmentId = null,
        string $findingId = '',
        array $payload = [],
        ?string $sendAfter = null,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ): int {
        $toEmail = trim($toEmail);
        $kind = trim($kind) !== '' ? trim($kind) : self::KIND_EXCEPTION_DUE;
        if ($toEmail === '' || trim($subject) === '') {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO email_outbox (
                kind, assessment_id, finding_id, user_id, to_email, subject,
                body_text, body_html, payload, send_after, attempts, max_attempts, created_at
             ) VALUES (
                :kind, :assessment_id, :finding_id, :user_id, :to_email, :subject,
                :body_text, :body_html, :payload, :send_after, 0, :max_attempts, datetime(\'now\')
             )'
        );
        $statement->execute([
            ':kind' => $kind,
            ':assessment_id' => $assessmentId !== null && $assessmentId > 0 ? $assessmentId : null,
            ':finding_id' => mb_substr(trim($findingId), 0, 200),
            ':user_id' => $userId !== null && $userId > 0 ? $userId : null,
            ':to_email' => mb_substr($toEmail, 0, 320),
            ':subject' => mb_substr(trim($subject), 0, 300),
            ':body_text' => $bodyText,
            ':body_html' => $bodyHtml,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ':send_after' => $sendAfter !== null && trim($sendAfter) !== ''
                ? trim($sendAfter)
                : date('Y-m-d H:i:s'),
            ':max_attempts' => max(1, min(20, $maxAttempts)),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countPending(?string $kind = null): int
    {
        $sql = 'SELECT COUNT(*) FROM email_outbox
                WHERE sent_at IS NULL
                  AND attempts < max_attempts
                  AND send_after <= datetime(\'now\')';
        $params = [];
        if ($kind !== null && trim($kind) !== '') {
            $sql .= ' AND kind = :kind';
            $params[':kind'] = trim($kind);
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPending(int $limit = 25, ?string $kind = null): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id, kind, assessment_id, finding_id, user_id, to_email, subject,
                       body_text, body_html, payload, send_after, attempts, max_attempts,
                       last_error, created_at, sent_at
                FROM email_outbox
                WHERE sent_at IS NULL
                  AND attempts < max_attempts
                  AND send_after <= datetime(\'now\')';
        $params = [];
        if ($kind !== null && trim($kind) !== '') {
            $sql .= ' AND kind = :kind';
            $params[':kind'] = trim($kind);
        }
        $sql .= ' ORDER BY send_after ASC, id ASC LIMIT ' . $limit;

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = $this->normalize($row);
            }
        }

        return $rows;
    }

    public function markSent(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'UPDATE email_outbox
             SET sent_at = datetime(\'now\'), last_error = \'\'
             WHERE id = :id AND sent_at IS NULL'
        );
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function markFailed(int $id, string $error, int $backoffMinutes = 15): bool
    {
        if ($id <= 0) {
            return false;
        }
        $backoffMinutes = max(1, min(24 * 60, $backoffMinutes));
        $error = mb_substr(trim($error), 0, 2000);
        $statement = $this->pdo->prepare(
            'UPDATE email_outbox
             SET attempts = attempts + 1,
                 last_error = :last_error,
                 send_after = datetime(\'now\', :backoff)
             WHERE id = :id AND sent_at IS NULL'
        );
        $statement->execute([
            ':id' => $id,
            ':last_error' => $error,
            ':backoff' => '+' . $backoffMinutes . ' minutes',
        ]);

        return $statement->rowCount() > 0;
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
            'kind' => (string) ($row['kind'] ?? ''),
            'assessment_id' => isset($row['assessment_id']) && $row['assessment_id'] !== null
                ? (int) $row['assessment_id']
                : null,
            'finding_id' => (string) ($row['finding_id'] ?? ''),
            'user_id' => isset($row['user_id']) && $row['user_id'] !== null
                ? (int) $row['user_id']
                : null,
            'to_email' => (string) ($row['to_email'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'body_text' => (string) ($row['body_text'] ?? ''),
            'body_html' => (string) ($row['body_html'] ?? ''),
            'payload' => $payload,
            'send_after' => (string) ($row['send_after'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'max_attempts' => (int) ($row['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS),
            'last_error' => (string) ($row['last_error'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'sent_at' => $row['sent_at'] !== null && $row['sent_at'] !== ''
                ? (string) $row['sent_at']
                : null,
        ];
    }
}
