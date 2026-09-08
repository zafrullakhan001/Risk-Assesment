<?php

declare(strict_types=1);

use RiskAssessment\Auth;

/**
 * Help link pinned at the bottom of the topbar menu (before Appearance).
 */
$navAuth = $navAuth ?? Auth::instance();
$navUser = $navUser ?? $navAuth->currentUser();
$navPrefix = $navPrefix ?? $navAuth->publicPrefix();
$navScript = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$navOnHelp = str_ends_with($navScript, '/help.php');

if ($navUser === null) {
    return;
}
?>
<div class="topbar-menu-section topbar-menu-help">
    <a
        class="button ghost home-link topbar-menu-item topbar-menu-help-link<?= $navOnHelp ? ' is-active' : '' ?>"
        data-menu-tone="rose"
        data-menu-group="help"
        href="<?= e($navPrefix) ?>help.php"
        title="Open Help &amp; About for guides on assessments, SharePoint, Ticket Dossier, and admin tools"
        <?= $navOnHelp ? ' aria-current="page"' : '' ?>
    ><span class="topbar-menu-emoji" aria-hidden="true">❓</span>Help &amp; About</a>
</div>
