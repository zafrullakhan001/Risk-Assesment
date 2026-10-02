<?php

declare(strict_types=1);

use RiskAssessment\Auth;
use RiskAssessment\UserAccess;

/**
 * Shared home section tabs (Find / Upload / Templates / SharePoint / Storage).
 * Set $homeTab before requiring: find | upload | templates | sharepoint | storage
 */
$homeTab = $homeTab ?? 'find';
$homeTabPrefix = $homeTabPrefix ?? '';
$homeTabUser = $currentUser ?? Auth::instance()->currentUser();
$homeTabUser = is_array($homeTabUser) ? $homeTabUser : null;
$homeTabShow = [];
foreach (['find', 'upload', 'templates', 'sharepoint', 'storage'] as $homeTabDest) {
    $homeTabShow[$homeTabDest] = UserAccess::canShowMenuDest($homeTabUser, $homeTabDest);
}
if (!in_array(true, $homeTabShow, true)) {
    return;
}
?>
<nav class="home-section-tabs<?= $homeTab === 'templates' ? ' template-section-tabs' : '' ?><?= $homeTab === 'sharepoint' ? ' sharepoint-section-tabs' : '' ?>" aria-label="Home sections">
    <?php if ($homeTabShow['find']): ?>
    <a class="<?= $homeTab === 'find' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>index.php#find-projects"<?= $homeTab === 'find' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">🔎</span> Find projects
    </a>
    <?php endif; ?>
    <?php if ($homeTabShow['upload']): ?>
    <a class="<?= $homeTab === 'upload' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>index.php#upload"<?= $homeTab === 'upload' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📤</span> Upload assessment
    </a>
    <?php endif; ?>
    <?php if ($homeTabShow['templates']): ?>
    <a class="<?= $homeTab === 'templates' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>templates.php"<?= $homeTab === 'templates' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📚</span> Template library
    </a>
    <?php endif; ?>
    <?php if ($homeTabShow['sharepoint']): ?>
    <a class="<?= $homeTab === 'sharepoint' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>sharepoint.php"<?= $homeTab === 'sharepoint' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📁</span> SharePoint catalog
    </a>
    <?php endif; ?>
    <?php if ($homeTabShow['storage']): ?>
    <a class="<?= $homeTab === 'storage' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>sharepoint.php?view=heatmap"<?= $homeTab === 'storage' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">🗺️</span> Storage heatmap
    </a>
    <?php endif; ?>
</nav>
