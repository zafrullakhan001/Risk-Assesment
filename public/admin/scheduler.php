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
            $flash = $result['ok'] ? 'Task enabled.' : 'Could not enable task.';
        } elseif ($action === 'scheduler_disable') {
            $kind = (string) ($_POST['task'] ?? 'monitor');
            $result = $scheduler->setEnabled($kind, false);
            $flash = $result['ok'] ? 'Task disabled.' : 'Could not disable task.';
        } elseif ($action === 'scheduler_enable_both') {
            $result = $scheduler->enableBoth();
            $flash = $result['ok'] ? 'Both workers enabled.' : 'Could not enable both tasks.';
        } elseif ($action === 'scheduler_disable_both') {
            $result = $scheduler->disableBoth();
            $flash = $result['ok'] ? 'Both workers disabled.' : 'Could not disable both tasks.';
        } elseif ($action === 'scheduler_run') {
            $kind = (string) ($_POST['task'] ?? 'monitor');
            $result = $scheduler->runNow($kind);
            if (!$result['ok']) {
                $result = $scheduler->runWorkerCli($kind);
                $flash = $result['ok']
                    ? 'Worker ran via PHP (Task Scheduler run was denied).'
                    : 'Run failed.';
            } else {
                $flash = 'Task started.';
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

$adminTitle = 'Task Scheduler';
$adminTab = 'scheduler';
$adminEyebrow = 'Background workers';
$adminHeading = 'Exception <em>Task Scheduler</em>';
$adminIntro = 'Install Windows Task Scheduler jobs that monitor due exceptions and send queued reminder emails (LinkNest-style).';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card settings-card scheduler-panel">
                <div class="scheduler-banner <?= !empty($status['workers_on']) ? 'is-on' : 'is-off' ?>">
                    <strong><?= e((string) $status['banner']) ?></strong>
                    <?php if (($status['hint'] ?? '') !== ''): ?>
                        <p><?= e((string) $status['hint']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="scheduler-health">
                    <div class="scheduler-health-card <?= !empty($status['php']['ok']) ? 'is-ok' : 'is-bad' ?>">
                        <div class="scheduler-health-label">PHP ready</div>
                        <div class="scheduler-health-value"><?= !empty($status['php']['ok']) ? 'Ready (XAMPP)' : 'Missing' ?></div>
                        <code class="scheduler-health-path"><?= e((string) ($status['php']['path'] ?: 'php.exe not found')) ?></code>
                    </div>
                    <div class="scheduler-health-card <?= !empty($status['sqlite']['ok']) ? 'is-ok' : 'is-bad' ?>">
                        <div class="scheduler-health-label">SQLite database</div>
                        <div class="scheduler-health-value"><?= !empty($status['sqlite']['ok']) ? 'Found' : 'Missing' ?></div>
                        <code class="scheduler-health-path"><?= e((string) ($status['sqlite']['path'] ?: '—')) ?></code>
                    </div>
                    <div class="scheduler-health-card <?= !empty($status['smtp']['enabled']) ? 'is-ok' : 'is-warn' ?>">
                        <div class="scheduler-health-label">SMTP</div>
                        <div class="scheduler-health-value"><?= !empty($status['smtp']['enabled']) ? 'Enabled' : 'Disabled' ?></div>
                        <code class="scheduler-health-path">Due: <?= (int) $status['due_count'] ?> · Outbox: <?= (int) $status['outbox_pending'] ?></code>
                    </div>
                </div>

                <div class="scheduler-task-grid">
                    <?php
                    $rows = [
                        'monitor' => [
                            'title' => 'Exception Monitor',
                            'blurb' => 'Detect due/overdue exceptions, create in-app notices, queue emails.',
                            'task' => $monitor,
                            'script' => 'bin\\exception_monitor.php',
                        ],
                        'email' => [
                            'title' => 'Email Sender',
                            'blurb' => 'Drain email_outbox via Admin → Email SMTP settings.',
                            'task' => $email,
                            'script' => 'bin\\exception_email_sender.php',
                        ],
                    ];
                    foreach ($rows as $kind => $row):
                        $task = $row['task'];
                        $exists = !empty($task['exists']);
                        $enabled = !empty($task['enabled']);
                        $stateLabel = !$exists ? 'NOT INSTALLED' : ($enabled ? 'ENABLED' : 'DISABLED');
                        ?>
                        <article class="scheduler-task-card <?= $exists ? ($enabled ? 'is-enabled' : 'is-disabled') : 'is-missing' ?>">
                            <header class="scheduler-task-head">
                                <div>
                                    <h2><?= e($row['title']) ?></h2>
                                    <p><?= e($row['blurb']) ?></p>
                                </div>
                                <span class="scheduler-task-state"><?= e($stateLabel) ?></span>
                            </header>
                            <dl class="scheduler-task-meta">
                                <div>
                                    <dt>Task name</dt>
                                    <dd><code><?= e((string) ($task['name'] ?? '')) ?></code></dd>
                                </div>
                                <div>
                                    <dt>Run as</dt>
                                    <dd><?= e((string) (($task['run_as'] ?? '') !== '' ? $task['run_as'] : ($exists ? '—' : 'n/a'))) ?>
                                        <?php if (($task['logon_type'] ?? '') !== ''): ?>
                                            <span class="muted">(<?= e((string) $task['logon_type']) ?>)</span>
                                        <?php elseif ($exists): ?>
                                            <span class="muted">(no logon required if SYSTEM)</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Schedule</dt>
                                    <dd>
                                        <?php if (($task['repetition_interval'] ?? '') !== ''): ?>
                                            Every <?= e((string) $task['repetition_interval']) ?>
                                        <?php else: ?>
                                            Every <?= (int) $status['interval_minutes'] ?> minutes
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Last / next</dt>
                                    <dd><?= e((string) (($task['last_run'] ?? '') !== '' ? $task['last_run'] : '—')) ?>
                                        · <?= e((string) (($task['next_run'] ?? '') !== '' ? $task['next_run'] : '—')) ?></dd>
                                </div>
                                <div>
                                    <dt>Script</dt>
                                    <dd><code><?= e($row['script']) ?></code></dd>
                                </div>
                            </dl>
                            <div class="scheduler-task-actions">
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="scheduler_enable">
                                    <input type="hidden" name="task" value="<?= e($kind) ?>">
                                    <button type="submit" class="button button-primary" <?= $exists && !$enabled ? '' : 'disabled' ?>>Enable</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="scheduler_disable">
                                    <input type="hidden" name="task" value="<?= e($kind) ?>">
                                    <button type="submit" class="button button-secondary" <?= $exists && $enabled ? '' : 'disabled' ?>>Disable</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="scheduler_run">
                                    <input type="hidden" name="task" value="<?= e($kind) ?>">
                                    <button type="submit" class="button ghost" <?= $exists ? '' : 'disabled' ?>>Run now</button>
                                </form>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="scheduler_run_cli">
                                    <input type="hidden" name="task" value="<?= e($kind) ?>">
                                    <button type="submit" class="button ghost" title="Run PHP worker in this request">Run CLI</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="scheduler-global-actions">
                    <form method="post" class="scheduler-install-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="scheduler_install">
                        <label>
                            <span>Interval (minutes)</span>
                            <input type="number" name="interval_minutes" min="15" max="1440" value="<?= (int) $status['interval_minutes'] ?>">
                        </label>
                        <button type="submit" class="button button-primary">Install tasks</button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="scheduler_enable_both">
                        <button type="submit" class="button button-primary" title="Enable Exception Monitor and Email Sender">Enable both</button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="scheduler_disable_both">
                        <button type="submit" class="button button-secondary" title="Disable Exception Monitor and Email Sender">Disable both</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Remove both scheduled tasks from Windows Task Scheduler?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="scheduler_uninstall">
                        <button type="submit" class="button button-danger">Uninstall tasks</button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="scheduler_refresh">
                        <button type="submit" class="button ghost">Refresh</button>
                    </form>
                </div>

                <p class="panel-help scheduler-help">
                    Prefer running <code>scripts\schedule-exception-workers.bat</code> if the web server account cannot change Task Scheduler.
                    Emails send only when <a href="email.php">Admin → Email</a> SMTP is enabled.
                </p>

                <?php if ($actionOutput !== ''): ?>
                    <pre class="scheduler-output"><?= e($actionOutput) ?></pre>
                <?php endif; ?>
            </section>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
