<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use DateTimeImmutable;
use PDO;
use RiskAssessment\Repositories\SharePointArchiveRepository;

/**
 * Limits SharePoint dashboards to projects whose last-modified date (newest item
 * in the project) falls inside an inclusive From/To day range.
 *
 * Stored dates mix "m/d/Y g:i A" and ISO text, so they are parsed in PHP and the
 * matching projects are kept in a per-connection TEMP table that SQL can join.
 */
final class SharePointProjectDateScope
{
    private const TABLE = 'temp.sp_project_date_scope';

    private function __construct(
        private readonly ?int $fromTs,
        private readonly ?int $toTs,
    ) {
    }

    /**
     * Reads `from` / `to` (YYYY-MM-DD) from the query string. Returns null when neither is a valid date.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(PDO $pdo, array $query): ?self
    {
        $from = self::parseDay($query['from'] ?? null, '00:00:00');
        $to = self::parseDay($query['to'] ?? null, '23:59:59');
        if ($from === null && $to === null) {
            return null;
        }

        $scope = new self($from, $to);
        $scope->materialize($pdo);

        return $scope;
    }

    /**
     * Archive visibility plus the date range (when a scope is active) for a sharepoint_items alias.
     */
    public static function visibleProjectSql(?self $scope, string $alias): string
    {
        $sql = SharePointArchiveRepository::visibleProjectSql($alias);

        return $scope === null ? $sql : $sql . ' AND ' . $scope->inRangeSql($alias);
    }

    /**
     * SQL fragment: keep rows whose source/project is inside the date range.
     */
    public function inRangeSql(string $alias): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'sharepoint_items';

        return 'EXISTS (
            SELECT 1 FROM ' . self::TABLE . " _spd
            WHERE _spd.source_key = {$alias}.source_key
              AND _spd.project_name = {$alias}.project_name
        )";
    }

    private static function parseDay(mixed $value, string $time): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value . ' ' . $time);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->getTimestamp();
    }

    private function materialize(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TEMP TABLE IF NOT EXISTS sp_project_date_scope (
                source_key TEXT NOT NULL,
                project_name TEXT NOT NULL,
                PRIMARY KEY (source_key, project_name)
            ) WITHOUT ROWID'
        );
        $pdo->exec('DELETE FROM ' . self::TABLE);

        $statement = $pdo->query(
            "SELECT source_key, project_name, last_modified
             FROM sharepoint_items
             WHERE last_modified <> '' AND project_name <> ''
             GROUP BY source_key, project_name, last_modified"
        );

        /** @var array<string, array{0: string, 1: string, 2: int}> $latest */
        $latest = [];
        foreach ($statement ?: [] as $row) {
            $ts = strtotime((string) $row['last_modified']);
            if ($ts === false) {
                continue;
            }
            $key = $row['source_key'] . "\n" . $row['project_name'];
            if (!isset($latest[$key]) || $ts > $latest[$key][2]) {
                $latest[$key] = [(string) $row['source_key'], (string) $row['project_name'], $ts];
            }
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        $insert = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (source_key, project_name) VALUES (?, ?)');
        foreach ($latest as [$sourceKey, $projectName, $ts]) {
            if (($this->fromTs !== null && $ts < $this->fromTs) || ($this->toTs !== null && $ts > $this->toTs)) {
                continue;
            }
            $insert->execute([$sourceKey, $projectName]);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    }
}
