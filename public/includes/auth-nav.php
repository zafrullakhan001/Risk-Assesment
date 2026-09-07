<?php

declare(strict_types=1);

use RiskAssessment\Auth;

$navAuth = Auth::instance();
$navUser = $navAuth->currentUser();
$navPrefix = $navAuth->publicPrefix();
$navScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$navOnHelp = str_ends_with($navScript, '/help.php');
?>
<?php if ($navUser !== null): ?>
    <a
        class="button ghost home-link<?= $navOnHelp ? ' is-active' : '' ?>"
        href="<?= e($navPrefix) ?>help.php"
        title="Help and About"
        <?= $navOnHelp ? ' aria-current="page"' : '' ?>
    >Help</a>
    <?php require __DIR__ . '/shared-access-nav.php'; ?>
    <?php if (!empty($navUser['is_admin'])): ?>
        <a class="button ghost home-link" href="<?= e($navPrefix) ?>admin/index.php" title="Users, branding, LDAP, and GitHub updates">Admin</a>
        <?php
        $notifyBrand = \RiskAssessment\Branding::current();
        $notifyIcon = $notifyBrand->faviconUrl();
        $notifyJs = dirname(__DIR__) . '/assets/js/update-notifications.js';
        ?>
        <div
            class="update-bell"
            id="update-notify-root"
            data-status-url="<?= e($navPrefix) ?>admin/update-status.php"
            data-updates-url="<?= e($navPrefix) ?>admin/updates.php"
            data-icon-url="<?= e($notifyIcon) ?>"
            data-brand-title="<?= e($notifyBrand->documentTitle()) ?>"
        >
            <button
                type="button"
                class="button ghost update-bell-btn"
                id="update-notify-btn"
                aria-label="App update notifications"
                aria-expanded="false"
                aria-haspopup="true"
                aria-controls="update-notify-panel"
                title="App update notifications"
            >
                <svg class="update-bell-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M12 22a2.2 2.2 0 0 0 2.2-2.2H9.8A2.2 2.2 0 0 0 12 22Zm6.7-6.2V11a6.7 6.7 0 0 0-5.2-6.5V3.8a1.5 1.5 0 1 0-3 0v.7A6.7 6.7 0 0 0 5.3 11v4.8L4 17.1V18h16v-.9l-1.3-1.3Z"/>
                </svg>
                <span class="update-bell-badge" id="update-notify-badge" hidden>0</span>
            </button>
            <div class="update-bell-panel" id="update-notify-panel" hidden role="dialog" aria-labelledby="update-notify-heading">
                <div class="update-bell-panel-head">
                    <h3 id="update-notify-heading">App updates</h3>
                    <button type="button" class="update-bell-close" data-update-notify-close aria-label="Close notifications">×</button>
                </div>
                <p class="update-bell-status" id="update-notify-status">Checking GitHub for updates…</p>
                <ul class="update-bell-list" id="update-notify-list" hidden></ul>
                <div class="update-bell-actions">
                    <a class="button button-primary" id="update-notify-open" href="<?= e($navPrefix) ?>admin/updates.php">Open App updates</a>
                    <button type="button" class="button ghost" id="update-notify-refresh">Check now</button>
                </div>
                <div class="update-bell-prefs">
                    <p class="update-bell-prefs-label">Alerts</p>
                    <label class="update-bell-pref">
                        <input type="checkbox" id="update-notify-toast-pref">
                        <span>Toast notifications</span>
                    </label>
                    <label class="update-bell-pref" id="update-notify-desktop-wrap">
                        <input type="checkbox" id="update-notify-desktop-pref">
                        <span id="update-notify-desktop-label">Browser notifications</span>
                    </label>
                </div>
            </div>
        </div>
        <?php if (!defined('RA_UPDATE_NOTIFY_SCRIPT')): ?>
            <?php define('RA_UPDATE_NOTIFY_SCRIPT', true); ?>
            <script src="<?= e($navPrefix) ?>assets/js/update-notifications.js?v=<?= is_file($notifyJs) ? filemtime($notifyJs) : time() ?>" defer></script>
        <?php endif; ?>
    <?php endif; ?>
    <span class="user-chip" title="<?= e((string) $navUser['email']) ?>">
        <span class="auth-badge <?= $navUser['auth_source'] === 'ldap' ? 'is-ldap' : 'is-local' ?>"><?= e((string) $navUser['auth_source']) ?></span>
        <?= e((string) ($navUser['display_name'] !== '' ? $navUser['display_name'] : $navUser['username'])) ?>
    </span>
    <form method="post" action="<?= e($navPrefix) ?>logout.php" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="button ghost">Sign out</button>
    </form>
<?php else: ?>
    <a class="button ghost home-link" href="<?= e($navPrefix) ?>login.php">Sign in</a>
<?php endif; ?>
