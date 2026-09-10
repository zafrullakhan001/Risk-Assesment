<?php

declare(strict_types=1);

use RiskAssessment\AppModules;
use RiskAssessment\Auth;

/**
 * SharePoint storage heatmap topbar shortcut.
 * Optional: set $heatmapSolo = true on the heatmap solo view for active state.
 * Optional: set $heatmapNavUrl before include (e.g. with publicPrefix from admin/ticket-dossier).
 */
$heatmapNavUser = $currentUser ?? Auth::instance()->currentUser();
if (!AppModules::instance()->canAccess(is_array($heatmapNavUser) ? $heatmapNavUser : null, AppModules::SHAREPOINT)) {
    return;
}
$heatmapSolo = $heatmapSolo ?? false;
$heatmapNavUrl = $heatmapNavUrl ?? 'sharepoint.php?view=heatmap';
?>
<a
    class="button ghost home-link<?= $heatmapSolo ? ' is-active' : '' ?>"
    data-menu-tone="rose"
    data-menu-group="sharepoint"
    href="<?= e($heatmapNavUrl) ?>"
    title="View catalog storage heatmap and drill down to large files"
    <?= $heatmapSolo ? ' aria-current="page"' : '' ?>
><span class="topbar-menu-emoji" aria-hidden="true">🗺️</span>Storage</a>
