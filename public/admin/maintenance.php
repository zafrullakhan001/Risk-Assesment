<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use RiskAssessment\Database\Database;
use RiskAssessment\Database\SqliteHealthcheck;
use RiskAssessment\SqliteMaintenance;

$currentUser = $auth->requireAdmin();
$dbConfig = require dirname(__DIR__, 2) . '/config/database.php';
$maintenance = SqliteMaintenance::fromConfig($pdo, $dbConfig);

$error = '';
$flash = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);
$integrityResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'integrity') {
            $integrityResult = $maintenance->integrityCheck();
            $flash = $integrityResult['ok']
                ? '✅ Integrity check passed.'
                : '⚠️ Integrity check reported problems.';
            $auth->users()->logAudit(
                'db.integrity_check',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['ok' => $integrityResult['ok']]
            );
        } elseif ($action === 'vacuum') {
            @set_time_limit(120);
            $before = is_file($maintenance->databasePath()) ? (int) filesize($maintenance->databasePath()) : 0;
            $maintenance->vacuum();
            clearstatcache(true, $maintenance->databasePath());
            $after = is_file($maintenance->databasePath()) ? (int) filesize($maintenance->databasePath()) : 0;
            $auth->users()->logAudit('db.vacuum', (int) $currentUser['id'], (string) $currentUser['username']);
            $flash = '🧹 VACUUM completed. Size ' . SqliteMaintenance::formatBytes($before)
                . ' → ' . SqliteMaintenance::formatBytes($after) . '.';
        } elseif ($action === 'analyze') {
            $maintenance->analyze();
            $auth->users()->logAudit('db.analyze', (int) $currentUser['id'], (string) $currentUser['username']);
            $flash = '📈 ANALYZE completed. Query planner statistics refreshed.';
        } elseif ($action === 'checkpoint') {
            $maintenance->checkpoint();
            $auth->users()->logAudit('db.checkpoint', (int) $currentUser['id'], (string) $currentUser['username']);
            $flash = '⚡ WAL checkpoint completed.';
        } elseif ($action === 'create_snapshot') {
            $label = trim((string) ($_POST['label'] ?? ''));
            $created = $maintenance->createSnapshot($label !== '' ? $label : null);
            $auth->users()->logAudit(
                'db.snapshot_create',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['file' => $created['filename']]
            );
            $flash = '📸 Snapshot created: ' . $created['filename']
                . ' (' . SqliteMaintenance::formatBytes((int) $created['sizeBytes']) . ').';
        } elseif ($action === 'delete_snapshot') {
            $filename = (string) ($_POST['filename'] ?? '');
            $maintenance->deleteSnapshot($filename);
            $auth->users()->logAudit(
                'db.snapshot_delete',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['file' => $filename]
            );
            $flash = '🗑️ Snapshot deleted.';
        } elseif ($action === 'download_snapshot') {
            $filename = (string) ($_POST['filename'] ?? '');
            $path = $maintenance->snapshotPath($filename);
            if (!is_file($path)) {
                throw new RuntimeException('Snapshot not found.');
            }
            $auth->users()->logAudit(
                'db.snapshot_download',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['file' => $filename]
            );
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            readfile($path);
            exit;
        } elseif ($action === 'restore_snapshot') {
            $filename = (string) ($_POST['filename'] ?? '');
            $safety = !empty($_POST['safety_backup']);
            @set_time_limit(180);
            ignore_user_abort(true);
            $prepared = $maintenance->prepareRestore($filename, $safety);
            // Release every handle that keeps the SQLite file locked on Windows.
            Database::disconnect();
            unset($maintenance, $pdo, $auth, $users, $settings, $ldap, $branding, $crypto);
            gc_collect_cycles();
            SqliteMaintenance::replaceDatabaseFile($prepared['dbPath'], $prepared['source']);
            $_SESSION['admin_flash'] = '♻️ Database restored from ' . $filename . '.'
                . ($prepared['safety'] !== null ? ' Safety snapshot saved as ' . $prepared['safety'] . '.' : '');
            $_SESSION['admin_audit_pending'] = [
                'event' => 'db.snapshot_restore',
                'details' => ['file' => $filename, 'safety' => $prepared['safety']],
            ];
            header('Location: maintenance.php');
            exit;
        } elseif ($action === 'restore_upload') {
            @set_time_limit(180);
            ignore_user_abort(true);
            $upload = $_FILES['snapshot_file'] ?? null;
            if (!is_array($upload)) {
                throw new RuntimeException('Choose a snapshot file to upload.');
            }
            if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed. Check the file size and try again.');
            }
            $safety = !empty($_POST['safety_backup']);
            $prepared = $maintenance->prepareRestoreFromUpload(
                (string) ($upload['tmp_name'] ?? ''),
                (string) ($upload['name'] ?? ''),
                $safety
            );
            Database::disconnect();
            unset($maintenance, $pdo, $auth, $users, $settings, $ldap, $branding, $crypto);
            gc_collect_cycles();
            SqliteMaintenance::replaceDatabaseFile($prepared['dbPath'], $prepared['source']);
            $_SESSION['admin_flash'] = '♻️ Database restored from uploaded file.'
                . ($prepared['safety'] !== null ? ' Safety snapshot saved as ' . $prepared['safety'] . '.' : '');
            $_SESSION['admin_audit_pending'] = [
                'event' => 'db.snapshot_restore_upload',
                'details' => ['file' => $prepared['filename'], 'safety' => $prepared['safety']],
            ];
            header('Location: maintenance.php');
            exit;
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (!empty($_SESSION['admin_audit_pending']) && is_array($_SESSION['admin_audit_pending'])) {
    $pending = $_SESSION['admin_audit_pending'];
    unset($_SESSION['admin_audit_pending']);
    try {
        $pdo = Database::connection($dbConfig);
        $maintenance = SqliteMaintenance::fromConfig($pdo, $dbConfig);
        $auth->users()->logAudit(
            (string) ($pending['event'] ?? 'db.snapshot_restore'),
            (int) $currentUser['id'],
            (string) $currentUser['username'],
            null,
            null,
            is_array($pending['details'] ?? null) ? $pending['details'] : []
        );
    } catch (Throwable) {
        // Restore already succeeded; skip audit if reconnect/logging fails.
    }
}

