<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

/**
 * Per-user favorites for SharePoint catalog sources and project folders.
 * Keyed by user + scope + source + project so they survive resync.
 */
final class SharePointFavoriteRepository
{
    public const SCOPE_SOURCE = 'source';
    public const SCOPE_PROJECT = 'project';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array{favorited: bool, scope: string, source_key: string, project_name: string}
     */
    public function setFavorite(
        int $userId,
        string $scope,
        string $sourceKey,
        bool $favorited,
        string $projectName = ''
    ): array {
        if ($userId <= 0) {
            throw new \RuntimeException('Sign in to manage favorites.');
        }

        $sourceKey = trim($sourceKey);
        $projectName = trim($projectName);
        $scope = $this->normalizeScope($scope);

        if ($sourceKey === '') {
            throw new \RuntimeException('Source is required.');
        }
        if ($scope === self::SCOPE_SOURCE) {
            $projectName = '';
        } elseif ($projectName === '') {
            throw new \RuntimeException('Project name is required.');
        }

        if ($favorited) {
            $statement = $this->pdo->prepare(
                'INSERT INTO user_catalog_favorites (
                    user_id, scope, source_key, project_name, created_at
                 ) VALUES (
                    :user_id, :scope, :source_key, :project_name, datetime(\'now\')
                 )
                 ON CONFLICT(user_id, scope, source_key, project_name) DO UPDATE SET
                    created_at = excluded.created_at'
            );
            $statement->execute([
                ':user_id' => $userId,
                ':scope' => $scope,
                ':source_key' => $sourceKey,
                ':project_name' => $projectName,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'DELETE FROM user_catalog_favorites
                 WHERE user_id = :user_id
                   AND scope = :scope
                   AND source_key = :source_key
                   AND project_name = :project_name'
            );
            $statement->execute([
                ':user_id' => $userId,
                ':scope' => $scope,
                ':source_key' => $sourceKey,
                ':project_name' => $projectName,
            ]);
        }

        return [
            'favorited' => $favorited,
            'scope' => $scope,
            'source_key' => $sourceKey,
            'project_name' => $projectName,
        ];
    }

    /**
     * @return array{
     *   sources: array<string, true>,
     *   projects: array<string, true>
     * }
     */
    public function indexForUser(int $userId): array
    {
        $index = [
            'sources' => [],
            'projects' => [],
        ];
        if ($userId <= 0) {
            return $index;
        }

        $statement = $this->pdo->prepare(
            'SELECT scope, source_key, project_name
             FROM user_catalog_favorites
             WHERE user_id = :user_id'
        );
        $statement->execute([':user_id' => $userId]);

        foreach ($statement->fetchAll() ?: [] as $row) {
            $scope = $this->normalizeScope((string) ($row['scope'] ?? ''));
            $sourceKey = trim((string) ($row['source_key'] ?? ''));
            $projectName = trim((string) ($row['project_name'] ?? ''));
            if ($sourceKey === '') {
                continue;
            }
            if ($scope === self::SCOPE_SOURCE) {
                $index['sources'][$sourceKey] = true;
                continue;
            }
            if ($projectName !== '') {
                $index['projects'][$this->projectKey($sourceKey, $projectName)] = true;
            }
        }

        return $index;
    }

    /**
     * @param array{sources: array<string, true>, projects: array<string, true>} $index
     */
    public function isSourceFavorite(string $sourceKey, array $index): bool
    {
        return isset($index['sources'][trim($sourceKey)]);
    }

    /**
     * @param array{sources: array<string, true>, projects: array<string, true>} $index
     */
    public function isProjectFavorite(string $sourceKey, string $projectName, array $index): bool
    {
        return isset($index['projects'][$this->projectKey($sourceKey, $projectName)]);
    }

    /**
     * @param list<array<string, mixed>> $projects
     * @param array{sources: array<string, true>, projects: array<string, true>} $index
     * @return list<array<string, mixed>>
     */
    public function attachToSearchIndex(array $projects, array $index): array
    {
        foreach ($projects as &$project) {
            $sourceKey = (string) ($project['source_key'] ?? '');
            $projectName = (string) ($project['project_name'] ?? '');
            $project['favorited'] = $this->isProjectFavorite($sourceKey, $projectName, $index);
        }
        unset($project);

        return $projects;
    }

    /**
     * @param array<string, mixed> $detail
     * @param array{sources: array<string, true>, projects: array<string, true>} $index
     * @return array<string, mixed>
     */
    public function attachToProjectDetail(array $detail, string $sourceKey, array $index): array
    {
        $projectName = (string) ($detail['project_name'] ?? '');
        $detail['favorited'] = $this->isProjectFavorite($sourceKey, $projectName, $index);

        return $detail;
    }

    public function deleteForSource(string $sourceKey): void
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return;
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM user_catalog_favorites WHERE source_key = :source_key'
        );
        $statement->execute([':source_key' => $sourceKey]);
    }

    /**
     * Add selected favorites for one user (upsert). Cap at 500.
     *
     * @param list<array{scope?: string, source_key?: string, project_name?: string}> $items
     * @return int Number of rows inserted or refreshed
     */
    public function setMany(int $userId, array $items): int
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Sign in to manage favorites.');
        }

        $added = 0;
        $seen = [];
        $limit = 500;
        $statement = $this->pdo->prepare(
            'INSERT INTO user_catalog_favorites (
                user_id, scope, source_key, project_name, created_at
             ) VALUES (
                :user_id, :scope, :source_key, :project_name, datetime(\'now\')
             )
             ON CONFLICT(user_id, scope, source_key, project_name) DO UPDATE SET
                created_at = excluded.created_at'
        );

        foreach ($items as $item) {
            if ($added >= $limit) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $sourceKey = trim((string) ($item['source_key'] ?? ''));
            if ($sourceKey === '') {
                continue;
            }
            $scope = $this->normalizeScope((string) ($item['scope'] ?? self::SCOPE_PROJECT));
            $projectName = $scope === self::SCOPE_SOURCE
                ? ''
                : trim((string) ($item['project_name'] ?? ''));
            if ($scope === self::SCOPE_PROJECT && $projectName === '') {
                continue;
            }
            $dedupe = $scope . "\0" . $sourceKey . "\0" . $projectName;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $statement->execute([
                ':user_id' => $userId,
                ':scope' => $scope,
                ':source_key' => $sourceKey,
                ':project_name' => $projectName,
            ]);
            $added += 1;
        }

        return $added;
    }

    /**
     * Remove selected favorites for one user. Does not require the SharePoint
     * source/project to still exist (orphaned stars can be cleared).
     *
     * @param list<array{scope?: string, source_key?: string, project_name?: string}> $items
     * @return int Number of rows deleted
     */
    public function unsetMany(int $userId, array $items): int
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Sign in to manage favorites.');
        }

        $removed = 0;
        $seen = [];
        $limit = 500;
        $statement = $this->pdo->prepare(
            'DELETE FROM user_catalog_favorites
             WHERE user_id = :user_id
               AND scope = :scope
               AND source_key = :source_key
               AND project_name = :project_name'
        );

        foreach ($items as $item) {
            if ($removed >= $limit) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $sourceKey = trim((string) ($item['source_key'] ?? ''));
            if ($sourceKey === '') {
                continue;
            }
            $scope = $this->normalizeScope((string) ($item['scope'] ?? self::SCOPE_PROJECT));
            $projectName = $scope === self::SCOPE_SOURCE
                ? ''
                : trim((string) ($item['project_name'] ?? ''));
            if ($scope === self::SCOPE_PROJECT && $projectName === '') {
                continue;
            }
            $dedupe = $scope . "\0" . $sourceKey . "\0" . $projectName;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $statement->execute([
                ':user_id' => $userId,
                ':scope' => $scope,
                ':source_key' => $sourceKey,
                ':project_name' => $projectName,
            ]);
            $removed += (int) $statement->rowCount();
        }

        return $removed;
    }

    /**
     * Clear all favorites for one user, optionally limited by scope.
     *
     * @param 'project'|'source'|'all' $scope
     * @return int Number of rows deleted
     */
    public function unsetAllForUser(int $userId, string $scope = 'all'): int
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Sign in to manage favorites.');
        }

        $scope = strtolower(trim($scope));
        if ($scope === 'all' || $scope === '') {
            $statement = $this->pdo->prepare(
                'DELETE FROM user_catalog_favorites WHERE user_id = :user_id'
            );
            $statement->execute([':user_id' => $userId]);

            return (int) $statement->rowCount();
        }

        $normalized = $this->normalizeScope($scope);
        $statement = $this->pdo->prepare(
            'DELETE FROM user_catalog_favorites
             WHERE user_id = :user_id
               AND scope = :scope'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':scope' => $normalized,
        ]);

        return (int) $statement->rowCount();
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === self::SCOPE_SOURCE || $scope === 'folder' || $scope === 'catalog') {
            return self::SCOPE_SOURCE;
        }

        return self::SCOPE_PROJECT;
    }

    private function projectKey(string $sourceKey, string $projectName): string
    {
        return trim($sourceKey) . "\0" . trim($projectName);
    }
}
