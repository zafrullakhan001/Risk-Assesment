<?php

declare(strict_types=1);

?>
<div class="sp-compare-columns-picker" id="sharepoint-list-columns-picker">
    <button type="button" class="sp-view-btn" id="sharepoint-list-columns-toggle" title="Show or hide table columns" aria-expanded="false" aria-haspopup="true" aria-controls="sharepoint-list-columns-menu">Columns</button>
    <div class="sp-compare-columns-menu" id="sharepoint-list-columns-menu" hidden role="group" aria-label="Visible columns">
        <div class="sp-compare-columns-menu-head">
            <span class="sp-compare-columns-menu-title">Columns</span>
            <button type="button" class="sp-columns-menu-close" data-columns-close title="Close" aria-label="Close columns menu">✕</button>
        </div>
        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="match" checked> Match</label>
        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="items" checked> Items</label>
        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="modified" checked> Modified</label>
        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="modified_by" checked> Modified By</label>
        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="created_by" checked> Created By</label>
        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="actions" checked> Link, QR &amp; archive</label>
    </div>
</div>
