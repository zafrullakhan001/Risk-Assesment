<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use RiskAssessment\ExceptionWorkerScheduler;
use RiskAssessment\Mail\SmtpSettings;
use RiskAssessment\Repositories\EmailOutboxRepository;
use RiskAssessment\Repositories\FindingStatusRepository;

$currentUser = $auth->requireAdmin();
$error = '';
$flash = '';
$actionOutput = '';
$lastAction = '';

$findings = new FindingStatusRepository($pdo);
$outbox = new EmailOutboxRepository($pdo);
$smtpSettings = new SmtpSettings($settings, $crypto);
$scheduler = new ExceptionWorkerScheduler(
    dirname(__DIR__, 2),
    $findings,
    $outbox,
    $smtpSettings
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $lastAction = $action;
        $result = ['ok' => false, 'output' => 'Unknown action.', 'code' => 1];

        if ($action === 'scheduler_install') {
            $interval = filter_var($_POST['interval_minutes'] ?? 60, FILTER_VALIDATE_INT) ?: 60;
            $result = $scheduler->install($interval, true);
            $flash = $result['ok']
                ? 'Exception worker tasks installed (every ' . max(15, min(1440, $interval)) . ' minutes).'
                : 'Install failed. You may need to run scripts\\schedule-exception-workers.bat as a Windows user with Task Scheduler rights.';
        } elseif ($action === 'scheduler_uninstall') {
            $result = $scheduler->uninstall();
            $flash = $result['ok'] ? 'Exception worker tasks uninstalled.' : 'Uninstall failed.';
        } elseif ($action === 'scheduler_enable') {
            $kind = (string) ($_POST['task'] ?? 'monitor');
            $result = $scheduler->setEnabled($kind, true);
            $flash = $result['ok'] ? 'Task started (enabled).' : 'Could not enable task.';
        } elseif ($action === 'scheduler_disable') {
            $kind = (string) ($_POST['task'] ?? 'monitor');
            $result = $scheduler->setEnabled($kind, false);
            $flash = $result['ok'] ? 'Task stopped (disabled).' : 'Could not disable task.';
        } elseif ($action === 'scheduler_enable_both') {
            $result = $scheduler->enableBoth();
            $flash = $result['ok'] ? 'Both workers started.' : 'Could not start both tasks.';
        } elseif ($action === 'scheduler_disable_both') {
            $result = $scheduler->disableBoth();
            $flash = $result['ok'] ? 'Both workers stopped.' : 'Could not stop both tasks.';
        } elseif ($action === 'scheduler_run') {
            $kind = (string) ($_POST['task'] ?? 'monitor');
            $result = $scheduler->runNow($kind);
            if (!$result['ok']) {
                $result = $scheduler->runWorkerCli($kind);
                $flash = $result['ok']
                    ? 'Worker finished via PHP (Task Scheduler run was denied).'
                    : 'Run failed.';
            } else {
                $flash = 'Task triggered.';
            }
        } elseif ($action === 'scheduler_run_cli') {
            $kind = (string) ($_POST['task'] ?? 'monitor');
            $result = $scheduler->runWorkerCli($kind);
            $flash = $result['ok'] ? 'Worker finished.' : 'Worker failed.';
        } elseif ($action === 'scheduler_refresh') {
            $flash = 'Status refreshed.';
            $result = ['ok' => true, 'output' => '', 'code' => 0];
        } else {
            throw new RuntimeException('Unknown action.');
        }

        $actionOutput = (string) ($result['output'] ?? '');
        if (!$result['ok'] && $error === '' && $flash !== '' && !str_starts_with($flash, 'Status')) {
            $error = $flash . ($actionOutput !== '' ? ' ' . $actionOutput : '');
            $flash = '';
        }

        $auth->users()->logAudit(
            'scheduler.' . preg_replace('/^scheduler_/', '', $action),
            (int) $currentUser['id'],
            (string) $currentUser['username'],
            null,
            null,
            [
                'ok' => !empty($result['ok']),
                'task' => (string) ($_POST['task'] ?? ''),
            ]
        );
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$status = $scheduler->status();
$monitor = $status['tasks']['monitor'];
$email = $status['tasks']['email'];
$monitorExists = !empty($monitor['exists']);
$emailExists = !empty($email['exists']);
$monitorOn = $monitorExists && !empty($monitor['enabled']);
$emailOn = $emailExists && !empty($email['enabled']);
$anyInstalled = $monitorExists || $emailExists;
$bothInstalled = $monitorExists && $emailExists;
$workersOn = !empty($status['workers_on']);
$bothOff = $bothInstalled && !$monitorOn && !$emailOn;
$partialOn = $bothInstalled && (($monitorOn && !$emailOn) || (!$monitorOn && $emailOn));

$checks = [
    [
        'id' => 'php',
        'label' => 'PHP runtime',
        'ok' => !empty($status['php']['ok']),
        'warn' => false,
        'detail' => (string) ($status['php']['path'] ?: 'php.exe not found'),
        'value' => !empty($status['php']['ok']) ? 'Ready' : 'Missing',
    ],
    [
        'id' => 'sqlite',
        'label' => 'SQLite database',
        'ok' => !empty($status['sqlite']['ok']),
        'warn' => false,
        'detail' => (string) ($status['sqlite']['path'] ?: '—'),
        'value' => !empty($status['sqlite']['ok']) ? 'Connected' : 'Missing',
    ],
    [
        'id' => 'smtp',
        'label' => 'SMTP mail',
        'ok' => !empty($status['smtp']['enabled']),
        'warn' => empty($status['smtp']['enabled']),
        'detail' => !empty($status['smtp']['enabled'])
            ? 'Outbound mail ready'
            : 'Enable in Admin → Email before emails send',
        'value' => !empty($status['smtp']['enabled']) ? 'On' : 'Off',
    ],
    [
        'id' => 'queue',
        'label' => 'Exception queue',
        'ok' => true,
        'warn' => (int) $status['due_count'] > 0 || (int) $status['outbox_pending'] > 0,
        'detail' => (int) $status['due_count'] . ' due · ' . (int) $status['outbox_pending'] . ' pending emails',
        'value' => ((int) $status['due_count'] + (int) $status['outbox_pending']) > 0 ? 'Active' : 'Quiet',
    ],
];

$passedChecks = 0;
foreach ($checks as $check) {
    if (!empty($check['ok']) && empty($check['warn'])) {
        $passedChecks++;
    } elseif (!empty($check['ok'])) {
        $passedChecks++;
    }
}
$progressPct = (int) round(($passedChecks / max(1, count($checks))) * 100);
if ($workersOn) {
    $progressPct = 100;
} elseif ($bothInstalled && !$workersOn) {
    $progressPct = max($progressPct, 70);
} elseif ($anyInstalled) {
    $progressPct = max($progressPct, 45);
}

$adminTitle = 'Task Scheduler';
$adminTab = 'scheduler';
$adminEyebrow = 'Background workers';
$adminHeading = 'Exception <em>Task Scheduler</em>';
$adminIntro = 'Install and control Windows tasks that monitor due exceptions and send queued reminder emails.';
require dirname(__DIR__) . '/includes/admin-header.php';

$rows = [
    'monitor' => [
        'title' => 'Exception Monitor',
        'blurb' => 'Finds due/overdue exceptions, creates in-app notices, and queues emails.',
        'task' => $monitor,
        'script' => 'bin\\exception_monitor.php',
        'on' => $monitorOn,
        'exists' => $monitorExists,
    ],
    'email' => [
        'title' => 'Email Sender',
        'blurb' => 'Drains the email outbox using Admin → Email SMTP settings.',
        'task' => $email,
        'script' => 'bin\\exception_email_sender.php',
        'on' => $emailOn,
        'exists' => $emailExists,
    ],
];
?>
            <section
                class="upload-card settings-card scheduler-panel"
                id="scheduler-root"
                data-workers-on="<?= $workersOn ? '1' : '0' ?>"
                data-installed="<?= $bothInstalled ? '1' : '0' ?>"
            >
                <div class="scheduler-progress-bar" id="scheduler-busy" hidden>
                    <div class="scheduler-progress-bar-track">
                        <span class="scheduler-progress-bar-fill is-indeterminate"></span>
                    </div>
                    <p class="scheduler-progress-bar-label" id="scheduler-busy-label">Working…</p>
                </div>

                <div class="scheduler-hero <?= $workersOn ? 'is-on' : ($anyInstalled ? 'is-paused' : 'is-off') ?>">
                    <div class="scheduler-hero-copy">
                        <div class="scheduler-hero-kicker"><?= $workersOn ? 'Workers running' : ($anyInstalled ? 'Workers installed' : 'Workers not installed') ?></div>
                        <h2 class="scheduler-hero-title"><?= e((string) $status['banner']) ?></h2>
                        <p class="scheduler-hero-text">
                            <?= e((string) (($status['hint'] ?? '') !== ''
                                ? $status['hint']
                                : ($workersOn
                                    ? 'Exception Monitor and Email Sender are enabled and will run on schedule.'
                                    : 'Install the Windows tasks, then start the workers.'))) ?>
                        </p>
                    </div>
                    <div class="scheduler-hero-meter" aria-label="Setup progress">
                        <div class="scheduler-ring" style="--pct: <?= (int) $progressPct ?>">
                            <strong><?= (int) $progressPct ?>%</strong>
                            <span>ready</span>
                        </div>
                        <ul class="scheduler-hero-stats">
                            <li><b><?= (int) $status['due_count'] ?></b> due</li>
                            <li><b><?= (int) $status['outbox_pending'] ?></b> queued</li>
                            <li><b><?= (int) $status['interval_minutes'] ?>m</b> interval</li>
                        </ul>
                    </div>
                </div>

                <div class="scheduler-checks" role="list">
                    <?php foreach ($checks as $check): ?>
                        <?php
                        $tone = !empty($check['ok'])
                            ? (!empty($check['warn']) ? 'is-warn' : 'is-ok')
                            : 'is-bad';
                        ?>
                        <div class="scheduler-check <?= $tone ?>" role="listitem">
                            <span class="scheduler-check-mark" aria-hidden="true">
                                <?= !empty($check['ok']) ? (!empty($check['warn']) ? '!' : '✓') : '✕' ?>
                            </span>
                            <div class="scheduler-check-body">
                                <div class="scheduler-check-top">
                                    <span class="scheduler-check-label"><?= e($check['label']) ?></span>
                                    <span class="scheduler-check-value"><?= e($check['value']) ?></span>
                                </div>
                                <code class="scheduler-check-detail"><?= e($check['detail']) ?></code>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="scheduler-toolbar">
                    <?php if (!$anyInstalled): ?>
                        <form method="post" class="scheduler-install-form" data-busy-label="Installing tasks…">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="scheduler_install">
                            <label>
                                <span>Interval (minutes)</span>
                                <input type="number" name="interval_minutes" min="15" max="1440" value="<?= (int) $status['interval_minutes'] ?>">
                            </label>
                            <button type="submit" class="button button-primary">Install &amp; start</button>
                        </form>
                    <?php else: ?>
                        <?php if ($workersOn): ?>
                            <form method="post" data-busy-label="Stopping workers…">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="scheduler_disable_both">
                                <button type="submit" class="button button-secondary scheduler-btn-stop">Stop both</button>
                            </form>
                        <?php elseif ($bothInstalled || $partialOn || $bothOff): ?>
                            <form method="post" data-busy-label="Starting workers…">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="scheduler_enable_both">
                                <button type="submit" class="button button-primary scheduler-btn-start">Start both</button>
                            </form>
                        <?php endif; ?>

                        <form method="post" class="scheduler-install-form" data-busy-label="Updating schedule…">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="scheduler_install">
                            <label>
                                <span>Interval</span>
                                <input type="number" name="interval_minutes" min="15" max="1440" value="<?= (int) $status['interval_minutes'] ?>">
                            </label>
                            <button type="submit" class="button ghost">Reinstall</button>
                        </form>

                        <form method="post" onsubmit="return confirm('Remove both scheduled tasks from Windows Task Scheduler?');" data-busy-label="Uninstalling…">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="scheduler_uninstall">
                            <button type="submit" class="button button-danger">Uninstall</button>
                        </form>
                    <?php endif; ?>

                    <form method="post" data-busy-label="Refreshing status…">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="scheduler_refresh">
                        <button type="submit" class="button ghost">Refresh</button>
                    </form>
                </div>

                <div class="scheduler-task-grid">
                    <?php foreach ($rows as $kind => $row): ?>
                        <?php
                        $task = $row['task'];
                        $exists = !empty($row['exists']);
                        $enabled = !empty($row['on']);
                        $stateClass = !$exists ? 'is-missing' : ($enabled ? 'is-enabled' : 'is-disabled');
                        $stateLabel = !$exists ? 'Not installed' : ($enabled ? 'Running' : 'Stopped');
                        ?>
                        <article class="scheduler-task-card <?= $stateClass ?>">
                            <header class="scheduler-task-head">
                                <div>
                                    <div class="scheduler-task-eyebrow"><?= $exists ? ($enabled ? 'Active schedule' : 'Installed · paused') : 'Needs install' ?></div>
                                    <h3><?= e($row['title']) ?></h3>
                                    <p><?= e($row['blurb']) ?></p>
                                </div>
                                <span class="scheduler-task-state"><?= e($stateLabel) ?></span>
                            </header>

                            <div class="scheduler-task-pulse" aria-hidden="true">
                                <span class="scheduler-pulse-dot"></span>
                                <span class="scheduler-pulse-track"><i></i></span>
                            </div>

                            <dl class="scheduler-task-meta">
                                <div>
                                    <dt>Task</dt>
                                    <dd><code><?= e((string) ($task['name'] ?? '')) ?></code></dd>
                                </div>
                                <div>
                                    <dt>Run as</dt>
                                    <dd>
                                        <?= e((string) (($task['run_as'] ?? '') !== '' ? $task['run_as'] : ($exists ? '—' : 'n/a'))) ?>
                                        <?php if (($task['logon_type'] ?? '') !== ''): ?>
                                            <span class="muted">(<?= e((string) $task['logon_type']) ?>)</span>
                                        <?php elseif ($exists): ?>
                                            <span class="muted">(SYSTEM preferred)</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Schedule</dt>
                                    <dd>
                                        <?php if (($task['repetition_interval'] ?? '') !== ''): ?>
                                            Every <?= e((string) $task['repetition_interval']) ?>
                                        <?php else: ?>
                                            Every <?= (int) $status['interval_minutes'] ?> min
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Last / next</dt>
                                    <dd><?= e((string) (($task['last_run'] ?? '') !== '' ? $task['last_run'] : '—')) ?>
                                        · <?= e((string) (($task['next_run'] ?? '') !== '' ? $task['next_run'] : '—')) ?></dd>
                                </div>
                            </dl>

                            <div class="scheduler-task-actions">
                                <?php if ($exists && $enabled): ?>
                                    <form method="post" data-busy-label="Stopping <?= e($row['title']) ?>…">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="scheduler_disable">
                                        <input type="hidden" name="task" value="<?= e($kind) ?>">
                                        <button type="submit" class="button button-secondary">Stop</button>
                                    </form>
                                <?php elseif ($exists): ?>
                                    <form method="post" data-busy-label="Starting <?= e($row['title']) ?>…">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="scheduler_enable">
                                        <input type="hidden" name="task" value="<?= e($kind) ?>">
                                        <button type="submit" class="button button-primary">Start</button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="button button-primary" disabled title="Install tasks first">Start</button>
                                <?php endif; ?>

                                <?php if ($exists): ?>
                                    <form method="post" data-busy-label="Running <?= e($row['title']) ?> now…">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="scheduler_run">
                                        <input type="hidden" name="task" value="<?= e($kind) ?>">
                                        <button type="submit" class="button ghost">Run once</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" data-busy-label="Running <?= e($row['title']) ?> via PHP…">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="scheduler_run_cli">
                                        <input type="hidden" name="task" value="<?= e($kind) ?>">
                                        <button type="submit" class="button ghost" title="Run PHP worker without Task Scheduler">Run CLI</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="panel-help scheduler-help">
                    If the web server cannot change Task Scheduler, run
                    <code>scripts\schedule-exception-workers.bat</code>.
                    Emails send only when <a href="email.php">Admin → Email</a> SMTP is enabled.
                </p>

                <?php if ($actionOutput !== ''): ?>
                    <details class="scheduler-output-wrap" <?= $lastAction !== '' ? 'open' : '' ?>>
                        <summary>Command output</summary>
                        <pre class="scheduler-output"><?= e($actionOutput) ?></pre>
                    </details>
                <?php endif; ?>
            </section>
            <script src="../assets/js/scheduler-admin.js?v=<?= is_file(dirname(__DIR__) . '/assets/js/scheduler-admin.js') ? filemtime(dirname(__DIR__) . '/assets/js/scheduler-admin.js') : time() ?>" defer></script>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
