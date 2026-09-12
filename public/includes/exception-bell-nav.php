<?php

declare(strict_types=1);

use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\FindingStatusRepository;

/**
 * Due/overdue exception bell for signed-in users.
 *
 * Expects $navUser (array) and $navPrefix (string) from auth-nav.php.
 */

if (!isset($navUser) || !is_array($navUser) || (int) ($navUser['id'] ?? 0) <= 0) {
    return;
}

$exceptionDueItems = [];
$exceptionDueCount = 0;
try {
    $exceptionBellDbConfig = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $exceptionBellPdo = Database::connection($exceptionBellDbConfig);
    $exceptionBellRepo = new FindingStatusRepository($exceptionBellPdo);
    $exceptionDueItems = $exceptionBellRepo->listDueForUser($navUser, 25);
    $exceptionDueCount = count($exceptionDueItems);
} catch (Throwable) {
    $exceptionDueItems = [];
    $exceptionDueCount = 0;
}

$exceptionBellJs = dirname(__DIR__) . '/assets/js/exception-notifications.js';
$exceptionBellPrefix = isset($navPrefix) ? (string) $navPrefix : '';
$exceptionBellCsrf = (string) ($_SESSION['csrf_token'] ?? '');
$tomorrowMin = date('Y-m-d', strtotime('+1 day'));
?>
<div
    class="exception-bell update-bell"
    id="exception-bell-root"
    data-notify-url="<?= e($exceptionBellPrefix) ?>exception-notifications.php"
    data-extend-url="<?= e($exceptionBellPrefix) ?>index.php"
    data-csrf="<?= e($exceptionBellCsrf) ?>"
    data-due-count="<?= (int) $exceptionDueCount ?>"
    data-min-extend="<?= e($tomorrowMin) ?>"
>
    <button
        type="button"
        class="button ghost update-bell-btn exception-bell-btn<?= $exceptionDueCount > 0 ? ' has-due' : '' ?>"
        id="exception-bell-btn"
        aria-label="Exception expiry reminders"
        aria-expanded="false"
        aria-haspopup="true"
        aria-controls="exception-bell-panel"
        title="Exceptions due or overdue"
    >
        <svg class="update-bell-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M12 22a2.2 2.2 0 0 0 2.2-2.2H9.8A2.2 2.2 0 0 0 12 22Zm6.7-6.2V11a6.7 6.7 0 0 0-5.2-6.5V3.8a1.5 1.5 0 1 0-3 0v.7A6.7 6.7 0 0 0 5.3 11v4.8L4 17.1V18h16v-.9l-1.3-1.3Z"/>
        </svg>
        <?php if ($exceptionDueCount > 0): ?>
            <span class="update-bell-badge exception-bell-badge" id="exception-bell-badge"><?= $exceptionDueCount > 99 ? '99+' : (string) $exceptionDueCount ?></span>
        <?php else: ?>
            <span class="update-bell-badge exception-bell-badge" id="exception-bell-badge" hidden>0</span>
        <?php endif; ?>
    </button>
    <div class="update-bell-panel exception-bell-panel" id="exception-bell-panel" hidden role="dialog" aria-labelledby="exception-bell-heading">
        <div class="update-bell-panel-head">
            <h3 id="exception-bell-heading">Exceptions to address</h3>
            <button type="button" class="update-bell-close" data-exception-bell-close aria-label="Close exception reminders">×</button>
        </div>
        <p class="update-bell-status" id="exception-bell-status">
            <?= $exceptionDueCount > 0
                ? (string) $exceptionDueCount . ' due or overdue'
                : 'No exceptions are due right now.' ?>
        </p>
        <ul class="exception-bell-list" id="exception-bell-list"<?= $exceptionDueItems === [] ? ' hidden' : '' ?>>
            <?php foreach ($exceptionDueItems as $item): ?>
                <?php
                $aid = (int) ($item['assessment_id'] ?? 0);
                $fid = (string) ($item['finding_id'] ?? '');
                $project = (string) ($item['project_name'] ?? 'Untitled project');
                $finding = (string) ($item['finding_text'] ?? $fid);
                $expires = (string) ($item['expires_at'] ?? '');
                $canEdit = !empty($item['can_edit']);
                $link = $exceptionBellPrefix . 'index.php?view=1&id=' . $aid . '&tab=actions&action_tab=exceptions#exception-tracker';
                ?>
                <li
                    class="exception-bell-item is-due"
                    data-assessment-id="<?= (int) $aid ?>"
                    data-finding-id="<?= e($fid) ?>"
                    data-can-edit="<?= $canEdit ? '1' : '0' ?>"
                >
                    <div class="exception-bell-item-head">
                        <span class="exception-bell-project"><?= e($project) ?></span>
                        <span class="exception-bell-date"><?= e($expires) ?></span>
                    </div>
                    <p class="exception-bell-finding"><?= e($finding) ?></p>
                    <div class="exception-bell-actions">
                        <a class="button button-secondary" href="<?= e($link) ?>">Open</a>
                        <?php if ($canEdit): ?>
                            <button type="button" class="button ghost exception-bell-extend-toggle">Extend</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEdit): ?>
                        <div class="exception-bell-extend-inline" hidden>
                            <input type="date" min="<?= e($tomorrowMin) ?>" aria-label="New expiry date">
                            <button type="button" class="button button-primary exception-bell-extend-save">Save</button>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="exception-bell-empty" id="exception-bell-empty"<?= $exceptionDueItems === [] ? '' : ' hidden' ?>>
            When an exception hits its expiry date, it appears here so you can close, approve, or extend it.
        </p>
    </div>
</div>
<?php if (!defined('RA_EXCEPTION_BELL_SCRIPT')): ?>
    <?php define('RA_EXCEPTION_BELL_SCRIPT', true); ?>
    <script src="<?= e($exceptionBellPrefix) ?>assets/js/exception-notifications.js?v=<?= is_file($exceptionBellJs) ? filemtime($exceptionBellJs) : time() ?>" defer></script>
<?php endif; ?>
