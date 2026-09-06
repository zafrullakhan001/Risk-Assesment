<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

/**
 * Soft-archive / ignore flags for SharePoint catalogs, projects, and files.
 * Keyed by source + scope + project + relative path so they survive resync.
 */
final class SharePointArchiveRepository
{
    public const SCOPE_SOURCE = 'source';
    public const SCOPE_PROJECT = 'project';
    public const SCOPE_ITEM = 'item';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param array{id?: int, username?: string}|null $actor
     * @return array{archived: bool, scope: string, source_key: string, project_name: string, relative_path: string}
     */
    public function setArchived(
        string $sourceKey,
        string $scope,
        bool $archived,
        string $projectName = '',
        string $relativePath = '',
        ?array $actor = null
    ): array {
        $sourceKey = trim($sourceKey);
        $projectName = trim($projectName);
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        $scope = $this->normalizeScope($scope);

        if ($sourceKey === '') {
            throw new \RuntimeException('Source is required.');
        }
        if ($scope === self::SCOPE_SOURCE) {
            $projectName = '';
            $relativePath = '';
        } elseif ($scope === self::SCOPE_PROJECT) {
            if ($projectName === '') {
                throw new \RuntimeException('Project name is required.');
            }
            $relativePath = '';
        } else {
            if ($projectName === '' || $relativePath === '') {
                throw new \RuntimeException('Project and file/folder path are required.');
            }
        }

        if ($archived) {
            $username = trim((string) ($actor['username'] ?? ''));
            $userId = isset($actor['id']) ? (int) $actor['id'] : 0;
            $statement = $this->pdo->prepare(
                'INSERT INTO sharepoint_archives (
                    source_key, scope, project_name, relative_path,
                    archived_by_user_id, archived_by_username, archived_at
                 ) VALUES (
                    :source_key, :scope, :project_name, :relative_path,
                    :archived_by_user_id, :archived_by_username, datetime(\'now\')
                 )
                 ON CONFLICT(source_key, scope, project_name, relative_path) DO UPDATE SET
                    archived_by_user_id = excluded.archived_by_user_id,
                    archived_by_username = excluded.archived_by_username,
                    archived_at = datetime(\'now\')'
            );
            $statement->execute([
                ':source_key' => $sourceKey,
                ':scope' => $scope,
                ':project_name' => $projectName,
                ':relative_path' => $relativePath,
                ':archived_by_user_id' => $userId > 0 ? $userId : null,
                ':archived_by_username' => $username,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'DELETE FROM sharepoint_archives
                 WHERE source_key = :source_key
                   AND scope = :scope
                   AND project_name = :project_name
                   AND relative_path = :relative_path'
            );
            $statement->execute([
                ':source_key' => $sourceKey,
                ':scope' => $scope,
                ':project_name' => $projectName,
                ':relative_path' => $relativePath,
            ]);
        }

        return [
            'archived' => $archived,
            'scope' => $scope,
            'source_key' => $sourceKey,
            'project_name' => $projectName,
            'relative_path' => $relativePath,
        ];
    }

    /**
     * @return array{
     *   sources: array<string, true>,
     *   projects: array<string, true>,
     *   items: array<string, true>,
     *   item_folders: array<string, list<string>>
     * }
     */
    public function indexForSources(array $sourceKeys): array
    {
        $index = [
            'sources' => [],
            'projects' => [],
            'items' => [],
            'item_folders' => [],
        ];
        $sourceKeys = array_values(array_filter(array_map('trim', $sourceKeys)));
        if ($sourceKeys === []) {
            return $index;
        }

        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT source_key, scope, project_name, relative_path
             FROM sharepoint_archives
             WHERE source_key IN ($placeholders)"
        );
        $statement->execute($sourceKeys);

        foreach ($statement->fetchAll() ?: [] as $row) {
            $sourceKey = (string) ($row['source_key'] ?? '');
            $scope = $this->normalizeScope((string) ($row['scope'] ?? ''));
            $projectName = (string) ($row['project_name'] ?? '');
            $relativePath = trim(str_replace('\\', '/', (string) ($row['relative_path'] ?? '')));
            if ($sourceKey === '') {
                continue;
            }
            if ($scope === self::SCOPE_SOURCE) {
                $index['sources'][$sourceKey] = true;
                continue;
            }
            if ($scope === self::SCOPE_PROJECT) {
                if ($projectName !== '') {
                    $index['projects'][$this->projectKey($sourceKey, $projectName)] = true;
                }
                continue;
            }
            if ($projectName === '' || $relativePath === '') {
                continue;
            }
            $itemKey = $this->itemKey($sourceKey, $projectName, $relativePath);
            $index['items'][$itemKey] = true;
            $folderKey = $this->projectKey($sourceKey, $projectName);
            $index['item_folders'][$folderKey][] = $relativePath;
        }

        return $index;
    }

    /**
     * @return array<string, true>
     */
    public function archivedSourceKeySet(array $sourceKeys = []): array
    {
        if ($sourceKeys === []) {
            $statement = $this->pdo->query(
                "SELECT source_key FROM sharepoint_archives WHERE scope = 'source'"
            );
            $rows = $statement === false ? [] : ($statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $out = [];
            foreach ($rows as $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $out[$key] = true;
                }
            }

            return $out;
        }

        return $this->indexForSources($sourceKeys)['sources'];
    }

    /**
     * @param array{
     *   sources: array<string, true>,
     *   projects: array<string, true>,
     *   items: array<string, true>,
     *   item_folders: array<string, list<string>>
     * } $index
     */
    public function isSourceArchived(string $sourceKey, array $index): bool
    {
        return isset($index['sources'][trim($sourceKey)]);
    }

    /**
     * @param array{
     *   sources: array<string, true>,
     *   projects: array<string, true>,
     *   items: array<string, true>,
     *   item_folders: array<string, list<string>>
     * } $index
     */
    public function isProjectArchived(string $sourceKey, string $projectName, array $index): bool
    {
        $sourceKey = trim($sourceKey);
        $projectName = trim($projectName);
        if ($sourceKey === '' || $projectName === '') {
            return false;
        }
        if (isset($index['sources'][$sourceKey])) {
            return true;
        }

        return isset($index['projects'][$this->projectKey($sourceKey, $projectName)]);
    }

    /**
     * @param array{
     *   sources: array<string, true>,
     *   projects: array<string, true>,
     *   items: array<string, true>,
     *   item_folders: array<string, list<string>>
     * } $index
     * @return array{archived: bool, archived_direct: bool, archive_scope: string}
     */
    public function itemArchiveState(
        string $sourceKey,
        string $projectName,
        string $relativePath,
        array $index
    ): array {
        $sourceKey = trim($sourceKey);
        $projectName = trim($projectName);
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($sourceKey === '') {
            return ['archived' => false, 'archived_direct' => false, 'archive_scope' => ''];
        }
        if (isset($index['sources'][$sourceKey])) {
            return ['archived' => true, 'archived_direct' => false, 'archive_scope' => self::SCOPE_SOURCE];
        }
        if ($projectName !== '' && isset($index['projects'][$this->projectKey($sourceKey, $projectName)])) {
            return ['archived' => true, 'archived_direct' => false, 'archive_scope' => self::SCOPE_PROJECT];
        }
        if ($relativePath === '') {
            return ['archived' => false, 'archived_direct' => false, 'archive_scope' => ''];
        }
        $itemKey = $this->itemKey($sourceKey, $projectName, $relativePath);
        if (isset($index['items'][$itemKey])) {
            return ['archived' => true, 'archived_direct' => true, 'archive_scope' => self::SCOPE_ITEM];
        }
        $folderKey = $this->projectKey($sourceKey, $projectName);
        foreach ($index['item_folders'][$folderKey] ?? [] as $archivedPath) {
            $archivedPath = trim(str_replace('\\', '/', (string) $archivedPath));
            if ($archivedPath === '') {
                continue;
            }
            if ($relativePath === $archivedPath || str_starts_with($relativePath, $archivedPath . '/')) {
                return [
                    'archived' => true,
                    'archived_direct' => $relativePath === $archivedPath,
                    'archive_scope' => self::SCOPE_ITEM,
                ];
            }
        }

        return ['archived' => false, 'archived_direct' => false, 'archive_scope' => ''];
    }

    /**
     * @param list<array<string, mixed>> $projects
     * @return list<array<string, mixed>>
     */
    public function attachToSearchIndex(array $projects, array $index): array
    {
        foreach ($projects as &$project) {
            $sourceKey = (string) ($project['source_key'] ?? '');
            $projectName = (string) ($project['project_name'] ?? '');
            $sourceArchived = $this->isSourceArchived($sourceKey, $index);
            $projectDirect = isset($index['projects'][$this->projectKey($sourceKey, $projectName)]);
            $project['archived'] = $sourceArchived || $projectDirect;
            $project['archived_direct'] = $projectDirect && !$sourceArchived;
            $project['archive_scope'] = $sourceArchived
                ? self::SCOPE_SOURCE
                : ($projectDirect ? self::SCOPE_PROJECT : '');

            $archivedFiles = 0;
            $archivedFolders = 0;
            if (isset($project['files']) && is_array($project['files'])) {
                foreach ($project['files'] as &$file) {
                    $path = (string) ($file['path'] ?? '');
                    $state = $this->itemArchiveState($sourceKey, $projectName, $path, $index);
                    $file['archived'] = $state['archived'];
                    $file['archived_direct'] = $state['archived_direct'];
                    $file['archive_scope'] = $state['archive_scope'];
                    if ($state['archived']) {
                        $archivedFiles++;
                    }
                }
                unset($file);
            }
            if (isset($project['folders']) && is_array($project['folders'])) {
                foreach ($project['folders'] as &$folder) {
                    $path = (string) ($folder['path'] ?? '');
                    $state = $this->itemArchiveState($sourceKey, $projectName, $path, $index);
                    $folder['archived'] = $state['archived'];
                    $folder['archived_direct'] = $state['archived_direct'];
                    $folder['archive_scope'] = $state['archive_scope'];
                    if ($state['archived']) {
                        $archivedFolders++;
                    }
                }
                unset($folder);
            }
            $project['archived_file_count'] = $archivedFiles;
            $project['archived_folder_count'] = $archivedFolders;
        }
        unset($project);

        return $projects;
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public function attachToProjectDetail(array $detail, string $sourceKey, array $index): array
    {
        $projectName = (string) ($detail['project_name'] ?? '');
        $sourceArchived = $this->isSourceArchived($sourceKey, $index);
        $projectDirect = isset($index['projects'][$this->projectKey($sourceKey, $projectName)]);
        $detail['archived'] = $sourceArchived || $projectDirect;
        $detail['archived_direct'] = $projectDirect && !$sourceArchived;
        $detail['archive_scope'] = $sourceArchived
            ? self::SCOPE_SOURCE
            : ($projectDirect ? self::SCOPE_PROJECT : '');

        if (isset($detail['items']) && is_array($detail['items'])) {
            foreach ($detail['items'] as &$item) {
                $path = trim((string) ($item['relative_path'] ?? ''));
                if ($path === '') {
                    $path = trim((string) ($item['name'] ?? ''));
                }
                $state = $this->itemArchiveState($sourceKey, $projectName, $path, $index);
                $item['archived'] = $state['archived'];
                $item['archived_direct'] = $state['archived_direct'];
                $item['archive_scope'] = $state['archive_scope'];
            }
            unset($item);
        }

        return $detail;
    }

    /**
     * Hide archived catalogs/projects and drop archived files from an assessment match.
     *
     * @param array<string, mixed>|null $detail
     * @return array<string, mixed>|null
     */
    public function applyToAssessmentMatch(?array $detail, string $sourceKey): ?array
    {
        if ($detail === null) {
            return null;
        }
        $sourceKey = trim($sourceKey) !== '' ? trim($sourceKey) : (string) ($detail['source_key'] ?? '');
        if ($sourceKey === '') {
            return $detail;
        }
        $index = $this->indexForSources([$sourceKey]);
        $projectName = (string) ($detail['project_name'] ?? '');
        if ($this->isProjectArchived($sourceKey, $projectName, $index)) {
            return null;
        }
        $detail = $this->attachToProjectDetail($detail, $sourceKey, $index);
        if (isset($detail['items']) && is_array($detail['items'])) {
            $detail['items'] = array_values(array_filter(
                $detail['items'],
                static fn (array $item): bool => empty($item['archived'])
            ));
        }

        return $detail;
    }

    /**
     * Drop archived projects (and optionally archived nested files) from a search index.
     *
     * @param list<array<string, mixed>> $projects
     * @return list<array<string, mixed>>
     */
    public function excludeArchivedFromSearchIndex(array $projects, bool $stripItems = true): array
    {
        $out = [];
        foreach ($projects as $project) {
            if (!empty($project['archived'])) {
                continue;
            }
            if ($stripItems) {
                $project = $this->stripArchivedEntries($project);
            }
            $out[] = $project;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    public function stripArchivedEntries(array $project): array
    {
        $visibleNames = [];
        $visiblePaths = [];
        if (isset($project['files']) && is_array($project['files'])) {
            $files = [];
            foreach ($project['files'] as $file) {
                if (!empty($file['archived'])) {
                    continue;
                }
                $files[] = $file;
                $name = trim((string) ($file['name'] ?? ''));
                $path = trim((string) ($file['path'] ?? ''));
                if ($name !== '') {
                    $visibleNames[$name] = true;
                }
                if ($path !== '') {
                    $visiblePaths[$path] = true;
                }
            }
            $project['files'] = $files;
            $project['file_count'] = count($files);
        }
        if (isset($project['folders']) && is_array($project['folders'])) {
            $folders = [];
            foreach ($project['folders'] as $folder) {
                if (!empty($folder['archived'])) {
                    continue;
                }
                $folders[] = $folder;
                $name = trim((string) ($folder['name'] ?? ''));
                $path = trim((string) ($folder['path'] ?? ''));
                if ($name !== '') {
                    $visibleNames[$name] = true;
                }
                if ($path !== '') {
                    $visiblePaths[$path] = true;
                }
            }
            $project['folders'] = $folders;
            $project['folder_count'] = count($folders);
        }
        $projectName = trim((string) ($project['project_name'] ?? ''));
        if ($projectName !== '') {
            $visibleNames[$projectName] = true;
        }
        if (isset($project['names']) && is_array($project['names'])) {
            $project['names'] = array_values(array_filter(
                $project['names'],
                static fn ($name): bool => isset($visibleNames[(string) $name]) || (string) $name === $projectName
            ));
        }
        if (isset($project['paths']) && is_array($project['paths'])) {
            $project['paths'] = array_values(array_filter(
                $project['paths'],
                static fn ($path): bool => isset($visiblePaths[(string) $path])
            ));
        }
        $project['item_count'] = (int) ($project['file_count'] ?? 0) + (int) ($project['folder_count'] ?? 0);
        $project['archived_file_count'] = 0;
        $project['archived_folder_count'] = 0;

        return $project;
    }

    /**
     * SQL fragment: keep rows whose source/project is not archived.
     */
    public static function visibleProjectSql(string $alias): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'sharepoint_items';

        return "NOT EXISTS (
            SELECT 1 FROM sharepoint_archives _spa
            WHERE _spa.source_key = {$alias}.source_key
              AND (
                _spa.scope = 'source'
                OR (_spa.scope = 'project' AND _spa.project_name = {$alias}.project_name)
              )
        )";
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === self::SCOPE_SOURCE || $scope === 'folder' || $scope === 'catalog') {
            return self::SCOPE_SOURCE;
        }
        if ($scope === self::SCOPE_ITEM || $scope === 'file') {
            return self::SCOPE_ITEM;
        }

        return self::SCOPE_PROJECT;
    }

    private function projectKey(string $sourceKey, string $projectName): string
    {
        return trim($sourceKey) . "\0" . trim($projectName);
    }

    private function itemKey(string $sourceKey, string $projectName, string $relativePath): string
    {
        return trim($sourceKey) . "\0" . trim($projectName) . "\0" . trim(str_replace('\\', '/', $relativePath));
    }
}
