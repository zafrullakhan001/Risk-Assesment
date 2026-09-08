<?php

declare(strict_types=1);

/**
 * Ticket Dossier topbar actions — same chrome as the rest of RiskRegister.
 * Expects RiskRegister bootstrap + auth already loaded.
 *
 * Optional: set $topbarMenuExtraBefore to HTML injected after the menu opens
 * (e.g. project-page back/export links).
 */
$topbarMenuExtraBefore = $topbarMenuExtraBefore ?? '';
?>
<?php require dirname(__DIR__, 2) . '/includes/topbar-menu-start.php'; ?>
<?= $topbarMenuExtraBefore ?>
<?php require dirname(__DIR__, 2) . '/includes/app-nav-links.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/updates-nav.php'; ?>
<?php require dirname(__DIR__, 2) . '/includes/topbar-menu-end.php'; ?>