$status = $maintenance->status();
$allSnapshots = $maintenance->listSnapshots();
$snapshotQuery = trim((string) ($_GET['snap_q'] ?? ''));
$snapshots = $allSnapshots;
if ($snapshotQuery !== '') {
    $needle = mb_strtolower($snapshotQuery);
    $snapshots = array_values(array_filter(
        $allSnapshots,
        static function (array $snap) use ($needle): bool {
            $hay = mb_strtolower((string) ($snap['filename'] ?? '') . ' ' . (string) ($snap['label'] ?? ''));

            return str_contains($hay, $needle);
        }
    ));
}
$snapshotTotalAll = count($allSnapshots);
$snapshotTotal = count($snapshots);
$snapshotPerPage = 5;
$snapshotPage = max(1, (int) ($_GET['snap_page'] ?? 1));
$snapshotPages = max(1, (int) ceil(max($snapshotTotal, 1) / $snapshotPerPage));
if ($snapshotPage > $snapshotPages) {
    $snapshotPage = $snapshotPages;
}
$snapshotOffset = ($snapshotPage - 1) * $snapshotPerPage;
$snapshotsPage = array_slice($snapshots, $snapshotOffset, $snapshotPerPage);
$snapshotsOpen = isset($_GET['snap_page']) || isset($_GET['snap_q']) || $snapshotTotalAll === 0;
$snapQuerySuffix = $snapshotQuery !== '' ? '&snap_q=' . rawurlencode($snapshotQuery) : '';
$freelistHot = (int) $status['freelistCount'] > 100;
$sqliteHealth = SqliteHealthcheck::run($pdo, (string) ($dbConfig['path'] ?? ''), false);
$healthOk = !empty($sqliteHealth['critical_ok']);

