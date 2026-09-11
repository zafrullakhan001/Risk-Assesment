<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use InvalidArgumentException;
use PDO;
use RiskAssessment\Repositories\SharePointArchiveRepository;

/**
 * Paginated project-folder comparison across two SharePoint catalogs.
 * Matches folders by normalized name so large catalogs stay in SQL.
 */
final class SharePointCatalogComparer
{
    public const PRESENCE_ANY = 'any';
    public const PRESENCE_BOTH = 'both';
    public const PRESENCE_LEFT = 'left';
    public const PRESENCE_RIGHT = 'right';

    private const ALLOWED_PER_PAGE = [25, 50, 100, 200];
    private const DEFAULT_PER_PAGE = 50;
    private const SORT_KEYS = ['name', 'presence', 'items', 'modified'];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public static function requestOptions(array $get): array
    {
        $truthy = static function (mixed $value, bool $default): bool {
            if ($value === null || $value === '') {
                return $default;
            }
            $value = strtolower(trim((string) $value));
            if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }

            return in_array($value, ['1', 'true', 'on', 'yes'], true) || $default;
        };

        return [
            'query' => trim((string) ($get['q'] ?? '')),
            'presence' => trim((string) ($get['presence'] ?? 'any')),
            'page' => (int) ($get['page'] ?? 1),
            'per_page' => (int) ($get['per'] ?? 50),
            'sort' => trim((string) ($get['sort'] ?? 'name')),
            'dir' => trim((string) ($get['dir'] ?? 'asc')),
            'word_mode' => strtolower(trim((string) ($get['mode'] ?? $get['word_mode'] ?? 'and'))),
            'fuzzy' => $truthy($get['fuzzy'] ?? null, false),
            'deep' => $truthy($get['deep'] ?? null, true),
            'match_scope' => trim((string) ($get['scope'] ?? $get['match_scope'] ?? 'all')),
            'types' => trim((string) ($get['type'] ?? $get['types'] ?? '')),
            'extensions' => trim((string) ($get['ext'] ?? $get['extensions'] ?? '')),
            'all' => $truthy($get['all'] ?? null, false),
        ];
    }

    /**
     * @param array{
     *   query?: string,
     *   presence?: string,
     *   page?: int,
     *   per_page?: int,
     *   sort?: string,
     *   dir?: string,
     *   include_archived?: bool,
     *   left_title?: string,
     *   right_title?: string,
     *   word_mode?: string,
     *   fuzzy?: bool,
     *   deep?: bool,
     *   match_scope?: string,
     *   types?: string|list<string>,
     *   extensions?: string|list<string>,
     *   viewer?: array{display?: string, name?: string, email?: string}
     * } $options
     * @return array<string, mixed>
     */
    public function compare(string $leftKey, string $rightKey, array $options = []): array
    {
        $leftKey = trim($leftKey);
        $rightKey = trim($rightKey);
        if ($leftKey === '' || $rightKey === '') {
            throw new InvalidArgumentException('Two catalog keys are required.');
        }
        if (strcasecmp($leftKey, $rightKey) === 0) {
            throw new InvalidArgumentException('Choose two different catalogs.');
        }

        $query = trim((string) ($options['query'] ?? ''));
        $presence = $this->normalizePresence((string) ($options['presence'] ?? self::PRESENCE_ANY));
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = $this->clampPerPage((int) ($options['per_page'] ?? self::DEFAULT_PER_PAGE));
        $sort = $this->normalizeSort((string) ($options['sort'] ?? 'name'));
        $dir = strtolower(trim((string) ($options['dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
        $includeArchived = !empty($options['include_archived']);
        $leftTitle = trim((string) ($options['left_title'] ?? '')) ?: $leftKey;
        $rightTitle = trim((string) ($options['right_title'] ?? '')) ?: $rightKey;
        $wordMode = strtolower(trim((string) ($options['word_mode'] ?? 'and'))) === 'or' ? 'or' : 'and';
        $fuzzy = !empty($options['fuzzy']);
        $deep = !array_key_exists('deep', $options) || !empty($options['deep']);
        $matchScope = $this->normalizeMatchScope((string) ($options['match_scope'] ?? 'all'));
        $extraTypes = $this->csvList($options['types'] ?? []);
        $extraExts = $this->csvList($options['extensions'] ?? []);
        $viewer = is_array($options['viewer'] ?? null) ? $options['viewer'] : [];

        $visibleSql = $includeArchived
            ? '1=1'
            : SharePointArchiveRepository::visibleProjectSql('sharepoint_items');

        $params = [
            ':left_key' => $leftKey,
            ':right_key' => $rightKey,
        ];
        $parsed = SharePointCatalogQuery::parse($query);
        $filter = [
            'parsed' => $parsed,
            'word_mode' => $wordMode,
            'fuzzy' => $fuzzy,
            'deep' => $deep,
            'match_scope' => $matchScope,
            'types' => $extraTypes,
            'extensions' => $extraExts,
            'viewer' => $viewer,
        ];
        $searchActive = SharePointCatalogQuery::isActive($parsed, $extraTypes, $extraExts);
        $returnAll = !empty($options['all']) || $perPage === 0;

        $matchedSql = $this->matchedSql($visibleSql);
        $hitsSql = '';
        if ($searchActive) {
            $leftHits = $this->hitsSelectSql(':left_key', 'lft', $filter, $params, 'left_p');
            $rightHits = $this->hitsSelectSql(':right_key', 'rgt', $filter, $params, 'right_p');
            $hitsSql = ",
            left_hits AS (
                {$leftHits}
            ),
            right_hits AS (
                {$rightHits}
            ),
            filtered AS (
                SELECT m.*
                FROM matched m
                WHERE m.name_key IN (SELECT name_key FROM left_hits)
                   OR m.name_key IN (SELECT name_key FROM right_hits)
            )";
        } else {
            $hitsSql = ',
            filtered AS (
                SELECT * FROM matched
            )';
        }

        $orderSql = $this->orderSql($sort, $dir, 'f');
        $listSql = "{$matchedSql}
            {$hitsSql}
            SELECT
                (SELECT COUNT(*) FROM left_p) AS left_project_count,
                (SELECT COALESCE(SUM(item_count), 0) FROM left_p) AS left_item_count,
                (SELECT COUNT(*) FROM right_p) AS right_project_count,
                (SELECT COALESCE(SUM(item_count), 0) FROM right_p) AS right_item_count,
                f.name_key,
                f.presence,
                f.left_name,
                f.right_name,
                f.left_item_count,
                f.right_item_count,
                f.left_file_count,
                f.right_file_count,
                f.left_folder_count,
                f.right_folder_count,
                f.left_modified,
                f.right_modified
            FROM (SELECT 1) AS _meta
            LEFT JOIN filtered f ON 1=1
            {$orderSql}";

        try {
            $statement = $this->pdo->prepare($listSql);
            $statement->execute($params);
            $rawRows = $statement->fetchAll() ?: [];
        } catch (\Throwable $exception) {
            if (!$searchActive || !$this->ftsAvailable()) {
                throw $exception;
            }
            $params = [
                ':left_key' => $leftKey,
                ':right_key' => $rightKey,
            ];
            $leftHits = $this->groupedHitsSql(':left_key', 'lft', $filter, $params, $parsed);
            $rightHits = $this->groupedHitsSql(':right_key', 'rgt', $filter, $params, $parsed);
            $hitsSql = ",
            left_hits AS (
                {$leftHits}
            ),
            right_hits AS (
                {$rightHits}
            ),
            filtered AS (
                SELECT m.*
                FROM matched m
                WHERE m.name_key IN (SELECT name_key FROM left_hits)
                   OR m.name_key IN (SELECT name_key FROM right_hits)
            )";
            $listSql = "{$matchedSql}
            {$hitsSql}
            SELECT
                (SELECT COUNT(*) FROM left_p) AS left_project_count,
                (SELECT COALESCE(SUM(item_count), 0) FROM left_p) AS left_item_count,
                (SELECT COUNT(*) FROM right_p) AS right_project_count,
                (SELECT COALESCE(SUM(item_count), 0) FROM right_p) AS right_item_count,
                f.name_key,
                f.presence,
                f.left_name,
                f.right_name,
                f.left_item_count,
                f.right_item_count,
                f.left_file_count,
                f.right_file_count,
                f.left_folder_count,
                f.right_folder_count,
                f.left_modified,
                f.right_modified
            FROM (SELECT 1) AS _meta
            LEFT JOIN filtered f ON 1=1
            {$orderSql}";
            $statement = $this->pdo->prepare($listSql);
            $statement->execute($params);
            $rawRows = $statement->fetchAll() ?: [];
        }

        $metaRow = $rawRows[0] ?? [];
        $dataRows = [];
        $totals = [
            'in_both' => 0,
            'only_left' => 0,
            'only_right' => 0,
            'all' => 0,
        ];
        foreach ($rawRows as $row) {
            if (trim((string) ($row['name_key'] ?? '')) === '') {
                continue;
            }
            $dataRows[] = $row;
            $totals['all']++;
            $rowPresence = (string) ($row['presence'] ?? '');
            if ($rowPresence === self::PRESENCE_BOTH) {
                $totals['in_both']++;
            } elseif ($rowPresence === self::PRESENCE_LEFT) {
                $totals['only_left']++;
            } elseif ($rowPresence === self::PRESENCE_RIGHT) {
                $totals['only_right']++;
            }
        }

        $visibleRows = $presence === self::PRESENCE_ANY
            ? $dataRows
            : array_values(array_filter(
                $dataRows,
                static fn (array $row): bool => (string) ($row['presence'] ?? '') === $presence
            ));
        $filteredCount = count($visibleRows);
        if ($returnAll || $perPage === 0) {
            $page = 1;
            $pageCount = 1;
            $pageRows = $visibleRows;
            $perPage = $filteredCount > 0 ? $filteredCount : self::DEFAULT_PER_PAGE;
        } else {
            $pageCount = max(1, (int) ceil($filteredCount / $perPage));
            if ($page > $pageCount) {
                $page = $pageCount;
            }
            $offset = ($page - 1) * $perPage;
            $pageRows = array_slice($visibleRows, $offset, $perPage);
        }

        $leftNames = [];
        $rightNames = [];
        foreach ($pageRows as $row) {
            $leftName = trim((string) ($row['left_name'] ?? ''));
            $rightName = trim((string) ($row['right_name'] ?? ''));
            if ($leftName !== '') {
                $leftNames[] = $leftName;
            }
            if ($rightName !== '') {
                $rightNames[] = $rightName;
            }
        }

        $leftSummaries = $this->summarizeProjects($leftKey, $leftNames, $includeArchived);
        $rightSummaries = $this->summarizeProjects($rightKey, $rightNames, $includeArchived);

        $rows = [];
        foreach ($pageRows as $row) {
            $leftName = trim((string) ($row['left_name'] ?? ''));
            $rightName = trim((string) ($row['right_name'] ?? ''));
            $rows[] = [
                'name_key' => (string) ($row['name_key'] ?? ''),
                'presence' => (string) ($row['presence'] ?? ''),
                'left' => $leftName !== ''
                    ? $this->sidePayload($leftSummaries[$leftName] ?? null, $row, 'left', $leftName)
                    : null,
                'right' => $rightName !== ''
                    ? $this->sidePayload($rightSummaries[$rightName] ?? null, $row, 'right', $rightName)
                    : null,
            ];
        }

        return [
            'left' => [
                'source_key' => $leftKey,
                'title' => $leftTitle,
                'project_count' => (int) ($metaRow['left_project_count'] ?? 0),
                'item_count' => (int) ($metaRow['left_item_count'] ?? 0),
            ],
            'right' => [
                'source_key' => $rightKey,
                'title' => $rightTitle,
                'project_count' => (int) ($metaRow['right_project_count'] ?? 0),
                'item_count' => (int) ($metaRow['right_item_count'] ?? 0),
            ],
            'query' => $query,
            'word_mode' => $wordMode,
            'fuzzy' => $fuzzy,
            'deep' => $deep,
            'match_scope' => $matchScope,
            'types' => $extraTypes,
            'extensions' => $extraExts,
            'presence' => $presence,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'per_page' => $perPage,
            'page_count' => $pageCount,
            'row_count' => $filteredCount,
            'totals' => [
                'in_both' => $totals['in_both'],
                'only_left' => $totals['only_left'],
                'only_right' => $totals['only_right'],
                'all' => $totals['all'],
            ],
            'rows' => $rows,
        ];
    }

    private function matchedSql(string $visibleSql): string
    {
        $projectSql = static function (string $alias) use ($visibleSql): string {
            return "SELECT
                    LOWER(TRIM(project_name)) AS name_key,
                    MIN(project_name) AS project_name,
                    COUNT(*) AS item_count,
                    SUM(CASE WHEN LOWER(item_type) = 'file' THEN 1 ELSE 0 END) AS file_count,
                    SUM(CASE WHEN LOWER(item_type) = 'folder' THEN 1 ELSE 0 END) AS folder_count,
                    MAX(last_modified) AS last_modified
                 FROM sharepoint_items
                 WHERE source_key = {$alias}
                   AND TRIM(project_name) != ''
                   AND {$visibleSql}
                 GROUP BY LOWER(TRIM(project_name))";
        };

        return "WITH left_p AS (
            {$projectSql(':left_key')}
        ),
        right_p AS (
            {$projectSql(':right_key')}
        ),
        matched AS (
            SELECT
                COALESCE(l.name_key, r.name_key) AS name_key,
                CASE
                    WHEN l.name_key IS NOT NULL AND r.name_key IS NOT NULL THEN 'both'
                    WHEN l.name_key IS NOT NULL THEN 'left'
                    ELSE 'right'
                END AS presence,
                l.project_name AS left_name,
                r.project_name AS right_name,
                COALESCE(l.item_count, 0) AS left_item_count,
                COALESCE(r.item_count, 0) AS right_item_count,
                COALESCE(l.file_count, 0) AS left_file_count,
                COALESCE(r.file_count, 0) AS right_file_count,
                COALESCE(l.folder_count, 0) AS left_folder_count,
                COALESCE(r.folder_count, 0) AS right_folder_count,
                IFNULL(l.last_modified, '') AS left_modified,
                IFNULL(r.last_modified, '') AS right_modified
            FROM left_p l
            LEFT JOIN right_p r ON r.name_key = l.name_key
            UNION ALL
            SELECT
                r.name_key,
                'right' AS presence,
                NULL AS left_name,
                r.project_name AS right_name,
                0 AS left_item_count,
                COALESCE(r.item_count, 0) AS right_item_count,
                0 AS left_file_count,
                COALESCE(r.file_count, 0) AS right_file_count,
                0 AS left_folder_count,
                COALESCE(r.folder_count, 0) AS right_folder_count,
                '' AS left_modified,
                IFNULL(r.last_modified, '') AS right_modified
            FROM right_p r
            LEFT JOIN left_p l ON l.name_key = r.name_key
            WHERE l.name_key IS NULL
        ) ";
    }

    private function orderSql(string $sort, string $dir, string $alias = 'm'): string
    {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'm';
        $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
        $nameOrder = "{$alias}.name_key COLLATE NOCASE ASC";
        if ($sort === 'presence') {
            return "ORDER BY CASE {$alias}.presence WHEN 'both' THEN 0 WHEN 'left' THEN 1 ELSE 2 END {$dirSql}, {$nameOrder}";
        }
        if ($sort === 'items') {
            return "ORDER BY ({$alias}.left_item_count + {$alias}.right_item_count) {$dirSql}, {$nameOrder}";
        }
        if ($sort === 'modified') {
            return "ORDER BY
                CASE
                    WHEN CASE WHEN {$alias}.left_modified >= {$alias}.right_modified THEN {$alias}.left_modified ELSE {$alias}.right_modified END = '' THEN 1
                    ELSE 0
                END ASC,
                CASE WHEN {$alias}.left_modified >= {$alias}.right_modified THEN {$alias}.left_modified ELSE {$alias}.right_modified END {$dirSql},
                {$nameOrder}";
        }

        return "ORDER BY {$alias}.name_key COLLATE NOCASE {$dirSql}";
    }

    /**
     * @param list<string> $names
     * @return array<string, array<string, mixed>>
     */
    private function summarizeProjects(string $sourceKey, array $names, bool $includeArchived): array
    {
        $names = array_values(array_unique(array_filter(array_map('strval', $names), static fn (string $name): bool => trim($name) !== '')));
        if ($names === []) {
            return [];
        }

        $visibleSql = $includeArchived
            ? '1=1'
            : SharePointArchiveRepository::visibleProjectSql('i');
        $placeholders = [];
        $params = [':source_key' => $sourceKey];
        foreach ($names as $index => $name) {
            $key = ':n' . $index;
            $placeholders[] = $key;
            $params[$key] = $name;
        }
        $inList = implode(',', $placeholders);
        $statement = $this->pdo->prepare(
            "SELECT
                i.project_name,
                COUNT(*) AS item_count,
                SUM(CASE WHEN LOWER(i.item_type) = 'file' THEN 1 ELSE 0 END) AS file_count,
                SUM(CASE WHEN LOWER(i.item_type) = 'folder' THEN 1 ELSE 0 END) AS folder_count,
                MAX(i.last_modified) AS last_modified,
                COALESCE((
                    SELECT latest.modified_by
                    FROM sharepoint_items latest
                    WHERE latest.source_key = i.source_key
                      AND latest.project_name = i.project_name
                      AND TRIM(IFNULL(latest.modified_by, '')) != ''
                    ORDER BY
                        CASE WHEN IFNULL(latest.last_modified, '') = '' THEN 1 ELSE 0 END ASC,
                        latest.last_modified DESC,
                        latest.id DESC
                    LIMIT 1
                ), '') AS modified_by,
                COALESCE((
                    SELECT creator.person
                    FROM sharepoint_items creator
                    WHERE creator.source_key = i.source_key
                      AND creator.project_name = i.project_name
                      AND TRIM(IFNULL(creator.person, '')) != ''
                    ORDER BY
                        CASE
                            WHEN LOWER(creator.item_type) = 'folder'
                             AND (creator.relative_path = '' OR creator.relative_path = creator.project_name OR creator.relative_path = creator.name)
                            THEN 0 ELSE 1
                        END ASC,
                        CASE WHEN IFNULL(creator.date_created, '') = '' THEN 1 ELSE 0 END ASC,
                        creator.date_created ASC,
                        creator.id ASC
                    LIMIT 1
                ), '') AS created_by,
                MAX(CASE
                    WHEN LOWER(i.item_type) = 'folder'
                     AND (i.relative_path = '' OR i.relative_path = i.project_name OR i.relative_path = i.name)
                    THEN i.web_url ELSE ''
                END) AS folder_url
             FROM sharepoint_items i
             WHERE i.source_key = :source_key
               AND i.project_name IN ({$inList})
               AND {$visibleSql}
             GROUP BY i.project_name"
        );
        $statement->execute($params);
        $out = [];
        foreach ($statement->fetchAll() ?: [] as $row) {
            $name = (string) ($row['project_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[$name] = [
                'project_name' => $name,
                'item_count' => (int) ($row['item_count'] ?? 0),
                'file_count' => (int) ($row['file_count'] ?? 0),
                'folder_count' => (int) ($row['folder_count'] ?? 0),
                'last_modified' => trim((string) ($row['last_modified'] ?? '')),
                'modified_by' => trim((string) ($row['modified_by'] ?? '')),
                'created_by' => trim((string) ($row['created_by'] ?? '')),
                'folder_url' => trim((string) ($row['folder_url'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $summary
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sidePayload(?array $summary, array $row, string $side, string $fallbackName): array
    {
        $prefix = $side === 'right' ? 'right_' : 'left_';
        if ($summary !== null) {
            return $summary;
        }

        return [
            'project_name' => $fallbackName,
            'item_count' => (int) ($row[$prefix . 'item_count'] ?? 0),
            'file_count' => (int) ($row[$prefix . 'file_count'] ?? 0),
            'folder_count' => (int) ($row[$prefix . 'folder_count'] ?? 0),
            'last_modified' => trim((string) ($row[$prefix . 'modified'] ?? '')),
            'modified_by' => '',
            'created_by' => '',
            'folder_url' => '',
        ];
    }

    private function normalizePresence(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['in_both', 'all', 'shared'], true)) {
            return self::PRESENCE_BOTH;
        }
        if (in_array($value, ['only_left', 'left_only'], true)) {
            return self::PRESENCE_LEFT;
        }
        if (in_array($value, ['only_right', 'right_only'], true)) {
            return self::PRESENCE_RIGHT;
        }
        if (in_array($value, [self::PRESENCE_BOTH, self::PRESENCE_LEFT, self::PRESENCE_RIGHT, self::PRESENCE_ANY], true)) {
            return $value;
        }

        return self::PRESENCE_ANY;
    }

    private function normalizeSort(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, self::SORT_KEYS, true) ? $value : 'name';
    }

    private function clampPerPage(int $perPage): int
    {
        if (in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            return $perPage;
        }
        if ($perPage === 0) {
            return 0;
        }
        if ($perPage < 0) {
            return self::DEFAULT_PER_PAGE;
        }

        foreach (self::ALLOWED_PER_PAGE as $allowed) {
            if ($perPage <= $allowed) {
                return $allowed;
            }
        }

        return 200;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * One-pass project-name keys that match the Find query in a single catalog.
     *
     * @param array<string, mixed> $filter
     * @param array<string, string> $params
     */
    private function hitsSelectSql(
        string $sourceParam,
        string $prefix,
        array $filter,
        array &$params,
        string $projectCte
    ): string {
        $parsed = is_array($filter['parsed'] ?? null) ? $filter['parsed'] : [];
        if (
            ($filter['match_scope'] ?? 'all') === 'names'
            && $this->isTextOnlyFilter($filter)
        ) {
            return $this->nameHitsSql($projectCte, $prefix, $filter, $params);
        }
        if (
            empty($filter['fuzzy'])
            && $this->isTextOnlyFilter($filter)
            && $this->ftsAvailable()
        ) {
            $fts = $this->ftsHitsSql($sourceParam, $prefix, $filter, $params);
            if ($fts !== '') {
                return $fts;
            }
        }

        return $this->groupedHitsSql($sourceParam, $prefix, $filter, $params, $parsed);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function isTextOnlyFilter(array $filter): bool
    {
        $parsed = is_array($filter['parsed'] ?? null) ? $filter['parsed'] : [];

        return ($filter['types'] ?? []) === []
            && ($filter['extensions'] ?? []) === []
            && ($parsed['extensions'] ?? []) === []
            && ($parsed['types'] ?? []) === []
            && ($parsed['paths'] ?? []) === []
            && ($parsed['has'] ?? []) === []
            && ($parsed['lacks'] ?? []) === []
            && ($parsed['tags'] ?? []) === []
            && trim((string) ($parsed['person'] ?? '')) === ''
            && trim((string) ($parsed['modified_by'] ?? '')) === ''
            && trim((string) ($parsed['created_by'] ?? '')) === '';
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, string> $params
     */
    private function nameHitsSql(string $projectCte, string $prefix, array $filter, array &$params): string
    {
        $parsed = is_array($filter['parsed'] ?? null) ? $filter['parsed'] : [];
        $hay = 'LOWER(IFNULL(project_name, \'\'))';
        $parts = ['1=1'];
        $n = 0;
        $mode = ($filter['word_mode'] ?? 'and') === 'or' ? 'or' : 'and';
        $fuzzy = !empty($filter['fuzzy']);

        foreach ((array) ($parsed['excludes'] ?? []) as $exclude) {
            $exclude = trim((string) $exclude);
            if ($exclude === '') {
                continue;
            }
            $key = ':' . $prefix . 'nex' . $n++;
            $params[$key] = '%' . $this->escapeLike($exclude) . '%';
            $parts[] = "{$hay} NOT LIKE {$key} ESCAPE '\\'";
        }
        foreach ((array) ($parsed['phrases'] ?? []) as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            $key = ':' . $prefix . 'nph' . $n++;
            $params[$key] = '%' . $this->escapeLike($phrase) . '%';
            $parts[] = "{$hay} LIKE {$key} ESCAPE '\\'";
        }
        $wordClauses = [];
        foreach ((array) ($parsed['words'] ?? []) as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }
            $exactKey = ':' . $prefix . 'nw' . $n++;
            $params[$exactKey] = '%' . $this->escapeLike($word) . '%';
            $wordSql = "{$hay} LIKE {$exactKey} ESCAPE '\\'";
            if ($fuzzy && mb_strlen($word) >= 3) {
                $fuzzyKey = ':' . $prefix . 'nwf' . $n++;
                $params[$fuzzyKey] = $this->fuzzyLike($word);
                $wordSql = "({$wordSql} OR {$hay} LIKE {$fuzzyKey} ESCAPE '\\')";
            }
            $wordClauses[] = $wordSql;
        }
        if ($wordClauses !== []) {
            $parts[] = '(' . implode($mode === 'or' ? ' OR ' : ' AND ', $wordClauses) . ')';
        }

        return "SELECT name_key FROM {$projectCte} WHERE " . implode(' AND ', $parts);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, string> $params
     */
    private function ftsHitsSql(string $sourceParam, string $prefix, array $filter, array &$params): string
    {
        $parsed = is_array($filter['parsed'] ?? null) ? $filter['parsed'] : [];
        $mode = ($filter['word_mode'] ?? 'and') === 'or' ? 'or' : 'and';
        $visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items_fts');
        $column = $this->ftsColumnPrefix((string) ($filter['match_scope'] ?? 'all'), !empty($filter['deep']));
        $sets = [];
        $n = 0;

        $pushMatch = function (string $match) use (&$sets, &$params, &$n, $prefix, $sourceParam, $visible, $column): void {
            if ($match === '') {
                return;
            }
            $key = ':' . $prefix . 'fts' . $n++;
            $params[$key] = $column . $match;
            $sets[] = "SELECT DISTINCT LOWER(TRIM(project_name)) AS name_key
                FROM sharepoint_items_fts
                WHERE source_key = {$sourceParam}
                  AND TRIM(project_name) != ''
                  AND sharepoint_items_fts MATCH {$key}
                  AND {$visible}";
        };

        foreach ((array) ($parsed['phrases'] ?? []) as $phrase) {
            $token = $this->ftsToken((string) $phrase, false);
            if ($token === '') {
                return '';
            }
            $pushMatch($token);
        }
        $wordMatches = [];
        foreach ((array) ($parsed['words'] ?? []) as $word) {
            $token = $this->ftsToken((string) $word, true);
            if ($token === '') {
                return '';
            }
            $wordMatches[] = $token;
        }
        if ($wordMatches !== []) {
            if ($mode === 'or') {
                $pushMatch(implode(' OR ', $wordMatches));
            } else {
                foreach ($wordMatches as $token) {
                    $pushMatch($token);
                }
            }
        }
        if ($sets === []) {
            $sets[] = "SELECT DISTINCT LOWER(TRIM(project_name)) AS name_key
                FROM sharepoint_items
                WHERE source_key = {$sourceParam}
                  AND TRIM(project_name) != ''";
        }

        $sql = $sets[0];
        if (count($sets) > 1) {
            $sql = implode("\nINTERSECT\n", $sets);
        }
        foreach ((array) ($parsed['excludes'] ?? []) as $exclude) {
            $token = $this->ftsToken((string) $exclude, true);
            if ($token === '') {
                return '';
            }
            $key = ':' . $prefix . 'ftsx' . $n++;
            $params[$key] = $column . $token;
            $sql .= "\nEXCEPT\nSELECT DISTINCT LOWER(TRIM(project_name)) AS name_key
                FROM sharepoint_items_fts
                WHERE source_key = {$sourceParam}
                  AND TRIM(project_name) != ''
                  AND sharepoint_items_fts MATCH {$key}
                  AND {$visible}";
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $parsed
     * @param array<string, string> $params
     */
    private function groupedHitsSql(
        string $sourceParam,
        string $prefix,
        array $filter,
        array &$params,
        array $parsed
    ): string {
        $visible = SharePointArchiveRepository::visibleProjectSql('i');
        $hay = $this->haystackSql('i', (string) ($filter['match_scope'] ?? 'all'), !empty($filter['deep']));
        $mode = ($filter['word_mode'] ?? 'and') === 'or' ? 'or' : 'and';
        $fuzzy = !empty($filter['fuzzy']);
        $having = [];
        $n = 0;

        foreach ((array) ($parsed['excludes'] ?? []) as $exclude) {
            $exclude = trim((string) $exclude);
            if ($exclude === '') {
                continue;
            }
            $key = ':' . $prefix . 'ex' . $n++;
            $params[$key] = '%' . $this->escapeLike($exclude) . '%';
            $having[] = "SUM(CASE WHEN ({$hay}) LIKE {$key} ESCAPE '\\' THEN 1 ELSE 0 END) = 0";
        }
        foreach ((array) ($parsed['phrases'] ?? []) as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            $key = ':' . $prefix . 'ph' . $n++;
            $params[$key] = '%' . $this->escapeLike($phrase) . '%';
            $having[] = "SUM(CASE WHEN ({$hay}) LIKE {$key} ESCAPE '\\' THEN 1 ELSE 0 END) > 0";
        }
        $wordClauses = [];
        foreach ((array) ($parsed['words'] ?? []) as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }
            $exactKey = ':' . $prefix . 'w' . $n++;
            $params[$exactKey] = '%' . $this->escapeLike($word) . '%';
            $wordSql = "({$hay}) LIKE {$exactKey} ESCAPE '\\'";
            if ($fuzzy && mb_strlen($word) >= 3) {
                $fuzzyKey = ':' . $prefix . 'wf' . $n++;
                $params[$fuzzyKey] = $this->fuzzyLike($word);
                $wordSql = "({$wordSql} OR ({$hay}) LIKE {$fuzzyKey} ESCAPE '\\')";
            }
            $wordClauses[] = "SUM(CASE WHEN {$wordSql} THEN 1 ELSE 0 END) > 0";
        }
        if ($wordClauses !== []) {
            $having[] = '(' . implode($mode === 'or' ? ' OR ' : ' AND ', $wordClauses) . ')';
        }
        foreach ((array) ($parsed['paths'] ?? []) as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $key = ':' . $prefix . 'path' . $n++;
            $params[$key] = '%' . $this->escapeLike($path) . '%';
            $having[] = "SUM(CASE WHEN LOWER(IFNULL(i.relative_path, '')) LIKE {$key} ESCAPE '\\' THEN 1 ELSE 0 END) > 0";
        }

        $viewer = is_array($filter['viewer'] ?? null) ? $filter['viewer'] : [];
        $person = $this->resolveViewerAlias((string) ($parsed['person'] ?? ''), $viewer);
        if ($person !== '') {
            $key = ':' . $prefix . 'who' . $n++;
            $params[$key] = '%' . $this->escapeLike($person) . '%';
            $having[] = "SUM(CASE WHEN LOWER(IFNULL(i.modified_by, '')) LIKE {$key} ESCAPE '\\'
                OR LOWER(IFNULL(i.person, '')) LIKE {$key} ESCAPE '\\' THEN 1 ELSE 0 END) > 0";
        }
        $modifiedBy = $this->resolveViewerAlias((string) ($parsed['modified_by'] ?? ''), $viewer);
        if ($modifiedBy !== '') {
            $key = ':' . $prefix . 'mod' . $n++;
            $params[$key] = '%' . $this->escapeLike($modifiedBy) . '%';
            $having[] = "SUM(CASE WHEN LOWER(IFNULL(i.modified_by, '')) LIKE {$key} ESCAPE '\\' THEN 1 ELSE 0 END) > 0";
        }
        $createdBy = $this->resolveViewerAlias((string) ($parsed['created_by'] ?? ''), $viewer);
        if ($createdBy !== '') {
            $key = ':' . $prefix . 'cre' . $n++;
            $params[$key] = '%' . $this->escapeLike($createdBy) . '%';
            $having[] = "SUM(CASE WHEN LOWER(IFNULL(i.person, '')) LIKE {$key} ESCAPE '\\' THEN 1 ELSE 0 END) > 0";
        }

        $exts = array_values(array_unique(array_merge(
            array_map('strval', (array) ($parsed['extensions'] ?? [])),
            array_map('strval', (array) ($filter['extensions'] ?? []))
        )));
        foreach ($exts as $ext) {
            $clause = $this->traitHavingSql((string) $ext, $prefix, $n, $params);
            if ($clause !== '') {
                $having[] = $clause;
            }
        }
        $traits = array_values(array_unique(array_merge(
            array_map('strval', (array) ($parsed['types'] ?? [])),
            array_map('strval', (array) ($parsed['has'] ?? [])),
            array_map('strval', (array) ($filter['types'] ?? []))
        )));
        foreach ($traits as $trait) {
            $clause = $this->traitHavingSql((string) $trait, $prefix, $n, $params);
            if ($clause !== '') {
                $having[] = $clause;
            }
        }
        foreach ((array) ($parsed['lacks'] ?? []) as $trait) {
            $clause = $this->traitHavingSql((string) $trait, $prefix, $n, $params);
            if ($clause !== '') {
                $having[] = 'NOT (' . $clause . ')';
            }
        }

        $where = "i.source_key = {$sourceParam} AND TRIM(i.project_name) != '' AND {$visible}";
        foreach ((array) ($parsed['tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }
            $key = ':' . $prefix . 'tag' . $n++;
            $params[$key] = '%' . $this->escapeLike($tag) . '%';
            $where .= " AND LOWER(TRIM(i.project_name)) IN (
                SELECT LOWER(TRIM(_ta.project_name))
                FROM sharepoint_search_tag_assignments _ta
                JOIN sharepoint_search_tags _tg ON _tg.id = _ta.tag_id
                WHERE _ta.source_key = {$sourceParam}
                  AND (
                      LOWER(IFNULL(_tg.label, '')) LIKE {$key} ESCAPE '\\'
                      OR LOWER(IFNULL(_tg.slug, '')) LIKE {$key} ESCAPE '\\'
                  )
            )";
        }

        $havingSql = $having === [] ? '1=1' : implode(' AND ', $having);

        return "SELECT LOWER(TRIM(i.project_name)) AS name_key
            FROM sharepoint_items i
            WHERE {$where}
            GROUP BY LOWER(TRIM(i.project_name))
            HAVING {$havingSql}";
    }

    /**
     * @param array<string, string> $params
     */
    private function traitHavingSql(string $trait, string $prefix, int &$n, array &$params): string
    {
        $trait = SharePointCatalogQuery::normalizeType($trait);
        if ($trait === '') {
            return '';
        }
        if ($trait === 'empty') {
            return "SUM(CASE WHEN LOWER(i.item_type) = 'file' THEN 1 ELSE 0 END) = 0";
        }
        if ($trait === 'stale') {
            return "SUM(CASE WHEN IFNULL(i.last_modified, '') != '' AND i.last_modified >= datetime('now', '-90 days') THEN 1 ELSE 0 END) = 0";
        }
        if ($trait === 'folders') {
            return "SUM(CASE WHEN LOWER(i.item_type) = 'folder'
                AND TRIM(IFNULL(i.relative_path, '')) NOT IN ('', i.project_name, i.name) THEN 1 ELSE 0 END) > 0";
        }
        if ($trait === 'drawings') {
            $key = ':' . $prefix . 'draw' . $n++;
            $params[$key] = '%drawing%';

            return "SUM(CASE WHEN LOWER(IFNULL(i.name, '')) LIKE {$key} ESCAPE '\\'
                OR LOWER(IFNULL(i.relative_path, '')) LIKE {$key} ESCAPE '\\' THEN 1 ELSE 0 END) > 0";
        }

        $exts = match ($trait) {
            'pdf' => ['pdf'],
            'visio' => ['vsdx', 'vsd'],
            'word', 'doc', 'docx' => ['doc', 'docx'],
            'excel', 'xls', 'xlsx' => ['xls', 'xlsx', 'xlsm', 'csv'],
            'powerpoint', 'ppt', 'pptx' => ['ppt', 'pptx'],
            'email', 'msg' => ['msg', 'eml'],
            'archive', 'zip' => ['zip', '7z', 'rar'],
            'cad' => ['dwg', 'dxf'],
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'],
            default => [$trait],
        };
        $ors = [];
        foreach ($exts as $ext) {
            $key = ':' . $prefix . 'ext' . $n++;
            $params[$key] = '%.' . $this->escapeLike($ext);
            $ors[] = "LOWER(IFNULL(i.name, '')) LIKE {$key} ESCAPE '\\'";
        }

        return 'SUM(CASE WHEN LOWER(i.item_type) = \'file\' AND (' . implode(' OR ', $ors) . ') THEN 1 ELSE 0 END) > 0';
    }

    private function ftsAvailable(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        try {
            $exists = $this->pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sharepoint_items_fts' LIMIT 1"
            );
            $available = $exists !== false && $exists->fetchColumn() !== false;
        } catch (\Throwable) {
            $available = false;
        }

        return $available;
    }

    private function ftsColumnPrefix(string $scope, bool $deep): string
    {
        return match ($scope) {
            'names' => '{project_name}: ',
            'people' => '{modified_by person}: ',
            'files' => '{name relative_path}: ',
            default => $deep ? '' : '{project_name modified_by person}: ',
        };
    }

    private function ftsToken(string $word, bool $prefix): string
    {
        $safe = preg_replace('/[^\p{L}\p{N}_.\-\s]+/u', '', trim($word)) ?? '';
        $safe = trim((string) preg_replace('/\s+/u', ' ', $safe));
        $safe = str_replace('"', '', $safe);
        if ($safe === '') {
            return '';
        }

        return $prefix ? '"' . $safe . '"*' : '"' . $safe . '"';
    }

    private function haystackSql(string $alias, string $scope, bool $deep): string
    {
        if ($scope === 'names') {
            return "LOWER(IFNULL({$alias}.project_name, ''))";
        }
        if ($scope === 'people') {
            return "LOWER(IFNULL({$alias}.modified_by, '') || char(10) || IFNULL({$alias}.person, ''))";
        }
        if ($scope === 'files') {
            return "LOWER(IFNULL({$alias}.name, '') || char(10) || IFNULL({$alias}.relative_path, ''))";
        }
        if (!$deep) {
            return "LOWER(IFNULL({$alias}.project_name, '') || char(10) || IFNULL({$alias}.modified_by, '') || char(10) || IFNULL({$alias}.person, ''))";
        }

        return "LOWER(IFNULL({$alias}.project_name, '') || char(10) || IFNULL({$alias}.name, '') || char(10) || IFNULL({$alias}.relative_path, '') || char(10) || IFNULL({$alias}.modified_by, '') || char(10) || IFNULL({$alias}.person, ''))";
    }

    private function fuzzyLike(string $word): string
    {
        $chars = preg_split('//u', mb_strtolower($word), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $safe = array_map(fn (string $char): string => $this->escapeLike($char), $chars);

        return '%' . implode('%', $safe) . '%';
    }

    /**
     * @param array<string, mixed> $viewer
     */
    private function resolveViewerAlias(string $value, array $viewer): string
    {
        $value = mb_strtolower(trim($value));
        if ($value !== 'me') {
            return $value;
        }
        foreach (['display', 'name', 'email'] as $key) {
            $candidate = mb_strtolower(trim((string) ($viewer[$key] ?? '')));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'me';
    }

    private function normalizeMatchScope(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['all', 'names', 'files', 'people'], true) ? $value : 'all';
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function csvList(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $out = [];
        foreach ($parts as $part) {
            $part = strtolower(trim((string) $part));
            $part = ltrim($part, '.');
            if ($part !== '') {
                $out[] = SharePointCatalogQuery::normalizeType($part);
            }
        }

        return array_values(array_unique($out));
    }
}
