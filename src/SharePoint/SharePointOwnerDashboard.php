<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Aggregates SharePoint catalog project folders by owner (Created By)
 * and by the month / quarter / year they were created.
 */
final class SharePointOwnerDashboard
{
    public const UNASSIGNED_KEY = '_unassigned';
    public const UNASSIGNED_NAME = 'Unassigned';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles keyed by source_key
     * @return array<string, mixed>
     */
    public function build(array $sourceKeys, array $sourceTitles = []): array
    {
        $sourceKeys = $this->normalizeKeys($sourceKeys);
        if ($sourceKeys === []) {
            return $this->emptyPayload([]);
        }

        $projects = $this->collectProjects($sourceKeys, $sourceTitles);
        if ($projects === []) {
            return $this->emptyPayload($this->sourceMeta($sourceKeys, $sourceTitles, []));
        }

        $owners = [];
        $byMonth = [];
        $byQuarter = [];
        $byYear = [];
        $unknownDate = 0;
        $datedIsos = [];
        $sourceCounts = [];

        foreach ($projects as $project) {
            $ownerKey = (string) $project['owner_key'];
            if (!isset($owners[$ownerKey])) {
                $owners[$ownerKey] = [
                    'key' => $ownerKey,
                    'name' => (string) $project['owner_name'],
                    'initials' => $this->initials((string) $project['owner_name']),
                    'hue' => $this->hue($ownerKey),
                    'project_count' => 0,
                    'unknown_date_count' => 0,
                    'first_created' => '',
                    'last_created' => '',
                    'streak_months' => 0,
                    'last_12_months' => 0,
                    'this_year' => 0,
                    'sources' => [],
                    'years' => [],
                    'months' => [],
                    'quarters' => [],
                    'projects' => [],
                ];
            }

            $owners[$ownerKey]['project_count']++;
            $owners[$ownerKey]['projects'][] = $project;

            $srcKey = (string) $project['source_key'];
            $owners[$ownerKey]['sources'][$srcKey] = ($owners[$ownerKey]['sources'][$srcKey] ?? 0) + 1;
            $sourceCounts[$srcKey] = ($sourceCounts[$srcKey] ?? 0) + 1;

            $monthKey = (string) ($project['month'] ?? '');
            $quarterKey = (string) ($project['quarter'] ?? '');
            $yearKey = (string) ($project['year'] ?? '');
            $iso = (string) ($project['date_created'] ?? '');

            if ($monthKey === '') {
                $unknownDate++;
                $owners[$ownerKey]['unknown_date_count']++;
                continue;
            }

            $datedIsos[] = $iso !== '' ? $iso : ($monthKey . '-01');
            $owners[$ownerKey]['months'][$monthKey] = ($owners[$ownerKey]['months'][$monthKey] ?? 0) + 1;
            $owners[$ownerKey]['quarters'][$quarterKey] = ($owners[$ownerKey]['quarters'][$quarterKey] ?? 0) + 1;
            $owners[$ownerKey]['years'][$yearKey] = ($owners[$ownerKey]['years'][$yearKey] ?? 0) + 1;

            if ($owners[$ownerKey]['first_created'] === '' || strcmp($iso, $owners[$ownerKey]['first_created']) < 0) {
                $owners[$ownerKey]['first_created'] = $iso;
            }
            if ($owners[$ownerKey]['last_created'] === '' || strcmp($iso, $owners[$ownerKey]['last_created']) > 0) {
                $owners[$ownerKey]['last_created'] = $iso;
            }

            $this->bumpPeriod($byMonth, $monthKey, $ownerKey);
            $this->bumpPeriod($byQuarter, $quarterKey, $ownerKey);
            $this->bumpPeriod($byYear, $yearKey, $ownerKey);
        }

        $nowYear = date('Y');
        $since12 = (new DateTimeImmutable('first day of this month'))->modify('-11 months')->format('Y-m');
        $ownerList = [];
        $totalProjects = count($projects);

        foreach ($owners as $owner) {
            ksort($owner['months']);
            ksort($owner['quarters']);
            ksort($owner['years']);
            usort(
                $owner['projects'],
                static fn (array $a, array $b): int => strcmp((string) ($b['date_created'] ?? ''), (string) ($a['date_created'] ?? ''))
            );
            $owner['share'] = $totalProjects > 0
                ? round($owner['project_count'] / $totalProjects, 4)
                : 0.0;
            $owner['last_12_months'] = 0;
            $owner['this_year'] = (int) ($owner['years'][$nowYear] ?? 0);
            foreach ($owner['months'] as $monthKey => $count) {
                if (strcmp((string) $monthKey, $since12) >= 0) {
                    $owner['last_12_months'] += (int) $count;
                }
            }
            $owner['streak_months'] = $this->trailingStreak(array_keys($owner['months']));
            $ownerList[] = $owner;
        }

        usort(
            $ownerList,
            static function (array $a, array $b): int {
                $cmp = ((int) $b['project_count']) <=> ((int) $a['project_count']);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp((string) $a['name'], (string) $b['name']);
            }
        );

        $monthKeys = $this->fillMonthRange(array_keys($byMonth));
        $quarterKeys = $this->fillQuarterRange(array_keys($byQuarter));
        $yearKeys = $this->fillYearRange(array_keys($byYear));

        $busiestOwner = $ownerList[0] ?? null;
        $busiestMonthKey = '';
        $busiestMonthCount = 0;
        foreach ($byMonth as $key => $bucket) {
            $total = (int) ($bucket['total'] ?? 0);
            if ($total > $busiestMonthCount) {
                $busiestMonthCount = $total;
                $busiestMonthKey = (string) $key;
            }
        }

        $thisYearCount = (int) (($byYear[$nowYear]['total'] ?? 0));
        $last12Count = 0;
        foreach ($byMonth as $key => $bucket) {
            if (strcmp((string) $key, $since12) >= 0) {
                $last12Count += (int) ($bucket['total'] ?? 0);
            }
        }

        $assignedCount = 0;
        foreach ($ownerList as $owner) {
            if ((string) $owner['key'] !== self::UNASSIGNED_KEY) {
                $assignedCount += (int) $owner['project_count'];
            }
        }

        sort($datedIsos);
        $yearMin = $yearKeys[0] ?? null;
        $yearMax = $yearKeys !== [] ? $yearKeys[count($yearKeys) - 1] : null;

        return [
            'sources' => $this->sourceMeta($sourceKeys, $sourceTitles, $sourceCounts),
            'kpis' => [
                'project_count' => $totalProjects,
                'owner_count' => count($ownerList),
                'assigned_count' => $assignedCount,
                'unassigned_count' => $totalProjects - $assignedCount,
                'unknown_date_count' => $unknownDate,
                'year_min' => $yearMin,
                'year_max' => $yearMax,
                'this_year_count' => $thisYearCount,
                'last_12_months' => $last12Count,
                'busiest_owner' => $busiestOwner['name'] ?? '',
                'busiest_owner_key' => $busiestOwner['key'] ?? '',
                'busiest_owner_count' => (int) ($busiestOwner['project_count'] ?? 0),
                'busiest_period' => $busiestMonthKey,
                'busiest_period_count' => $busiestMonthCount,
            ],
            'owners' => $ownerList,
            'timeline' => [
                'months' => $monthKeys,
                'quarters' => $quarterKeys,
                'years' => $yearKeys,
                'by_month' => $this->orderBuckets($byMonth, $monthKeys),
                'by_quarter' => $this->orderBuckets($byQuarter, $quarterKeys),
                'by_year' => $this->orderBuckets($byYear, $yearKeys),
            ],
        ];
    }

