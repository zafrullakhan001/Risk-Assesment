<?php

declare(strict_types=1);

use RiskAssessment\AppModules;
use RiskAssessment\Auth;

$adminTab = $adminTab ?? 'home';
$navUser = $currentUser ?? Auth::instance()->currentUser();
$navApps = AppModules::instance();
$canSeeSharePointJump = $navApps->canAccess(is_array($navUser) ? $navUser : null, AppModules::SHAREPOINT);
?>
<nav class="admin-tabs" aria-label="Admin sections">
    <a class="<?= $adminTab === 'home' ? 'is-active' : '' ?>" href="index.php">Overview</a>
    <a class="<?= $adminTab === 'users' ? 'is-active' : '' ?>" href="users.php">Users</a>
    <a class="<?= $adminTab === 'apps' ? 'is-active' : '' ?>" href="apps.php">Apps</a>
    <a class="<?= $adminTab === 'authentication' ? 'is-active' : '' ?>" href="authentication.php">Authentication</a>
    <a class="<?= $adminTab === 'branding' ? 'is-active' : '' ?>" href="branding.php">Branding</a>
    <a class="<?= $adminTab === 'email' ? 'is-active' : '' ?>" href="email.php">✉️ Email</a>
    <a class="<?= $adminTab === 'mcp' ? 'is-active' : '' ?>" href="mcp.php">🤖 MCP / AI</a>
    <a class="<?= $adminTab === 'maintenance' ? 'is-active' : '' ?>" href="maintenance.php">🗄️ SQLite</a>
    <a class="<?= $adminTab === 'updates' ? 'is-active' : '' ?>" href="updates.php" data-update-nav="updates">App updates</a>
    <?php if ($canSeeSharePointJump): ?>
        <a href="../sharepoint.php#sharepoint-admin">📁 SharePoint</a>
    <?php endif; ?>
</nav>
