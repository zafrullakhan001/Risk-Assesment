<?php

declare(strict_types=1);

/**
 * Dedicated Catalog topbar shortcut (relative URL — host/path independent).
 * Optional: set $catalogSolo = true on the SharePoint catalog-solo view for active state.
 */
$catalogSolo = $catalogSolo ?? false;
$catalogNavUrl = 'sharepoint.php?view=catalog&source=default&mode=or&per=100';
?>
<a
    class="button ghost home-link<?= $catalogSolo ? ' is-active' : '' ?>"
    href="<?= e($catalogNavUrl) ?>"
    title="Open SharePoint catalog (default source, OR mode, 100 per page)"
    <?= $catalogSolo ? ' aria-current="page"' : '' ?>
>🔎 Catalog</a>
