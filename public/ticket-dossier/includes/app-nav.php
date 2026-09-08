<?php

declare(strict_types=1);

/**
 * Ticket Dossier topbar actions — same chrome as the rest of RiskRegister.
 * Expects RiskRegister bootstrap + auth already loaded.
 *
 * Optional: set $topbarMenuExtraBefore to HTML injected after the menu opens
 * (e.g. project-page back/export links).
 */
$publicPrefix = $auth->publicPrefix();
$ticketDossierSolo = true;
$ticketDossierNavUrl = 'index.php';
$catalogNavUrl = $publicPrefix . 'sharepoint.php?view=catalog&source=default&mode=or&per=100';
$topbarMenuExtraBefore = $topbarMenuExtraBefore ?? '';
?>
<?php require dirname(__DIR__, 2) . '/includes/topbar-menu-start.php'; ?>
<?= $topbarMenuExtraBefore ?>
<a class="button ghost home-link" data-menu-group="risk" data-menu-tone="sky" href="<?= e($publicPrefix) ?>index.php#find-projects"><span class="topbar-menu-emoji" aria-hidden="true">🔎</span>Find projects</a>
<a class="button ghost home-link" data-menu-group="risk" data-menu-tone="lavender" href="<?= e($publicPrefix) ?>templates.php"><span class="topbar-menu-emoji" aria-hidden="true">📚</span>Templates</a>
<a class="button ghost home-link" data-menu-group="sharepoint" data-menu-tone="peach" href="<?= e($publicPrefix) ?>sharepoint.php"><span class="topbar-menu-emoji" aria-hidden="true">📁</span>SharePoint</a>
<?php require dirname(__DIR__, 2) . '/includes/catalog-nav-link.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/ticket-dossier-nav-link.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/updates-nav.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/topbar-menu-end.php'; ?>