$adminTitle = 'SQLite maintenance';
$adminTab = 'maintenance';
$adminEyebrow = '🗄️ Database';
$adminHeading = 'SQLite <em>maintenance</em>';
$adminIntro = 'Inspect the live database, reclaim space, and back up or restore from snapshots.';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card maint-card maint-card-status">
                <div class="maint-card-head">
                    <h2><span class="maint-emoji" aria-hidden="true">📊</span> Database status</h2>
                    <span class="maint-pill maint-pill-live">● Live</span>
                </div>

                <div class="maint-metrics">
                    <div class="maint-metric maint-metric-size">
                        <span class="maint-metric-icon" aria-hidden="true">💾</span>
                        <span class="maint-metric-label">File size</span>
                        <strong><?= e($status['sizeLabel']) ?></strong>
                    </div>
                    <div class="maint-metric maint-metric-tables">
                        <span class="maint-metric-icon" aria-hidden="true">📑</span>
                        <span class="maint-metric-label">Tables</span>
                        <strong><?= (int) $status['tableCount'] ?></strong>
                    </div>
                    <div class="maint-metric maint-metric-journal">
                        <span class="maint-metric-icon" aria-hidden="true">🧾</span>
                        <span class="maint-metric-label">Journal mode</span>
                        <strong><?= e($status['journalMode']) ?></strong>
                    </div>
                    <div class="maint-metric maint-metric-free <?= $freelistHot ? 'is-hot' : '' ?>">
                        <span class="maint-metric-icon" aria-hidden="true"><?= $freelistHot ? '🧹' : '✨' ?></span>
                        <span class="maint-metric-label">Free pages</span>
                        <strong><?= (int) $status['freelistCount'] ?></strong>
                        <em><?= e(SqliteMaintenance::formatBytes((int) $status['freelistBytes'])) ?></em>
                    </div>
                    <div class="maint-metric maint-metric-page">
                        <span class="maint-metric-icon" aria-hidden="true">📐</span>
                        <span class="maint-metric-label">Page size</span>
                        <strong><?= (int) $status['pageSize'] ?> B</strong>
                    </div>
                    <div class="maint-metric maint-metric-snaps">
                        <span class="maint-metric-icon" aria-hidden="true">📸</span>
                        <span class="maint-metric-label">Snapshots</span>
                        <strong><?= (int) $status['snapshotCount'] ?> <span class="maint-metric-of">/ <?= (int) SqliteMaintenance::MAX_SNAPSHOTS ?></span></strong>
                    </div>
                </div>

                <p class="maint-path">
                    <span aria-hidden="true">📁</span>
                    <code><?= e($status['path']) ?></code>
                </p>

                <details class="maint-health <?= $healthOk ? 'is-ok' : 'is-bad' ?>"<?= $healthOk ? '' : ' open' ?>>
                    <summary class="maint-health-head">
                        <span class="maint-health-title">
                            <span class="maint-health-chevron" aria-hidden="true"></span>
                            <span aria-hidden="true">⚙️</span>
                            Performance configuration
                        </span>
                        <span class="maint-pill <?= $healthOk ? 'maint-pill-safe' : 'maint-pill-warn' ?>">
                            <?= $healthOk ? '● PASS' : '● FAIL' ?>
                            · <?= e((string) ($sqliteHealth['checks_passed'] ?? '')) ?>
                        </span>
                    </summary>
                    <div class="maint-health-body">
                        <p class="maint-health-summary"><?= e((string) ($sqliteHealth['summary'] ?? '')) ?></p>
                        <div class="maint-health-table-wrap">
                            <table class="maint-health-table">
                                <thead>
                                    <tr>
                                        <th>Setting</th>
                                        <th>Expected</th>
                                        <th>Current</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($sqliteHealth['settings'] ?? []) as $row): ?>
                                        <tr class="<?= !empty($row['ok']) ? 'is-ok' : 'is-fail' ?>">
                                            <td>
                                                <code><?= e((string) ($row['id'] ?? '')) ?></code>
                                                <?php if (!empty($row['critical'])): ?>
                                                    <span class="maint-health-critical" title="Critical">*</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e((string) ($row['expected'] ?? '')) ?></td>
                                            <td><?= e((string) ($row['actual'] ?? '')) ?></td>
                                            <td>
                                                <?php if (!empty($row['ok'])): ?>
                                                    <span class="maint-health-badge is-ok">OK</span>
                                                <?php else: ?>
                                                    <span class="maint-health-badge is-fail">FAIL</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php if (empty($row['ok']) && ($row['fix'] ?? '') !== ''): ?>
                                            <tr class="maint-health-fix">
                                                <td colspan="4"><?= e((string) $row['fix']) ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="maint-health-hint">
                            CLI: <code>php bin/check_sqlite_health.php</code>
                            · Cache <?= e((string) ($sqliteHealth['sizes']['cache_human'] ?? '?')) ?>
                            · mmap <?= e((string) ($sqliteHealth['sizes']['mmap_human'] ?? '?')) ?>
                            · SQLite <?= e((string) ($sqliteHealth['environment']['sqlite_version'] ?? '?')) ?>
                        </p>
                    </div>
                </details>

                <div class="maint-toolbar">
                    <div class="maint-toolbar-label">Quick actions</div>
                    <div class="maint-action-row">
                        <form method="post" class="maint-action-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="integrity">
                            <button type="submit" class="button maint-btn maint-btn-check">✅ Integrity check</button>
                        </form>
                        <form method="post" class="maint-action-form" onsubmit="return confirm('Run VACUUM now? This rewrites the database file and may take a moment.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="vacuum">
                            <button type="submit" class="button maint-btn maint-btn-vacuum">🧹 VACUUM</button>
                        </form>
                        <form method="post" class="maint-action-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="analyze">
                            <button type="submit" class="button maint-btn maint-btn-analyze">📈 ANALYZE</button>
                        </form>
                        <form method="post" class="maint-action-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="checkpoint">
                            <button type="submit" class="button maint-btn maint-btn-wal">⚡ WAL checkpoint</button>
                        </form>
                    </div>
                </div>

                <?php if (is_array($integrityResult)): ?>
                    <div class="integrity-result <?= $integrityResult['ok'] ? 'is-ok' : 'is-bad' ?>">
                        <strong><?= $integrityResult['ok'] ? '✅ Integrity OK' : '⚠️ Integrity issues' ?></strong>
                        <?php foreach ($integrityResult['messages'] as $message): ?>
                            <code><?= e((string) $message) ?></code>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="maint-split">
                <section class="upload-card maint-card maint-card-backup">
                    <div class="maint-card-head">
                        <h2><span class="maint-emoji" aria-hidden="true">📸</span> Create snapshot</h2>
                        <span class="maint-pill maint-pill-safe">Backup</span>
                    </div>
                    <p class="maint-lead">Consistent copy via <code>VACUUM INTO</code>. Keeps the newest <?= (int) SqliteMaintenance::MAX_SNAPSHOTS ?> automatically.</p>
                    <form method="post" class="maint-create-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_snapshot">
                        <label class="file-input maint-label-grow">
                            <span>🏷️ Optional label</span>
                            <input type="text" name="label" maxlength="40" placeholder="e.g. before_migrate" pattern="[A-Za-z0-9_-]*" autocomplete="off">
                        </label>
                        <button type="submit" class="button button-primary maint-btn maint-btn-create">📷 Create snapshot</button>
                    </form>
                </section>

                <section class="upload-card maint-card maint-card-upload">
                    <div class="maint-card-head">
                        <h2><span class="maint-emoji" aria-hidden="true">📥</span> Restore from upload</h2>
                        <span class="maint-pill maint-pill-warn">Caution</span>
                    </div>
                    <p class="updater-warning maint-upload-warn">⚠️ Replaces the live SQLite file. A safety snapshot is saved first. Refresh after restore if needed.</p>
                    <form method="post" enctype="multipart/form-data" class="maint-create-form" onsubmit="return confirm('Replace the live database with this uploaded file?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="restore_upload">
                        <input type="hidden" name="safety_backup" value="1">
                        <label class="file-input maint-label-grow">
                            <span>📄 Snapshot file (.sqlite)</span>
                            <input type="file" name="snapshot_file" accept=".sqlite,.db,application/octet-stream" required>
                        </label>
                        <button type="submit" class="button maint-btn maint-btn-restore-upload">♻️ Upload &amp; restore</button>
                    </form>
                </section>
            </div>

            <details class="upload-card maint-card maint-card-list maint-snap-panel"<?= $snapshotsOpen ? ' open' : '' ?> id="snapshots">
                <summary class="maint-card-head maint-snap-summary">
                    <span class="maint-health-title">
                        <span class="maint-health-chevron" aria-hidden="true"></span>
                        <span class="maint-emoji" aria-hidden="true">🗂️</span>
                        Snapshots
                    </span>
                    <span class="maint-pill">
                        <?php if ($snapshotQuery !== ''): ?>
                            <?= (int) $snapshotTotal ?> match<?= $snapshotTotal === 1 ? '' : 'es' ?>
                            · <?= (int) $snapshotTotalAll ?> total
                        <?php else: ?>
                            <?= (int) $snapshotTotalAll ?> saved
                        <?php endif; ?>
                        <?php if ($snapshotTotal > $snapshotPerPage): ?>
                            · page <?= (int) $snapshotPage ?>/<?= (int) $snapshotPages ?>
                        <?php endif; ?>
                    </span>
                </summary>
                <div class="maint-snap-body">
                <?php if ($snapshotTotalAll === 0): ?>
                    <div class="maint-empty">
                        <span class="maint-empty-icon" aria-hidden="true">📭</span>
                        <p>No snapshots yet. Create one above before you need a restore.</p>
                    </div>
                <?php else: ?>
                    <form method="get" class="maint-snap-search" action="maintenance.php#snapshots" role="search">
                        <label class="file-input maint-snap-search-field">
                            <span>🔎 Search label or file name</span>
                            <input
                                type="search"
                                name="snap_q"
                                value="<?= e($snapshotQuery) ?>"
                                placeholder="e.g. pre_restore, sharepoint, snapshot_2026"
                                autocomplete="off"
                            >
                        </label>
                        <div class="maint-snap-search-actions">
                            <button type="submit" class="button maint-btn">Search</button>
                            <?php if ($snapshotQuery !== ''): ?>
                                <a class="button ghost maint-btn" href="maintenance.php#snapshots">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if ($snapshots === []): ?>
                        <div class="maint-empty">
                            <span class="maint-empty-icon" aria-hidden="true">🔍</span>
                            <p>No snapshots match <strong><?= e($snapshotQuery) ?></strong>.</p>
                        </div>
                    <?php else: ?>
                    <div class="maint-snap-list">
                        <?php foreach ($snapshotsPage as $snap): ?>
                            <article class="maint-snap-row">
                                <div class="maint-snap-main">
                                    <div class="maint-snap-title">
                                        <span class="maint-snap-badge" aria-hidden="true">💾</span>
                                        <div>
                                            <code class="snap-name"><?= e($snap['filename']) ?></code>
                                            <div class="maint-snap-meta">
                                                <?php if ($snap['label'] !== ''): ?>
                                                    <span class="maint-tag">🏷️ <?= e($snap['label']) ?></span>
                                                <?php else: ?>
                                                    <span class="maint-tag is-muted">No label</span>
                                                <?php endif; ?>
                                                <span class="maint-tag maint-tag-size">📦 <?= e($snap['sizeLabel']) ?></span>
                                                <span class="maint-tag maint-tag-time">🕒 <?= e($snap['modifiedAt']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="maint-snap-actions">
                                    <form method="post" class="maint-action-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="download_snapshot">
                                        <input type="hidden" name="filename" value="<?= e($snap['filename']) ?>">
                                        <button type="submit" class="button maint-btn maint-btn-download">⬇️ Download</button>
                                    </form>
                                    <form
                                        method="post"
                                        class="maint-action-form"
                                        onsubmit="return confirm('Restore the live database from this snapshot? A safety snapshot of the current database will be saved first.');"
                                    >
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="restore_snapshot">
                                        <input type="hidden" name="filename" value="<?= e($snap['filename']) ?>">
                                        <input type="hidden" name="safety_backup" value="1">
                                        <button type="submit" class="button maint-btn maint-btn-restore">♻️ Restore</button>
                                    </form>
                                    <form
                                        method="post"
                                        class="maint-action-form"
                                        onsubmit="return confirm('Delete this snapshot permanently?');"
                                    >
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_snapshot">
                                        <input type="hidden" name="filename" value="<?= e($snap['filename']) ?>">
                                        <button type="submit" class="button maint-btn maint-btn-delete">🗑️ Delete</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($snapshotPages > 1): ?>
                        <nav class="maint-snap-pager" aria-label="Snapshot pages">
                            <span class="maint-snap-pager-info">
                                Showing <?= (int) ($snapshotOffset + 1) ?>–<?= (int) min($snapshotOffset + $snapshotPerPage, $snapshotTotal) ?>
                                of <?= (int) $snapshotTotal ?><?= $snapshotQuery !== '' ? ' matching' : '' ?>
                            </span>
                            <div class="maint-snap-pager-links">
                                <?php if ($snapshotPage > 1): ?>
                                    <a class="button ghost maint-btn" href="?snap_page=<?= (int) ($snapshotPage - 1) . e($snapQuerySuffix) ?>#snapshots">← Prev</a>
                                <?php endif; ?>
                                <?php for ($p = 1; $p <= $snapshotPages; $p++): ?>
                                    <?php if ($p === $snapshotPage): ?>
                                        <span class="maint-snap-page is-current" aria-current="page"><?= $p ?></span>
                                    <?php else: ?>
                                        <a class="maint-snap-page" href="?snap_page=<?= $p . e($snapQuerySuffix) ?>#snapshots"><?= $p ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($snapshotPage < $snapshotPages): ?>
                                    <a class="button ghost maint-btn" href="?snap_page=<?= (int) ($snapshotPage + 1) . e($snapQuerySuffix) ?>#snapshots">Next →</a>
                                <?php endif; ?>
                            </div>
                        </nav>
                    <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
            </details>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
