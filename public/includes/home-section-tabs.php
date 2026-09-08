<?php

declare(strict_types=1);

use RiskAssessment\AppModules;
use RiskAssessment\Auth;

/**
 * Shared home section tabs (Find / Upload / Templates / SharePoint).
 * Set $homeTab before requiring: find | upload | templates | sharepoint
 */
$homeTab = $homeTab ?? 'find';
$homeTabPrefix = $homeTabPrefix ?? '';
$homeTabUser = $currentUser ?? Auth::instance()->currentUser();
$homeTabModules = AppModules::instance();
$showRiskTabs = $homeTabModules->canAccess(is_array($homeTabUser) ? $homeTabUser : null, AppModules::RISK);
$showSharePointTab = $homeTabModules->canAccess(is_array($homeTabUser) ? $homeTabUser : null, AppModules::SHAREPOINT);
if (!$showRiskTabs && !$showSharePointTab) {
    return;
}
?>
<nav class="home-section-tabs<?= $homeTab === 'templates' ? ' template-section-tabs' : '' ?><?= $homeTab === 'sharepoint' ? ' sharepoint-section-tabs' : '' ?>" aria-label="Home sections">
    <?php if ($showRiskTabs): ?>
    <a class="<?= $homeTab === 'find' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>index.php#find-projects"<?= $homeTab === 'find' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">🔎</span> Find projects
    </a>
    <a class="<?= $homeTab === 'upload' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>index.php#upload"<?= $homeTab === 'upload' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📤</span> Upload assessment
    </a>
    <a class="<?= $homeTab === 'templates' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>templates.php"<?= $homeTab === 'templates' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📚</span> Template library
    </a>
    <?php endif; ?>
    <?php if ($showSharePointTab): ?>
    <a class="<?= $homeTab === 'sharepoint' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>sharepoint.php"<?= $homeTab === 'sharepoint' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📁</span> SharePoint catalog
    </a>
    <?php endif; ?>
</nav>
