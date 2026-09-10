<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use PDO;
use RiskAssessment\Repositories\SharePointArchiveRepository;

/**
 * Aggregates SharePoint catalog storage by project-folder owner (Created By)
 * for treemap visualization and concentration metrics.
 */
final class SharePointOwnerStorageDashboard
{
    public const UNASSIGNED_KEY = '_unassigned';
    public const UNASSIGNED_NAME = 'Unassigned';

    private const LARGE_FILE_LIMIT = 25;
    private const CONCENTRATION_WARN = 0.35;

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

        $projects = $this->collectProjectStorage($sourceKeys, $sourceTitles);
        if ($projects === []) {
            return $this->emptyOverview($this->sourceMeta($sourceKeys, $sourceTitles));
        }

        /** @var array<string, array<string, mixed>> $owners */
        $owners = [];
        $totalBytes = 0;
        $totalFiles = 0;
        $totalProjects = 0;

        foreach ($projects as $project) {
            $ownerKey = (string) $project['owner_key'];
            $ownerName = (string) $project['owner_name'];
            $bytes = (int) $project['size_bytes'];
            $files = (int) $project['file_count'];

            if (!isset($owners[$ownerKey])) {
                $owners[$ownerKey] = [
                    'key' => $ownerKey,
                    'owner_key' => $ownerKey,
                    'label' => $ownerName,
                    'type' => 'owner',
                    'size_bytes' => 0,
                    'file_count' => 0,
                    'project_count' => 0,
                    'avg_file_size' => 0,
                    'hue' => $this->hue($ownerKey),
                    'aliases' => [],
                    'sources' => [],
                ];
            }

            if ($ownerName !== '' && strcasecmp($ownerName, (string) $owners[$ownerKey]['label']) !== 0) {
                $owners[$ownerKey]['aliases'][$ownerName] = true;
            }
            $owners[$ownerKey]['label'] = $this->preferredDisplayName(
                (string) $owners[$ownerKey]['label'],
                $ownerName
            );

            $owners[$ownerKey]['size_bytes'] += $bytes;
            $owners[$ownerKey]['file_count'] += $files;
            $owners[$ownerKey]['project_count']++;

            $srcKey = (string) $project['source_key'];
            $owners[$ownerKey]['sources'][$srcKey] = ($owners[$ownerKey]['sources'][$srcKey] ?? 0) + 1;

            $totalBytes += $bytes;
            $totalFiles += $files;
            $totalProjects++;
        }

        $nodes = [];
        foreach ($owners as $owner) {
            $fileCount = (int) $owner['file_count'];
            $owner['avg_file_size'] = $fileCount > 0
                ? (int) round(((int) $owner['size_bytes']) / $fileCount)
                : 0;
            $owner['share'] = $totalBytes > 0
                ? round(((int) $owner['size_bytes']) / $totalBytes, 4)
                : 0.0;
            $owner['aliases'] = array_values(array_keys($owner['aliases']));
            $nodes[] = $owner;
        }

