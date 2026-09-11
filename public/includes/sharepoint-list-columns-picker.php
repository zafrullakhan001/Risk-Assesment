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
        <div class="sp-compare-col-row" data-col-row="match">
            <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="match" checked> Match</label>
            <div class="sp-col-order-btns">
                <button type="button" class="sp-col-order-btn" data-col-move="up" data-col="match" title="Move up" aria-label="Move Match up">▲</button>
                <button type="button" class="sp-col-order-btn" data-col-move="down" data-col="match" title="Move down" aria-label="Move Match down">▼</button>
            </div>
        </div>
        <div class="sp-compare-col-row" data-col-row="items">
            <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="items" checked> Items</label>
            <div class="sp-col-order-btns">
                <button type="button" class="sp-col-order-btn" data-col-move="up" data-col="items" title="Move up" aria-label="Move Items up">▲</button>
                <button type="button" class="sp-col-order-btn" data-col-move="down" data-col="items" title="Move down" aria-label="Move Items down">▼</button>
            </div>
        </div>
        <div class="sp-compare-col-row" data-col-row="modified">
            <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="modified" checked> Modified</label>
            <div class="sp-col-order-btns">
                <button type="button" class="sp-col-order-btn" data-col-move="up" data-col="modified" title="Move up" aria-label="Move Modified up">▲</button>
                <button type="button" class="sp-col-order-btn" data-col-move="down" data-col="modified" title="Move down" aria-label="Move Modified down">▼</button>
            </div>
        </div>
        <div class="sp-compare-col-row" data-col-row="modified_by">
            <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="modified_by" checked> Modified By</label>
            <div class="sp-col-order-btns">
                <button type="button" class="sp-col-order-btn" data-col-move="up" data-col="modified_by" title="Move up" aria-label="Move Modified By up">▲</button>
                <button type="button" class="sp-col-order-btn" data-col-move="down" data-col="modified_by" title="Move down" aria-label="Move Modified By down">▼</button>
            </div>
        </div>
        <div class="sp-compare-col-row" data-col-row="created_by">
            <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="created_by" checked> Created By</label>
            <div class="sp-col-order-btns">
                <button type="button" class="sp-col-order-btn" data-col-move="up" data-col="created_by" title="Move up" aria-label="Move Created By up">▲</button>
                <button type="button" class="sp-col-order-btn" data-col-move="down" data-col="created_by" title="Move down" aria-label="Move Created By down">▼</button>
            </div>
        </div>
        <div class="sp-compare-col-row" data-col-row="actions">
            <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="actions" checked> Link, QR &amp; archive</label>
            <div class="sp-col-order-btns">
                <button type="button" class="sp-col-order-btn" data-col-move="up" data-col="actions" title="Move up" aria-label="Move Link, QR and archive up">▲</button>
                <button type="button" class="sp-col-order-btn" data-col-move="down" data-col="actions" title="Move down" aria-label="Move Link, QR and archive down">▼</button>
            </div>
        </div>
    </div>
</div>
