<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use PDO;
use RiskAssessment\Repositories\SharePointArchiveRepository;

/**
 * Aggregates SharePoint catalog storage by approximate portfolio hierarchy
 * for treemap visualization: portfolio → sub-portfolio → project.
 */
final class SharePointPortfolioDashboard
{
    public const ALLOWED_SOURCES = [
        'default' => true,
        'architectural-projects-private' => true,
    ];

    private const LARGE_FILE_LIMIT = 25;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param list<string> $sourceKeys
     * @return list<string>
     */
    public function filterAllowedSources(array $sourceKeys): array
    {
        $out = [];
        foreach ($sourceKeys as $key) {
            $key = trim((string) $key);
            if ($key === '' || !isset(self::ALLOWED_SOURCES[$key]) || isset($out[$key])) {
                continue;
            }
            $out[$key] = $key;
        }

        return array_values($out);
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return array<string, mixed>
     */
    public function buildOverview(array $sourceKeys, array $sourceTitles = []): array
    {
        $sourceKeys = $this->filterAllowedSources($sourceKeys);
        if ($sourceKeys === []) {
            return $this->emptyOverview([]);
        }

        $projects = $this->collectProjectStorage($sourceKeys, $sourceTitles);
        $map = SharePointPortfolioMapping::load();
        $coverage = SharePointPortfolioMapping::coverageStats(
            array_map(static fn (array $p): string => (string) ($p['project_name'] ?? ''), $projects),
            $map
        );

        /** @var array<string, array<string, mixed>> $portfolios */
        $portfolios = [];
        $totalBytes = 0;
        $totalFiles = 0;
        $projectCount = 0;

        foreach ($projects as $project) {
            $resolved = SharePointPortfolioMapping::resolve((string) $project['project_name'], $map);
            $portfolio = $resolved['portfolio'];
            $bytes = (int) $project['size_bytes'];
            $files = (int) $project['file_count'];

            if (!isset($portfolios[$portfolio])) {
                $portfolios[$portfolio] = [
                    'key' => $portfolio,
                    'label' => $portfolio,
                    'type' => 'portfolio',
                    'size_bytes' => 0,
                    'file_count' => 0,
                    'project_count' => 0,
                    'owner_count' => 0,
                    'sub_portfolio_count' => 0,
                    'needs_review' => stripos($portfolio, 'Needs Review') !== false,
                    'hue' => $this->hue('portfolio:' . $portfolio),
                    '_subs' => [],
                    '_owners' => [],
                ];
            }

            $portfolios[$portfolio]['size_bytes'] += $bytes;
            $portfolios[$portfolio]['file_count'] += $files;
            $portfolios[$portfolio]['project_count']++;
            $portfolios[$portfolio]['_subs'][$resolved['sub_portfolio']] = true;
            $ownerKey = (string) ($project['owner_key'] ?? SharePointOwnerStorageDashboard::UNASSIGNED_KEY);
            $portfolios[$portfolio]['_owners'][$ownerKey] = true;
            $totalBytes += $bytes;
            $totalFiles += $files;
            $projectCount++;
        }

        $nodes = [];
        foreach ($portfolios as $node) {
            $node['sub_portfolio_count'] = count($node['_subs']);
            $node['owner_count'] = count($node['_owners']);
            unset($node['_subs'], $node['_owners']);
            $nodes[] = $node;
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
        $projectMenu = $this->buildProjectMenu($projects, $map);

        return [
            'level' => 'portfolios',
            'sources' => $this->sourceMeta($sourceKeys, $sourceTitles),
            'mapping_error' => SharePointPortfolioMapping::lastError(),
            'coverage' => $coverage,
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'project_count' => $projectCount,
                'portfolio_count' => count($nodes),
                'mapped_projects' => $coverage['mapped'],
                'unmapped_projects' => $coverage['unmapped'],
                'needs_review_projects' => $coverage['needs_review'],
                'largest_bytes' => (int) ($top['size_bytes'] ?? 0),
                'largest_label' => (string) ($top['label'] ?? ''),
            ],
            'nodes' => $nodes,
            'project_menu' => $projectMenu,
            'large_files' => $this->topFiles($sourceKeys, null, null, null),
        ];
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return array<string, mixed>
     */
    public function buildSubPortfolios(array $sourceKeys, array $sourceTitles, string $portfolio): array
    {
        $sourceKeys = $this->filterAllowedSources($sourceKeys);
        $portfolio = trim($portfolio);
        if ($sourceKeys === [] || $portfolio === '') {
            return $this->emptySubPortfolios($this->sourceMeta($sourceKeys, $sourceTitles), $portfolio);
        }

        $projects = $this->collectProjectStorage($sourceKeys, $sourceTitles);
        $map = SharePointPortfolioMapping::load();

        /** @var array<string, array<string, mixed>> $subs */
        $subs = [];
        $menuProjects = [];
        $totalBytes = 0;
        $totalFiles = 0;
        $projectCount = 0;

        foreach ($projects as $project) {
            $resolved = SharePointPortfolioMapping::resolve((string) $project['project_name'], $map);
            if (strcasecmp($resolved['portfolio'], $portfolio) !== 0) {
                continue;
            }
            $sub = $resolved['sub_portfolio'];
            $bytes = (int) $project['size_bytes'];
            $files = (int) $project['file_count'];

            if (!isset($subs[$sub])) {
                $subs[$sub] = [
                    'key' => $sub,
                    'label' => $sub,
                    'type' => 'sub_portfolio',
                    'portfolio' => $resolved['portfolio'],
                    'size_bytes' => 0,
                    'file_count' => 0,
                    'project_count' => 0,
                    'needs_review' => $resolved['needs_review'],
                    'hue' => $this->hue('sub:' . $resolved['portfolio'] . "\n" . $sub),
                ];
            }

            $subs[$sub]['size_bytes'] += $bytes;
            $subs[$sub]['file_count'] += $files;
            $subs[$sub]['project_count']++;
            $menuProjects[] = $project;
            $totalBytes += $bytes;
            $totalFiles += $files;
            $projectCount++;
        }

        $nodes = array_values($subs);
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
            'level' => 'sub_portfolios',
            'portfolio' => $portfolio,
            'sources' => $this->sourceMeta($sourceKeys, $sourceTitles),
            'mapping_error' => SharePointPortfolioMapping::lastError(),
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'project_count' => $projectCount,
                'sub_portfolio_count' => count($nodes),
            ],
            'nodes' => $nodes,
            'project_menu' => $this->buildProjectMenu($menuProjects, $map),
            'large_files' => $this->topFiles($sourceKeys, $portfolio, null, null),
        ];
    }

