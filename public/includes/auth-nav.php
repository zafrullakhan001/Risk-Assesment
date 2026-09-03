<?php

declare(strict_types=1);

use RiskAssessment\Auth;

$navAuth = Auth::instance();
$navUser = $navAuth->currentUser();
$navPrefix = $navAuth->publicPrefix();
?>
<?php if ($navUser !== null): ?>
    <?php if (!empty($navUser['is_admin'])): ?>
        <a class="button ghost home-link" href="<?= e($navPrefix) ?>admin/index.php" title="Users, branding, LDAP, and GitHub updates">Admin</a>
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
