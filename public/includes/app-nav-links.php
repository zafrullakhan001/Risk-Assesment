<?php

declare(strict_types=1);

use RiskAssessment\AppModules;
use RiskAssessment\Auth;

/**
 * Standard topbar destination links shared across signed-in pages.
 * Uses Auth::publicPrefix() so admin/ and ticket-dossier/ resolve correctly.
 */
$appNavPrefix = Auth::instance()->publicPrefix();
$appNavUser = $currentUser ?? Auth::instance()->currentUser();
$appNavModules = AppModules::instance();
$canNavRisk = $appNavModules->canAccess(is_array($appNavUser) ? $appNavUser : null, AppModules::RISK);
$canNavSharePoint = $appNavModules->canAccess(is_array($appNavUser) ? $appNavUser : null, AppModules::SHAREPOINT);
$catalogNavUrl = $appNavPrefix . 'sharepoint.php?view=catalog&source=default&mode=or&per=100';
$ownersNavUrl = $appNavPrefix . 'sharepoint.php?view=owners';
$heatmapNavUrl = $appNavPrefix . 'sharepoint.php?view=heatmap';
$ticketDossierNavUrl = $appNavPrefix . 'ticket-dossier/';
?>
<?php if ($canNavRisk): ?>
<a
    class="button ghost home-link"
    data-menu-group="risk"
    data-menu-tone="sky"
    href="<?= e($appNavPrefix) ?>index.php#find-projects"
    title="Search and open saved risk assessments by name, vendor, owner, and more"
><span class="topbar-menu-emoji" aria-hidden="true">🔎</span>Find projects</a>
<a
    class="button ghost home-link"
    data-menu-group="risk"
    data-menu-tone="mint"
    href="<?= e($appNavPrefix) ?>index.php#upload"
    title="Upload an Architecture Risk Assessment workbook (.xlsx) to generate a dashboard"
><span class="topbar-menu-emoji" aria-hidden="true">📤</span>Upload</a>
<a
    class="button ghost home-link"
    data-menu-group="risk"
    data-menu-tone="lavender"
    href="<?= e($appNavPrefix) ?>templates.php"
    title="Browse and manage assessment workbook templates"
><span class="topbar-menu-emoji" aria-hidden="true">📚</span>Templates</a>
<?php endif; ?>
<?php if ($canNavSharePoint): ?>
<a
    class="button ghost home-link"
    data-menu-group="sharepoint"
    data-menu-tone="peach"
    href="<?= e($appNavPrefix) ?>sharepoint.php"
    title="Browse SharePoint folders, sync projects, and search architecture work"
><span class="topbar-menu-emoji" aria-hidden="true">📁</span>SharePoint</a>
<?php require __DIR__ . '/catalog-nav-link.php'; ?>
<?php require __DIR__ . '/owners-nav-link.php'; ?>
<?php require __DIR__ . '/heatmap-nav-link.php'; ?>
<?php endif; ?>
<?php require __DIR__ . '/ticket-dossier-nav-link.php'; ?>
