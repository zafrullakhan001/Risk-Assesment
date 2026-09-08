<?php

declare(strict_types=1);

/**
 * Ticket Dossier topbar actions — same chrome as the rest of RiskRegister.
 * Expects RiskRegister bootstrap + auth already loaded.
 */
$publicPrefix = $auth->publicPrefix();
$ticketDossierSolo = true;
$ticketDossierNavUrl = 'index.php';
$catalogNavUrl = $publicPrefix . 'sharepoint.php?view=catalog&source=default&mode=or&per=100';
?>
<a class="button ghost home-link" href="<?= e($publicPrefix) ?>index.php#find-projects">🔎 Find projects</a>
<a class="button ghost home-link" href="<?= e($publicPrefix) ?>templates.php">📚 Templates</a>
<a class="button ghost home-link" href="<?= e($publicPrefix) ?>sharepoint.php">📁 SharePoint</a>
<?php require dirname(__DIR__, 2) . '/includes/catalog-nav-link.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/ticket-dossier-nav-link.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/updates-nav.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/theme-controls.php'; ?>
