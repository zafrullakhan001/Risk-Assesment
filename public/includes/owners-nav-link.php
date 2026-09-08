<?php

declare(strict_types=1);

/**
 * SharePoint Project owners topbar shortcut.
 * Optional: set $ownerSolo = true on the SharePoint owners-solo view for active state.
 * Optional: set $ownersNavUrl before include (e.g. with publicPrefix from admin/ticket-dossier).
 */
$ownerSolo = $ownerSolo ?? false;
$ownersNavUrl = $ownersNavUrl ?? 'sharepoint.php?view=owners';
?>
<a
    class="button ghost home-link<?= $ownerSolo ? ' is-active' : '' ?>"
    data-menu-tone="sky"
    data-menu-group="sharepoint"
    href="<?= e($ownersNavUrl) ?>"
    title="View SharePoint project owners and ownership cards"
    <?= $ownerSolo ? ' aria-current="page"' : '' ?>
><span class="topbar-menu-emoji" aria-hidden="true">👤</span>Owners</a>
