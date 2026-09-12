<?php

declare(strict_types=1);

use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\AssessmentAccessRepository;
use RiskAssessment\Repositories\UserNotificationRepository;

/**
 * Shared-access inbox + access notifications for signed-in users.
 *
 * Expects $navUser (array) and $navPrefix (string) from auth-nav.php.
 */

if (!isset($navUser) || !is_array($navUser) || (int) ($navUser['id'] ?? 0) <= 0) {
    return;
}

$sharedAccessUserId = (int) $navUser['id'];
$sharedAccessProjects = [];
$accessNotices = [];
$accessUnreadCount = 0;
try {
    $sharedAccessDbConfig = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $sharedAccessPdo = Database::connection($sharedAccessDbConfig);
    $sharedAccessRepo = new AssessmentAccessRepository($sharedAccessPdo);
    $sharedAccessProjects = $sharedAccessRepo->listSharedWithUser($sharedAccessUserId);
    $notificationRepo = new UserNotificationRepository($sharedAccessPdo);
    $accessNotices = $notificationRepo->listRecent($sharedAccessUserId, 20);
    $accessUnreadCount = $notificationRepo->countUnread($sharedAccessUserId);
} catch (Throwable) {
    $sharedAccessProjects = [];
    $accessNotices = [];
    $accessUnreadCount = 0;
}

$sharedAccessCount = count($sharedAccessProjects);
// Badge is unread notices only — shared project count must not keep the number stuck.
$badgeCount = $accessUnreadCount;
$hasActivity = $badgeCount > 0;
$sharedAccessJs = dirname(__DIR__) . '/assets/js/shared-access.js';
$accessNotifyJs = dirname(__DIR__) . '/assets/js/access-notifications.js';
$sharedAccessPrefix = isset($navPrefix) ? (string) $navPrefix : '';
$accessCsrf = (string) ($_SESSION['csrf_token'] ?? '');
?>
<div
    class="shared-access update-bell"
    id="shared-access-root"
    data-notify-url="<?= e($sharedAccessPrefix) ?>access-notifications.php"
    data-csrf="<?= e($accessCsrf) ?>"
    data-unread-count="<?= (int) $accessUnreadCount ?>"
