<?php

declare(strict_types=1);

namespace RiskAssessment;

use PDO;
use RiskAssessment\Database\Database;
use RuntimeException;

final class SqliteMaintenance
{
    public const MAX_SNAPSHOTS = 30;

    private const NAME_PATTERN = '/^snapshot_\d{8}_\d{6}(?:_[a-zA-Z0-9_-]{1,40})?\.sqlite$/';

    public function __construct(
        private PDO $pdo,
        private readonly string $dbPath,
        private readonly string $snapshotsDir,
    ) {
    }

    /** @param array{driver?: string, path?: string} $dbConfig */
    public static function fromConfig(PDO $pdo, array $dbConfig): self
    {
        $path = (string) ($dbConfig['path'] ?? '');
        if ($path === '') {
            throw new RuntimeException('SQLite database path is not configured.');
        }

        $dir = dirname($path) . DIRECTORY_SEPARATOR . 'snapshots';

        return new self($pdo, $path, $dir);
    }

    public function databasePath(): string
    {
        return $this->dbPath;
    }

    public function snapshotsDirectory(): string
    {
        return $this->snapshotsDir;
    }

    /**
     * @return array{
     *   path: string,
     *   exists: bool,
     *   sizeBytes: int,
     *   sizeLabel: string,
     *   pageCount: int,
     *   pageSize: int,
     *   freelistCount: int,
     *   freelistBytes: int,
     *   journalMode: string,
     *   integrityOk: bool|null,
     *   tableCount: int,
     *   snapshotsDir: string,
     *   snapshotCount: int
     * }
     */
    public function status(bool $runIntegrity = false): array
    {
        $exists = is_file($this->dbPath);
        $size = $exists ? (int) filesize($this->dbPath) : 0;
        $pageCount = (int) $this->pragmaInt('page_count');
        $pageSize = (int) $this->pragmaInt('page_size');
        $freelist = (int) $this->pragmaInt('freelist_count');
        $journal = (string) $this->pragmaValue('journal_mode');
        $tableCount = 0;
        $tables = $this->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        );
        if ($tables !== false) {
            $tableCount = (int) $tables->fetchColumn();
        }

        $integrityOk = null;
        if ($runIntegrity) {
            $integrityOk = $this->integrityCheck()['ok'];
        }

