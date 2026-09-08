<?php

declare(strict_types=1);

use RiskAssessment\Auth;

/**
 * Top-of-menu account actions (Admin + Sign out, or Sign in).
 */
$navAuth = Auth::instance();
$navUser = $navAuth->currentUser();
$navPrefix = $navAuth->publicPrefix();
?>
<?php if ($navUser !== null): ?>
    <div class="topbar-menu-section topbar-menu-account">
        <div class="topbar-menu-account-grid">
            <?php if (!empty($navUser['is_admin'])): ?>
                <a
                    class="topbar-menu-account-card"
                    data-menu-tone="lilac"
                    href="<?= e($navPrefix) ?>admin/index.php"
                    title="Open Admin to manage users, branding, authentication, email, SQLite, and app updates"
                >
                    <span class="topbar-menu-account-art" aria-hidden="true">
                        <svg viewBox="0 0 48 48" width="36" height="36" focusable="false">
                            <rect x="4" y="4" width="40" height="40" rx="14" fill="currentColor" opacity="0.14"/>
                            <path fill="currentColor" d="M24 16.2a2.3 2.3 0 0 1 2.3 2.3v.7l2 .9 1.4-1.4a2.3 2.3 0 0 1 3.3 3.3l-1.4 1.4.9 2h.7a2.3 2.3 0 1 1 0 4.6h-.7l-.9 2 1.4 1.4a2.3 2.3 0 1 1-3.3 3.3l-1.4-1.4-2 .9v.7a2.3 2.3 0 1 1-4.6 0v-.7l-2-.9-1.4 1.4a2.3 2.3 0 1 1-3.3-3.3l1.4-1.4-.9-2h-.7a2.3 2.3 0 1 1 0-4.6h.7l.9-2-1.4-1.4a2.3 2.3 0 1 1 3.3-3.3l1.4 1.4 2-.9v-.7A2.3 2.3 0 0 1 24 16.2Zm0 8.3a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z"/>
                        </svg>
                    </span>
                    <span class="topbar-menu-account-copy">
                        <span class="topbar-menu-account-title">Admin</span>
                        <span class="topbar-menu-account-sub">Users &amp; settings</span>
                    </span>
                </a>
            <?php endif; ?>
            <form method="post" action="<?= e($navPrefix) ?>logout.php" class="topbar-menu-account-form">
                <?= csrf_field() ?>
                <button type="submit" class="topbar-menu-account-card is-signout" data-menu-tone="coral" title="Sign out and end your session on this device">
                    <span class="topbar-menu-account-art" aria-hidden="true">
                        <svg viewBox="0 0 48 48" width="36" height="36" focusable="false">
                            <rect x="4" y="4" width="40" height="40" rx="14" fill="currentColor" opacity="0.14"/>
                            <path fill="currentColor" d="M22 14h-6a4 4 0 0 0-4 4v12a4 4 0 0 0 4 4h6v-3h-6V18h6v-4Zm4.6 3.4 7 6.6-7 6.6-2-1.9 3.5-3.3H20v-2.8h8.1l-3.5-3.3 2-1.9Z"/>
                        </svg>
                    </span>
                    <span class="topbar-menu-account-copy">
                        <span class="topbar-menu-account-title">Sign out</span>
                        <span class="topbar-menu-account-sub">End session</span>
                    </span>
                </button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="topbar-menu-section topbar-menu-account">
        <div class="topbar-menu-account-grid">
            <a
                class="topbar-menu-account-card"
                data-menu-tone="mint"
                href="<?= e($navPrefix) ?>login.php"
                title="Sign in to access risk assessments, SharePoint, catalogs, and Ticket Dossier"
            >
                <span class="topbar-menu-account-art" aria-hidden="true">
                    <svg viewBox="0 0 48 48" width="36" height="36" focusable="false">
                        <rect x="4" y="4" width="40" height="40" rx="14" fill="currentColor" opacity="0.14"/>
                        <path fill="currentColor" d="M24 14a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 12c5.5 0 10 2.7 10 6v2H14v-2c0-3.3 4.5-6 10-6Zm10.5-9.8 2.1 2.1-7.4 7.4-2.1-2.1 7.4-7.4Z"/>
                    </svg>
                </span>
                <span class="topbar-menu-account-copy">
                    <span class="topbar-menu-account-title">Sign in</span>
                    <span class="topbar-menu-account-sub">Access your projects</span>
                </span>
            </a>
        </div>
    </div>
<?php endif; ?>
