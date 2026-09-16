<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

/**
 * Loads the fixed repository portfolio mapping CSV (outside public/).
 * No user-supplied paths are accepted.
 */
final class SharePointPortfolioMapping
{
    public const NEEDS_REVIEW_PORTFOLIO = 'Needs Review / Administrative Artifact';
    public const NEEDS_REVIEW_SUB = 'Unclassified / Non-project artifact';
    public const UNMAPPED_PORTFOLIO = 'Needs Review / Unmapped';
    public const UNMAPPED_SUB = 'No mapping row';

    private const ALLOWED_CONFIDENCE = ['High' => true, 'Medium' => true, 'Low' => true];

    /** @var array<string, array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string}>|null */
    private static ?array $cache = null;

    private static ?string $cachePath = null;

    private static ?string $lastError = null;

    /**
     * Absolute path to the repository mapping CSV (not under public/).
     */
    public static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'project_portfolio_mapping.csv';
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
        self::$cachePath = null;
        self::$lastError = null;
    }

    /**
     * Normalize a project name for case-insensitive lookups.
     */
    public static function normalizeKey(string $projectName): string
    {
        $projectName = trim(preg_replace('/\s+/u', ' ', $projectName) ?? $projectName);

        return mb_strtolower($projectName, 'UTF-8');
    }

    /**
     * @return array<string, array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string}>
     */
    public static function load(?string $path = null): array
    {
        $path = $path ?? self::defaultPath();
        if (self::$cache !== null && self::$cachePath === $path) {
            return self::$cache;
        }

        self::$lastError = null;
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            self::$lastError = 'Portfolio mapping file is missing or unreadable.';
            self::$cache = [];
            self::$cachePath = $path;

            return self::$cache;
        }

        // Refuse any path under public/ in case a caller ever overrides the default.
        $publicRoot = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public');
        if ($publicRoot !== false && str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR)) {
            self::$lastError = 'Portfolio mapping must not live under public/.';
            self::$cache = [];
            self::$cachePath = $path;

            return self::$cache;
        }

        $handle = fopen($real, 'rb');
        if ($handle === false) {
            self::$lastError = 'Could not open portfolio mapping CSV.';
            self::$cache = [];
            self::$cachePath = $path;

            return self::$cache;
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);
            self::$lastError = 'Portfolio mapping CSV is empty.';
            self::$cache = [];
            self::$cachePath = $path;

            return self::$cache;
        }

        $expected = ['project', 'approximate portfolio', 'approximate sub-portfolio', 'confidence', 'classification note'];
        $normalizedHeader = array_map(
            static fn ($h): string => mb_strtolower(trim((string) $h), 'UTF-8'),
            $header
        );
        if ($normalizedHeader !== $expected) {
            fclose($handle);
            self::$lastError = 'Portfolio mapping CSV header is invalid.';
            self::$cache = [];
            self::$cachePath = $path;

            return self::$cache;
        }

        /** @var array<string, array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string}> $rows */
        $rows = [];
        $line = 1;
        $errors = [];
        while (($cols = fgetcsv($handle)) !== false) {
            $line++;
            if ($cols === [null]) {
                continue;
            }
            $project = trim((string) ($cols[0] ?? ''));
            if ($project === '') {
                continue;
            }
            $portfolio = trim((string) ($cols[1] ?? ''));
            $sub = trim((string) ($cols[2] ?? ''));
            $confidence = trim((string) ($cols[3] ?? ''));
            $note = trim((string) ($cols[4] ?? ''));

            if ($portfolio === '' || $sub === '') {
                $errors[] = "line {$line}: missing portfolio or sub-portfolio for \"{$project}\"";
                continue;
            }
            if ($confidence === '') {
                $confidence = 'Medium';
            }
            if (!isset(self::ALLOWED_CONFIDENCE[$confidence])) {
                $errors[] = "line {$line}: invalid confidence \"{$confidence}\" for \"{$project}\"";
                continue;
            }

            $key = self::normalizeKey($project);
            if (isset($rows[$key])) {
                $errors[] = "line {$line}: duplicate project \"{$project}\"";
                continue;
            }

            $rows[$key] = [
                'project' => $project,
                'portfolio' => $portfolio,
                'sub_portfolio' => $sub,
                'confidence' => $confidence,
                'note' => $note,
            ];
        }
        fclose($handle);

        if ($errors !== []) {
            self::$lastError = implode('; ', array_slice($errors, 0, 8))
                . (count($errors) > 8 ? ' …' : '');
        }

        self::$cache = $rows;
        self::$cachePath = $path;

        return $rows;
    }

    /**
     * Resolve mapping for one project name.
     *
     * @return array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string, mapped: bool, needs_review: bool}
     */
    public static function resolve(string $projectName, ?array $map = null): array
    {
        $projectName = trim($projectName);
        $map ??= self::load();
        $key = self::normalizeKey($projectName);
        if ($key !== '' && isset($map[$key])) {
            $row = $map[$key];
            $needsReview = stripos($row['portfolio'], 'Needs Review') !== false
                || strcasecmp($row['confidence'], 'Low') === 0;

            return [
                'project' => $projectName !== '' ? $projectName : $row['project'],
                'portfolio' => $row['portfolio'],
                'sub_portfolio' => $row['sub_portfolio'],
                'confidence' => $row['confidence'],
                'note' => $row['note'],
                'mapped' => true,
                'needs_review' => $needsReview,
            ];
        }

        return [
            'project' => $projectName,
            'portfolio' => self::UNMAPPED_PORTFOLIO,
            'sub_portfolio' => self::UNMAPPED_SUB,
            'confidence' => 'Low',
            'note' => 'No matching row in project_portfolio_mapping.csv.',
            'mapped' => false,
            'needs_review' => true,
        ];
    }

    /**
     * @param list<string> $projectNames
     * @return array{mapped: int, unmapped: int, needs_review: int, low_confidence: int, total: int}
     */
    public static function coverageStats(array $projectNames, ?array $map = null): array
    {
        $map ??= self::load();
        $seen = [];
        $mapped = 0;
        $unmapped = 0;
        $needsReview = 0;
        $low = 0;
        foreach ($projectNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $key = self::normalizeKey($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $resolved = self::resolve($name, $map);
            if (!$resolved['mapped']) {
                $unmapped++;
                $needsReview++;
                $low++;
                continue;
            }
            $mapped++;
            if ($resolved['needs_review']) {
                $needsReview++;
            }
            if (strcasecmp($resolved['confidence'], 'Low') === 0) {
                $low++;
            }
        }

        return [
            'mapped' => $mapped,
            'unmapped' => $unmapped,
            'needs_review' => $needsReview,
            'low_confidence' => $low,
            'total' => count($seen),
        ];
    }

    /**
     * Distinct portfolios and their sub-portfolios for edit dropdowns.
     *
     * @return list<array{portfolio: string, sub_portfolios: list<string>}>
     */
    public static function optionTree(?array $map = null): array
    {
        $map ??= self::load();
        /** @var array<string, array<string, true>> $tree */
        $tree = [];
        foreach ($map as $row) {
            $portfolio = trim((string) ($row['portfolio'] ?? ''));
            $sub = trim((string) ($row['sub_portfolio'] ?? ''));
            if ($portfolio === '') {
                continue;
            }
            if (!isset($tree[$portfolio])) {
                $tree[$portfolio] = [];
            }
            if ($sub !== '') {
                $tree[$portfolio][$sub] = true;
            }
        }

        // Always offer the needs-review buckets so admins can move projects there intentionally.
        if (!isset($tree[self::NEEDS_REVIEW_PORTFOLIO])) {
            $tree[self::NEEDS_REVIEW_PORTFOLIO] = [];
        }
        $tree[self::NEEDS_REVIEW_PORTFOLIO][self::NEEDS_REVIEW_SUB] = true;
        if (!isset($tree[self::UNMAPPED_PORTFOLIO])) {
            $tree[self::UNMAPPED_PORTFOLIO] = [];
        }
        $tree[self::UNMAPPED_PORTFOLIO][self::UNMAPPED_SUB] = true;

        $portfolios = array_keys($tree);
        natcasesort($portfolios);
        $out = [];
        foreach ($portfolios as $portfolio) {
            $subs = array_keys($tree[$portfolio]);
            natcasesort($subs);
            $out[] = [
                'portfolio' => $portfolio,
                'sub_portfolios' => array_values($subs),
            ];
        }

        return $out;
    }

    /**
     * Insert or update one project mapping row in the repository CSV.
     *
     * @return array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string, mapped: bool, needs_review: bool}
     */
    public static function upsert(
        string $projectName,
        string $portfolio,
        string $subPortfolio,
        string $confidence = 'Medium',
        string $note = '',
        ?string $path = null
    ): array {
        $projectName = trim(preg_replace('/\s+/u', ' ', $projectName) ?? $projectName);
        $portfolio = trim(preg_replace('/\s+/u', ' ', $portfolio) ?? $portfolio);
        $subPortfolio = trim(preg_replace('/\s+/u', ' ', $subPortfolio) ?? $subPortfolio);
        $confidence = trim($confidence);
        $note = trim($note);

        if ($projectName === '') {
            throw new \InvalidArgumentException('Project name is required.');
        }
        if ($portfolio === '' || $subPortfolio === '') {
            throw new \InvalidArgumentException('Portfolio and sub-portfolio are required.');
        }
        if ($confidence === '') {
            $confidence = 'Medium';
        }
        if (!isset(self::ALLOWED_CONFIDENCE[$confidence])) {
            throw new \InvalidArgumentException('Confidence must be High, Medium, or Low.');
        }
        if (mb_strlen($projectName, 'UTF-8') > 300
            || mb_strlen($portfolio, 'UTF-8') > 200
            || mb_strlen($subPortfolio, 'UTF-8') > 200
            || mb_strlen($note, 'UTF-8') > 1000
        ) {
            throw new \InvalidArgumentException('One or more mapping fields are too long.');
        }

        $path = $path ?? self::defaultPath();
        $real = self::assertWritablePath($path);

        $map = self::load($real);
        $key = self::normalizeKey($projectName);
        $map[$key] = [
            'project' => $projectName,
            'portfolio' => $portfolio,
            'sub_portfolio' => $subPortfolio,
            'confidence' => $confidence,
            'note' => $note,
        ];

        self::writeMap($map, $real);

        return self::resolve($projectName);
    }

    /**
     * Parse a CSV upload into mapping rows (same schema as the repository file).
     *
     * @return array{
     *   rows: array<string, array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string}>,
     *   skipped: int,
     *   duplicates: int,
     *   duplicate_samples: list<string>,
     *   errors: list<string>
     * }
     */
    public static function parseCsvFile(string $filePath, bool $keepUnique = true): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException('Uploaded CSV is missing or unreadable.');
        }
        $size = filesize($filePath);
        if ($size === false || $size <= 0) {
            throw new \InvalidArgumentException('Uploaded CSV is empty.');
        }
        if ($size > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Uploaded CSV is too large (max 5 MB).');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open uploaded CSV.');
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);
            throw new \InvalidArgumentException('Uploaded CSV is empty.');
        }
        // Strip UTF-8 BOM from first header cell when present.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
        }
        $expected = ['project', 'approximate portfolio', 'approximate sub-portfolio', 'confidence', 'classification note'];
        $normalizedHeader = array_map(
            static fn ($h): string => mb_strtolower(trim((string) $h), 'UTF-8'),
            $header
        );
        if ($normalizedHeader !== $expected) {
            fclose($handle);
            throw new \InvalidArgumentException(
                'CSV header must be: Project, Approximate Portfolio, Approximate Sub-Portfolio, Confidence, Classification Note'
            );
        }

        /** @var array<string, array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string}> $rows */
        $rows = [];
        $errors = [];
        $skipped = 0;
        $duplicates = 0;
        /** @var list<string> $duplicateSamples */
        $duplicateSamples = [];
        $line = 1;
        while (($cols = fgetcsv($handle)) !== false) {
            $line++;
            if ($cols === [null]) {
                continue;
            }
            $project = trim((string) ($cols[0] ?? ''));
            if ($project === '') {
                $skipped++;
                continue;
            }
            $portfolio = trim((string) ($cols[1] ?? ''));
            $sub = trim((string) ($cols[2] ?? ''));
            $confidence = trim((string) ($cols[3] ?? ''));
            $note = trim((string) ($cols[4] ?? ''));
            if ($portfolio === '' || $sub === '') {
                $skipped++;
                $errors[] = "line {$line}: missing portfolio or sub-portfolio for \"{$project}\"";
                continue;
            }
            if ($confidence === '') {
                $confidence = 'Medium';
            }
            if (!isset(self::ALLOWED_CONFIDENCE[$confidence])) {
                $skipped++;
                $errors[] = "line {$line}: invalid confidence \"{$confidence}\" for \"{$project}\"";
                continue;
            }
            if (mb_strlen($project, 'UTF-8') > 300
                || mb_strlen($portfolio, 'UTF-8') > 200
                || mb_strlen($sub, 'UTF-8') > 200
                || mb_strlen($note, 'UTF-8') > 1000
            ) {
                $skipped++;
                $errors[] = "line {$line}: field too long for \"{$project}\"";
                continue;
            }

            $key = self::normalizeKey($project);
            if (isset($rows[$key])) {
                $duplicates++;
                if (count($duplicateSamples) < 8) {
                    $duplicateSamples[] = $project;
                }
                if (!$keepUnique) {
                    continue;
                }
                // keepUnique: last row wins
            }

            $rows[$key] = [
                'project' => $project,
                'portfolio' => $portfolio,
                'sub_portfolio' => $sub,
                'confidence' => $confidence,
                'note' => $note,
            ];
        }
        fclose($handle);

        if ($rows === []) {
            throw new \InvalidArgumentException('No valid mapping rows found in the uploaded CSV.');
        }

        if (!$keepUnique && $duplicates > 0) {
            $sample = implode(', ', array_slice($duplicateSamples, 0, 5));
            throw new \InvalidArgumentException(
                "CSV has {$duplicates} duplicate project name(s)"
                . ($sample !== '' ? " (e.g. {$sample})" : '')
                . '. Enable “Filter duplicates — keep unique”, or remove duplicate Project rows.'
            );
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'duplicates' => $duplicates,
            'duplicate_samples' => $duplicateSamples,
            'errors' => array_slice($errors, 0, 12),
        ];
    }

    /**
     * Import parsed mapping rows.
     *
     * @param array<string, array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string}> $incoming
     * @param 'append'|'replace' $mode
     * @return array{mode: string, before: int, after: int, added: int, updated: int, unchanged: int, skipped: int, duplicates: int, duplicate_samples: list<string>, errors: list<string>}
     */
    public static function importRows(
        array $incoming,
        string $mode = 'append',
        int $skipped = 0,
        array $errors = [],
        int $duplicates = 0,
        array $duplicateSamples = [],
        ?string $path = null
    ): array {
        $mode = strtolower(trim($mode)) === 'replace' ? 'replace' : 'append';
        if ($incoming === []) {
            throw new \InvalidArgumentException('No mapping rows to import.');
        }

        $path = $path ?? self::defaultPath();
        $real = self::assertWritablePath($path);
        $existing = self::load($real);
        $before = count($existing);

        if ($mode === 'replace') {
            $map = $incoming;
            $added = count($incoming);
            $updated = 0;
            $unchanged = 0;
            // Create a timestamped backup before full replace.
            $backup = $real . '.bak.' . date('Ymd_His');
            if (!@copy($real, $backup)) {
                throw new \RuntimeException('Could not create a backup before replace import.');
            }
        } else {
            $map = $existing;
            $added = 0;
            $updated = 0;
            $unchanged = 0;
            foreach ($incoming as $key => $row) {
                if (!isset($map[$key])) {
                    $map[$key] = $row;
                    $added++;
                    continue;
                }
                $prev = $map[$key];
                $same = (string) $prev['portfolio'] === (string) $row['portfolio']
                    && (string) $prev['sub_portfolio'] === (string) $row['sub_portfolio']
                    && (string) $prev['confidence'] === (string) $row['confidence']
                    && (string) $prev['note'] === (string) $row['note'];
                if ($same) {
                    $unchanged++;
                    continue;
                }
                // Keep the incoming project display name, but preserve prior casing if identical ignoring case.
                $map[$key] = $row;
                $updated++;
            }
        }

        self::writeMap($map, $real);

        return [
            'mode' => $mode,
            'before' => $before,
            'after' => count($map),
            'added' => $added,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'duplicates' => $duplicates,
            'duplicate_samples' => $duplicateSamples,
            'errors' => $errors,
        ];
    }

    /**
     * Stream the current mapping CSV to the browser.
     */
    public static function exportToOutput(?string $path = null): void
    {
        $path = $path ?? self::defaultPath();
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException('Portfolio mapping file is missing or unreadable.');
        }
        $publicRoot = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public');
        if ($publicRoot !== false && str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Portfolio mapping must not live under public/.');
        }

        $filename = 'project_portfolio_mapping_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        // UTF-8 BOM helps Excel open the file correctly.
        echo "\xEF\xBB\xBF";
        readfile($real);
    }

    /**
     * Download a blank CSV template (header + example rows) for bulk import.
     */
    public static function exportTemplateToOutput(): void
    {
        $filename = 'project_portfolio_mapping_template.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not write CSV template.');
        }
        fputcsv($out, [
            'Project',
            'Approximate Portfolio',
            'Approximate Sub-Portfolio',
            'Confidence',
            'Classification Note',
        ]);
        fputcsv($out, [
            'Example Vendor - Example Product',
            'Laboratory & Pathology',
            'Clinical Laboratory / Anatomic Pathology',
            'Medium',
            'Replace this example row with real project names from the catalog.',
        ]);
        fputcsv($out, [
            'Another Example Project',
            'Cardiology',
            'Cardiovascular Services',
            'High',
            'Confidence must be High, Medium, or Low.',
        ]);
        fclose($out);
    }

    /**
     * @return string Absolute writable mapping path
     */
    private static function assertWritablePath(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new \RuntimeException('Portfolio mapping file is missing.');
        }
        $publicRoot = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public');
        if ($publicRoot !== false && str_starts_with($real, $publicRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Portfolio mapping must not live under public/.');
        }
        if (!is_readable($real) || !is_writable($real)) {
            throw new \RuntimeException('Portfolio mapping file is not writable.');
        }

        return $real;
    }

    /**
     * @param array<string, array{project: string, portfolio: string, sub_portfolio: string, confidence: string, note: string}> $map
     */
    private static function writeMap(array $map, string $realPath): void
    {
        uasort(
            $map,
            static fn (array $a, array $b): int => strcasecmp((string) $a['project'], (string) $b['project'])
        );

        $tmp = $realPath . '.tmp.' . bin2hex(random_bytes(4));
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not create temporary mapping file.');
        }

        $locked = false;
        try {
            $locked = flock($handle, LOCK_EX);
            if (!$locked) {
                throw new \RuntimeException('Could not lock portfolio mapping file for writing.');
            }
            fwrite($handle, "Project,Approximate Portfolio,Approximate Sub-Portfolio,Confidence,Classification Note\n");
            foreach ($map as $row) {
                fputcsv($handle, [
                    (string) $row['project'],
                    (string) $row['portfolio'],
                    (string) $row['sub_portfolio'],
                    (string) $row['confidence'],
                    (string) $row['note'],
                ]);
            }
            fflush($handle);
            if ($locked) {
                flock($handle, LOCK_UN);
                $locked = false;
            }
            fclose($handle);
            $handle = null;

            if (!rename($tmp, $realPath)) {
                if (is_file($realPath) && !unlink($realPath)) {
                    throw new \RuntimeException('Could not replace portfolio mapping file.');
                }
                if (!rename($tmp, $realPath)) {
                    throw new \RuntimeException('Could not finalize portfolio mapping file.');
                }
            }
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                if ($locked) {
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw $e;
        }

        self::clearCache();
    }
}
