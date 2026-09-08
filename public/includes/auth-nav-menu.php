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
        class="button ghost home-link topbar-menu-item<?= $navOnHelp ? ' is-active' : '' ?>"
        data-menu-tone="rose"
        href="<?= e($navPrefix) ?>help.php"
        title="Help and About"
        <?= $navOnHelp ? ' aria-current="page"' : '' ?>
    ><span class="topbar-menu-emoji" aria-hidden="true">❓</span>Help</a>
    <?php if (!empty($navUser['is_admin'])): ?>
        <a
            class="button ghost home-link topbar-menu-item"
            data-menu-tone="lilac"
            href="<?= e($navPrefix) ?>admin/index.php"
            title="Users, branding, LDAP, and GitHub updates"
        ><span class="topbar-menu-emoji" aria-hidden="true">⚙️</span>Admin</a>
    <?php endif; ?>
    <form method="post" action="<?= e($navPrefix) ?>logout.php" class="inline-form topbar-menu-item" data-menu-tone="coral">
        <?= csrf_field() ?>
        <button type="submit" class="button ghost"><span class="topbar-menu-emoji" aria-hidden="true">🚪</span>Sign out</button>
    </form>
<?php else: ?>
    <a
        class="button ghost home-link topbar-menu-item"
        data-menu-tone="mint"
        href="<?= e($navPrefix) ?>login.php"
    ><span class="topbar-menu-emoji" aria-hidden="true">🔑</span>Sign in</a>
<?php endif; ?>
