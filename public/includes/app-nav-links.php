<?php

declare(strict_types=1);

use RiskAssessment\Auth;
use RiskAssessment\UserAccess;

/**
 * Standard topbar destination links shared across signed-in pages.
 * Uses Auth::publicPrefix() so admin/ and ticket-dossier/ resolve correctly.
 */
$appNavPrefix = Auth::instance()->publicPrefix();
$appNavUser = $currentUser ?? Auth::instance()->currentUser();
$appNavUser = is_array($appNavUser) ? $appNavUser : null;
$catalogNavUrl = $appNavPrefix . 'sharepoint.php?view=catalog&source=default&mode=or&per=100';
$ownersNavUrl = $appNavPrefix . 'sharepoint.php?view=owners';
$heatmapNavUrl = $appNavPrefix . 'sharepoint.php?view=heatmap';
$ticketDossierNavUrl = $appNavPrefix . 'ticket-dossier/';
?>
<?php if (UserAccess::canShowMenuDest($appNavUser, 'find')): ?>
<a
    class="button ghost home-link"
    data-menu-group="risk"
    data-menu-tone="sky"
    data-nav-dest="find"
    href="<?= e($appNavPrefix) ?>index.php#find-projects"
    title="Search and open saved risk assessments by name, vendor, owner, and more"
><span class="topbar-menu-emoji" aria-hidden="true">🔎</span>Find projects</a>
<?php endif; ?>
<?php if (UserAccess::canShowMenuDest($appNavUser, 'upload')): ?>
<a
    class="button ghost home-link"
    data-menu-group="risk"
    data-menu-tone="mint"
    data-nav-dest="upload"
    href="<?= e($appNavPrefix) ?>index.php#upload"
    title="Upload an Architecture Risk Assessment workbook (.xlsx) to generate a dashboard"
><span class="topbar-menu-emoji" aria-hidden="true">📤</span>Upload</a>
<?php endif; ?>
<?php if (UserAccess::canShowMenuDest($appNavUser, 'templates')): ?>
<a
    class="button ghost home-link"
    data-menu-group="risk"
    data-menu-tone="lavender"
    data-nav-dest="templates"
    href="<?= e($appNavPrefix) ?>templates.php"
    title="Browse and manage assessment workbook templates"
><span class="topbar-menu-emoji" aria-hidden="true">📚</span>Templates</a>
<?php endif; ?>
<?php if (UserAccess::canShowMenuDest($appNavUser, 'sharepoint')): ?>
<a
    class="button ghost home-link"
    data-menu-group="sharepoint"
    data-menu-tone="peach"
    data-nav-dest="sharepoint"
    href="<?= e($appNavPrefix) ?>sharepoint.php"
    title="Browse SharePoint folders, sync projects, and search architecture work"
><span class="topbar-menu-emoji" aria-hidden="true">📁</span>SharePoint</a>
<?php endif; ?>
<?php require __DIR__ . '/catalog-nav-link.php'; ?>
<?php require __DIR__ . '/owners-nav-link.php'; ?>
<?php require __DIR__ . '/heatmap-nav-link.php'; ?>
<?php require __DIR__ . '/ticket-dossier-nav-link.php'; ?>
