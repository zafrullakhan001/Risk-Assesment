<?php

declare(strict_types=1);

namespace RiskAssessment\Database;

use PDO;
use PDOException;

/**
 * Enterprise SQLite performance PRAGMAs for RiskRegister.
 *
 * Adapted from LinkNest: WAL + mmap + sized page cache for RAM-fast reads,
 * while keeping foreign_keys ON (RiskRegister uses ON DELETE CASCADE).
 *
 * Safe to call on every connection; most values are connection-scoped.
 * journal_mode persists on the database file.
 */
final class SqlitePerformance
{
    public const BUSY_TIMEOUT_MS = 30000;
    public const CACHE_MIN_KIB = 32768;   // 32 MiB
    public const CACHE_MAX_KIB = 524288;  // 512 MiB
    public const MMAP_MIN_BYTES = 268435456;  // 256 MiB
    public const MMAP_MAX_BYTES = 536870912;  // 512 MiB
    public const JOURNAL_SIZE_LIMIT = 67108864; // 64 MiB
    public const WAL_AUTOCHECKPOINT = 1000;
    public const WORKER_THREADS = 4;
    public const PREFETCH_MAX_BYTES = 134217728; // 128 MiB
    public const MIN_SQLITE_VERSION = '3.53.0';

    /** @var array<string, mixed>|null */
    private static ?array $lastApplied = null;

    /**
     * Apply performance PRAGMAs on an open connection.
     *
     * @return array<string, mixed> Applied / verified pragma values
     */
    public static function apply(PDO $pdo, string $dbPath, bool $heavy = false): array
    {
        $applied = [];

        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $applied['busy_timeout'] = self::BUSY_TIMEOUT_MS;

        // RiskRegister schema relies on FK CASCADE — keep ON (unlike LinkNest).
        $pdo->exec('PRAGMA foreign_keys = ON');
        $applied['foreign_keys'] = 'ON';

        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $applied['journal_mode'] = strtolower((string) self::pragma($pdo, 'journal_mode'));
        } catch (PDOException $e) {
            $applied['journal_mode'] = (string) self::pragma($pdo, 'journal_mode');
        }

        $dbBytes = self::databaseBytes($pdo, $dbPath);
        $mmapTarget = self::mmapSizeForDatabase($dbBytes);
        try {
            $pdo->exec('PRAGMA mmap_size = ' . $mmapTarget);
            $applied['mmap_size'] = (int) self::pragma($pdo, 'mmap_size');
        } catch (PDOException $e) {
            $applied['mmap_size'] = (int) self::pragma($pdo, 'mmap_size');
        }

        $cacheKiB = self::sizePageCache($pdo, $dbPath);
        $applied['cache_size'] = (int) self::pragma($pdo, 'cache_size');
        $applied['cache_size_kib'] = $cacheKiB;

        $pdo->exec('PRAGMA synchronous = NORMAL');
        $applied['synchronous'] = (int) self::pragma($pdo, 'synchronous');

        $pdo->exec('PRAGMA temp_store = MEMORY');
        $applied['temp_store'] = (int) self::pragma($pdo, 'temp_store');

        try {
            $pdo->exec('PRAGMA threads = ' . self::WORKER_THREADS);
            $applied['threads'] = (int) self::pragma($pdo, 'threads');
        } catch (PDOException $e) {
            $applied['threads'] = (int) self::pragma($pdo, 'threads');
        }

        try {
            $pdo->exec('PRAGMA journal_size_limit = ' . self::JOURNAL_SIZE_LIMIT);
            $applied['journal_size_limit'] = (int) self::pragma($pdo, 'journal_size_limit');
        } catch (PDOException $e) {
            $applied['journal_size_limit'] = (int) self::pragma($pdo, 'journal_size_limit');
        }

        try {
            $pdo->exec('PRAGMA wal_autocheckpoint = ' . self::WAL_AUTOCHECKPOINT);
            $applied['wal_autocheckpoint'] = (int) self::pragma($pdo, 'wal_autocheckpoint');
        } catch (PDOException $e) {
            $applied['wal_autocheckpoint'] = (int) self::pragma($pdo, 'wal_autocheckpoint');
        }

        if ($heavy) {
            $applied['ram_prefetch'] = self::warmOsPageCache($dbPath);
            try {
                $pdo->exec('PRAGMA optimize');
                $applied['optimize'] = true;
            } catch (PDOException $e) {
                $applied['optimize'] = false;
            }
        } else {
            $applied['ram_prefetch'] = false;
            $applied['optimize'] = false;
        }

        try {
            $applied['sqlite_version'] = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
        } catch (PDOException $e) {
            $applied['sqlite_version'] = '';
        }

