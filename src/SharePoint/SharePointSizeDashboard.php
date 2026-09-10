<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use PDO;
use RiskAssessment\Repositories\SharePointArchiveRepository;

/**
 * Aggregates SharePoint catalog storage by source, project folder, and path
 * for treemap / heatmap visualization with drill-down.
 */
final class SharePointSizeDashboard
{
    private const LARGE_FILE_LIMIT = 25;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles keyed by source_key
     * @return array<string, mixed>
     */
    public function buildOverview(array $sourceKeys, array $sourceTitles = []): array
    {
        $sourceKeys = $this->normalizeKeys($sourceKeys);
        if ($sourceKeys === []) {
            return $this->emptyOverview([]);
        }

        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));

        $statement = $this->pdo->prepare(
            "SELECT source_key, project_name,
                    SUM(CASE WHEN lower(item_type) = 'file' THEN COALESCE(size_bytes, 0) ELSE 0 END) AS size_bytes,
                    SUM(CASE WHEN lower(item_type) = 'file' THEN 1 ELSE 0 END) AS file_count,
                    COUNT(DISTINCT project_name) AS project_count
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND {$visible}
             GROUP BY source_key, project_name
             HAVING size_bytes > 0 OR file_count > 0"
        );
        $statement->execute($sourceKeys);
        $rows = $statement->fetchAll() ?: [];

        $catalogs = [];
        $totalBytes = 0;
        $totalFiles = 0;
        $projectCount = 0;
        $largestBytes = 0;
        $largestLabel = '';

        foreach ($rows as $row) {
            $sourceKey = (string) ($row['source_key'] ?? '');
            $projectName = (string) ($row['project_name'] ?? '');
            $bytes = (int) ($row['size_bytes'] ?? 0);
            $files = (int) ($row['file_count'] ?? 0);
            if ($sourceKey === '' || $projectName === '') {
                continue;
            }

            if (!isset($catalogs[$sourceKey])) {
                $catalogs[$sourceKey] = [
                    'key' => $sourceKey,
                    'label' => (string) ($sourceTitles[$sourceKey] ?? $sourceKey),
                    'type' => 'catalog',
                    'size_bytes' => 0,
                    'file_count' => 0,
                    'project_count' => 0,
                    'hue' => $this->hue($sourceKey),
                ];
            }

            $catalogs[$sourceKey]['size_bytes'] += $bytes;
            $catalogs[$sourceKey]['file_count'] += $files;
            $catalogs[$sourceKey]['project_count']++;
            $totalBytes += $bytes;
            $totalFiles += $files;
            $projectCount++;

            if ($bytes > $largestBytes) {
                $largestBytes = $bytes;
                $largestLabel = $projectName;
            }
        }

        $nodes = array_values($catalogs);
        usort(
            $nodes,
            static fn (array $a, array $b): int => ((int) $b['size_bytes']) <=> ((int) $a['size_bytes'])
        );

        $sources = [];
        foreach ($sourceKeys as $key) {
            $sources[] = [
                'source_key' => $key,
                'title' => (string) ($sourceTitles[$key] ?? $key),
            ];
        }

        return [
            'level' => 'overview',
            'sources' => $sources,
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'project_count' => $projectCount,
                'catalog_count' => count($nodes),
                'largest_bytes' => $largestBytes,
                'largest_label' => $largestLabel,
            ],
            'nodes' => $nodes,
            'large_files' => $this->topFiles($sourceKeys, null, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildProjects(string $sourceKey, string $sourceTitle = ''): array
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return $this->emptyProjects('', '');
        }

        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $statement = $this->pdo->prepare(
            "SELECT project_name,
                    SUM(CASE WHEN lower(item_type) = 'file' THEN COALESCE(size_bytes, 0) ELSE 0 END) AS size_bytes,
                    SUM(CASE WHEN lower(item_type) = 'file' THEN 1 ELSE 0 END) AS file_count,
                    SUM(CASE WHEN lower(item_type) = 'folder' THEN 1 ELSE 0 END) AS folder_count,
                    MAX(last_modified) AS last_modified
             FROM sharepoint_items
             WHERE source_key = :source_key
               AND {$visible}
             GROUP BY project_name
             HAVING size_bytes > 0 OR file_count > 0
             ORDER BY size_bytes DESC, LOWER(project_name) ASC"
        );
        $statement->execute([':source_key' => $sourceKey]);
        $rows = $statement->fetchAll() ?: [];

        $nodes = [];
        $totalBytes = 0;
        $totalFiles = 0;
        foreach ($rows as $row) {
            $projectName = trim((string) ($row['project_name'] ?? ''));
            if ($projectName === '') {
                continue;
            }
            $bytes = (int) ($row['size_bytes'] ?? 0);
            $files = (int) ($row['file_count'] ?? 0);
            $totalBytes += $bytes;
            $totalFiles += $files;
            $nodes[] = [
                'key' => $projectName,
                'label' => $projectName,
                'type' => 'project',
                'source_key' => $sourceKey,
                'size_bytes' => $bytes,
                'file_count' => $files,
                'folder_count' => (int) ($row['folder_count'] ?? 0),
                'last_modified' => trim((string) ($row['last_modified'] ?? '')),
                'hue' => $this->hue($sourceKey . "\n" . $projectName),
            ];
        }

        if ($sourceTitle === '') {
            $sourceTitle = $sourceKey;
        }

        return [
            'level' => 'projects',
            'source_key' => $sourceKey,
            'source_title' => $sourceTitle,
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'project_count' => count($nodes),
            ],
            'nodes' => $nodes,
            'large_files' => $this->topFiles([$sourceKey], null, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDrilldown(string $sourceKey, string $projectName, string $folderPath = ''): array
    {
        $sourceKey = trim($sourceKey);
        $projectName = trim($projectName);
        $folderPath = trim(str_replace('\\', '/', $folderPath));
        if ($sourceKey === '' || $projectName === '') {
            return $this->emptyDrilldown($sourceKey, $projectName, $folderPath);
        }

        if ($folderPath === '') {
            $folderPath = $projectName;
        }

        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $statement = $this->pdo->prepare(
            "SELECT name, item_type, relative_path, web_url, size_bytes, last_modified, mime_type
             FROM sharepoint_items
             WHERE source_key = :source_key
               AND project_name = :project_name
               AND {$visible}
             ORDER BY LOWER(relative_path) ASC, id ASC"
        );
        $statement->execute([
            ':source_key' => $sourceKey,
            ':project_name' => $projectName,
        ]);
        $rows = $statement->fetchAll() ?: [];

        $prefix = rtrim($folderPath, '/');
        $prefixLen = strlen($prefix);
        $childFolders = [];
        $childFiles = [];
        $folderUrl = '';

        foreach ($rows as $row) {
            $rel = trim(str_replace('\\', '/', (string) ($row['relative_path'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            $itemType = strtolower((string) ($row['item_type'] ?? 'file')) === 'folder' ? 'folder' : 'file';
            $webUrl = trim((string) ($row['web_url'] ?? ''));

            if ($rel === $projectName && $itemType === 'folder' && $folderUrl === '' && $webUrl !== '') {
                $folderUrl = $webUrl;
            }
            if ($rel === $prefix && $itemType === 'folder' && $webUrl !== '') {
                $folderUrl = $webUrl;
            }

            if ($rel !== $prefix && !str_starts_with($rel, $prefix . '/')) {
                continue;
            }

            $remainder = $rel === $prefix ? '' : substr($rel, $prefixLen + 1);
            if ($remainder === '') {
                continue;
            }

            $segments = explode('/', $remainder);
            $childName = (string) ($segments[0] ?? '');
            if ($childName === '') {
                continue;
            }

            if (count($segments) === 1 && $rel === $prefix . '/' . $childName) {
                if ($itemType === 'file') {
                    $childFiles[$childName] = [
                        'key' => $childName,
                        'label' => $childName,
                        'type' => 'file',
                        'path' => $rel,
                        'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                        'last_modified' => trim((string) ($row['last_modified'] ?? '')),
                        'mime_type' => trim((string) ($row['mime_type'] ?? '')),
                        'web_url' => $webUrl,
                    ];
                } else {
                    $childPath = $prefix . '/' . $childName;
                    if (!isset($childFolders[$childPath])) {
                        $childFolders[$childPath] = [
                            'key' => $childPath,
                            'label' => $childName,
                            'type' => 'folder',
                            'path' => $childPath,
                            'size_bytes' => 0,
                            'file_count' => 0,
                            'web_url' => $webUrl,
                            'hue' => $this->hue($sourceKey . "\n" . $childPath),
                        ];
                    }
                }
                continue;
            }

            if ($itemType === 'file') {
                $childPath = $prefix . '/' . $childName;
                if (!isset($childFolders[$childPath])) {
                    $childFolders[$childPath] = [
                        'key' => $childPath,
                        'label' => $childName,
                        'type' => 'folder',
                        'path' => $childPath,
                        'size_bytes' => 0,
                        'file_count' => 0,
                        'web_url' => '',
                        'hue' => $this->hue($sourceKey . "\n" . $childPath),
                    ];
                }
            }
        }

        foreach ($rows as $row) {
            if (strtolower((string) ($row['item_type'] ?? '')) !== 'file') {
                continue;
            }
            $rel = trim(str_replace('\\', '/', (string) ($row['relative_path'] ?? '')));
            if ($rel !== $prefix && !str_starts_with($rel, $prefix . '/')) {
                continue;
            }
            $bytes = (int) ($row['size_bytes'] ?? 0);
            if ($rel === $prefix) {
                continue;
            }
            $remainder = substr($rel, $prefixLen + 1);
            $segments = explode('/', $remainder);
            $childName = (string) ($segments[0] ?? '');
            if ($childName === '') {
                continue;
            }
            $childPath = $prefix . '/' . $childName;
            if (!isset($childFolders[$childPath])) {
                continue;
            }
            $childFolders[$childPath]['size_bytes'] += $bytes;
            $childFolders[$childPath]['file_count']++;
        }

        $nodes = array_merge(array_values($childFolders), array_values($childFiles));
        usort(
            $nodes,
            static function (array $a, array $b): int {
                $cmp = ((int) $b['size_bytes']) <=> ((int) $a['size_bytes']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                if (($a['type'] ?? '') === ($b['type'] ?? '')) {
                    return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
                }

                return ($a['type'] ?? '') === 'folder' ? -1 : 1;
            }
        );

        $totalBytes = 0;
        $totalFiles = 0;
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'file') {
                $totalBytes += (int) ($node['size_bytes'] ?? 0);
                $totalFiles++;
            } else {
                $totalBytes += (int) ($node['size_bytes'] ?? 0);
                $totalFiles += (int) ($node['file_count'] ?? 0);
            }
        }

        $breadcrumb = $this->breadcrumb($projectName, $folderPath);

        return [
            'level' => 'folder',
            'source_key' => $sourceKey,
            'project_name' => $projectName,
            'folder_path' => $folderPath,
            'folder_url' => $folderUrl,
            'breadcrumb' => $breadcrumb,
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'item_count' => count($nodes),
            ],
            'nodes' => $nodes,
            'large_files' => $this->topFiles([$sourceKey], $projectName, $folderPath),
        ];
    }

    /**
     * Find duplicate files (same name case-insensitive + same size) across catalogs.
     *
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles keyed by source_key
     * @return array<string, mixed>
     */
    public function buildDuplicateStats(array $sourceKeys, array $sourceTitles = []): array
    {
        $sourceKeys = $this->normalizeKeys($sourceKeys);
        if ($sourceKeys === []) {
            return $this->emptyDuplicates([]);
        }

        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));

        $statement = $this->pdo->prepare(
            "SELECT i.source_key, i.project_name, i.name, i.relative_path, i.web_url,
                    i.size_bytes, i.mime_type, i.last_modified
             FROM sharepoint_items i
             INNER JOIN (
                 SELECT LOWER(name) AS name_key, size_bytes
                 FROM sharepoint_items
                 WHERE source_key IN ($placeholders)
                   AND lower(item_type) = 'file'
                   AND {$visible}
                 GROUP BY LOWER(name), size_bytes
                 HAVING COUNT(*) > 1
             ) d ON LOWER(i.name) = d.name_key AND COALESCE(i.size_bytes, 0) = COALESCE(d.size_bytes, 0)
             WHERE i.source_key IN ($placeholders)
               AND lower(i.item_type) = 'file'
               AND " . SharePointArchiveRepository::visibleProjectSql('i') . '
             ORDER BY i.size_bytes DESC, LOWER(i.name) ASC, i.source_key ASC, LOWER(i.relative_path) ASC'
        );
        // Bind source keys twice: once for the subquery, once for the outer filter.
        $statement->execute(array_merge($sourceKeys, $sourceKeys));
        $rows = $statement->fetchAll() ?: [];

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        /** @var array<string, array{source_key: string, catalog_title: string, duplicate_count: int, occupied_space: int, wasted_space: int}> $byCatalog */
        $byCatalog = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $sizeBytes = (int) ($row['size_bytes'] ?? 0);
            $nameKey = strtolower($name);
            $groupKey = $nameKey . "\0" . $sizeBytes;
            $sourceKey = (string) ($row['source_key'] ?? '');
            if ($name === '' || $sourceKey === '') {
                continue;
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'file_name' => $name,
                    'size_bytes' => $sizeBytes,
                    'mime_type' => trim((string) ($row['mime_type'] ?? '')),
                    'occurrence_count' => 0,
                    'total_space' => 0,
                    'wasted_space' => 0,
                    'locations' => [],
                ];
            }

            $groups[$groupKey]['occurrence_count']++;
            $groups[$groupKey]['total_space'] += $sizeBytes;
            if ($groups[$groupKey]['mime_type'] === '' && trim((string) ($row['mime_type'] ?? '')) !== '') {
                $groups[$groupKey]['mime_type'] = trim((string) ($row['mime_type'] ?? ''));
            }
            $groups[$groupKey]['locations'][] = [
                'source_key' => $sourceKey,
                'catalog_title' => (string) ($sourceTitles[$sourceKey] ?? $sourceKey),
                'project_name' => (string) ($row['project_name'] ?? ''),
                'relative_path' => (string) ($row['relative_path'] ?? ''),
                'web_url' => trim((string) ($row['web_url'] ?? '')),
                'last_modified' => trim((string) ($row['last_modified'] ?? '')),
            ];

            if (!isset($byCatalog[$sourceKey])) {
                $byCatalog[$sourceKey] = [
                    'source_key' => $sourceKey,
                    'catalog_title' => (string) ($sourceTitles[$sourceKey] ?? $sourceKey),
                    'duplicate_count' => 0,
                    'occupied_space' => 0,
                    'wasted_space' => 0,
                ];
            }
            $byCatalog[$sourceKey]['duplicate_count']++;
            $byCatalog[$sourceKey]['occupied_space'] += $sizeBytes;
        }

        $duplicateGroups = [];
        $totalWasted = 0;
        $totalDuplicateFiles = 0;
        foreach ($groups as $group) {
            $count = (int) $group['occurrence_count'];
            $size = (int) $group['size_bytes'];
            $wasted = max(0, $count - 1) * $size;
            $group['wasted_space'] = $wasted;
            $totalWasted += $wasted;
            $totalDuplicateFiles += $count;

            // Attribute wasted space proportionally by how many copies each catalog holds.
            $locByCatalog = [];
            foreach ($group['locations'] as $loc) {
                $sk = (string) $loc['source_key'];
                $locByCatalog[$sk] = ($locByCatalog[$sk] ?? 0) + 1;
            }
            foreach ($locByCatalog as $sk => $localCount) {
                if (!isset($byCatalog[$sk]) || $count <= 0) {
                    continue;
                }
                $byCatalog[$sk]['wasted_space'] += (int) round($wasted * ($localCount / $count));
            }

            $duplicateGroups[] = $group;
        }

        usort(
            $duplicateGroups,
            static function (array $a, array $b): int {
                $cmp = ((int) $b['wasted_space']) <=> ((int) $a['wasted_space']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) ($a['file_name'] ?? ''), (string) ($b['file_name'] ?? ''));
            }
        );

        // Cap groups returned to keep the UI responsive; summary still reflects all.
        $returnedGroups = array_slice($duplicateGroups, 0, 500);

        $catalogList = array_values($byCatalog);
        usort(
            $catalogList,
            static fn (array $a, array $b): int => ((int) $b['occupied_space']) <=> ((int) $a['occupied_space'])
        );

        $sources = [];
        foreach ($sourceKeys as $key) {
            $sources[] = [
                'source_key' => $key,
                'title' => (string) ($sourceTitles[$key] ?? $key),
            ];
        }

        return [
            'level' => 'duplicates',
            'sources' => $sources,
            'summary' => [
                'total_duplicate_files' => $totalDuplicateFiles,
                'total_wasted_space' => $totalWasted,
                'duplicate_sets' => count($duplicateGroups),
                'catalogs_affected' => count($catalogList),
                'groups_returned' => count($returnedGroups),
            ],
            'by_catalog' => $catalogList,
            'duplicate_groups' => $returnedGroups,
        ];
    }

    /**
     * @param list<string> $sourceKeys
     * @return list<array<string, mixed>>
     */
    private function topFiles(array $sourceKeys, ?string $projectName, ?string $folderPath): array
    {
        $sourceKeys = $this->normalizeKeys($sourceKeys);
        if ($sourceKeys === []) {
            return [];
        }

        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $params = $sourceKeys;
        $extra = '';

        if ($projectName !== null && trim($projectName) !== '') {
            $extra .= ' AND project_name = ?';
            $params[] = trim($projectName);
        }
        if ($folderPath !== null && trim($folderPath) !== '') {
            $path = rtrim(trim(str_replace('\\', '/', $folderPath)), '/');
            $extra .= ' AND (relative_path = ? OR relative_path LIKE ? ESCAPE \'\\\')';
            $params[] = $path;
            $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path) . '/%';
        }

        $statement = $this->pdo->prepare(
            "SELECT source_key, project_name, name, relative_path, size_bytes, last_modified, web_url, mime_type
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND lower(item_type) = 'file'
               AND COALESCE(size_bytes, 0) > 0
               AND {$visible}
               {$extra}
             ORDER BY size_bytes DESC, LOWER(name) ASC
             LIMIT " . self::LARGE_FILE_LIMIT
        );
        $statement->execute($params);
        $out = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $out[] = [
                'source_key' => (string) ($row['source_key'] ?? ''),
                'project_name' => (string) ($row['project_name'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'relative_path' => (string) ($row['relative_path'] ?? ''),
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'last_modified' => trim((string) ($row['last_modified'] ?? '')),
                'web_url' => trim((string) ($row['web_url'] ?? '')),
                'mime_type' => trim((string) ($row['mime_type'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    private function breadcrumb(string $projectName, string $folderPath): array
    {
        $folderPath = trim(str_replace('\\', '/', $folderPath));
        $crumbs = [
            ['label' => $projectName, 'path' => $projectName],
        ];
        if ($folderPath === '' || $folderPath === $projectName) {
            return $crumbs;
        }

        $prefix = rtrim($projectName, '/') . '/';
        if (!str_starts_with($folderPath, $prefix)) {
            return $crumbs;
        }

        $rest = substr($folderPath, strlen($prefix));
        $segments = array_values(array_filter(explode('/', $rest), static fn (string $s): bool => $s !== ''));
        $path = $projectName;
        foreach ($segments as $segment) {
            $path .= '/' . $segment;
            $crumbs[] = ['label' => $segment, 'path' => $path];
        }

        return $crumbs;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function normalizeKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = $key;
        }

        return array_values($out);
    }

    private function hue(string $key): int
    {
        return abs(crc32($key)) % 360;
    }

    /**
     * @param list<array{source_key: string, title: string}> $sources
     * @return array<string, mixed>
     */
    private function emptyOverview(array $sources): array
    {
        return [
            'level' => 'overview',
            'sources' => $sources,
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'project_count' => 0,
                'catalog_count' => 0,
                'largest_bytes' => 0,
                'largest_label' => '',
            ],
            'nodes' => [],
            'large_files' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProjects(string $sourceKey, string $sourceTitle): array
    {
        return [
            'level' => 'projects',
            'source_key' => $sourceKey,
            'source_title' => $sourceTitle,
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'project_count' => 0,
            ],
            'nodes' => [],
            'large_files' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDrilldown(string $sourceKey, string $projectName, string $folderPath): array
    {
        return [
            'level' => 'folder',
            'source_key' => $sourceKey,
            'project_name' => $projectName,
            'folder_path' => $folderPath,
            'folder_url' => '',
            'breadcrumb' => $this->breadcrumb($projectName, $folderPath !== '' ? $folderPath : $projectName),
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'item_count' => 0,
            ],
            'nodes' => [],
            'large_files' => [],
        ];
    }

    /**
     * @param list<array{source_key: string, title: string}> $sources
     * @return array<string, mixed>
     */
    private function emptyDuplicates(array $sources): array
    {
        return [
            'level' => 'duplicates',
            'sources' => $sources,
            'summary' => [
                'total_duplicate_files' => 0,
                'total_wasted_space' => 0,
                'duplicate_sets' => 0,
                'catalogs_affected' => 0,
                'groups_returned' => 0,
            ],
            'by_catalog' => [],
            'duplicate_groups' => [],
        ];
    }
}
