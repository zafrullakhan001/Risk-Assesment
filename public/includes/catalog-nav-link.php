<?php

declare(strict_types=1);

/**
 * Dedicated Catalog topbar shortcut (relative URL — host/path independent).
 * Optional: set $catalogSolo = true on the SharePoint catalog-solo view for active state.
 * Optional: set $catalogNavUrl before include (e.g. with publicPrefix from ticket-dossier).
 */
$catalogSolo = $catalogSolo ?? false;
$catalogNavUrl = $catalogNavUrl ?? 'sharepoint.php?view=catalog&source=default&mode=or&per=100';
?>
<a
    class="button ghost home-link<?= $catalogSolo ? ' is-active' : '' ?>"
    data-menu-tone="aqua"
    data-menu-group="sharepoint"
    href="<?= e($catalogNavUrl) ?>"
    title="Open catalogs (default source, OR mode, 100 per page)"
    <?= $catalogSolo ? ' aria-current="page"' : '' ?>
><span class="topbar-menu-emoji" aria-hidden="true">🔎</span>catalogs</a>