    /**
     * @param list<string> $sourceKeys
     * @param array<string, string> $sourceTitles
     * @return list<array<string, mixed>>
     */
    private function collectProjects(array $sourceKeys, array $sourceTitles): array
    {
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT source_key, project_name, name, item_type, relative_path, web_url,
                    date_created, last_modified, person
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
               AND item_type = 'folder'
               AND (
                    relative_path = ''
                    OR relative_path = project_name
                    OR relative_path = name
               )
             ORDER BY source_key ASC, LOWER(project_name) ASC, id ASC"
        );
        $statement->execute($sourceKeys);
        $rows = $statement->fetchAll() ?: [];

        $countStmt = $this->pdo->prepare(
            "SELECT source_key, project_name, COUNT(*) AS item_count
             FROM sharepoint_items
             WHERE source_key IN ($placeholders)
             GROUP BY source_key, project_name"
        );
        $countStmt->execute($sourceKeys);
        $itemCounts = [];
        foreach ($countStmt->fetchAll() ?: [] as $countRow) {
            $itemCounts[(string) $countRow['source_key'] . "\n" . (string) $countRow['project_name']] = (int) $countRow['item_count'];
        }

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $projectName = trim((string) ($row['project_name'] ?? ''));
            $sourceKey = trim((string) ($row['source_key'] ?? ''));
            if ($projectName === '' || $sourceKey === '') {
                continue;
            }
            $groupKey = $sourceKey . "\n" . $projectName;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'project_name' => $projectName,
                    'source_key' => $sourceKey,
                    'folder_url' => '',
                    'item_count' => $itemCounts[$groupKey] ?? 0,
                    'root_person' => '',
                    'root_created' => '',
                    'people' => [],
                    'created_candidates' => [],
                ];
            }

            $groups[$groupKey]['item_count'] = $itemCounts[$groupKey] ?? max(1, (int) $groups[$groupKey]['item_count']);
            $name = trim((string) ($row['name'] ?? ''));
            $path = trim((string) ($row['relative_path'] ?? ''));
            $itemType = strtolower((string) ($row['item_type'] ?? 'file')) === 'folder' ? 'folder' : 'file';
            $webUrl = trim((string) ($row['web_url'] ?? ''));
            $person = trim((string) ($row['person'] ?? ''));
            $created = trim((string) ($row['date_created'] ?? ''));
            $modified = trim((string) ($row['last_modified'] ?? ''));

            $isRoot = $itemType === 'folder'
                && ($path === '' || $path === $projectName || $path === $name);

            if ($isRoot) {
                if ($groups[$groupKey]['folder_url'] === '' && $webUrl !== '') {
                    $groups[$groupKey]['folder_url'] = $webUrl;
                }
                if ($person !== '') {
                    $groups[$groupKey]['root_person'] = $person;
                }
                if ($created !== '') {
                    $groups[$groupKey]['root_created'] = $created;
                } elseif ($modified !== '' && $groups[$groupKey]['root_created'] === '') {
                    $groups[$groupKey]['root_created'] = $modified;
                }
            }

            if ($groups[$groupKey]['folder_url'] === '' && $webUrl !== '' && $itemType === 'folder') {
                $groups[$groupKey]['folder_url'] = $webUrl;
            }
            if ($person !== '') {
                $groups[$groupKey]['people'][$person] = ($groups[$groupKey]['people'][$person] ?? 0) + 1;
            }
            if ($created !== '') {
                $groups[$groupKey]['created_candidates'][] = $created;
            } elseif ($modified !== '') {
                $groups[$groupKey]['created_candidates'][] = $modified;
            }
        }

        foreach ($itemCounts as $groupKey => $count) {
            if (isset($groups[$groupKey]) || $count < 1) {
                continue;
            }
            [$sourceKey, $projectName] = explode("\n", (string) $groupKey, 2);
            $groups[$groupKey] = [
                'project_name' => $projectName,
                'source_key' => $sourceKey,
                'folder_url' => '',
                'item_count' => $count,
                'root_person' => '',
                'root_created' => '',
                'people' => [],
                'created_candidates' => [],
            ];
        }

        $out = [];
        foreach ($groups as $group) {
            $ownerName = trim((string) $group['root_person']);
            if ($ownerName === '' && $group['people'] !== []) {
                arsort($group['people']);
                $ownerName = (string) array_key_first($group['people']);
            }
            $ownerKey = $this->ownerKey($ownerName);
            $displayName = $ownerName !== '' ? $ownerName : self::UNASSIGNED_NAME;

            $createdRaw = trim((string) $group['root_created']);
            if ($createdRaw === '' && $group['created_candidates'] !== []) {
                sort($group['created_candidates']);
                $createdRaw = (string) $group['created_candidates'][0];
            }
            $parsed = $this->parseDate($createdRaw);

            $out[] = [
                'project_name' => (string) $group['project_name'],
                'source_key' => (string) $group['source_key'],
                'source_title' => (string) ($sourceTitles[$group['source_key']] ?? $group['source_key']),
                'folder_url' => (string) $group['folder_url'],
                'item_count' => (int) $group['item_count'],
                'owner_key' => $ownerKey,
                'owner_name' => $displayName,
                'date_created' => $parsed['iso'] ?? '',
                'year' => $parsed['year'] ?? '',
                'month' => $parsed['month'] ?? '',
                'quarter' => $parsed['quarter'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array{total: int, owners: array<string, int>}> $buckets
     */
    private function bumpPeriod(array &$buckets, string $key, string $ownerKey): void
    {
        if ($key === '') {
            return;
        }
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['total' => 0, 'owners' => []];
        }
        $buckets[$key]['total']++;
        $buckets[$key]['owners'][$ownerKey] = ($buckets[$key]['owners'][$ownerKey] ?? 0) + 1;
    }

    /**
     * @param array<string, array{total: int, owners: array<string, int>}> $buckets
     * @param list<string> $keys
     * @return array<string, array{total: int, owners: array<string, int>}>
     */
    private function orderBuckets(array $buckets, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $out[$key] = $buckets[$key] ?? $buckets[(int) $key] ?? ['total' => 0, 'owners' => []];
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function fillMonthRange(array $keys): array
    {
        $keys = array_values(array_filter(
            array_map(static fn ($key): string => (string) $key, $keys),
            static fn (string $key): bool => preg_match('/^\d{4}-\d{2}$/', $key) === 1
        ));
        if ($keys === []) {
            return [];
        }
        sort($keys);
        try {
            $cursor = new DateTimeImmutable($keys[0] . '-01');
            $end = new DateTimeImmutable($keys[count($keys) - 1] . '-01');
        } catch (\Throwable) {
            return $keys;
        }
        $out = [];
        while ($cursor <= $end) {
            $out[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function fillQuarterRange(array $keys): array
    {
        $keys = array_values(array_filter(
            array_map(static fn ($key): string => (string) $key, $keys),
            static fn (string $key): bool => preg_match('/^\d{4}-Q[1-4]$/', $key) === 1
        ));
        if ($keys === []) {
            return [];
        }
        sort($keys);
        $start = $keys[0];
        $end = $keys[count($keys) - 1];
        $year = (int) substr($start, 0, 4);
        $quarter = (int) substr($start, 6, 1);
        $endYear = (int) substr($end, 0, 4);
        $endQuarter = (int) substr($end, 6, 1);
        $out = [];
        while ($year < $endYear || ($year === $endYear && $quarter <= $endQuarter)) {
            $out[] = sprintf('%04d-Q%d', $year, $quarter);
            $quarter++;
            if ($quarter > 4) {
                $quarter = 1;
                $year++;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function fillYearRange(array $keys): array
    {
        $years = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (preg_match('/^\d{4}$/', $key) === 1) {
                $years[] = (int) $key;
            }
        }
        if ($years === []) {
            return [];
        }
        $out = [];
        for ($year = min($years); $year <= max($years); $year++) {
            $out[] = (string) $year;
        }

        return $out;
    }

    /**
     * Consecutive months ending at the latest owned month.
     *
     * @param list<string> $monthKeys
     */
    private function trailingStreak(array $monthKeys): int
    {
        $monthKeys = array_values(array_filter(
            $monthKeys,
            static fn (string $key): bool => preg_match('/^\d{4}-\d{2}$/', $key) === 1
        ));
        if ($monthKeys === []) {
            return 0;
        }
        rsort($monthKeys);
        $streak = 1;
        try {
            $cursor = new DateTimeImmutable($monthKeys[0] . '-01');
        } catch (\Throwable) {
            return 1;
        }
        for ($i = 1, $n = count($monthKeys); $i < $n; $i++) {
            $cursor = $cursor->modify('-1 month');
            if ($cursor->format('Y-m') !== $monthKeys[$i]) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /**
     * @return array{iso: string, year: string, month: string, quarter: string}|null
     */
    private function parseDate(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?/', $value, $match) === 1) {
            $year = $match[1];
            $monthNum = (int) $match[2];
            if ($monthNum < 1 || $monthNum > 12) {
                return null;
            }
            $day = isset($match[3]) ? (int) $match[3] : 1;
            if ($day < 1 || $day > 31) {
                $day = 1;
            }

            return $this->dateParts($year, $monthNum, $day);
        }

        $formats = [
            'n/j/Y g:i A',
            'n/j/Y G:i',
            'n/j/Y H:i:s',
            'n/j/Y',
            'm/d/Y g:i A',
            'm/d/Y G:i',
            'm/d/Y H:i:s',
            'm/d/Y',
            DateTimeInterface::ATOM,
            DateTimeInterface::RFC3339,
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i:s.uP',
            'j M Y H:i:s',
            'M j, Y g:i A',
            'M j, Y',
        ];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($parsed instanceof DateTimeImmutable) {
                $errors = DateTimeImmutable::getLastErrors();
                if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                    continue;
                }

                return $this->dateParts(
                    $parsed->format('Y'),
                    (int) $parsed->format('n'),
                    (int) $parsed->format('j')
                );
            }
        }

        try {
            $parsed = new DateTimeImmutable($value);

            return $this->dateParts(
                $parsed->format('Y'),
                (int) $parsed->format('n'),
                (int) $parsed->format('j')
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{iso: string, year: string, month: string, quarter: string}
     */
    private function dateParts(string $year, int $monthNum, int $day): array
    {
        $quarter = (int) ceil($monthNum / 3);

        return [
            'iso' => sprintf('%s-%02d-%02d', $year, $monthNum, $day),
            'year' => $year,
            'month' => sprintf('%s-%02d', $year, $monthNum),
            'quarter' => sprintf('%s-Q%d', $year, $quarter),
        ];
    }

    private function ownerKey(string $name): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        if ($normalized === '') {
            return self::UNASSIGNED_KEY;
        }

        return $normalized;
    }

    private function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === self::UNASSIGNED_NAME) {
            return '?';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            $char = mb_substr($part, 0, 1);
            if ($char !== '') {
                $letters .= mb_strtoupper($char);
            }
            if (mb_strlen($letters) >= 2) {
                break;
            }
        }
        if ($letters === '') {
            return '?';
        }
        if (mb_strlen($letters) === 1) {
            $letters .= mb_strtoupper(mb_substr($name, 1, 1) ?: $letters);
        }

        return $letters;
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
     * @param array<string, int> $counts
     * @return list<array{source_key: string, title: string, project_count: int}>
     */
    private function sourceMeta(array $sourceKeys, array $sourceTitles, array $counts): array
    {
        $out = [];
        foreach ($sourceKeys as $key) {
            $out[] = [
                'source_key' => $key,
                'title' => (string) ($sourceTitles[$key] ?? $key),
                'project_count' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{source_key: string, title: string, project_count: int}> $sources
     * @return array<string, mixed>
     */
    private function emptyPayload(array $sources): array
    {
        return [
            'sources' => $sources,
            'kpis' => [
                'project_count' => 0,
                'owner_count' => 0,
                'assigned_count' => 0,
                'unassigned_count' => 0,
                'unknown_date_count' => 0,
                'year_min' => null,
                'year_max' => null,
                'this_year_count' => 0,
                'last_12_months' => 0,
                'busiest_owner' => '',
                'busiest_owner_key' => '',
                'busiest_owner_count' => 0,
                'busiest_period' => '',
                'busiest_period_count' => 0,
            ],
            'owners' => [],
            'timeline' => [
                'months' => [],
                'quarters' => [],
                'years' => [],
                'by_month' => [],
                'by_quarter' => [],
                'by_year' => [],
            ],
        ];
    }
}
