<?php

declare(strict_types=1);

/**
 * Catalog search export menu: CSV / JSON × matching / selected / full catalog.
 *
 * Optional:
 * - $exportPickerClass (string) extra class on the wrapper
 * - $exportToggleClass (string) classes for the toggle button
 * - $exportToggleLabel (string)
 */

$exportPickerClass = trim((string) ($exportPickerClass ?? ''));
$exportToggleClass = trim((string) ($exportToggleClass ?? 'button ghost sp-adv-export'));
$exportToggleLabel = (string) ($exportToggleLabel ?? '⬇️ Export');
$wrapperClass = trim('sharepoint-export-picker ' . $exportPickerClass);
?>
<div class="<?= e($wrapperClass) ?>">
    <button type="button"
            class="<?= e($exportToggleClass) ?> sharepoint-export-toggle"
            title="Download matching, selected, or all catalog projects as CSV or JSON"
            aria-expanded="false"
            aria-haspopup="true">
        <?= e($exportToggleLabel) ?>
    </button>
    <div class="sharepoint-export-menu" hidden role="menu" aria-label="Export catalog projects">
        <div class="sharepoint-export-menu-head">
            <span class="sharepoint-export-menu-title">Export</span>
            <button type="button" class="sp-columns-menu-close sharepoint-export-close" title="Close" aria-label="Close export menu">✕</button>
        </div>
        <div class="sharepoint-export-row" data-export-scope="matching">
            <span class="sharepoint-export-row-label">Matching <em class="sharepoint-export-count" data-export-count="matching">0</em></span>
            <button type="button" class="button ghost sharepoint-export-action" role="menuitem" data-export-format="csv" data-export-scope="matching" title="Download all matching projects as CSV" aria-label="Download matching projects as CSV">CSV</button>
            <button type="button" class="button ghost sharepoint-export-action" role="menuitem" data-export-format="json" data-export-scope="matching" title="Download all matching projects as JSON" aria-label="Download matching projects as JSON">JSON</button>
        </div>
        <div class="sharepoint-export-row" data-export-scope="selected">
            <span class="sharepoint-export-row-label">Selected <em class="sharepoint-export-count" data-export-count="selected">0</em></span>
            <button type="button" class="button ghost sharepoint-export-action" role="menuitem" data-export-format="csv" data-export-scope="selected" disabled title="Download the checked projects as CSV" aria-label="Download selected projects as CSV">CSV</button>
            <button type="button" class="button ghost sharepoint-export-action" role="menuitem" data-export-format="json" data-export-scope="selected" disabled title="Download the checked projects as JSON" aria-label="Download selected projects as JSON">JSON</button>
        </div>
        <div class="sharepoint-export-row" data-export-scope="full">
            <span class="sharepoint-export-row-label">Full catalog <em class="sharepoint-export-count" data-export-count="full">0</em></span>
            <button type="button" class="button ghost sharepoint-export-action" role="menuitem" data-export-format="csv" data-export-scope="full" title="Download every project in the selected catalogs as CSV" aria-label="Download full catalog as CSV">CSV</button>
            <button type="button" class="button ghost sharepoint-export-action" role="menuitem" data-export-format="json" data-export-scope="full" title="Download every project in the selected catalogs as JSON" aria-label="Download full catalog as JSON">JSON</button>
        </div>
        <p class="sharepoint-export-hint">Matching uses the current search and filters. Selected uses the row checkboxes. Full catalog exports every project in the catalogs you have checked (archived stay hidden unless Show archived is on).</p>
    </div>
</div>
