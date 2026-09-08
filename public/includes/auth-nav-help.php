<?php

declare(strict_types=1);

use RiskAssessment\Auth;

/**
 * Compact Help control for the topbar menu header (and full bar mode).
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
<a
    class="topbar-menu-help-icon<?= $navOnHelp ? ' is-active' : '' ?>"
    href="<?= e($navPrefix) ?>help.php"
    title="Open Help &amp; About for guides on assessments, SharePoint, Ticket Dossier, and admin tools"
    aria-label="Help and About"
    <?= $navOnHelp ? ' aria-current="page"' : '' ?>
>?</a>
