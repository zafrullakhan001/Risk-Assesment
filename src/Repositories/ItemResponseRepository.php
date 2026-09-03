<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class ItemResponseRepository
{
    /** @var list<string> */
    public const ACTIONS = [
        'open',
        'take_care',
        'ignore',
        'not_applicable',
        'closed',
    ];

    /** @var array<string, string> */
    public const ACTION_LABELS = [
        'open' => 'Open',
        'take_care' => 'Taken care',
        'ignore' => 'Ignore',
        'not_applicable' => 'Not applicable',
        'closed' => 'Closed',
    ];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array<string, array{action: string, comment: string, updated_at: string}>
     */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT item_key, action, comment, updated_at
             FROM item_responses
             WHERE assessment_id = :assessment_id'
        );
        $statement->execute([':assessment_id' => $assessmentId]);

        $out = [];
        foreach ($statement->fetchAll() as $row) {
            $key = (string) ($row['item_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$key] = [
                'action' => self::normalizeAction((string) ($row['action'] ?? 'open')),
                'comment' => (string) ($row['comment'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }

    public function upsert(int $assessmentId, string $itemKey, string $action, string $comment): bool
    {
        if ($assessmentId <= 0 || trim($itemKey) === '') {
            return false;
        }

        $action = self::normalizeAction($action);
        $comment = trim($comment);
        if (mb_strlen($comment) > 2000) {
            $comment = mb_substr($comment, 0, 2000);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO item_responses (assessment_id, item_key, action, comment, updated_at)
             VALUES (:assessment_id, :item_key, :action, :comment, datetime(\'now\'))
             ON CONFLICT(assessment_id, item_key) DO UPDATE SET
                action = excluded.action,
                comment = excluded.comment,
                updated_at = datetime(\'now\')'
        );

        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':item_key' => $itemKey,
            ':action' => $action,
            ':comment' => $comment,
        ]);

        return true;
    }

    /**
     * @param list<string> $itemKeys
     */
    public function upsertMany(int $assessmentId, array $itemKeys, string $action, string $comment): int
    {
        $saved = 0;
        $seen = [];
        foreach ($itemKeys as $itemKey) {
            $itemKey = trim((string) $itemKey);
            if ($itemKey === '' || isset($seen[$itemKey])) {
                continue;
            }
            $seen[$itemKey] = true;
            if ($this->upsert($assessmentId, $itemKey, $action, $comment)) {
                $saved++;
            }
        }

        return $saved;
    }

    public function deleteForAssessment(int $assessmentId): void
    {
        if ($assessmentId <= 0) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM item_responses WHERE assessment_id = :id');
        $statement->execute([':id' => $assessmentId]);
    }

    public static function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));
        return in_array($action, self::ACTIONS, true) ? $action : 'open';
    }

    public static function label(string $action): string
    {
        $action = self::normalizeAction($action);

        return self::ACTION_LABELS[$action] ?? 'Open';
    }

    public static function isActionableStatus(string $status, string $riskLevel = ''): bool
    {
        $status = strtolower(trim($status));
        $riskLevel = strtolower(trim($riskLevel));

        return in_array($status, ['gap', 'risk', 'tbd'], true) || $riskLevel === 'high';
    }

    public static function isAddressed(string $action): bool
    {
        return self::normalizeAction($action) !== 'open';
    }
}
