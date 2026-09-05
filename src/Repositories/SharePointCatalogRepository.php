<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class SharePointCatalogRepository
{
    public const SOURCE_DEFAULT = 'default';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Replace all items for a source key in one transaction.
     *
     * @param list<array{
     *   item_key: string,
     *   parent_item_key?: string,
     *   project_name: string,
     *   name: string,
     *   item_type: string,
     *   web_url: string,
     *   relative_path?: string,
     *   mime_type?: string,
     *   size_bytes?: int,
     *   last_modified?: string,
     *   modified_by?: string,
     *   person?: string
     * }> $items
     */
    public function replaceForSource(string $sourceKey, array $items): int
    {
        $sourceKey = trim($sourceKey) !== '' ? trim($sourceKey) : self::SOURCE_DEFAULT;
        $syncedAt = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM sharepoint_items WHERE source_key = :source_key');
            $delete->execute([':source_key' => $sourceKey]);

            $insert = $this->pdo->prepare(
                'INSERT INTO sharepoint_items (
                    source_key, item_key, parent_item_key, project_name, name, item_type,
                    web_url, relative_path, mime_type, size_bytes, last_modified,
                    modified_by, person, synced_at
                ) VALUES (
                    :source_key, :item_key, :parent_item_key, :project_name, :name, :item_type,
                    :web_url, :relative_path, :mime_type, :size_bytes, :last_modified,
                    :modified_by, :person, :synced_at
                )'
            );

            $count = 0;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemKey = trim((string) ($item['item_key'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                $webUrl = trim((string) ($item['web_url'] ?? ''));
                $projectName = trim((string) ($item['project_name'] ?? ''));
                if ($itemKey === '' || $name === '' || $webUrl === '' || $projectName === '') {
                    continue;
                }
                if (!$this->isAllowedUrl($webUrl)) {
                    continue;
                }

                $itemType = strtolower(trim((string) ($item['item_type'] ?? 'file')));
                if ($itemType !== 'folder') {
                    $itemType = 'file';
                }

                $insert->execute([
                    ':source_key' => $sourceKey,
                    ':item_key' => mb_substr($itemKey, 0, 500),
                    ':parent_item_key' => mb_substr(trim((string) ($item['parent_item_key'] ?? '')), 0, 500),
                    ':project_name' => mb_substr($projectName, 0, 500),
                    ':name' => mb_substr($name, 0, 500),
                    ':item_type' => $itemType,
                    ':web_url' => mb_substr($webUrl, 0, 2000),
                    ':relative_path' => mb_substr(trim((string) ($item['relative_path'] ?? '')), 0, 2000),
                    ':mime_type' => mb_substr(trim((string) ($item['mime_type'] ?? '')), 0, 200),
                    ':size_bytes' => max(0, (int) ($item['size_bytes'] ?? 0)),
                    ':last_modified' => mb_substr(trim((string) ($item['last_modified'] ?? '')), 0, 64),
                    ':modified_by' => mb_substr(trim((string) ($item['modified_by'] ?? '')), 0, 300),
                    ':person' => mb_substr(trim((string) ($item['person'] ?? '')), 0, 300),
                    ':synced_at' => $syncedAt,
                ]);
                $count++;
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $count;
    }

    public function count(string $sourceKey = self::SOURCE_DEFAULT): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sharepoint_items WHERE source_key = :source_key'
        );
        $statement->execute([':source_key' => $sourceKey]);

        return (int) $statement->fetchColumn();
    }

    public function countProjects(string $sourceKey = self::SOURCE_DEFAULT): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT project_name) FROM sharepoint_items WHERE source_key = :source_key'
        );
        $statement->execute([':source_key' => $sourceKey]);

        return (int) $statement->fetchColumn();
    }

    public function countMatchingProjects(string $query, string $sourceKey = self::SOURCE_DEFAULT): int
    {
        $query = trim($query);
        if ($query === '') {
            return $this->countProjects($sourceKey);
        }

        $like = '%' . $this->escapeLike($query) . '%';
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT project_name)
             FROM sharepoint_items
             WHERE source_key = :source_key
               AND (
                   project_name LIKE :q ESCAPE \'\\\'
                   OR name LIKE :q2 ESCAPE \'\\\'
                   OR relative_path LIKE :q3 ESCAPE \'\\\'
                   OR modified_by LIKE :q4 ESCAPE \'\\\'
                   OR person LIKE :q5 ESCAPE \'\\\'
               )'
        );
        $statement->execute([
            ':source_key' => $sourceKey,
            ':q' => $like,
            ':q2' => $like,
            ':q3' => $like,
            ':q4' => $like,
            ':q5' => $like,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Paginated project-folder summaries for the catalog table.
     *
     * @return list<array{
     *   project_name: string,
     *   folder_url: string,
     *   item_count: int,
     *   file_count: int,
     *   folder_count: int,
     *   last_modified: string,
     *   modified_by: string,
     *   person: string
     * }>
     */
    public function listProjects(
        string $query,
        int $page = 1,
        int $perPage = 25,
        string $sourceKey = self::SOURCE_DEFAULT
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $query = trim($query);

        if ($query === '') {
            $projectsStmt = $this->pdo->prepare(
                'SELECT DISTINCT project_name
                 FROM sharepoint_items
                 WHERE source_key = :source_key
                 ORDER BY LOWER(project_name) ASC
                 LIMIT :lim OFFSET :off'
            );
            $projectsStmt->bindValue(':source_key', $sourceKey, PDO::PARAM_STR);
            $projectsStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $projectsStmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $projectsStmt->execute();
        } else {
            $like = '%' . $this->escapeLike($query) . '%';
            $projectsStmt = $this->pdo->prepare(
                'SELECT DISTINCT project_name
                 FROM sharepoint_items
                 WHERE source_key = :source_key
                   AND (
                       project_name LIKE :q ESCAPE \'\\\'
                       OR name LIKE :q2 ESCAPE \'\\\'
                       OR relative_path LIKE :q3 ESCAPE \'\\\'
                       OR modified_by LIKE :q4 ESCAPE \'\\\'
                       OR person LIKE :q5 ESCAPE \'\\\'
                   )
                 ORDER BY LOWER(project_name) ASC
                 LIMIT :lim OFFSET :off'
            );
            $projectsStmt->bindValue(':source_key', $sourceKey, PDO::PARAM_STR);
            $projectsStmt->bindValue(':q', $like, PDO::PARAM_STR);
            $projectsStmt->bindValue(':q2', $like, PDO::PARAM_STR);
            $projectsStmt->bindValue(':q3', $like, PDO::PARAM_STR);
            $projectsStmt->bindValue(':q4', $like, PDO::PARAM_STR);
            $projectsStmt->bindValue(':q5', $like, PDO::PARAM_STR);
            $projectsStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $projectsStmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $projectsStmt->execute();
        }

        $names = array_map(
            static fn ($row): string => (string) ($row['project_name'] ?? ''),
            $projectsStmt->fetchAll() ?: []
        );

        $summaries = [];
        foreach ($names as $projectName) {
            if ($projectName === '') {
                continue;
            }
            $summaries[] = $this->summarizeProject($projectName, $sourceKey);
        }

        return $summaries;
    }

    /**
     * @return array{
     *   project_name: string,
     *   folder_url: string,
     *   item_count: int,
     *   file_count: int,
     *   folder_count: int,
     *   last_modified: string,
     *   modified_by: string,
     *   person: string
     * }
     */
    public function summarizeProject(string $projectName, string $sourceKey = self::SOURCE_DEFAULT): array
    {
        $group = $this->groupForProject($projectName, $sourceKey);
        $items = $group['items'];
        $fileCount = 0;
        $folderCount = 0;
        $lastModified = '';
        $modifiedBy = '';
        $person = '';

        foreach ($items as $item) {
            if (($item['item_type'] ?? '') === 'folder') {
                $folderCount++;
            } else {
                $fileCount++;
            }
            $rel = trim((string) ($item['relative_path'] ?? ''));
            $isRoot = ($item['item_type'] ?? '') === 'folder'
                && ($rel === '' || $rel === $projectName || $rel === ($item['name'] ?? ''));
            if ($isRoot) {
                $lastModified = (string) ($item['last_modified'] ?? '');
                $modifiedBy = (string) ($item['modified_by'] ?? '');
                $person = (string) ($item['person'] ?? '');
            }
        }

        // Fallback: newest modified among children if root has none.
        if ($lastModified === '') {
            foreach ($items as $item) {
                $candidate = (string) ($item['last_modified'] ?? '');
                if ($candidate !== '' && ($lastModified === '' || strcmp($candidate, $lastModified) > 0)) {
                    $lastModified = $candidate;
                    $modifiedBy = (string) ($item['modified_by'] ?? $modifiedBy);
                    if ($person === '') {
                        $person = (string) ($item['person'] ?? '');
                    }
                }
            }
        }

        return [
            'project_name' => $projectName,
            'folder_url' => (string) ($group['folder_url'] ?? ''),
            'item_count' => count($items),
            'file_count' => $fileCount,
            'folder_count' => $folderCount,
            'last_modified' => $lastModified,
            'modified_by' => $modifiedBy,
            'person' => $person,
        ];
    }

    /**
     * Lightweight project index for client-side LinkNest-style fuzzy search.
     *
     * @param list<array{source_key: string, title?: string}> $sources
     * @return list<array<string, mixed>>
     */
    public function listSearchIndexForSources(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            $key = trim((string) ($source['source_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $title = trim((string) ($source['title'] ?? $key));
            foreach ($this->listSearchIndex($key, $title) as $project) {
                $out[] = $project;
            }
        }

        return $out;
    }

    /**
     * @return list<array{
     *   project_name: string,
     *   source_key: string,
     *   source_title: string,
     *   folder_url: string,
     *   item_count: int,
     *   file_count: int,
     *   folder_count: int,
     *   last_modified: string,
     *   modified_by: string,
     *   person: string,
     *   names: list<string>,
     *   paths: list<string>
     * }>
     */
    public function listSearchIndex(string $sourceKey = self::SOURCE_DEFAULT, string $sourceTitle = ''): array
    {
        $sourceKey = trim($sourceKey) !== '' ? trim($sourceKey) : self::SOURCE_DEFAULT;
        $sourceTitle = trim($sourceTitle) !== '' ? trim($sourceTitle) : $sourceKey;

        $statement = $this->pdo->prepare(
            'SELECT project_name, name, item_type, relative_path, web_url,
                    last_modified, modified_by, person
             FROM sharepoint_items
             WHERE source_key = :source_key
             ORDER BY LOWER(project_name) ASC, id ASC'
        );
        $statement->execute([':source_key' => $sourceKey]);
        $rows = $statement->fetchAll() ?: [];

        /** @var array<string, array<string, mixed>> $projects */
        $projects = [];

        foreach ($rows as $row) {
            $projectName = trim((string) ($row['project_name'] ?? ''));
            if ($projectName === '') {
                continue;
            }

            if (!isset($projects[$projectName])) {
                $projects[$projectName] = [
                    'project_name' => $projectName,
                    'source_key' => $sourceKey,
                    'source_title' => $sourceTitle,
                    'folder_url' => '',
                    'item_count' => 0,
                    'file_count' => 0,
                    'folder_count' => 0,
                    'last_modified' => '',
                    'modified_by' => '',
                    'person' => '',
                    'names' => [],
                    'paths' => [],
                    '_name_set' => [],
                    '_path_set' => [],
                ];
            }

            $name = trim((string) ($row['name'] ?? ''));
            $path = trim((string) ($row['relative_path'] ?? ''));
            $itemType = strtolower((string) ($row['item_type'] ?? 'file')) === 'folder' ? 'folder' : 'file';
            $webUrl = trim((string) ($row['web_url'] ?? ''));

            $projects[$projectName]['item_count']++;
            if ($itemType === 'folder') {
                $projects[$projectName]['folder_count']++;
            } else {
                $projects[$projectName]['file_count']++;
            }

            if ($name !== '' && !isset($projects[$projectName]['_name_set'][$name])) {
                $projects[$projectName]['_name_set'][$name] = true;
                $projects[$projectName]['names'][] = $name;
            }
            if ($path !== '' && !isset($projects[$projectName]['_path_set'][$path])) {
                $projects[$projectName]['_path_set'][$path] = true;
                $projects[$projectName]['paths'][] = $path;
            }

            $isRoot = $itemType === 'folder'
                && ($path === '' || $path === $projectName || $path === $name);
            if ($isRoot && $webUrl !== '' && $projects[$projectName]['folder_url'] === '') {
                $projects[$projectName]['folder_url'] = $webUrl;
                $projects[$projectName]['last_modified'] = (string) ($row['last_modified'] ?? '');
                $projects[$projectName]['modified_by'] = (string) ($row['modified_by'] ?? '');
                $projects[$projectName]['person'] = (string) ($row['person'] ?? '');
            }

            if ($projects[$projectName]['folder_url'] === '' && $webUrl !== '' && $itemType === 'folder') {
                $projects[$projectName]['folder_url'] = $webUrl;
            }
        }

        $out = [];
        foreach ($projects as $project) {
            if ($project['last_modified'] === '') {
                // Keep empty; client shows em dash. Root metadata was preferred above.
            }
            unset($project['_name_set'], $project['_path_set']);
            $out[] = $project;
        }

        return $out;
    }

    /**
     * Search catalog; returns projects grouped with their items.
     *
     * @return list<array{project_name: string, folder_url: string, items: list<array<string, mixed>>}>
     */
    public function search(string $query, string $sourceKey = self::SOURCE_DEFAULT, int $limit = 50): array
    {
        $pageSize = max(1, min(200, $limit));
        $summaries = $this->listProjects($query, 1, $pageSize, $sourceKey);
        $groups = [];
        foreach ($summaries as $summary) {
            $groups[] = $this->groupForProject((string) $summary['project_name'], $sourceKey);
        }

        return $groups;
    }

    /**
     * Full project group (folder + nested items) for dialog / JSON.
     *
     * @return array{project_name: string, folder_url: string, items: list<array<string, mixed>>}|null
     */
    public function getProject(string $projectName, string $sourceKey = self::SOURCE_DEFAULT): ?array
    {
        $projectName = trim($projectName);
        if ($projectName === '') {
            return null;
        }

        $check = $this->pdo->prepare(
            'SELECT 1 FROM sharepoint_items
             WHERE source_key = :source_key AND project_name = :project_name
             LIMIT 1'
        );
        $check->execute([
            ':source_key' => $sourceKey,
            ':project_name' => $projectName,
        ]);
        if ($check->fetchColumn() === false) {
            return null;
        }

        return $this->groupForProject($projectName, $sourceKey);
    }

    /**
     * Find a matching project across every registered source (assessment linking).
     *
     * @return array{project_name: string, folder_url: string, items: list<array<string, mixed>>, source_key?: string}|null
     */
    public function findMatchingProjectAnySource(string $solutionName): ?array
    {
        $keysStmt = $this->pdo->query(
            'SELECT DISTINCT source_key FROM sharepoint_items ORDER BY source_key ASC'
        );
        $keys = $keysStmt ? ($keysStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        foreach ($keys as $key) {
            $found = $this->findMatchingProject($solutionName, (string) $key);
            if ($found !== null) {
                $found['source_key'] = (string) $key;

                return $found;
            }
        }

        return null;
    }

    /**
     * Find catalog items matching an assessment solution name.
     *
     * @return array{project_name: string, folder_url: string, items: list<array<string, mixed>>}|null
     */
    public function findMatchingProject(string $solutionName, string $sourceKey = self::SOURCE_DEFAULT): ?array
    {
        $normalized = $this->normalizeName($solutionName);
        if ($normalized === '') {
            return null;
        }

        // Exact normalized match (case-insensitive, collapsed whitespace).
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT project_name
             FROM sharepoint_items
             WHERE source_key = :source_key
               AND LOWER(REPLACE(REPLACE(REPLACE(TRIM(project_name), \'  \', \' \'), CHAR(9), \' \'), CHAR(160), \' \'))
                   = :normalized
             ORDER BY project_name ASC
             LIMIT 2'
        );
        $statement->execute([
            ':source_key' => $sourceKey,
            ':normalized' => $normalized,
        ]);
        $exact = $statement->fetchAll() ?: [];
        if (count($exact) === 1) {
            return $this->groupForProject((string) $exact[0]['project_name'], $sourceKey);
        }

        // Unique LIKE match when solution name is a prefix of the folder name.
        $like = $this->escapeLike($solutionName) . '%';
        $likeStmt = $this->pdo->prepare(
            'SELECT DISTINCT project_name
             FROM sharepoint_items
             WHERE source_key = :source_key
               AND project_name LIKE :q ESCAPE \'\\\' COLLATE NOCASE
             ORDER BY project_name ASC
             LIMIT 2'
        );
        $likeStmt->execute([
            ':source_key' => $sourceKey,
            ':q' => $like,
        ]);
        $fuzzy = $likeStmt->fetchAll() ?: [];
        if (count($fuzzy) === 1) {
            return $this->groupForProject((string) $fuzzy[0]['project_name'], $sourceKey);
        }

        return null;
    }

    /**
     * @return array{project_name: string, folder_url: string, items: list<array<string, mixed>>}
     */
    private function groupForProject(string $projectName, string $sourceKey): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, item_key, parent_item_key, project_name, name, item_type,
                    web_url, relative_path, mime_type, size_bytes, last_modified,
                    modified_by, person
             FROM sharepoint_items
             WHERE source_key = :source_key AND project_name = :project_name
             ORDER BY
                CASE WHEN relative_path = \'\' OR relative_path = name THEN 0 ELSE 1 END,
                CASE WHEN item_type = \'folder\' THEN 0 ELSE 1 END,
                LOWER(relative_path) ASC,
                LOWER(name) ASC,
                id ASC'
        );
        $statement->execute([
            ':source_key' => $sourceKey,
            ':project_name' => $projectName,
        ]);
        $rows = $statement->fetchAll() ?: [];

        $folderUrl = '';
        $items = [];
        foreach ($rows as $row) {
            $mapped = [
                'id' => (int) ($row['id'] ?? 0),
                'item_key' => (string) ($row['item_key'] ?? ''),
                'parent_item_key' => (string) ($row['parent_item_key'] ?? ''),
                'project_name' => (string) ($row['project_name'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'item_type' => (string) ($row['item_type'] ?? 'file'),
                'web_url' => (string) ($row['web_url'] ?? ''),
                'relative_path' => (string) ($row['relative_path'] ?? ''),
                'mime_type' => (string) ($row['mime_type'] ?? ''),
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'last_modified' => (string) ($row['last_modified'] ?? ''),
                'modified_by' => (string) ($row['modified_by'] ?? ''),
                'person' => (string) ($row['person'] ?? ''),
            ];
            $rel = trim($mapped['relative_path']);
            $isRootFolder = $mapped['item_type'] === 'folder'
                && ($rel === '' || $rel === $mapped['name'] || $rel === $projectName);
            if ($isRootFolder && $folderUrl === '' && $mapped['web_url'] !== '') {
                $folderUrl = $mapped['web_url'];
            }
            $items[] = $mapped;
        }

        if ($folderUrl === '') {
            foreach ($items as $item) {
                if (($item['item_type'] ?? '') === 'folder' && ($item['web_url'] ?? '') !== '') {
                    $folderUrl = (string) $item['web_url'];
                    break;
                }
            }
        }
        if ($folderUrl === '' && $items !== []) {
            $folderUrl = (string) ($items[0]['web_url'] ?? '');
        }

        return [
            'project_name' => $projectName,
            'folder_url' => $folderUrl,
            'items' => $items,
        ];
    }

    public function normalizeName(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = str_replace(["\xc2\xa0", "\t", "\r", "\n"], ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name);

        return mb_strtolower($name);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function isAllowedUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