        return [
            'path' => $this->dbPath,
            'exists' => $exists,
            'sizeBytes' => $size,
            'sizeLabel' => self::formatBytes($size),
            'pageCount' => $pageCount,
            'pageSize' => $pageSize,
            'freelistCount' => $freelist,
            'freelistBytes' => $freelist * $pageSize,
            'journalMode' => $journal !== '' ? $journal : 'unknown',
            'integrityOk' => $integrityOk,
            'tableCount' => $tableCount,
            'snapshotsDir' => $this->snapshotsDir,
            'snapshotCount' => count($this->listSnapshots()),
        ];
    }

    /** @return array{ok: bool, messages: list<string>} */
    public function integrityCheck(): array
    {
        $statement = $this->pdo->query('PRAGMA integrity_check');
        $messages = [];
        if ($statement !== false) {
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $messages[] = (string) ($row[0] ?? '');
            }
        }
        if ($messages === []) {
            $messages[] = 'unable to run integrity_check';
        }

        $ok = count($messages) === 1 && strtolower($messages[0]) === 'ok';

        return ['ok' => $ok, 'messages' => $messages];
    }

    public function vacuum(): void
    {
        $this->pdo->exec('VACUUM');
    }

    public function analyze(): void
    {
        $this->pdo->exec('ANALYZE');
    }

    public function checkpoint(): void
    {
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    }

    /**
     * Create a consistent snapshot with VACUUM INTO.
     *
     * @return array{filename: string, path: string, sizeBytes: int, label: string, createdAt: string}
     */
    public function createSnapshot(?string $label = null): array
    {
        $this->ensureSnapshotsDir();
        $safeLabel = $this->sanitizeLabel($label);
        $stamp = date('Ymd_His');
        $filename = 'snapshot_' . $stamp . ($safeLabel !== '' ? '_' . $safeLabel : '') . '.sqlite';
        $target = $this->snapshotPath($filename);

        if (is_file($target)) {
            throw new RuntimeException('A snapshot with that name already exists. Wait a second and try again.');
        }

        $escaped = str_replace("'", "''", $this->toSqlitePath($target));
        try {
            $this->pdo->exec("VACUUM INTO '" . $escaped . "'");
        } catch (\Throwable $exception) {
            // Fallback for SQLite builds without VACUUM INTO.
            $this->checkpoint();
            if (!copy($this->dbPath, $target)) {
                throw new RuntimeException(
                    'Unable to create snapshot: ' . $exception->getMessage()
                );
            }
        }

        if (!is_file($target)) {
            throw new RuntimeException('Snapshot file was not created.');
        }

        $this->pruneOldSnapshots();

        return [
            'filename' => $filename,
            'path' => $target,
            'sizeBytes' => (int) filesize($target),
            'label' => $safeLabel,
            'createdAt' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<array{filename: string, path: string, sizeBytes: int, sizeLabel: string, modifiedAt: string, label: string}>
     */
    public function listSnapshots(): array
    {
        if (!is_dir($this->snapshotsDir)) {
            return [];
        }

        $files = glob($this->snapshotsDir . DIRECTORY_SEPARATOR . 'snapshot_*.sqlite') ?: [];
        $items = [];
        foreach ($files as $path) {
            $filename = basename($path);
            if (!$this->isValidSnapshotName($filename) || !is_file($path)) {
                continue;
            }
            $mtime = (int) filemtime($path);
            $size = (int) filesize($path);
            $items[] = [
                'filename' => $filename,
                'path' => $path,
                'sizeBytes' => $size,
                'sizeLabel' => self::formatBytes($size),
                'modifiedAt' => date('Y-m-d H:i:s', $mtime),
                'label' => $this->labelFromFilename($filename),
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp($b['filename'], $a['filename'])
        );

        return $items;
    }

    /**
     * Create an optional safety snapshot, then return paths needed for file replace.
     * Caller must release every PDO handle, then call replaceDatabaseFile().
     *
     * @return array{source: string, safety: string|null, dbPath: string}
     */
    public function prepareRestore(string $filename, bool $createSafetyBackup = true): array
    {
        $this->assertValidSnapshotName($filename);
        $source = $this->snapshotPath($filename);
        if (!is_file($source)) {
            throw new RuntimeException('Snapshot not found.');
        }

        $safetyName = null;
        if ($createSafetyBackup && is_file($this->dbPath)) {
            $safety = $this->createSnapshot('pre_restore');
            $safetyName = $safety['filename'];
        }

        return [
            'source' => $source,
            'safety' => $safetyName,
            'dbPath' => $this->dbPath,
        ];
    }

    /**
     * Replace the live database file. All PDO handles to the live file must already be closed.
     */
    public static function replaceDatabaseFile(string $dbPath, string $sourcePath): void
    {
        if ($dbPath === '' || !is_file($sourcePath)) {
            throw new RuntimeException('Invalid restore paths.');
        }

        Database::disconnect();

        foreach ([$dbPath . '-wal', $dbPath . '-shm', $dbPath . '-journal'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }

        $dir = dirname($dbPath);
        $base = basename($dbPath);
        foreach (glob($dir . DIRECTORY_SEPARATOR . $base . '.retired-*') ?: [] as $retiredOld) {
            @unlink($retiredOld);
        }

        $retired = $dbPath . '.retired-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
        if (is_file($dbPath) && !@rename($dbPath, $retired)) {
            // Last resort: overwrite in place (works when rename is blocked).
            $contents = file_get_contents($sourcePath);
            if ($contents === false || file_put_contents($dbPath, $contents) === false) {
                throw new RuntimeException('Unable to replace the live database file. Close other app connections and try again.');
            }
            foreach ([$dbPath . '-wal', $dbPath . '-shm', $dbPath . '-journal'] as $sidecar) {
                if (is_file($sidecar)) {
                    @unlink($sidecar);
                }
            }

            return;
        }

        if (!@copy($sourcePath, $dbPath)) {
            if (is_file($retired)) {
                @rename($retired, $dbPath);
            }
            throw new RuntimeException('Unable to copy the snapshot into place.');
        }

        foreach ([$dbPath . '-wal', $dbPath . '-shm', $dbPath . '-journal'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }
        if (is_file($retired)) {
            @unlink($retired);
        }
    }

    /**
     * Replace the live database with a snapshot.
     * Closes the shared PDO handle on this instance; caller must also drop other PDO refs
     * (bootstrap $pdo, Auth repositories) before this returns on Windows, or call
     * prepareRestore() + replaceDatabaseFile() after unsetting them.
     *
     * @return array{restored: string, safety: string|null}
     */
    public function restoreSnapshot(string $filename, bool $createSafetyBackup = true): array
    {
        $prepared = $this->prepareRestore($filename, $createSafetyBackup);
        Database::disconnect();
        $this->pdo = new PDO('sqlite::memory:');
        self::replaceDatabaseFile($prepared['dbPath'], $prepared['source']);

        return [
            'restored' => $filename,
            'safety' => $prepared['safety'],
        ];
    }

    /**
     * Validate an uploaded .sqlite file, store it as a snapshot, prepare restore paths.
     *
     * @return array{filename: string, source: string, safety: string|null, dbPath: string}
     */
    public function prepareRestoreFromUpload(string $tmpPath, string $originalName, bool $createSafetyBackup = true): array
    {
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('No valid upload received.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'sqlite' && $ext !== 'db') {
            throw new RuntimeException('Upload a .sqlite (or .db) snapshot file.');
        }

        $probe = new PDO('sqlite:' . $tmpPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $check = $probe->query('PRAGMA integrity_check');
        $result = $check !== false ? (string) $check->fetchColumn() : '';
        $probe = null;
        if (strtolower($result) !== 'ok') {
            throw new RuntimeException('Uploaded file failed SQLite integrity_check: ' . ($result !== '' ? $result : 'unknown error'));
        }

        $imported = $this->createSnapshotFromFile($tmpPath, 'upload');
        $prepared = $this->prepareRestore($imported['filename'], $createSafetyBackup);

        return [
            'filename' => $imported['filename'],
            'source' => $prepared['source'],
            'safety' => $prepared['safety'],
            'dbPath' => $prepared['dbPath'],
        ];
    }

    /**
     * @return array{restored: string, safety: string|null}
     */
    public function restoreFromUpload(string $tmpPath, string $originalName, bool $createSafetyBackup = true): array
    {
        $prepared = $this->prepareRestoreFromUpload($tmpPath, $originalName, $createSafetyBackup);
        Database::disconnect();
        $this->pdo = new PDO('sqlite::memory:');
        self::replaceDatabaseFile($prepared['dbPath'], $prepared['source']);

        return [
            'restored' => $prepared['filename'],
            'safety' => $prepared['safety'],
        ];
    }

    public function deleteSnapshot(string $filename): void
    {
        $this->assertValidSnapshotName($filename);
        $path = $this->snapshotPath($filename);
        if (!is_file($path)) {
            throw new RuntimeException('Snapshot not found.');
        }
        if (!unlink($path)) {
            throw new RuntimeException('Unable to delete the snapshot.');
        }
    }

    public function snapshotPath(string $filename): string
    {
        $this->assertValidSnapshotName($filename);

        return $this->snapshotsDir . DIRECTORY_SEPARATOR . $filename;
    }

    public function assertValidSnapshotName(string $filename): void
    {
        if (!$this->isValidSnapshotName($filename)) {
            throw new RuntimeException('Invalid snapshot name.');
        }
    }

    public function isValidSnapshotName(string $filename): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $filename);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024) {
                return round($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
            }
        }

        return round($value, 1) . ' PB';
    }

    /** @return array{filename: string, path: string, sizeBytes: int, label: string, createdAt: string} */
    private function createSnapshotFromFile(string $sourcePath, string $label): array
    {
        $this->ensureSnapshotsDir();
        $safeLabel = $this->sanitizeLabel($label);
        $stamp = date('Ymd_His');
        $filename = 'snapshot_' . $stamp . ($safeLabel !== '' ? '_' . $safeLabel : '') . '.sqlite';
        $target = $this->snapshotPath($filename);
        if (!copy($sourcePath, $target)) {
            throw new RuntimeException('Unable to store the uploaded snapshot.');
        }

        $this->pruneOldSnapshots();

        return [
            'filename' => $filename,
            'path' => $target,
            'sizeBytes' => (int) filesize($target),
            'label' => $safeLabel,
            'createdAt' => date('Y-m-d H:i:s'),
        ];
    }

    private function ensureSnapshotsDir(): void
    {
        if (is_dir($this->snapshotsDir)) {
            return;
        }
        if (!mkdir($this->snapshotsDir, 0755, true) && !is_dir($this->snapshotsDir)) {
            throw new RuntimeException('Unable to create the snapshots directory.');
        }
        $deny = $this->snapshotsDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\n");
        }
    }

    private function pruneOldSnapshots(): void
    {
        $items = $this->listSnapshots();
        if (count($items) <= self::MAX_SNAPSHOTS) {
            return;
        }
        foreach (array_slice($items, self::MAX_SNAPSHOTS) as $old) {
            @unlink((string) $old['path']);
        }
    }

    private function sanitizeLabel(?string $label): string
    {
        $label = strtolower(trim((string) $label));
        $label = preg_replace('/[^a-z0-9_-]+/', '_', $label) ?? '';
        $label = trim($label, '_-');
        if (strlen($label) > 40) {
            $label = substr($label, 0, 40);
        }

        return $label;
    }

    private function labelFromFilename(string $filename): string
    {
        if (preg_match('/^snapshot_\d{8}_\d{6}_([a-zA-Z0-9_-]{1,40})\.sqlite$/', $filename, $m)) {
            return (string) $m[1];
        }

        return '';
    }

    private function toSqlitePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function pragmaInt(string $name): int
    {
        $value = $this->pragmaValue($name);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function pragmaValue(string $name): string
    {
        $allowed = ['page_count', 'page_size', 'freelist_count', 'journal_mode'];
        if (!in_array($name, $allowed, true)) {
            return '';
        }
        $statement = $this->pdo->query('PRAGMA ' . $name);
        if ($statement === false) {
            return '';
        }
        $value = $statement->fetchColumn();

        return $value === false ? '' : (string) $value;
    }
}
