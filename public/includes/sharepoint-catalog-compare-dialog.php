<?php

declare(strict_types=1);

?>
            <dialog class="response-dialog sharepoint-catalog-compare-dialog sp-workspace-dialog is-compact-chrome" id="sharepoint-catalog-compare-dialog" aria-labelledby="sharepoint-catalog-compare-title" data-density="compact">
                <div class="response-dialog-form sharepoint-catalog-compare-body">
                    <section class="sp-dialog-band sp-dialog-band--identity" aria-label="Catalog compare identity">
                        <div class="response-dialog-head sp-dialog-drag-handle">
                            <div>
                                <div class="eyebrow">⚖️ Catalog compare</div>
                                <h3 id="sharepoint-catalog-compare-title">Compare catalogs</h3>
                                <p class="response-dialog-sub" id="sharepoint-catalog-compare-sub">Select two catalogs to compare project folders.</p>
                            </div>
                            <div class="sp-dialog-window-tools">
                                <button type="button" class="button ghost sp-dialog-refresh" id="sharepoint-catalog-compare-refresh" title="Reload comparison from the database" aria-label="Refresh catalog comparison">
                                    <span class="sp-dialog-refresh-icon" aria-hidden="true">↻</span>
                                </button>
                                <button type="button" class="button ghost sp-dialog-maximize" id="sharepoint-catalog-compare-maximize" title="Maximize" aria-label="Maximize dialog" aria-pressed="false">⛶</button>
                                <button type="button" class="button ghost response-dialog-close" id="sharepoint-catalog-compare-close" aria-label="Close catalog compare">✕</button>
                            </div>
                        </div>
                    </section>
                    <section class="sp-dialog-band sp-dialog-band--work" aria-label="Comparison totals">
                        <div class="sharepoint-catalog-compare-legend" id="sharepoint-catalog-compare-legend" hidden>
                            <button type="button" class="sp-cc-total" data-presence="any" aria-pressed="true">All <span data-total="all">0</span></button>
                            <button type="button" class="sp-cc-total" data-presence="both" aria-pressed="false"><span class="sp-diff-pill sp-diff-pill--all">In both</span> <span data-total="in_both">0</span></button>
                            <button type="button" class="sp-cc-total" data-presence="left" aria-pressed="false"><span class="sp-diff-pill sp-diff-pill--left">Only left</span> <span data-total="only_left">0</span></button>
                            <button type="button" class="sp-cc-total" data-presence="right" aria-pressed="false"><span class="sp-diff-pill sp-diff-pill--right">Only right</span> <span data-total="only_right">0</span></button>
                        </div>
                    </section>
                    <section class="sp-dialog-band sp-dialog-band--find sharepoint-dialog-search" id="sharepoint-catalog-compare-search-wrap" aria-label="Find and filters">
                        <div class="sp-cc-toolbar">
                            <label class="sharepoint-compare-search-field sp-cc-search-field">
                                <span class="sharepoint-compare-search-icon" aria-hidden="true">🔎</span>
                                <input type="search" id="sharepoint-catalog-compare-search" placeholder="Filter project folders…" autocomplete="off" aria-label="Filter compared project folders">
                            </label>
                            <button type="button" class="button button-primary" id="sharepoint-catalog-compare-search-run" title="Run filter (Enter)">Search</button>
                            <label class="sp-view-select">
                                <span>Sort</span>
                                <select id="sharepoint-catalog-compare-sort" aria-label="Sort compared projects">
                                    <option value="name" selected>Name</option>
                                    <option value="presence">Presence</option>
                                    <option value="items">Items</option>
                                    <option value="modified">Modified</option>
                                </select>
                            </label>
                            <label class="sp-view-select">
                                <span>Dir</span>
                                <select id="sharepoint-catalog-compare-dir" aria-label="Sort direction">
                                    <option value="asc" selected>A–Z</option>
                                    <option value="desc">Z–A</option>
                                </select>
                            </label>
                            <label class="sp-view-select">
                                <span>Show</span>
                                <select id="sharepoint-catalog-compare-per" aria-label="Rows per page">
                                    <option value="25">25</option>
                                    <option value="50" selected>50</option>
                                    <option value="100">100</option>
                                    <option value="200">200</option>
                                </select>
                            </label>
                        </div>
                        <p class="sharepoint-dialog-search-meta" id="sharepoint-catalog-compare-meta" aria-live="polite"></p>
                    </section>
                    <div class="sharepoint-catalog-compare-grid" id="sharepoint-catalog-compare-grid">
                        <div class="sp-cc-col-head" data-side="left" id="sharepoint-catalog-compare-left-head">Left</div>
                        <div class="sp-cc-col-head" data-side="right" id="sharepoint-catalog-compare-right-head">Right</div>
                        <div class="sp-cc-col-head sp-cc-col-head--meta">Diff</div>
                    </div>
                    <div class="sharepoint-catalog-compare-rows" id="sharepoint-catalog-compare-rows">
                        <p class="sharepoint-dialog-empty">Select two catalogs to compare.</p>
                    </div>
                    <nav class="sharepoint-catalog-compare-pager" id="sharepoint-catalog-compare-pager" hidden aria-label="Catalog compare pages">
                        <button type="button" class="button ghost" id="sharepoint-catalog-compare-prev">← Prev</button>
                        <span id="sharepoint-catalog-compare-page-label">Page 1</span>
                        <button type="button" class="button ghost" id="sharepoint-catalog-compare-next">Next →</button>
                    </nav>
                </div>
            </dialog>
