<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RiskAssessment\Actor;

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
     * @return array<string, array{
     *   action: string,
     *   comment: string,
     *   updated_at: string,
     *   updated_by_user_id: int|null,
     *   updated_by_username: string,
     *   updated_by_display_name: string,
     *   updated_by_auth_source: string,
     *   updated_by_label: string
     * }>
     */
    public function listForAssessment(int $assessmentId): array
    {
        if ($assessmentId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT item_key, action, comment, updated_at,
                    updated_by_user_id, updated_by_username, updated_by_display_name, updated_by_auth_source
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
            $out[$key] = $this->mapRow($row);
        }

        return $out;
    }

    /**
     * @param array{
     *   user_id?: int,
     *   username?: string,
     *   display_name?: string,
     *   auth_source?: string
     * }|null $actor
     * @return array{
     *   action: string,
     *   comment: string,
     *   updated_at: string,
     *   updated_by_user_id: int|null,
     *   updated_by_username: string,
     *   updated_by_display_name: string,
     *   updated_by_auth_source: string,
     *   updated_by_label: string
     * }|null
     */
    public function upsert(int $assessmentId, string $itemKey, string $action, string $comment, ?array $actor = null): ?array
    {
        if ($assessmentId <= 0 || trim($itemKey) === '') {
            return null;
        }

        $action = self::normalizeAction($action);
        $comment = trim($comment);
        if (mb_strlen($comment) > 2000) {
            $comment = mb_substr($comment, 0, 2000);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM assessments WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $assessmentId]);
        if ($exists->fetchColumn() === false) {
            return null;
        }

        $actorId = isset($actor['user_id']) ? (int) $actor['user_id'] : null;
        if ($actorId !== null && $actorId <= 0) {
            $actorId = null;
        }
        $actorUsername = (string) ($actor['username'] ?? '');
        $actorDisplayName = (string) ($actor['display_name'] ?? '');
        $actorAuthSource = (string) ($actor['auth_source'] ?? '');

        $statement = $this->pdo->prepare(
            'INSERT INTO item_responses (
                assessment_id, item_key, action, comment, updated_at,
                updated_by_user_id, updated_by_username, updated_by_display_name, updated_by_auth_source
             ) VALUES (
                :assessment_id, :item_key, :action, :comment, datetime(\'now\'),
                :updated_by_user_id, :updated_by_username, :updated_by_display_name, :updated_by_auth_source
             )
             ON CONFLICT(assessment_id, item_key) DO UPDATE SET
                action = excluded.action,
                comment = excluded.comment,
                updated_at = datetime(\'now\'),
                updated_by_user_id = excluded.updated_by_user_id,
                updated_by_username = excluded.updated_by_username,
                updated_by_display_name = excluded.updated_by_display_name,
                updated_by_auth_source = excluded.updated_by_auth_source'
        );

        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':item_key' => $itemKey,
            ':action' => $action,
            ':comment' => $comment,
            ':updated_by_user_id' => $actorId,
            ':updated_by_username' => $actorUsername,
            ':updated_by_display_name' => $actorDisplayName,
            ':updated_by_auth_source' => $actorAuthSource,
        ]);

        return $this->findOne($assessmentId, $itemKey);
    }

    /**
     * @param list<string> $itemKeys
     * @param array{
     *   user_id?: int,
     *   username?: string,
     *   display_name?: string,
     *   auth_source?: string
     * }|null $actor
     * @return list<array{
     *   item_key: string,
     *   action: string,
     *   comment: string,
     *   updated_at: string,
     *   updated_by_user_id: int|null,
     *   updated_by_username: string,
     *   updated_by_display_name: string,
     *   updated_by_auth_source: string,
     *   updated_by_label: string
     * }>
     */
    public function upsertMany(int $assessmentId, array $itemKeys, string $action, string $comment, ?array $actor = null): array
    {
        $saved = [];
        $seen = [];
        foreach ($itemKeys as $itemKey) {
            $itemKey = trim((string) $itemKey);
            if ($itemKey === '' || isset($seen[$itemKey])) {
                continue;
            }
            $seen[$itemKey] = true;
            $row = $this->upsert($assessmentId, $itemKey, $action, $comment, $actor);
            if ($row !== null) {
                $saved[] = array_merge(['item_key' => $itemKey], $row);
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

    /**
     * @return array{
     *   action: string,
     *   comment: string,
     *   updated_at: string,
     *   updated_by_user_id: int|null,
     *   updated_by_username: string,
     *   updated_by_display_name: string,
     *   updated_by_auth_source: string,
     *   updated_by_label: string
     * }|null
     */
    private function findOne(int $assessmentId, string $itemKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT action, comment, updated_at,
                    updated_by_user_id, updated_by_username, updated_by_display_name, updated_by_auth_source
             FROM item_responses
             WHERE assessment_id = :assessment_id AND item_key = :item_key
             LIMIT 1'
        );
        $statement->execute([
            ':assessment_id' => $assessmentId,
            ':item_key' => $itemKey,
        ]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   action: string,
     *   comment: string,
     *   updated_at: string,
     *   updated_by_user_id: int|null,
     *   updated_by_username: string,
     *   updated_by_display_name: string,
     *   updated_by_auth_source: string,
     *   updated_by_label: string
     * }
     */
    private function mapRow(array $row): array
    {
        $userId = $row['updated_by_user_id'] ?? null;

        return [
            'action' => self::normalizeAction((string) ($row['action'] ?? 'open')),
            'comment' => (string) ($row['comment'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'updated_by_user_id' => $userId !== null && $userId !== '' ? (int) $userId : null,
            'updated_by_username' => (string) ($row['updated_by_username'] ?? ''),
            'updated_by_display_name' => (string) ($row['updated_by_display_name'] ?? ''),
            'updated_by_auth_source' => (string) ($row['updated_by_auth_source'] ?? ''),
            'updated_by_label' => Actor::labelFromRow($row, 'updated_by'),
        ];
    }
}