    /**
     * Owners within one portfolio, sized by storage / project count.
     *
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return array<string, mixed>
     */
    public function buildOwners(array $sourceKeys, array $sourceTitles, string $portfolio): array
    {
        $sourceKeys = $this->filterAllowedSources($sourceKeys);
        $portfolio = trim($portfolio);
        if ($sourceKeys === [] || $portfolio === '') {
            return $this->emptyOwners($this->sourceMeta($sourceKeys, $sourceTitles), $portfolio);
        }

        $projects = $this->collectProjectStorage($sourceKeys, $sourceTitles);
        $map = SharePointPortfolioMapping::load();

        /** @var array<string, array<string, mixed>> $owners */
        $owners = [];
        $menuProjects = [];
        $totalBytes = 0;
        $totalFiles = 0;
        $projectCount = 0;

        foreach ($projects as $project) {
            $resolved = SharePointPortfolioMapping::resolve((string) $project['project_name'], $map);
            if (strcasecmp($resolved['portfolio'], $portfolio) !== 0) {
                continue;
            }

            $ownerKey = (string) ($project['owner_key'] ?? SharePointOwnerStorageDashboard::UNASSIGNED_KEY);
            $ownerName = (string) ($project['owner_name'] ?? SharePointOwnerStorageDashboard::UNASSIGNED_NAME);
            $bytes = (int) $project['size_bytes'];
            $files = (int) $project['file_count'];

            if (!isset($owners[$ownerKey])) {
                $owners[$ownerKey] = [
                    'key' => $ownerKey,
                    'label' => $ownerName,
                    'type' => 'owner',
                    'owner_key' => $ownerKey,
                    'owner_name' => $ownerName,
                    'portfolio' => $resolved['portfolio'],
                    'size_bytes' => 0,
                    'file_count' => 0,
                    'project_count' => 0,
                    'hue' => $this->hue('owner:' . $ownerKey . "\n" . $portfolio),
                ];
            }

            $owners[$ownerKey]['size_bytes'] += $bytes;
            $owners[$ownerKey]['file_count'] += $files;
            $owners[$ownerKey]['project_count']++;
            $menuProjects[] = $project;
            $totalBytes += $bytes;
            $totalFiles += $files;
            $projectCount++;
        }

        $nodes = array_values($owners);
        usort(
            $nodes,
            static function (array $a, array $b): int {
                $cmp = ((int) $b['project_count']) <=> ((int) $a['project_count']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                $cmp = ((int) $b['size_bytes']) <=> ((int) $a['size_bytes']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            }
        );

        return [
            'level' => 'owners',
            'portfolio' => $portfolio,
            'sources' => $this->sourceMeta($sourceKeys, $sourceTitles),
            'mapping_error' => SharePointPortfolioMapping::lastError(),
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'project_count' => $projectCount,
                'owner_count' => count($nodes),
            ],
            'nodes' => $nodes,
            'project_menu' => $this->buildProjectMenu($menuProjects, $map),
            'large_files' => $this->topFiles($sourceKeys, $portfolio, null, null),
        ];
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return array<string, mixed>
     */
    public function buildProjects(
        array $sourceKeys,
        array $sourceTitles,
        string $portfolio,
        string $subPortfolio = '',
        string $ownerKey = ''
    ): array {
        $sourceKeys = $this->filterAllowedSources($sourceKeys);
        $portfolio = trim($portfolio);
        $subPortfolio = trim($subPortfolio);
        $ownerKey = trim($ownerKey);
        if ($sourceKeys === [] || $portfolio === '') {
            return $this->emptyProjects($this->sourceMeta($sourceKeys, $sourceTitles), $portfolio, $subPortfolio, $ownerKey);
        }

        $projects = $this->collectProjectStorage($sourceKeys, $sourceTitles);
        $map = SharePointPortfolioMapping::load();

        $nodes = [];
        $totalBytes = 0;
        $totalFiles = 0;
        $ownerName = '';

        foreach ($projects as $project) {
            $resolved = SharePointPortfolioMapping::resolve((string) $project['project_name'], $map);
            if (strcasecmp($resolved['portfolio'], $portfolio) !== 0) {
                continue;
            }
            if ($subPortfolio !== '' && strcasecmp($resolved['sub_portfolio'], $subPortfolio) !== 0) {
                continue;
            }
            $projectOwnerKey = (string) ($project['owner_key'] ?? SharePointOwnerStorageDashboard::UNASSIGNED_KEY);
            if ($ownerKey !== '' && $projectOwnerKey !== $ownerKey) {
                continue;
            }

            $bytes = (int) $project['size_bytes'];
            $files = (int) $project['file_count'];
            $totalBytes += $bytes;
            $totalFiles += $files;
            if ($ownerName === '') {
                $ownerName = (string) ($project['owner_name'] ?? SharePointOwnerStorageDashboard::UNASSIGNED_NAME);
            }

            $nodes[] = [
                'key' => (string) $project['source_key'] . "\n" . (string) $project['project_name'],
                'label' => (string) $project['project_name'],
                'type' => 'project',
                'source_key' => (string) $project['source_key'],
                'source_title' => (string) $project['source_title'],
                'project_name' => (string) $project['project_name'],
                'portfolio' => $resolved['portfolio'],
                'sub_portfolio' => $resolved['sub_portfolio'],
                'confidence' => $resolved['confidence'],
                'note' => $resolved['note'],
                'mapped' => $resolved['mapped'],
                'needs_review' => $resolved['needs_review'],
                'owner_key' => $projectOwnerKey,
                'owner_name' => (string) ($project['owner_name'] ?? SharePointOwnerStorageDashboard::UNASSIGNED_NAME),
                'size_bytes' => $bytes,
                'file_count' => $files,
                'project_count' => 1,
                'last_modified' => (string) ($project['last_modified'] ?? ''),
                'hue' => $this->hue((string) $project['source_key'] . "\n" . (string) $project['project_name']),
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

        $drill = 'portfolio_projects';
        if ($ownerKey !== '') {
            $drill = 'owner_projects';
        } elseif ($subPortfolio !== '') {
            $drill = 'sub_portfolio_projects';
        }

        return [
            'level' => 'projects',
            'portfolio' => $portfolio,
            'sub_portfolio' => $subPortfolio,
            'owner_key' => $ownerKey,
            'owner_name' => $ownerName,
            'drill' => $drill,
            'sources' => $this->sourceMeta($sourceKeys, $sourceTitles),
            'mapping_error' => SharePointPortfolioMapping::lastError(),
            'kpis' => [
                'total_bytes' => $totalBytes,
                'file_count' => $totalFiles,
                'project_count' => count($nodes),
            ],
            'nodes' => $nodes,
            'project_menu' => $nodes,
            'large_files' => $this->topFiles($sourceKeys, $portfolio, $subPortfolio !== '' ? $subPortfolio : null, null),
        ];
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return list<array<string, mixed>>
     */
    private function collectProjectStorage(array $sourceKeys, array $sourceTitles): array
    {
        $sourceKeys = $this->filterAllowedSources($sourceKeys);
        if ($sourceKeys === []) {
            return [];
        }

        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT source_key, project_name,
                    SUM(CASE WHEN lower(item_type) = 'file' THEN COALESCE(size_bytes, 0) ELSE 0 END) AS size_bytes,
                    SUM(CASE WHEN lower(item_type) = 'file' THEN 1 ELSE 0 END) AS file_count,
                    MAX(last_modified) AS last_modified
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND {$visible}
             GROUP BY source_key, project_name
             HAVING size_bytes > 0 OR file_count > 0"
        );
        $statement->execute($sourceKeys);
        $statsRows = $statement->fetchAll() ?: [];
        if ($statsRows === []) {
            return [];
        }

        $owners = $this->resolveProjectOwners($sourceKeys);

        $out = [];
        foreach ($statsRows as $row) {
            $sourceKey = (string) ($row['source_key'] ?? '');
            $projectName = trim((string) ($row['project_name'] ?? ''));
            if ($sourceKey === '' || $projectName === '') {
                continue;
            }
            $groupKey = $sourceKey . "\n" . $projectName;
            $owner = $owners[$groupKey] ?? [
                'owner_key' => SharePointOwnerStorageDashboard::UNASSIGNED_KEY,
                'owner_name' => SharePointOwnerStorageDashboard::UNASSIGNED_NAME,
            ];
            $out[] = [
                'source_key' => $sourceKey,
                'source_title' => (string) ($sourceTitles[$sourceKey] ?? $sourceKey),
                'project_name' => $projectName,
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'file_count' => (int) ($row['file_count'] ?? 0),
                'last_modified' => trim((string) ($row['last_modified'] ?? '')),
                'owner_key' => (string) $owner['owner_key'],
                'owner_name' => (string) $owner['owner_name'],
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $sourceKeys
     * @return array<string, array{owner_key: string, owner_name: string}>
     */
    private function resolveProjectOwners(array $sourceKeys): array
    {
        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));

        $rootStmt = $this->pdo->prepare(
            "SELECT source_key, project_name, person
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
        foreach ($rootStmt->fetchAll() ?: [] as $row) {
            $groupKey = (string) ($row['source_key'] ?? '') . "\n" . (string) ($row['project_name'] ?? '');
            if ($groupKey === "\n" || isset($rootPerson[$groupKey])) {
                continue;
            }
            $person = trim((string) ($row['person'] ?? ''));
            if ($person !== '') {
                $rootPerson[$groupKey] = $person;
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
        $allKeys = array_unique(array_merge(array_keys($rootPerson), array_keys($peopleByGroup)));
        foreach ($allKeys as $groupKey) {
            $ownerName = trim((string) ($rootPerson[$groupKey] ?? ''));
            if ($ownerName === '' && !empty($peopleByGroup[$groupKey])) {
                arsort($peopleByGroup[$groupKey]);
                $ownerName = (string) array_key_first($peopleByGroup[$groupKey]);
            }
            if ($ownerName === '') {
                $out[$groupKey] = [
                    'owner_key' => SharePointOwnerStorageDashboard::UNASSIGNED_KEY,
                    'owner_name' => SharePointOwnerStorageDashboard::UNASSIGNED_NAME,
                ];
                continue;
            }
            $out[$groupKey] = [
                'owner_key' => $this->ownerKey($ownerName),
                'owner_name' => $this->displayOwnerName($ownerName),
            ];
        }

        return $out;
    }

    private function ownerKey(string $ownerName): string
    {
        $name = trim($ownerName);
        if ($name === '' || strcasecmp($name, SharePointOwnerStorageDashboard::UNASSIGNED_NAME) === 0) {
            return SharePointOwnerStorageDashboard::UNASSIGNED_KEY;
        }
        $name = preg_replace('/<[^>]+>/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = mb_strtolower(trim($name), 'UTF-8');
        if (preg_match('/^([^,]+),\s*(.+)$/u', $name, $match) === 1) {
            $name = trim($match[2] . ' ' . $match[1]);
        }

        return $name !== '' ? $name : SharePointOwnerStorageDashboard::UNASSIGNED_KEY;
    }

    private function displayOwnerName(string $name): string
    {
        $name = trim(preg_replace('/<[^>]+>/u', ' ', $name) ?? $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        if (preg_match('/^([^,]+),\s*(.+)$/u', $name, $match) === 1) {
            return trim($match[2] . ' ' . $match[1]);
        }

        return trim($name);
    }

    /**
     * @param list<array<string, mixed>> $projects
     * @param array<string, array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string}> $map
     * @return list<array<string, mixed>>
     */
    private function buildProjectMenu(array $projects, array $map): array
    {
        $menu = [];
        foreach ($projects as $project) {
            $resolved = SharePointPortfolioMapping::resolve((string) ($project['project_name'] ?? ''), $map);
            $menu[] = [
                'key' => (string) ($project['source_key'] ?? '') . "\n" . (string) ($project['project_name'] ?? ''),
                'label' => (string) ($project['project_name'] ?? ''),
                'type' => 'project',
                'source_key' => (string) ($project['source_key'] ?? ''),
                'source_title' => (string) ($project['source_title'] ?? ''),
                'project_name' => (string) ($project['project_name'] ?? ''),
                'portfolio' => $resolved['portfolio'],
                'sub_portfolio' => $resolved['sub_portfolio'],
                'confidence' => $resolved['confidence'],
                'note' => $resolved['note'],
                'needs_review' => $resolved['needs_review'],
                'mapped' => $resolved['mapped'],
                'owner_key' => (string) ($project['owner_key'] ?? SharePointOwnerStorageDashboard::UNASSIGNED_KEY),
                'owner_name' => (string) ($project['owner_name'] ?? SharePointOwnerStorageDashboard::UNASSIGNED_NAME),
                'size_bytes' => (int) ($project['size_bytes'] ?? 0),
                'file_count' => (int) ($project['file_count'] ?? 0),
                'last_modified' => (string) ($project['last_modified'] ?? ''),
            ];
        }

        usort(
            $menu,
            static function (array $a, array $b): int {
                $cmp = ((int) $b['size_bytes']) <=> ((int) $a['size_bytes']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            }
        );

        return $menu;
    }

    /**
     * Top files optionally filtered by portfolio / sub-portfolio via PHP join.
     *
     * @param list<string> $sourceKeys
     * @return list<array<string, mixed>>
     */
    private function topFiles(
        array $sourceKeys,
        ?string $portfolio,
        ?string $subPortfolio,
        ?string $projectName
    ): array {
        $sourceKeys = $this->filterAllowedSources($sourceKeys);
        if ($sourceKeys === []) {
            return [];
        }

        $map = SharePointPortfolioMapping::load();
        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $params = $sourceKeys;
        $extra = '';
        if ($projectName !== null && trim($projectName) !== '') {
            $extra .= ' AND project_name = ?';
            $params[] = trim($projectName);
        }

        // Fetch a wider candidate set when filtering by portfolio in PHP.
        $limit = ($portfolio !== null && trim($portfolio) !== '') ? 200 : self::LARGE_FILE_LIMIT;
        $statement = $this->pdo->prepare(
            "SELECT source_key, project_name, name, relative_path, size_bytes, last_modified, web_url, mime_type
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND lower(item_type) = 'file'
               AND COALESCE(size_bytes, 0) > 0
               AND {$visible}
               {$extra}
             ORDER BY size_bytes DESC, LOWER(name) ASC
             LIMIT {$limit}"
        );
        $statement->execute($params);

        $out = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $pname = (string) ($row['project_name'] ?? '');
            $resolved = SharePointPortfolioMapping::resolve($pname, $map);
            if ($portfolio !== null && trim($portfolio) !== ''
                && strcasecmp($resolved['portfolio'], trim($portfolio)) !== 0) {
                continue;
            }
            if ($subPortfolio !== null && trim($subPortfolio) !== ''
                && strcasecmp($resolved['sub_portfolio'], trim($subPortfolio)) !== 0) {
                continue;
            }

            $out[] = [
                'source_key' => (string) ($row['source_key'] ?? ''),
                'project_name' => $pname,
                'name' => (string) ($row['name'] ?? ''),
                'relative_path' => (string) ($row['relative_path'] ?? ''),
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'last_modified' => trim((string) ($row['last_modified'] ?? '')),
                'web_url' => trim((string) ($row['web_url'] ?? '')),
                'mime_type' => trim((string) ($row['mime_type'] ?? '')),
                'portfolio' => $resolved['portfolio'],
                'sub_portfolio' => $resolved['sub_portfolio'],
                'confidence' => $resolved['confidence'],
            ];
            if (count($out) >= self::LARGE_FILE_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return list<array{source_key: string, title: string}>
     */
    private function sourceMeta(array $sourceKeys, array $sourceTitles): array
    {
        $sources = [];
        foreach ($sourceKeys as $key) {
            $sources[] = [
                'source_key' => $key,
                'title' => (string) ($sourceTitles[$key] ?? $key),
            ];
        }

        return $sources;
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
            'level' => 'portfolios',
            'sources' => $sources,
            'mapping_error' => SharePointPortfolioMapping::lastError(),
            'coverage' => [
                'mapped' => 0,
                'unmapped' => 0,
                'needs_review' => 0,
                'low_confidence' => 0,
                'total' => 0,
            ],
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'project_count' => 0,
                'portfolio_count' => 0,
                'mapped_projects' => 0,
                'unmapped_projects' => 0,
                'needs_review_projects' => 0,
                'largest_bytes' => 0,
                'largest_label' => '',
            ],
            'nodes' => [],
            'project_menu' => [],
            'large_files' => [],
        ];
    }

    /**
     * @param list<array{source_key: string, title: string}> $sources
     * @return array<string, mixed>
     */
    private function emptySubPortfolios(array $sources, string $portfolio): array
    {
        return [
            'level' => 'sub_portfolios',
            'portfolio' => $portfolio,
            'sources' => $sources,
            'mapping_error' => SharePointPortfolioMapping::lastError(),
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'project_count' => 0,
                'sub_portfolio_count' => 0,
            ],
            'nodes' => [],
            'project_menu' => [],
            'large_files' => [],
        ];
    }

    /**
     * @param list<array{source_key: string, title: string}> $sources
     * @return array<string, mixed>
     */
    private function emptyOwners(array $sources, string $portfolio): array
    {
        return [
            'level' => 'owners',
            'portfolio' => $portfolio,
            'sources' => $sources,
            'mapping_error' => SharePointPortfolioMapping::lastError(),
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'project_count' => 0,
                'owner_count' => 0,
            ],
            'nodes' => [],
            'project_menu' => [],
            'large_files' => [],
        ];
    }

    /**
     * @param list<array{source_key: string, title: string}> $sources
     * @return array<string, mixed>
     */
    private function emptyProjects(
        array $sources,
        string $portfolio,
        string $subPortfolio,
        string $ownerKey = ''
    ): array {
        return [
            'level' => 'projects',
            'portfolio' => $portfolio,
            'sub_portfolio' => $subPortfolio,
            'owner_key' => $ownerKey,
            'owner_name' => '',
            'sources' => $sources,
            'mapping_error' => SharePointPortfolioMapping::lastError(),
            'kpis' => [
                'total_bytes' => 0,
                'file_count' => 0,
                'project_count' => 0,
            ],
            'nodes' => [],
            'project_menu' => [],
            'large_files' => [],
        ];
    }
}