        $applied['database_bytes'] = $dbBytes;
        $applied['ram_resident'] = ($cacheKiB * 1024) >= $dbBytes && $dbBytes > 0;

        self::$lastApplied = $applied;

        return $applied;
    }

    /**
     * Canonical expected values for a given database size (used by healthcheck).
     *
     * @return array{
     *   busy_timeout: int,
     *   foreign_keys: string,
     *   journal_mode: string,
     *   synchronous: int,
     *   temp_store: int,
     *   threads: int,
     *   journal_size_limit: int,
     *   wal_autocheckpoint: int,
     *   cache_size_kib: int,
     *   cache_size: int,
     *   mmap_size: int,
     *   min_sqlite_version: string
     * }
     */
    public static function targets(int $dbBytes): array
    {
        $cacheKiB = self::cacheKiBForDatabase($dbBytes);
        $mmap = self::mmapSizeForDatabase($dbBytes);

        return [
            'busy_timeout' => self::BUSY_TIMEOUT_MS,
            'foreign_keys' => 'ON',
            'journal_mode' => 'wal',
            'synchronous' => 1, // NORMAL
            'temp_store' => 2,  // MEMORY
            'threads' => self::WORKER_THREADS,
            'journal_size_limit' => self::JOURNAL_SIZE_LIMIT,
            'wal_autocheckpoint' => self::WAL_AUTOCHECKPOINT,
            'cache_size_kib' => $cacheKiB,
            'cache_size' => -$cacheKiB,
            'mmap_size' => $mmap,
            'min_sqlite_version' => self::MIN_SQLITE_VERSION,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function lastApplied(): ?array
    {
        return self::$lastApplied;
    }

    /**
     * Size SQLite page cache so the working set stays in RAM.
     * Negative PRAGMA cache_size is KiB. Min 32 MiB, max 512 MiB.
     */
    public static function sizePageCache(PDO $pdo, string $dbPath): int
    {
        $kib = self::cacheKiBForDatabase(self::databaseBytes($pdo, $dbPath));
        $pdo->exec('PRAGMA cache_size = -' . $kib);

        return $kib;
    }

    /**
     * Pull the on-disk DB into the OS page cache (read-only prefetch).
     * Runs at most once per PHP worker. Skips files larger than PREFETCH_MAX_BYTES.
     */
    public static function warmOsPageCache(string $dbPath): bool
    {
        static $done = false;
        if ($done) {
            return true;
        }
        if ($dbPath === '' || !is_readable($dbPath)) {
            return false;
        }
        $size = @filesize($dbPath);
        if ($size === false || $size <= 0 || $size > self::PREFETCH_MAX_BYTES) {
            return false;
        }

        $fh = @fopen($dbPath, 'rb');
        if ($fh === false) {
            return false;
        }
        try {
            while (!feof($fh)) {
                $chunk = fread($fh, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
            }
            foreach ([$dbPath . '-wal', $dbPath . '-shm'] as $side) {
                $sideSize = is_readable($side) ? (@filesize($side) ?: 0) : 0;
                if ($sideSize > 0 && $sideSize <= 16 * 1024 * 1024) {
                    @file_get_contents($side);
                }
            }
            $done = true;

            return true;
        } finally {
            fclose($fh);
        }
    }

    public static function cacheKiBForDatabase(int $dbBytes): int
    {
        $kib = (int) ceil(($dbBytes * 2) / 1024);
        $kib = max($kib, self::CACHE_MIN_KIB);
        $kib = min($kib, self::CACHE_MAX_KIB);

        return $kib;
    }

    public static function mmapSizeForDatabase(int $dbBytes): int
    {
        $mmap = max(self::MMAP_MIN_BYTES, $dbBytes);

        return (int) min($mmap, self::MMAP_MAX_BYTES);
    }

    public static function databaseBytes(PDO $pdo, string $dbPath): int
    {
        try {
            $pageCount = (int) self::pragma($pdo, 'page_count');
            $pageSize = (int) self::pragma($pdo, 'page_size');
            $bytes = $pageCount * $pageSize;
            if ($bytes > 0) {
                return $bytes;
            }
        } catch (PDOException $e) {
            // Fall through to filesize.
        }
        if ($dbPath !== '' && is_file($dbPath)) {
            return (int) @filesize($dbPath);
        }

        return 0;
    }

    /** @return mixed */
    private static function pragma(PDO $pdo, string $name)
    {
        try {
            return $pdo->query('PRAGMA ' . $name)->fetchColumn();
        } catch (PDOException $e) {
            return null;
        }
    }
}
