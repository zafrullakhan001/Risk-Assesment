<?php

declare(strict_types=1);

/**
 * Compact color-picker trigger for a SharePoint catalog/folder card.
 * Expects $srcKey and $srcTitle.
 */
$srcKey = (string) ($srcKey ?? '');
$srcTitle = (string) ($srcTitle ?? 'catalog');
if ($srcKey === '') {
    return;
}
?>
<button
    type="button"
    class="sharepoint-card-color-btn"
    data-source-key="<?= e($srcKey) ?>"
    title="Background and text colors for <?= e($srcTitle) ?>"
    aria-label="Choose background and text colors for <?= e($srcTitle) ?>"
    aria-haspopup="dialog"
    aria-expanded="false"
></button>
