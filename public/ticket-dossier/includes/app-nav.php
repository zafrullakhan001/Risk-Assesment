<?php

declare(strict_types=1);

/**
 * Shared topbar actions for Ticket Dossier pages (blends with RiskRegister nav).
 * Expects RiskRegister bootstrap + auth already loaded.
 */
$ticketDossierSolo = true;
$ticketDossierNavUrl = 'index.php';
$catalogNavUrl = '../sharepoint.php?view=catalog&source=default&mode=or&per=100';
?>
<a class="button ghost home-link" href="../index.php#find-projects">🔎 Find projects</a>
<a class="button ghost home-link" href="../templates.php">📚 Templates</a>
<a class="button ghost home-link" href="../sharepoint.php">📁 SharePoint</a>
<a
    class="button ghost home-link"
    href="<?= e($catalogNavUrl) ?>"
    title="Open SharePoint catalog (default source, OR mode, 100 per page)"
>🔎 Catalog</a>
<?php require dirname(__DIR__, 2) . '/includes/ticket-dossier-nav-link.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/updates-nav.php'; ?>
<?php require __DIR__ . '/theme-controls.php'; ?>
