<?php

declare(strict_types=1);

/**
 * Shared home section tabs (Find / Upload / Templates / SharePoint).
 * Set $homeTab before requiring: find | upload | templates | sharepoint
 */
$homeTab = $homeTab ?? 'find';
$homeTabPrefix = $homeTabPrefix ?? '';
?>
<nav class="home-section-tabs<?= $homeTab === 'templates' ? ' template-section-tabs' : '' ?><?= $homeTab === 'sharepoint' ? ' sharepoint-section-tabs' : '' ?>" aria-label="Home sections">
    <a class="<?= $homeTab === 'find' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>index.php#find-projects"<?= $homeTab === 'find' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">🔎</span> Find projects
    </a>
    <a class="<?= $homeTab === 'upload' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>index.php#upload"<?= $homeTab === 'upload' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📤</span> Upload assessment
    </a>
    <a class="<?= $homeTab === 'templates' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>templates.php"<?= $homeTab === 'templates' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📚</span> Template library
    </a>
    <a class="<?= $homeTab === 'sharepoint' ? 'is-active' : '' ?>" href="<?= e($homeTabPrefix) ?>sharepoint.php"<?= $homeTab === 'sharepoint' ? ' aria-current="page"' : '' ?>>
        <span class="settings-emoji" aria-hidden="true">📁</span> SharePoint catalog
    </a>
</nav>
