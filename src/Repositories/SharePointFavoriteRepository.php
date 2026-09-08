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
