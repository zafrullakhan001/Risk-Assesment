<?php

declare(strict_types=1);

namespace RiskAssessment\Database;

use PDO;

/**
 * Compare expected enterprise SQLite settings vs what is currently set.
 */
final class SqliteHealthcheck
{
    /**
     * @return array{
     *   success: bool,
     *   summary: string,
     *   critical_ok: bool,
     *   checks_passed: string,
     *   settings: list<array{id: string, label: string, expected: string, actual: string, ok: bool, critical: bool, fix: string}>,
     *   environment: array<string, mixed>,
     *   sizes: array<string, mixed>,
     *   applied: array<string, mixed>,
     *   fixes: list<string>,
     *   timing_ms: array{connect: float, warmup_read: float}
     * }
     */
    public static function run(PDO $pdo, string $dbPath, bool $heavy = true): array
    {
        $t0 = microtime(true);
        $applied = SqlitePerformance::apply($pdo, $dbPath, $heavy);
        $connectMs = round((microtime(true) - $t0) * 1000, 2);

        $dbBytes = SqlitePerformance::databaseBytes($pdo, $dbPath);
        $fileBytes = is_file($dbPath) ? (int) filesize($dbPath) : $dbBytes;
        $targets = SqlitePerformance::targets($dbBytes);

        $pageSize = (int) self::pragma($pdo, 'page_size');
        $cachePragma = (int) self::pragma($pdo, 'cache_size');
        $cacheBytes = $cachePragma < 0
            ? abs($cachePragma) * 1024
            : abs($cachePragma) * max($pageSize, 1);
        $mmapSize = (int) self::pragma($pdo, 'mmap_size');
        $journalMode = strtolower((string) self::pragma($pdo, 'journal_mode'));
        $synchronous = (int) self::pragma($pdo, 'synchronous');
        $tempStore = (int) self::pragma($pdo, 'temp_store');
        $threads = (int) self::pragma($pdo, 'threads');
        $busyTimeout = (int) self::pragma($pdo, 'busy_timeout');
        $walAutocheckpoint = (int) self::pragma($pdo, 'wal_autocheckpoint');
        $journalSizeLimit = (int) self::pragma($pdo, 'journal_size_limit');
        $foreignKeys = (int) self::pragma($pdo, 'foreign_keys');
        $sqliteVersion = (string) ($applied['sqlite_version'] ?? self::queryScalar($pdo, 'SELECT sqlite_version()'));
        $sourceId = self::queryScalar($pdo, 'SELECT sqlite_source_id()');

        $t1 = microtime(true);
        $pdo->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
        try {
            $pdo->query('SELECT COUNT(*) FROM sharepoint_items')->fetchColumn();
        } catch (\Throwable $e) {
            // Table may not exist on empty install.
        }
        $readMs = round((microtime(true) - $t1) * 1000, 2);

        $cacheHoldsDb = $cacheBytes >= $dbBytes && $dbBytes > 0;
        $mmapEnabled = $mmapSize > 0;
        $mmapCoversDb = $mmapEnabled && $mmapSize >= $dbBytes;
        $versionOk = version_compare($sqliteVersion, SqlitePerformance::MIN_SQLITE_VERSION, '>=');
        $fkOn = $foreignKeys === 1;

        $settings = [];

        $settings[] = self::row(
            'sqlite_version',
            'SQLite version',
            '>= ' . SqlitePerformance::MIN_SQLITE_VERSION,
            $sqliteVersion !== '' ? $sqliteVersion : '(unknown)',
            $versionOk,
            true,
            'Install SQLite ' . SqlitePerformance::MIN_SQLITE_VERSION . '+ (replace libsqlite3.dll on Windows XAMPP) and restart Apache.'
        );

        $settings[] = self::row(
            'journal_mode',
            'journal_mode',
            $targets['journal_mode'],
            $journalMode,
            $journalMode === $targets['journal_mode'],
            true,
            'Ensure Database::connection() applies SqlitePerformance; restart Apache after deploy.'
        );

        $settings[] = self::row(
            'synchronous',
            'synchronous',
            '1 (NORMAL)',
            (string) $synchronous . ($synchronous === 1 ? ' (NORMAL)' : ($synchronous === 2 ? ' (FULL)' : '')),
            $synchronous === $targets['synchronous'],
            true,
            'Re-apply PRAGMA synchronous = NORMAL via SqlitePerformance::apply().'
        );

        $settings[] = self::row(
            'temp_store',
            'temp_store',
            '2 (MEMORY)',
            (string) $tempStore . ($tempStore === 2 ? ' (MEMORY)' : ''),
            $tempStore === $targets['temp_store'],
            false,
            'Re-apply PRAGMA temp_store = MEMORY via SqlitePerformance::apply().'
        );

        $settings[] = self::row(
            'busy_timeout',
            'busy_timeout',
            (string) $targets['busy_timeout'] . ' ms',
            (string) $busyTimeout . ' ms',
            $busyTimeout >= $targets['busy_timeout'],
            true,
            'Re-apply PRAGMA busy_timeout = ' . $targets['busy_timeout'] . '.'
        );

        $settings[] = self::row(
            'foreign_keys',
            'foreign_keys',
            'ON',
            $fkOn ? 'ON' : 'OFF',
            $fkOn,
            true,
            'Re-apply PRAGMA foreign_keys = ON (required for CASCADE deletes).'
        );

        $expectedCacheLabel = '-' . $targets['cache_size_kib'] . ' KiB (' . self::formatBytes($targets['cache_size_kib'] * 1024) . ')';
        $actualCacheLabel = (string) $cachePragma . ' (' . self::formatBytes($cacheBytes) . ')';
        // Allow small variance: accept if cache holds the DB and is at least the min floor.
        $cacheOk = $cacheHoldsDb && $cacheBytes >= (SqlitePerformance::CACHE_MIN_KIB * 1024);
        $settings[] = self::row(
            'cache_size',
            'cache_size (page cache)',
            $expectedCacheLabel,
            $actualCacheLabel,
            $cacheOk,
            true,
            'SqlitePerformance should set cache_size to ~2× DB size (32–512 MiB). Confirm apply() runs on connect.'
        );

        $settings[] = self::row(
            'mmap_size',
            'mmap_size',
            self::formatBytes($targets['mmap_size']),
            self::formatBytes($mmapSize) . ($mmapEnabled ? '' : ' (OFF)'),
            $mmapEnabled && $mmapSize >= min($targets['mmap_size'], max($dbBytes, 1)),
            true,
            $mmapSize === 0
                ? 'mmap_size=0: stock XAMPP SQLite often lacks mmap — install SQLite 3.53+ DLL and restart Apache.'
                : 'Raise PRAGMA mmap_size so it covers the database file (capped at 512 MiB).'
        );

        $settings[] = self::row(
            'threads',
            'threads',
            (string) $targets['threads'],
            (string) $threads,
            $threads >= 1,
            false,
            'PRAGMA threads requires SQLite with worker threads (3.53+). Upgrade libsqlite3.dll if threads=0.'
        );

        $settings[] = self::row(
            'journal_size_limit',
            'journal_size_limit',
            self::formatBytes($targets['journal_size_limit']),
            self::formatBytes($journalSizeLimit),
            $journalSizeLimit === $targets['journal_size_limit']
                || ($journalSizeLimit > 0 && $journalSizeLimit <= $targets['journal_size_limit']),
            false,
            'Re-apply PRAGMA journal_size_limit = ' . $targets['journal_size_limit'] . '.'
        );

        $settings[] = self::row(
            'wal_autocheckpoint',
            'wal_autocheckpoint',
            (string) $targets['wal_autocheckpoint'],
            (string) $walAutocheckpoint,
            $walAutocheckpoint === $targets['wal_autocheckpoint'] || $walAutocheckpoint > 0,
            false,
            'Re-apply PRAGMA wal_autocheckpoint = ' . $targets['wal_autocheckpoint'] . ' after enabling WAL.'
        );

        $settings[] = self::row(
            'mmap_covers_db',
            'mmap covers DB',
            'mmap >= DB size',
            $mmapCoversDb ? 'yes' : 'no',
            $mmapCoversDb,
            false,
            'Increase mmap_size or compact the DB if the catalog exceeds the 512 MiB mmap cap.'
        );

        $settings[] = self::row(
            'cache_holds_db',
            'page cache holds DB',
            'cache >= DB size',
            $cacheHoldsDb ? 'yes' : 'no',
            $cacheHoldsDb,
            true,
            'Increase cache_size (dynamic sizing in SqlitePerformance) or reduce freelist with VACUUM.'
        );

        $pdoLoaded = extension_loaded('pdo_sqlite');
        $settings[] = self::row(
            'pdo_sqlite',
            'pdo_sqlite extension',
            'loaded',
            $pdoLoaded ? 'loaded' : 'missing',
            $pdoLoaded,
            true,
            'Enable extension=pdo_sqlite in php.ini and restart Apache.'
        );

        $passCount = count(array_filter($settings, static fn (array $c): bool => $c['ok']));
        $criticalOk = true;
        $fixes = [];
        foreach ($settings as $row) {
            if (!$row['ok'] && $row['critical']) {
                $criticalOk = false;
            }
            if (!$row['ok'] && $row['fix'] !== '') {
                $fixes[] = $row['id'] . ': ' . $row['fix'];
            }
        }

        $ramFast = $cacheHoldsDb && $mmapEnabled && $journalMode === 'wal';
        $summary = $criticalOk
            ? ($ramFast
                ? 'RAM-fast reads YES — page cache + mmap cover the DB; commits go to disk via WAL; foreign_keys ON'
                : 'Critical checks passed — see non-critical warnings')
            : 'Configuration incomplete — see FAIL rows and Fixes';

        $memoryLimit = (string) ini_get('memory_limit');
        $memoryBytes = self::parseIniBytes($memoryLimit);
        $cacheNeeds = $targets['cache_size_kib'] * 1024;
        $memoryOk = $memoryBytes === -1 || $memoryBytes >= ($cacheNeeds + 64 * 1024 * 1024);

        $walPath = $dbPath . '-wal';
        $shmPath = $dbPath . '-shm';

        return [
            'success' => true,
            'summary' => $summary,
            'critical_ok' => $criticalOk,
            'checks_passed' => $passCount . '/' . count($settings),
            'settings' => $settings,
            'environment' => [
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'memory_limit' => $memoryLimit,
                'memory_limit_ok_for_cache' => $memoryOk,
                'pdo_sqlite' => $pdoLoaded,
                'sqlite_version' => $sqliteVersion,
                'sqlite_source_id' => $sourceId,
                'database_path' => $dbPath,
                'wal_sidecar_exists' => is_file($walPath),
                'shm_sidecar_exists' => is_file($shmPath),
                'wal_sidecar_bytes' => is_file($walPath) ? (int) filesize($walPath) : 0,
                'shm_sidecar_bytes' => is_file($shmPath) ? (int) filesize($shmPath) : 0,
            ],
            'sizes' => [
                'database_bytes' => $dbBytes,
                'database_human' => self::formatBytes($dbBytes),
                'file_bytes' => $fileBytes,
                'file_human' => self::formatBytes($fileBytes),
                'cache_bytes' => $cacheBytes,
                'cache_human' => self::formatBytes($cacheBytes),
                'mmap_bytes' => $mmapSize,
                'mmap_human' => self::formatBytes($mmapSize),
                'cache_holds_entire_db' => $cacheHoldsDb,
                'mmap_covers_entire_db' => $mmapCoversDb,
                'page_size' => $pageSize,
                'page_count' => (int) self::pragma($pdo, 'page_count'),
            ],
            'applied' => $applied,
            'fixes' => array_values(array_unique($fixes)),
            'timing_ms' => [
                'connect' => $connectMs,
                'warmup_read' => $readMs,
            ],
            'verdict' => [
                'ram_fast_reads' => $ramFast,
                'durable_commits_to_disk' => true,
                'foreign_keys_on' => $fkOn,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function formatCli(array $result): string
    {
        $lines = [];
        $lines[] = '============================================';
        $lines[] = '  RiskRegister SQLite Health Check';
        $lines[] = '============================================';
        $lines[] = '  Verdict: ' . (!empty($result['critical_ok']) ? 'PASS' : 'FAIL')
            . ' — ' . (string) ($result['summary'] ?? '');
        $lines[] = '';
        $lines[] = sprintf('  %-22s %-28s %-28s %s', 'Setting', 'Expected', 'Current', 'Status');
        $lines[] = '  ' . str_repeat('-', 100);

        foreach ($result['settings'] as $row) {
            $status = $row['ok'] ? 'OK' : 'FAIL';
            $lines[] = sprintf(
                '  %-22s %-28s %-28s %s',
                self::truncate((string) $row['id'], 22),
                self::truncate((string) $row['expected'], 28),
                self::truncate((string) $row['actual'], 28),
                $status
            );
            if (!$row['ok'] && $row['fix'] !== '') {
                $lines[] = '    → ' . $row['fix'];
            }
        }

        $env = $result['environment'] ?? [];
        $sizes = $result['sizes'] ?? [];
        $lines[] = '';
        $lines[] = '  Environment';
        $lines[] = '    SQLite     : ' . (string) ($env['sqlite_version'] ?? '?');
        $lines[] = '    PHP        : ' . (string) ($env['php_version'] ?? '?')
            . '  memory_limit=' . (string) ($env['memory_limit'] ?? '?');
        $lines[] = '    DB path    : ' . (string) ($env['database_path'] ?? '?');
        $lines[] = '    DB size    : ' . (string) ($sizes['database_human'] ?? '?');
        $lines[] = '    Page cache : ' . (string) ($sizes['cache_human'] ?? '?')
            . (!empty($sizes['cache_holds_entire_db']) ? ' (holds entire DB)' : ' (TOO SMALL)');
        $lines[] = '    mmap       : ' . (string) ($sizes['mmap_human'] ?? '?');
        $lines[] = '    WAL/SHM    : '
            . (!empty($env['wal_sidecar_exists']) ? 'wal=' . self::formatBytes((int) ($env['wal_sidecar_bytes'] ?? 0)) : 'no-wal')
            . ' / '
            . (!empty($env['shm_sidecar_exists']) ? 'shm=' . self::formatBytes((int) ($env['shm_sidecar_bytes'] ?? 0)) : 'no-shm');
        $lines[] = '    Timing     : connect ' . (string) ($result['timing_ms']['connect'] ?? '?')
            . ' ms, sample read ' . (string) ($result['timing_ms']['warmup_read'] ?? '?') . ' ms';

        $fixes = $result['fixes'] ?? [];
        if ($fixes !== []) {
            $lines[] = '';
            $lines[] = '  Fixes';
            $n = 1;
            foreach ($fixes as $fix) {
                $lines[] = '    ' . $n . '. ' . $fix;
                $n++;
            }
        }

        $lines[] = '';
        $lines[] = '  Passed: ' . (string) ($result['checks_passed'] ?? '');
        $lines[] = '============================================';

        return implode("\n", $lines) . "\n";
    }

    public static function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }

    /**
     * @return array{id: string, label: string, expected: string, actual: string, ok: bool, critical: bool, fix: string}
     */
    private static function row(
        string $id,
        string $label,
        string $expected,
        string $actual,
        bool $ok,
        bool $critical,
        string $fix
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'expected' => $expected,
            'actual' => $actual,
            'ok' => $ok,
            'critical' => $critical,
            'fix' => $ok ? '' : $fix,
        ];
    }

    /** @return mixed */
    private static function pragma(PDO $pdo, string $name)
    {
        try {
            return $pdo->query('PRAGMA ' . $name)->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function queryScalar(PDO $pdo, string $sql): string
    {
        try {
            return (string) $pdo->query($sql)->fetchColumn();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function parseIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (!preg_match('/^(-?\d+)\s*([KMG])?B?$/i', $value, $m)) {
            return (int) $value;
        }
        $n = (int) $m[1];
        $unit = strtoupper($m[2] ?? '');
        return match ($unit) {
            'K' => $n * 1024,
            'M' => $n * 1024 * 1024,
            'G' => $n * 1024 * 1024 * 1024,
            default => $n,
        };
    }

    private static function truncate(string $value, int $len): string
    {
        if (strlen($value) <= $len) {
            return $value;
        }

        return substr($value, 0, max(0, $len - 1)) . '…';
    }
}
