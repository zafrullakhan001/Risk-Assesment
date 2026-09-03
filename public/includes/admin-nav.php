<?php

declare(strict_types=1);

$adminTab = $adminTab ?? 'home';
?>
<nav class="admin-tabs" aria-label="Admin sections">
    <a class="<?= $adminTab === 'home' ? 'is-active' : '' ?>" href="index.php">Overview</a>
    <a class="<?= $adminTab === 'users' ? 'is-active' : '' ?>" href="users.php">Users</a>
    <a class="<?= $adminTab === 'authentication' ? 'is-active' : '' ?>" href="authentication.php">Authentication</a>
    <a class="<?= $adminTab === 'updates' ? 'is-active' : '' ?>" href="updates.php">App updates</a>
</nav>
