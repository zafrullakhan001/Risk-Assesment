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
        $searchSql = '';
        if (SharePointCatalogQuery::isActive($parsed, $extraTypes, $extraExts)) {
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
            $leftMatch = $this->sideMatchesSql(':left_key', 'm.left_name', 'm.name_key', 'lft', $filter, $params);
            $rightMatch = $this->sideMatchesSql(':right_key', 'm.right_name', 'm.name_key', 'rgt', $filter, $params);
            $searchSql = " AND (({$leftMatch}) OR ({$rightMatch}))";
        }

        $matchedSql = $this->matchedSql($visibleSql);
        $totals = $this->loadTotals($matchedSql, $searchSql, $params);
        $filteredCount = match ($presence) {
            self::PRESENCE_BOTH => $totals['in_both'],
            self::PRESENCE_LEFT => $totals['only_left'],
            self::PRESENCE_RIGHT => $totals['only_right'],
            default => $totals['all'],
        };

        $pageCount = max(1, (int) ceil($filteredCount / $perPage));
        if ($page > $pageCount) {
            $page = $pageCount;
        }
        $offset = ($page - 1) * $perPage;

        $presenceSql = '';
        if ($presence !== self::PRESENCE_ANY) {
            $presenceSql = ' AND m.presence = :presence';
            $params[':presence'] = $presence;
        }

        $orderSql = $this->orderSql($sort, $dir);
        $listSql = "{$matchedSql}
            SELECT
                m.name_key,
                m.presence,
                m.left_name,
                m.right_name,
                m.left_item_count,
                m.right_item_count,
                m.left_file_count,
                m.right_file_count,
                m.left_folder_count,
                m.right_folder_count,
                m.left_modified,
                m.right_modified
            FROM matched m
            WHERE 1=1
            {$searchSql}
            {$presenceSql}
            {$orderSql}
            LIMIT :lim OFFSET :off";

        $statement = $this->pdo->prepare($listSql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':off', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rawRows = $statement->fetchAll() ?: [];

        $leftNames = [];
        $rightNames = [];
        foreach ($rawRows as $row) {
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
        foreach ($rawRows as $row) {
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
            'left' => $this->catalogMeta($leftKey, $leftTitle, $includeArchived),
            'right' => $this->catalogMeta($rightKey, $rightTitle, $includeArchived),
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

    /**
     * @param array<string, mixed> $params
     * @return array{in_both: int, only_left: int, only_right: int, all: int}
     */
    private function loadTotals(string $matchedSql, string $searchSql, array $params): array
    {
        $sql = "{$matchedSql}
            SELECT
                SUM(CASE WHEN m.presence = 'both' THEN 1 ELSE 0 END) AS in_both,
                SUM(CASE WHEN m.presence = 'left' THEN 1 ELSE 0 END) AS only_left,
                SUM(CASE WHEN m.presence = 'right' THEN 1 ELSE 0 END) AS only_right,
                COUNT(*) AS all_count
            FROM matched m
            WHERE 1=1
            {$searchSql}";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch() ?: [];

        return [
            'in_both' => (int) ($row['in_both'] ?? 0),
            'only_left' => (int) ($row['only_left'] ?? 0),
            'only_right' => (int) ($row['only_right'] ?? 0),
            'all' => (int) ($row['all_count'] ?? 0),
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

    private function orderSql(string $sort, string $dir): string
    {
        $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
        $nameOrder = "m.name_key COLLATE NOCASE ASC";
        if ($sort === 'presence') {
            return "ORDER BY CASE m.presence WHEN 'both' THEN 0 WHEN 'left' THEN 1 ELSE 2 END {$dirSql}, {$nameOrder}";
        }
        if ($sort === 'items') {
            return "ORDER BY (m.left_item_count + m.right_item_count) {$dirSql}, {$nameOrder}";
        }
        if ($sort === 'modified') {
            return "ORDER BY
                CASE
                    WHEN CASE WHEN m.left_modified >= m.right_modified THEN m.left_modified ELSE m.right_modified END = '' THEN 1
                    ELSE 0
                END ASC,
                CASE WHEN m.left_modified >= m.right_modified THEN m.left_modified ELSE m.right_modified END {$dirSql},
                {$nameOrder}";
        }

        return "ORDER BY m.name_key COLLATE NOCASE {$dirSql}";
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
            : SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
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
                project_name,
                COUNT(*) AS item_count,
                SUM(CASE WHEN LOWER(item_type) = 'file' THEN 1 ELSE 0 END) AS file_count,
                SUM(CASE WHEN LOWER(item_type) = 'folder' THEN 1 ELSE 0 END) AS folder_count,
                MAX(last_modified) AS last_modified,
                MAX(CASE
                    WHEN LOWER(item_type) = 'folder'
                     AND (relative_path = '' OR relative_path = project_name OR relative_path = name)
                    THEN web_url ELSE ''
                END) AS folder_url
             FROM sharepoint_items
             WHERE source_key = :source_key
               AND project_name IN ({$inList})
               AND {$visibleSql}
             GROUP BY project_name"
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
            'folder_url' => '',
        ];
    }

    /**
     * @return array{source_key: string, title: string, project_count: int, item_count: int}
     */
    private function catalogMeta(string $sourceKey, string $title, bool $includeArchived): array
    {
        $visibleSql = $includeArchived
            ? '1=1'
            : SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
        $itemStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sharepoint_items
             WHERE source_key = :source_key AND {$visibleSql}"
        );
        $itemStmt->execute([':source_key' => $sourceKey]);
        $projectStmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT LOWER(TRIM(project_name))) FROM sharepoint_items
             WHERE source_key = :source_key
               AND TRIM(project_name) != ''
               AND {$visibleSql}"
        );
        $projectStmt->execute([':source_key' => $sourceKey]);

        return [
            'source_key' => $sourceKey,
            'title' => $title,
            'project_count' => (int) $projectStmt->fetchColumn(),
            'item_count' => (int) $itemStmt->fetchColumn(),
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
        if ($perPage <= 0) {
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
     * @param array<string, mixed> $filter
     * @param array<string, string> $params
     */
    private function sideMatchesSql(
        string $sourceParam,
        string $nameExpr,
        string $nameKeyExpr,
        string $prefix,
        array $filter,
        array &$params
    ): string {
        $parts = ["{$nameExpr} IS NOT NULL", "TRIM({$nameExpr}) != ''"];
        $parsed = is_array($filter['parsed'] ?? null) ? $filter['parsed'] : [];
        $alias = 'i_' . $prefix;
        $projectPred = "{$alias}.source_key = {$sourceParam} AND LOWER(TRIM({$alias}.project_name)) = {$nameKeyExpr}";
        $hay = $this->haystackSql($alias, (string) ($filter['match_scope'] ?? 'all'), !empty($filter['deep']));
        $mode = ($filter['word_mode'] ?? 'and') === 'or' ? 'or' : 'and';
        $fuzzy = !empty($filter['fuzzy']);
        $n = 0;

        foreach ((array) ($parsed['excludes'] ?? []) as $exclude) {
            $exclude = trim((string) $exclude);
            if ($exclude === '') {
                continue;
            }
            $key = ':' . $prefix . 'ex' . $n++;
            $params[$key] = '%' . $this->escapeLike($exclude) . '%';
            $parts[] = "NOT EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND ({$hay}) LIKE {$key} ESCAPE '\\'
            )";
        }

        foreach ((array) ($parsed['phrases'] ?? []) as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            $key = ':' . $prefix . 'ph' . $n++;
            $params[$key] = '%' . $this->escapeLike($phrase) . '%';
            $parts[] = "EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND ({$hay}) LIKE {$key} ESCAPE '\\'
            )";
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
            $wordClauses[] = "EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND {$wordSql}
            )";
        }
        if ($wordClauses !== []) {
            $parts[] = '(' . implode($mode === 'or' ? ' OR ' : ' AND ', $wordClauses) . ')';
        }

        foreach ((array) ($parsed['paths'] ?? []) as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $key = ':' . $prefix . 'path' . $n++;
            $params[$key] = '%' . $this->escapeLike($path) . '%';
            $parts[] = "EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND LOWER(IFNULL({$alias}.relative_path, '')) LIKE {$key} ESCAPE '\\'
            )";
        }

        $viewer = is_array($filter['viewer'] ?? null) ? $filter['viewer'] : [];
        $person = $this->resolveViewerAlias((string) ($parsed['person'] ?? ''), $viewer);
        if ($person !== '') {
            $key = ':' . $prefix . 'who' . $n++;
            $params[$key] = '%' . $this->escapeLike($person) . '%';
            $parts[] = "EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND (
                    LOWER(IFNULL({$alias}.modified_by, '')) LIKE {$key} ESCAPE '\\'
                    OR LOWER(IFNULL({$alias}.person, '')) LIKE {$key} ESCAPE '\\'
                )
            )";
        }
        $modifiedBy = $this->resolveViewerAlias((string) ($parsed['modified_by'] ?? ''), $viewer);
        if ($modifiedBy !== '') {
            $key = ':' . $prefix . 'mod' . $n++;
            $params[$key] = '%' . $this->escapeLike($modifiedBy) . '%';
            $parts[] = "EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND LOWER(IFNULL({$alias}.modified_by, '')) LIKE {$key} ESCAPE '\\'
            )";
        }
        $createdBy = $this->resolveViewerAlias((string) ($parsed['created_by'] ?? ''), $viewer);
        if ($createdBy !== '') {
            $key = ':' . $prefix . 'cre' . $n++;
            $params[$key] = '%' . $this->escapeLike($createdBy) . '%';
            $parts[] = "EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND LOWER(IFNULL({$alias}.person, '')) LIKE {$key} ESCAPE '\\'
            )";
        }

        $exts = array_values(array_unique(array_merge(
            array_map('strval', (array) ($parsed['extensions'] ?? [])),
            array_map('strval', (array) ($filter['extensions'] ?? []))
        )));
        foreach ($exts as $ext) {
            $ext = ltrim(strtolower(trim($ext)), '.');
            if ($ext === '') {
                continue;
            }
            $parts[] = $this->traitExistsSql($alias, $projectPred, $ext, $prefix, $n, $params);
        }

        $traits = array_values(array_unique(array_merge(
            array_map('strval', (array) ($parsed['types'] ?? [])),
            array_map('strval', (array) ($parsed['has'] ?? [])),
            array_map('strval', (array) ($filter['types'] ?? []))
        )));
        foreach ($traits as $trait) {
            $clause = $this->traitExistsSql($alias, $projectPred, $trait, $prefix, $n, $params, $nameExpr);
            if ($clause !== '') {
                $parts[] = $clause;
            }
        }
        foreach ((array) ($parsed['lacks'] ?? []) as $trait) {
            $clause = $this->traitExistsSql($alias, $projectPred, (string) $trait, $prefix, $n, $params, $nameExpr);
            if ($clause !== '') {
                $parts[] = 'NOT ' . $clause;
            }
        }

        foreach ((array) ($parsed['tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }
            $key = ':' . $prefix . 'tag' . $n++;
            $params[$key] = '%' . $this->escapeLike($tag) . '%';
            $parts[] = "EXISTS (
                SELECT 1
                FROM sharepoint_search_tag_assignments _ta
                JOIN sharepoint_search_tags _tg ON _tg.id = _ta.tag_id
                WHERE _ta.source_key = {$sourceParam}
                  AND LOWER(TRIM(_ta.project_name)) = {$nameKeyExpr}
                  AND (
                      LOWER(IFNULL(_tg.label, '')) LIKE {$key} ESCAPE '\\'
                      OR LOWER(IFNULL(_tg.slug, '')) LIKE {$key} ESCAPE '\\'
                  )
            )";
        }

        return implode(' AND ', $parts);
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

    /**
     * @param array<string, string> $params
     */
    private function traitExistsSql(
        string $alias,
        string $projectPred,
        string $trait,
        string $prefix,
        int &$n,
        array &$params,
        string $nameExpr = ''
    ): string {
        $trait = SharePointCatalogQuery::normalizeType($trait);
        if ($trait === '') {
            return '';
        }
        if ($trait === 'empty') {
            return "NOT EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND LOWER({$alias}.item_type) = 'file'
            )";
        }
        if ($trait === 'stale') {
            return "(
                NOT EXISTS (
                    SELECT 1 FROM sharepoint_items {$alias}
                    WHERE {$projectPred} AND IFNULL({$alias}.last_modified, '') != ''
                      AND {$alias}.last_modified >= datetime('now', '-90 days')
                )
            )";
        }
        if ($trait === 'folders') {
            $exclude = ["''", "{$alias}.name"];
            if (trim($nameExpr) !== '') {
                $exclude[] = $nameExpr;
            }

            return "EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred}
                  AND LOWER({$alias}.item_type) = 'folder'
                  AND TRIM(IFNULL({$alias}.relative_path, '')) NOT IN (" . implode(', ', $exclude) . ')
            )';
        }
        if ($trait === 'drawings') {
            $key = ':' . $prefix . 'draw' . $n++;
            $params[$key] = '%drawing%';
            return "EXISTS (
                SELECT 1 FROM sharepoint_items {$alias}
                WHERE {$projectPred} AND (
                    LOWER(IFNULL({$alias}.name, '')) LIKE {$key} ESCAPE '\\'
                    OR LOWER(IFNULL({$alias}.relative_path, '')) LIKE {$key} ESCAPE '\\'
                )
            )";
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
            $ors[] = "LOWER(IFNULL({$alias}.name, '')) LIKE {$key} ESCAPE '\\'";
        }

        return "EXISTS (
            SELECT 1 FROM sharepoint_items {$alias}
            WHERE {$projectPred}
              AND LOWER({$alias}.item_type) = 'file'
              AND (" . implode(' OR ', $ors) . ')
        )';
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