>
    <button
        type="button"
        class="button ghost update-bell-btn shared-access-btn<?= $hasActivity ? ' has-shared' : '' ?>"
        id="shared-access-btn"
        aria-label="Shared project access and notices"
        aria-expanded="false"
        aria-haspopup="true"
        aria-controls="shared-access-panel"
        title="Shared access and notices"
    >
        <svg class="update-bell-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.94 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
        </svg>
        <?php if ($badgeCount > 0): ?>
            <span class="update-bell-badge shared-access-badge" id="shared-access-badge"><?= $badgeCount > 99 ? '99+' : (string) $badgeCount ?></span>
        <?php else: ?>
            <span class="update-bell-badge shared-access-badge" id="shared-access-badge" hidden>0</span>
        <?php endif; ?>
    </button>
    <div class="update-bell-panel shared-access-panel" id="shared-access-panel" hidden role="dialog" aria-labelledby="shared-access-heading">
        <div class="update-bell-panel-head">
            <h3 id="shared-access-heading">Access &amp; shares</h3>
            <button type="button" class="update-bell-close" data-shared-access-close aria-label="Close shared access">×</button>
        </div>

        <div class="shared-access-section" id="shared-access-notices-section"<?= $accessNotices === [] ? ' hidden' : '' ?>>
            <div class="shared-access-section-head">
                <h4>Recent notices</h4>
                <button
                    type="button"
                    class="button ghost shared-access-ack-btn"
                    id="shared-access-ack"
                    <?= $accessUnreadCount > 0 ? '' : ' hidden' ?>
                >Acknowledge</button>
            </div>
            <ul class="update-bell-list shared-access-notices" id="shared-access-notices">
                <?php foreach ($accessNotices as $notice): ?>
                    <?php
                    $noticeId = (int) ($notice['id'] ?? 0);
                    $noticeTitle = (string) ($notice['title'] ?? 'Notice');
                    $noticeBody = (string) ($notice['body'] ?? '');
                    $noticeLink = trim((string) ($notice['link_url'] ?? ''));
                    $noticeUnread = !empty($notice['is_unread']);
                    if ($noticeLink !== '' && !preg_match('#^(https?:)?//#i', $noticeLink) && !str_starts_with($noticeLink, '/')) {
                        $noticeHref = $sharedAccessPrefix . ltrim($noticeLink, '/');
                    } elseif ($noticeLink !== '') {
                        $noticeHref = $noticeLink;
                    } else {
                        $noticeHref = $sharedAccessPrefix . 'index.php#find-projects';
                    }
                    ?>
                    <li class="shared-access-notice<?= $noticeUnread ? ' is-unread' : '' ?>" data-notice-id="<?= $noticeId ?>">
                        <a class="shared-access-notice-link" href="<?= e($noticeHref) ?>">
                            <span class="shared-access-notice-title">
                                <?= e($noticeTitle) ?>
                                <?php if ($noticeUnread): ?>
                                    <span class="shared-access-new-chip">New</span>
                                <?php endif; ?>
                            </span>
                            <?php if ($noticeBody !== ''): ?>
                                <span class="shared-access-notice-body"><?= e($noticeBody) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <p class="update-bell-status<?= $sharedAccessCount > 0 ? ' is-ready' : '' ?>" id="shared-access-status">
            <?php if ($sharedAccessCount === 0): ?>
                No one has granted you edit access yet.
            <?php elseif ($sharedAccessCount === 1): ?>
                1 project others shared with you for editing.
            <?php else: ?>
                <?= (int) $sharedAccessCount ?> projects others shared with you for editing.
            <?php endif; ?>
        </p>
        <div class="shared-access-section-head shared-access-projects-head">
            <h4>Projects shared with you</h4>
        </div>
        <div class="shared-access-search-wrap"<?= $sharedAccessCount === 0 ? ' hidden' : '' ?>>
            <label class="visually-hidden" for="shared-access-search">Search shared projects</label>
            <input
                type="search"
                id="shared-access-search"
                class="shared-access-search"
                placeholder="Search by project or owner…"
                autocomplete="off"
                <?= $sharedAccessCount === 0 ? 'disabled' : '' ?>
            >
        </div>
        <ul class="update-bell-list shared-access-list" id="shared-access-list"<?= $sharedAccessCount === 0 ? ' hidden' : '' ?>>
            <?php foreach ($sharedAccessProjects as $sharedProject): ?>
                <?php
                $sharedId = (int) ($sharedProject['id'] ?? 0);
                $sharedName = (string) ($sharedProject['solution_name'] ?? 'Untitled project');
                $sharedOwner = (string) ($sharedProject['owner_label'] ?? 'Unknown owner');
                $sharedGrantedBy = (string) ($sharedProject['granted_by_label'] ?? $sharedOwner);
                $sharedVendor = (string) ($sharedProject['vendor'] ?? '');
                $sharedLocked = ((int) ($sharedProject['is_locked'] ?? 0)) === 1;
                $searchBlob = mb_strtolower(trim($sharedName . ' ' . $sharedOwner . ' ' . $sharedGrantedBy . ' ' . $sharedVendor . ' #' . $sharedId));
                ?>
                <li
                    class="shared-access-item"
                    data-search="<?= e($searchBlob) ?>"
                    data-project="<?= e(mb_strtolower($sharedName)) ?>"
                    data-owner="<?= e(mb_strtolower($sharedOwner)) ?>"
                >
                    <a class="shared-access-link" href="<?= e($sharedAccessPrefix) ?>index.php?view=1&amp;id=<?= $sharedId ?>">
                        <span class="shared-access-item-title">
                            <?= e($sharedName) ?>
                            <?php if ($sharedLocked): ?>
                                <span class="project-lock-badge" title="Locked project">🔒</span>
                            <?php endif; ?>
                        </span>
                        <span class="shared-access-item-meta">
                            <span class="shared-access-item-owner">Owner · <?= e($sharedOwner) ?></span>
                            <span class="shared-access-item-granted">Access from · <?= e($sharedGrantedBy) ?></span>
                            <?php if ($sharedVendor !== ''): ?>
                                <span class="shared-access-item-vendor"><?= e($sharedVendor) ?></span>
                            <?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="shared-access-empty" id="shared-access-empty" hidden>No shared projects match that search.</p>
        <div class="update-bell-actions">
            <a class="button ghost" href="<?= e($sharedAccessPrefix) ?>transfer-ownership.php">Transfer my projects</a>
            <a class="button ghost" href="<?= e($sharedAccessPrefix) ?>index.php#find-projects">Find all projects</a>
        </div>
    </div>
</div>
<?php if (!defined('RA_SHARED_ACCESS_SCRIPT')): ?>
    <?php define('RA_SHARED_ACCESS_SCRIPT', true); ?>
    <script src="<?= e($sharedAccessPrefix) ?>assets/js/shared-access.js?v=<?= is_file($sharedAccessJs) ? filemtime($sharedAccessJs) : time() ?>" defer></script>
    <script src="<?= e($sharedAccessPrefix) ?>assets/js/access-notifications.js?v=<?= is_file($accessNotifyJs) ? filemtime($accessNotifyJs) : time() ?>" defer></script>
<?php endif; ?>