        usort(
            $nodes,
            static function (array $a, array $b): int {
                $cmp = ((int) $b['size_bytes']) <=> ((int) $a['size_bytes']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            }
        );

        $top = $nodes[0] ?? null;
        $topBytes = (int) ($top['size_bytes'] ?? 0);
        $concentration = $totalBytes > 0 ? round($topBytes / $totalBytes, 4) : 0.0;

        return [
            'level' => 'owner_storage',
            'sources' => $this->sourceMeta($sourceKeys, $sourceTitles),
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'project_count' => $totalProjects,
                'total_owners' => count($nodes),
                'avg_file_size' => $totalFiles > 0 ? (int) round($totalBytes / $totalFiles) : 0,
                'top_owner_name' => (string) ($top['label'] ?? ''),
                'top_owner_key' => (string) ($top['owner_key'] ?? ''),
                'top_owner_bytes' => $topBytes,
                'concentration_pct' => $concentration,
                'concentration_warn' => $concentration >= self::CONCENTRATION_WARN,
            ],
            'nodes' => $nodes,
            'large_files' => $this->topFilesWithOwners($sourceKeys, $sourceTitles, $projects),
        ];
    }

    /**
     * Drill-down: project folders for one owner, sized by storage.
     *
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles keyed by source_key
     * @return array<string, mixed>
     */
    public function buildOwnerProjects(array $sourceKeys, array $sourceTitles, string $ownerKey): array
    {
        $sourceKeys = $this->normalizeKeys($sourceKeys);
        $ownerKey = trim($ownerKey);
        if ($sourceKeys === [] || $ownerKey === '') {
            return $this->emptyOwnerProjects($this->sourceMeta($sourceKeys, $sourceTitles), $ownerKey, '');
        }

        $projects = $this->collectProjectStorage($sourceKeys, $sourceTitles);
        $owned = array_values(array_filter(
            $projects,
            static fn (array $project): bool => (string) ($project['owner_key'] ?? '') === $ownerKey
        ));

        $ownerName = $ownerKey === self::UNASSIGNED_KEY
            ? self::UNASSIGNED_NAME
            : (string) ($owned[0]['owner_name'] ?? $ownerKey);

        if ($owned === []) {
            return $this->emptyOwnerProjects($this->sourceMeta($sourceKeys, $sourceTitles), $ownerKey, $ownerName);
        }

        $nodes = [];
        $totalBytes = 0;
        $totalFiles = 0;
        foreach ($owned as $project) {
            $bytes = (int) ($project['size_bytes'] ?? 0);
            $files = (int) ($project['file_count'] ?? 0);
            $totalBytes += $bytes;
            $totalFiles += $files;
            $sourceKey = (string) $project['source_key'];
            $projectName = (string) $project['project_name'];
            $nodes[] = [
                'key' => $sourceKey . "\n" . $projectName,
                'label' => $projectName,
                'type' => 'project',
                'source_key' => $sourceKey,
                'source_title' => (string) ($project['source_title'] ?? $sourceKey),
                'project_name' => $projectName,
                'folder_url' => (string) ($project['folder_url'] ?? ''),
                'size_bytes' => $bytes,
                'file_count' => $files,
                'avg_file_size' => $files > 0 ? (int) round($bytes / $files) : 0,
                'owner_key' => $ownerKey,
                'owner_name' => (string) ($project['owner_name'] ?? $ownerName),
                'hue' => $this->hue($sourceKey . "\n" . $projectName),
            ];
        }

        usort(
            $nodes,
            static function (array $a, array $b): int {
                $cmp = ((int) $b['size_bytes']) <=> ((int) $a['size_bytes']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            }
        );

        return [
            'level' => 'owner_projects',
            'owner_key' => $ownerKey,
            'owner_name' => $ownerName,
            'sources' => $this->sourceMeta($sourceKeys, $sourceTitles),
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'project_count' => count($nodes),
                'total_owners' => 1,
                'avg_file_size' => $totalFiles > 0 ? (int) round($totalBytes / $totalFiles) : 0,
                'top_owner_name' => $ownerName,
                'top_owner_key' => $ownerKey,
                'top_owner_bytes' => $totalBytes,
                'concentration_pct' => 1.0,
                'concentration_warn' => false,
            ],
            'nodes' => $nodes,
            'large_files' => $this->topFilesWithOwners($sourceKeys, $sourceTitles, $owned, $ownerKey),
        ];
    }

    /**
     * Collect per-project storage with the same owner attribution as the owner dashboard
     * (root folder Created By / person, else most common person in the project).
     *
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return list<array<string, mixed>>
     */
    private function collectProjectStorage(array $sourceKeys, array $sourceTitles): array
    {
        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));

        $statsStmt = $this->pdo->prepare(
            "SELECT source_key, project_name,
                    SUM(CASE WHEN lower(item_type) = 'file' THEN COALESCE(size_bytes, 0) ELSE 0 END) AS size_bytes,
                    SUM(CASE WHEN lower(item_type) = 'file' THEN 1 ELSE 0 END) AS file_count
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND {$visible}
             GROUP BY source_key, project_name
             HAVING size_bytes > 0 OR file_count > 0"
        );
        $statsStmt->execute($sourceKeys);
        $statsRows = $statsStmt->fetchAll() ?: [];
        if ($statsRows === []) {
            return [];
        }

        $rootStmt = $this->pdo->prepare(
            "SELECT source_key, project_name, name, relative_path, person, web_url
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND lower(item_type) = 'folder'
               AND (
                    relative_path = ''
                    OR relative_path = project_name
                    OR relative_path = name
               )
               AND {$visible}
             ORDER BY source_key ASC, LOWER(project_name) ASC, id ASC"
        );
        $rootStmt->execute($sourceKeys);
        $rootPerson = [];
        $rootFolderUrl = [];
        foreach ($rootStmt->fetchAll() ?: [] as $row) {
            $groupKey = (string) ($row['source_key'] ?? '') . "\n" . (string) ($row['project_name'] ?? '');
            if ($groupKey === "\n") {
                continue;
            }
            if (!isset($rootPerson[$groupKey])) {
                $person = trim((string) ($row['person'] ?? ''));
                if ($person !== '') {
                    $rootPerson[$groupKey] = $person;
                }
            }
            if (!isset($rootFolderUrl[$groupKey])) {
                $webUrl = trim((string) ($row['web_url'] ?? ''));
                if ($webUrl !== '') {
                    $rootFolderUrl[$groupKey] = $webUrl;
                }
            }
        }

        $peopleStmt = $this->pdo->prepare(
            "SELECT source_key, project_name, person, COUNT(*) AS n
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND person <> ''
               AND {$visible}
             GROUP BY source_key, project_name, person"
        );
        $peopleStmt->execute($sourceKeys);
        $peopleByGroup = [];
        foreach ($peopleStmt->fetchAll() ?: [] as $row) {
            $groupKey = (string) ($row['source_key'] ?? '') . "\n" . (string) ($row['project_name'] ?? '');
            $person = trim((string) ($row['person'] ?? ''));
            if ($person === '') {
                continue;
            }
            $peopleByGroup[$groupKey][$person] = (int) ($row['n'] ?? 1);
        }

        $out = [];
        foreach ($statsRows as $row) {
            $sourceKey = trim((string) ($row['source_key'] ?? ''));
            $projectName = trim((string) ($row['project_name'] ?? ''));
            if ($sourceKey === '' || $projectName === '') {
                continue;
            }
            $groupKey = $sourceKey . "\n" . $projectName;

            $ownerName = trim((string) ($rootPerson[$groupKey] ?? ''));
            if ($ownerName === '' && !empty($peopleByGroup[$groupKey])) {
                arsort($peopleByGroup[$groupKey]);
                $ownerName = (string) array_key_first($peopleByGroup[$groupKey]);
            }

            $ownerKey = $this->ownerKey($ownerName);
            $displayName = $ownerName !== ''
                ? $this->preferredDisplayName($ownerName, $ownerName)
                : self::UNASSIGNED_NAME;

            $out[] = [
                'project_name' => $projectName,
                'source_key' => $sourceKey,
                'source_title' => (string) ($sourceTitles[$sourceKey] ?? $sourceKey),
                'folder_url' => (string) ($rootFolderUrl[$groupKey] ?? ''),
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'file_count' => (int) ($row['file_count'] ?? 0),
                'owner_key' => $ownerKey,
                'owner_name' => $displayName,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @param list<array<string, mixed>> $projects
     * @return list<array<string, mixed>>
     */
    private function topFilesWithOwners(
        array $sourceKeys,
        array $sourceTitles,
        array $projects,
        ?string $ownerKeyFilter = null
    ): array {
        $ownerByProject = [];
        foreach ($projects as $project) {
            $key = (string) $project['source_key'] . "\n" . (string) $project['project_name'];
            $ownerByProject[$key] = [
                'owner_key' => (string) $project['owner_key'],
                'owner_name' => (string) $project['owner_name'],
            ];
        }

        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT source_key, project_name, name, relative_path, size_bytes,
                    date_created, last_modified, web_url, mime_type
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND lower(item_type) = 'file'
               AND COALESCE(size_bytes, 0) > 0
               AND {$visible}
             ORDER BY size_bytes DESC, LOWER(name) ASC
             LIMIT 200"
        );
        $statement->execute($sourceKeys);

        $out = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $sourceKey = (string) ($row['source_key'] ?? '');
            $projectName = (string) ($row['project_name'] ?? '');
            $mapKey = $sourceKey . "\n" . $projectName;
            if (!isset($ownerByProject[$mapKey])) {
                continue;
            }
            $owner = $ownerByProject[$mapKey];
            if ($ownerKeyFilter !== null && $ownerKeyFilter !== '' && (string) $owner['owner_key'] !== $ownerKeyFilter) {
                continue;
            }
            $out[] = [
                'source_key' => $sourceKey,
                'source_title' => (string) ($sourceTitles[$sourceKey] ?? $sourceKey),
                'project_name' => $projectName,
                'name' => (string) ($row['name'] ?? ''),
                'relative_path' => (string) ($row['relative_path'] ?? ''),
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'date_created' => trim((string) ($row['date_created'] ?? '')),
                'last_modified' => trim((string) ($row['last_modified'] ?? '')),
                'web_url' => trim((string) ($row['web_url'] ?? '')),
                'mime_type' => trim((string) ($row['mime_type'] ?? '')),
                'owner_key' => $owner['owner_key'],
                'owner_name' => $owner['owner_name'],
            ];
            if (count($out) >= self::LARGE_FILE_LIMIT) {
                break;
            }
        }

        return $out;
    }

    private function ownerKey(string $name): string
    {
        $normalized = $this->canonicalName($name);
        if ($normalized === '') {
            return self::UNASSIGNED_KEY;
        }

        return $normalized;
    }

    private function canonicalName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, self::UNASSIGNED_NAME) === 0) {
            return '';
        }
        $name = preg_replace('/<[^>]+>/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = mb_strtolower(trim($name));
        if (preg_match('/^([^,]+),\s*(.+)$/u', $name, $match) === 1) {
            $name = trim($match[2] . ' ' . $match[1]);
        }

        return $name;
    }

    private function preferredDisplayName(string $current, string $candidate): string
    {
        $candidate = trim($candidate);
        $current = trim($current);
        if ($candidate === '') {
            return $current;
        }
        if ($current === '' || strcasecmp($current, self::UNASSIGNED_NAME) === 0) {
            return $this->prettyName($candidate);
        }
        $prettyCurrent = $this->prettyName($current);
        $prettyCandidate = $this->prettyName($candidate);
        $currentHasComma = str_contains($prettyCurrent, ',');
        $candidateHasComma = str_contains($prettyCandidate, ',');
        if ($currentHasComma && !$candidateHasComma) {
            return $prettyCandidate;
        }
        if (mb_strlen($prettyCandidate) > mb_strlen($prettyCurrent) + 2 && !$candidateHasComma) {
            return $prettyCandidate;
        }

        return $prettyCurrent;
    }

    private function prettyName(string $name): string
    {
        $name = trim(preg_replace('/<[^>]+>/u', ' ', $name) ?? $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        if (preg_match('/^([^,]+),\s*(.+)$/u', $name, $match) === 1) {
            return trim($match[2] . ' ' . $match[1]);
        }

        return trim($name);
    }

    private function hue(string $key): int
    {
        if ($key === self::UNASSIGNED_KEY) {
            return 220;
        }

        return abs(crc32($key)) % 360;
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

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return list<array{source_key: string, title: string}>
     */
    private function sourceMeta(array $sourceKeys, array $sourceTitles): array
    {
        $out = [];
        foreach ($sourceKeys as $key) {
            $out[] = [
                'source_key' => $key,
                'title' => (string) ($sourceTitles[$key] ?? $key),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{source_key: string, title: string}> $sources
     * @return array<string, mixed>
     */
    private function emptyOverview(array $sources): array
    {
        return [
            'level' => 'owner_storage',
            'sources' => $sources,
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'project_count' => 0,
                'total_owners' => 0,
                'avg_file_size' => 0,
                'top_owner_name' => '',
                'top_owner_key' => '',
                'top_owner_bytes' => 0,
                'concentration_pct' => 0.0,
                'concentration_warn' => false,
            ],
            'nodes' => [],
            'large_files' => [],
        ];
    }

    /**
     * @param list<array{source_key: string, title: string}> $sources
     * @return array<string, mixed>
     */
    private function emptyOwnerProjects(array $sources, string $ownerKey, string $ownerName): array
    {
        return [
            'level' => 'owner_projects',
            'owner_key' => $ownerKey,
            'owner_name' => $ownerName !== '' ? $ownerName : ($ownerKey === self::UNASSIGNED_KEY ? self::UNASSIGNED_NAME : $ownerKey),
            'sources' => $sources,
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'project_count' => 0,
                'total_owners' => 0,
                'avg_file_size' => 0,
                'top_owner_name' => $ownerName,
                'top_owner_key' => $ownerKey,
                'top_owner_bytes' => 0,
                'concentration_pct' => 0.0,
                'concentration_warn' => false,
            ],
            'nodes' => [],
            'large_files' => [],
        ];
    }
}
