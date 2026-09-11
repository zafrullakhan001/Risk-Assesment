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
                        <div class="sp-dialog-find-primary">
                            <div class="sp-cc-toolbar">
                                <label class="sharepoint-compare-search-field sp-cc-search-field">
                                    <span class="sharepoint-compare-search-icon" aria-hidden="true">🔎</span>
                                    <input type="search" id="sharepoint-catalog-compare-search" placeholder='Try: encore · tag:priority · ext:pdf · person:"Last, First" · -exclude' autocomplete="off" aria-label="Search compared project folders" title="Same operators as main Find. Tag names match without tag:. Tips: tag:name · ext:pdf · type:visio · person:name · path:drawings · has:pdf · &quot;exact phrase&quot; · -exclude · Press Enter or Search">
                                </label>
                                <button type="button" class="button button-primary" id="sharepoint-catalog-compare-search-run" title="Run search (Enter)">Search</button>
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
                                <details class="sp-compare-columns-picker" id="sharepoint-catalog-compare-columns-picker">
                                    <summary class="sp-view-btn" title="Show or hide comparison columns">Columns</summary>
                                    <div class="sp-compare-columns-menu" role="group" aria-label="Visible comparison columns">
                                        <div class="sp-compare-columns-menu-head">
                                            <span class="sp-compare-columns-menu-title">Columns</span>
                                            <button type="button" class="sp-columns-menu-close" data-columns-close title="Close" aria-label="Close columns menu">✕</button>
                                        </div>
                                        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="items" checked> Items</label>
                                        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="files" checked> Files</label>
                                        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="folders" checked> Folders</label>
                                        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="modified" checked> Modified</label>
                                        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="diff" checked> Diff</label>
                                        <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="actions" checked> Actions &amp; links</label>
                                    </div>
                                </details>
                                <button type="button" class="button ghost" id="sharepoint-catalog-compare-export-csv" title="Export all filtered comparison rows as CSV">Export CSV</button>
                            </div>
                        </div>
                        <div class="sp-dialog-find-filters">
                            <div class="sharepoint-dialog-saved-searches" id="sharepoint-catalog-compare-saved" hidden>
                                <span class="sharepoint-recent-label" title="Live Find query and saved searches from the main catalog">📌 Saved</span>
                                <div class="sharepoint-recent-chips" id="sharepoint-catalog-compare-saved-chips" role="list" aria-label="Saved searches"></div>
                            </div>
                            <div class="sharepoint-dialog-search-controls" id="sharepoint-catalog-compare-search-controls">
                                <div class="sp-search-toggle-group sp-dialog-word-mode" role="group" aria-label="Match spaced words with AND or OR" hidden>
                                    <button type="button" class="sp-search-toggle is-active" data-word-mode="and" title="AND — every word must appear somewhere in the project" aria-pressed="true">AND</button>
                                    <button type="button" class="sp-search-toggle" data-word-mode="or" title="OR — match if any word appears" aria-pressed="false">OR</button>
                                </div>
                                <button type="button" class="sp-search-toggle sp-search-fuzzy sp-dialog-fuzzy" title="Fuzzy — tolerate typos and similar-sounding words (e.g. Encore ≈ Encor)" aria-pressed="false">✨ Fuzzy</button>
                                <button type="button" class="sp-search-toggle sp-search-deep sp-dialog-deep is-active" title="Deep files — also search nested file and folder names/paths inside each project (not file contents)" aria-pressed="true">📂 Deep files</button>
                            </div>
                            <div class="sp-search-toggle-group sp-cc-match-scope" role="group" aria-label="Where to search">
                                <button type="button" class="sp-search-toggle is-active" data-match-scope="all" title="Search everywhere: project names, nested files, and people" aria-pressed="true">🌐 All</button>
                                <button type="button" class="sp-search-toggle" data-match-scope="names" title="Names only — project folder titles" aria-pressed="false">📁 Names</button>
                                <button type="button" class="sp-search-toggle" data-match-scope="files" title="Files only — nested file and folder names/paths" aria-pressed="false">📄 Files</button>
                                <button type="button" class="sp-search-toggle" data-match-scope="people" title="People only — Modified By and Created By" aria-pressed="false">👤 People</button>
                            </div>
                            <div class="sharepoint-type-chips" id="sharepoint-catalog-compare-type-chips" role="group" aria-label="File type filters">
                                <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--pdf" data-type-chip="pdf" title="Has at least one PDF file" aria-pressed="false">📕 PDF</button>
                                <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--word" data-type-chip="word" title="Has a Word document (.doc or .docx)" aria-pressed="false">🔵 DOCX</button>
                                <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--excel" data-type-chip="excel" title="Has an Excel workbook (.xls, .xlsx, .xlsm, or .csv)" aria-pressed="false">🟢 XLSX</button>
                                <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--powerpoint" data-type-chip="powerpoint" title="Has a PowerPoint presentation (.ppt or .pptx)" aria-pressed="false">🟠 PPTX</button>
                                <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--visio" data-type-chip="visio" title="Has Visio diagrams (.vsdx / .vsd)" aria-pressed="false">📐 Visio</button>
                                <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--email" data-type-chip="email" title="Has saved email files (.msg or .eml)" aria-pressed="false">✉️ MSG</button>
                                <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--archive" data-type-chip="archive" title="Has a compressed archive (.zip, .7z, or .rar)" aria-pressed="false">🗜️ ZIP</button>
                                <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--folders" data-type-chip="folders" title="Has nested subfolders" aria-pressed="false">📂 Folders</button>
                            </div>
                            <div class="sharepoint-dialog-search-chips" role="group" aria-label="Quick extensions">
                                <button type="button" class="sp-dialog-chip" data-ext="vsdx">.vsdx</button>
                                <button type="button" class="sp-dialog-chip" data-ext="pdf">.pdf</button>
                                <button type="button" class="sp-dialog-chip" data-ext="xlsx">.xlsx</button>
                                <button type="button" class="sp-dialog-chip" data-ext="docx">.docx</button>
                                <button type="button" class="button ghost sp-dialog-search-clear" id="sharepoint-catalog-compare-search-clear" hidden>Clear filters</button>
                            </div>
                            <p class="sharepoint-dialog-search-meta" id="sharepoint-catalog-compare-meta" aria-live="polite"></p>
                        </div>
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
