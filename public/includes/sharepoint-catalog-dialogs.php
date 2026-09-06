<?php

declare(strict_types=1);

?>
            <dialog class="response-dialog sharepoint-project-dialog sp-workspace-dialog is-compact-chrome" id="sharepoint-project-dialog" aria-labelledby="sharepoint-project-dialog-title" data-density="compact">
                <div class="response-dialog-form sharepoint-project-dialog-body">
                    <div class="response-dialog-head sp-dialog-drag-handle">
                        <div>
                            <div class="eyebrow">📂 SharePoint project</div>
                            <h3 id="sharepoint-project-dialog-title">Project</h3>
                            <p class="response-dialog-sub" id="sharepoint-project-dialog-sub"></p>
                        </div>
                        <div class="sp-dialog-window-tools">
                            <button type="button" class="button ghost sp-dialog-refresh" id="sharepoint-project-dialog-refresh" title="Reload this folder from the database" aria-label="Refresh folder from database">
                                <span class="sp-dialog-refresh-icon" aria-hidden="true">↻</span>
                            </button>
                            <button type="button" class="button ghost sp-dialog-maximize" id="sharepoint-project-dialog-maximize" title="Maximize" aria-label="Maximize dialog" aria-pressed="false">⛶</button>
                            <button type="button" class="button ghost response-dialog-close" id="sharepoint-project-dialog-close" aria-label="Close">✕</button>
                        </div>
                    </div>
                    <div class="sharepoint-project-dialog-stats" id="sharepoint-project-dialog-stats" hidden></div>
                    <div class="sharepoint-project-dialog-tags" id="sharepoint-project-dialog-tags" hidden></div>
                    <div class="sharepoint-project-dialog-actions" id="sharepoint-project-dialog-actions"></div>
                    <div class="sharepoint-dialog-search" id="sharepoint-project-dialog-search-wrap" hidden>
                        <div class="sharepoint-dialog-toolbar">
                            <div class="sp-view-toggle" role="group" aria-label="Layout">
                                <button type="button" class="sp-view-btn is-active" data-layout="tree" aria-pressed="true">🌳 Tree</button>
                                <button type="button" class="sp-view-btn" data-layout="flat" aria-pressed="false">☰ List</button>
                            </div>
                            <div class="sp-view-toggle" role="group" aria-label="Chrome density">
                                <button type="button" class="sp-view-btn" data-density="comfort" title="Show full headers and filters" aria-pressed="false">Comfort</button>
                                <button type="button" class="sp-view-btn is-active" data-density="compact" title="Shrink headers so the file list uses more space" aria-pressed="true">Compact</button>
                            </div>
                            <div class="sp-tree-actions" role="group" aria-label="Tree expand collapse">
                                <button type="button" class="sp-tree-action-btn" data-tree-action="expand" title="Expand all folders">⬇ Expand all</button>
                                <button type="button" class="sp-tree-action-btn" data-tree-action="collapse" title="Collapse all folders">⬆ Collapse all</button>
                            </div>
                            <label class="sp-view-select">
                                <span>Show</span>
                                <select id="sharepoint-project-dialog-kind" aria-label="Show files and/or folders">
                                    <option value="all" selected>Files &amp; folders</option>
                                    <option value="files">Files only</option>
                                    <option value="folders">Folders only</option>
                                </select>
                            </label>
                            <label class="sp-view-select">
                                <span>Type</span>
                                <select id="sharepoint-project-dialog-ext" aria-label="File extension filter">
                                    <option value="" selected>Any extension</option>
                                    <option value="vsdx">Visio (.vsdx)</option>
                                    <option value="vsd">Visio (.vsd)</option>
                                    <option value="pdf">PDF</option>
                                    <option value="xlsx">Excel (.xlsx)</option>
                                    <option value="xls">Excel (.xls)</option>
                                    <option value="docx">Word (.docx)</option>
                                    <option value="doc">Word (.doc)</option>
                                    <option value="pptx">PowerPoint (.pptx)</option>
                                    <option value="msg">Email (.msg)</option>
                                    <option value="zip">Archive (.zip)</option>
                                </select>
                            </label>
                        </div>
                        <label class="sharepoint-dialog-search-label" for="sharepoint-project-dialog-search">
                            <span aria-hidden="true">🔎</span>
                            <input type="search" id="sharepoint-project-dialog-search" placeholder="Search name or path… (AND / OR · Fuzzy)" autocomplete="off">
                        </label>
                        <div class="sharepoint-dialog-search-controls" id="sharepoint-project-dialog-search-controls">
                            <div class="sp-search-toggle-group sp-dialog-word-mode" role="group" aria-label="Match spaced words with AND or OR" hidden>
                                <button type="button" class="sp-search-toggle is-active" data-word-mode="and" title="Match only when every word is found" aria-pressed="true">AND</button>
                                <button type="button" class="sp-search-toggle" data-word-mode="or" title="Match when any word is found" aria-pressed="false">OR</button>
                            </div>
                            <button type="button" class="sp-search-toggle sp-search-fuzzy sp-dialog-fuzzy" title="Match similar-sounding words and common misspellings" aria-pressed="false">Fuzzy</button>
                        </div>
                        <div class="sharepoint-dialog-search-chips" role="group" aria-label="Quick extensions">
                            <button type="button" class="sp-dialog-chip" data-ext="vsdx">.vsdx</button>
                            <button type="button" class="sp-dialog-chip" data-ext="pdf">.pdf</button>
                            <button type="button" class="sp-dialog-chip" data-ext="xlsx">.xlsx</button>
                            <button type="button" class="sp-dialog-chip" data-ext="docx">.docx</button>
                            <button type="button" class="button ghost sp-dialog-search-clear" id="sharepoint-project-dialog-search-clear" hidden>Clear filters</button>
                        </div>
                        <p class="sharepoint-dialog-search-meta" id="sharepoint-project-dialog-search-meta" aria-live="polite"></p>
                    </div>
                    <div class="table-wrap sharepoint-dialog-table-wrap">
                        <table class="sharepoint-projects-table sharepoint-dialog-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="is-sortable is-sorted-asc" data-sort="name" aria-sort="ascending">
                                        <button type="button" class="sp-dialog-sort-btn" data-sort="name">📄 Name</button>
                                    </th>
                                    <th scope="col" class="is-sortable" data-sort="type" aria-sort="none">
                                        <button type="button" class="sp-dialog-sort-btn" data-sort="type">🏷️ Type</button>
                                    </th>
                                    <th scope="col" class="is-sortable" data-sort="size" aria-sort="none">
                                        <button type="button" class="sp-dialog-sort-btn" data-sort="size">📦 Size</button>
                                    </th>
                                    <th scope="col" class="is-sortable" data-sort="modified" aria-sort="none">
                                        <button type="button" class="sp-dialog-sort-btn" data-sort="modified">🕒 Modified</button>
                                    </th>
                                    <th scope="col" class="is-sortable" data-sort="created" aria-sort="none">
                                        <button type="button" class="sp-dialog-sort-btn" data-sort="created">📅 Created</button>
                                    </th>
                                    <th scope="col" class="is-sortable" data-sort="modified_by" aria-sort="none">
                                        <button type="button" class="sp-dialog-sort-btn" data-sort="modified_by">👤 Modified By</button>
                                    </th>
                                    <th scope="col" class="is-sortable" data-sort="created_by" aria-sort="none">
                                        <button type="button" class="sp-dialog-sort-btn" data-sort="created_by">🙋 Created By</button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="sharepoint-project-dialog-rows">
                                <tr><td colspan="7" class="sharepoint-dialog-empty">⏳ Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </dialog>

            <dialog class="response-dialog sharepoint-compare-dialog sp-workspace-dialog is-compact-chrome" id="sharepoint-compare-dialog" aria-labelledby="sharepoint-compare-dialog-title" data-density="compact">
                <div class="response-dialog-form sharepoint-compare-dialog-body">
                    <div class="response-dialog-head sp-dialog-drag-handle">
                        <div>
                            <div class="eyebrow">⚖️ Side-by-side compare</div>
                            <h3 id="sharepoint-compare-dialog-title">Compare folders</h3>
                            <p class="response-dialog-sub" id="sharepoint-compare-dialog-sub">Select 2 or 3 project folders to compare files and folders.</p>
                        </div>
                        <div class="sp-dialog-window-tools">
                            <button type="button" class="button ghost sp-dialog-refresh" id="sharepoint-compare-dialog-refresh" title="Reload compared folders from the database" aria-label="Refresh compared folders from database">
                                <span class="sp-dialog-refresh-icon" aria-hidden="true">↻</span>
                            </button>
                            <button type="button" class="button ghost sp-dialog-maximize" id="sharepoint-compare-dialog-maximize" title="Maximize" aria-label="Maximize dialog" aria-pressed="false">⛶</button>
                            <button type="button" class="button ghost response-dialog-close" id="sharepoint-compare-dialog-close" aria-label="Close compare">✕</button>
                        </div>
                    </div>
                    <div class="sharepoint-compare-legend" id="sharepoint-compare-legend" hidden>
                        <span class="sp-diff-pill sp-diff-pill--all">In all</span>
                        <span class="sp-diff-pill sp-diff-pill--shared">Shared</span>
                        <span class="sp-diff-pill sp-diff-pill--left">Only left</span>
                        <span class="sp-diff-pill sp-diff-pill--mid">Only middle</span>
                        <span class="sp-diff-pill sp-diff-pill--right">Only right</span>
                    </div>
                    <div class="sharepoint-dialog-search sharepoint-compare-search" id="sharepoint-compare-search-wrap" hidden>
                        <div class="sharepoint-dialog-toolbar">
                            <div class="sp-view-toggle" role="group" aria-label="Layout">
                                <button type="button" class="sp-view-btn is-active" data-layout="tree" aria-pressed="true">🌳 Tree</button>
                                <button type="button" class="sp-view-btn" data-layout="flat" aria-pressed="false">☰ List</button>
                            </div>
                            <div class="sp-view-toggle" role="group" aria-label="Chrome density">
                                <button type="button" class="sp-view-btn" data-density="comfort" title="Show full headers and filters" aria-pressed="false">Comfort</button>
                                <button type="button" class="sp-view-btn is-active" data-density="compact" title="Shrink headers so the file list uses more space" aria-pressed="true">Compact</button>
                            </div>
                            <div class="sp-tree-actions" role="group" aria-label="Tree expand collapse">
                                <button type="button" class="sp-tree-action-btn" data-tree-action="expand" title="Expand all folders on all sides">⬇ Expand all</button>
                                <button type="button" class="sp-tree-action-btn" data-tree-action="collapse" title="Collapse all folders on all sides">⬆ Collapse all</button>
                            </div>
                            <label class="sp-view-select">
                                <span>Show</span>
                                <select id="sharepoint-compare-kind" aria-label="Show files and/or folders">
                                    <option value="all" selected>Files &amp; folders</option>
                                    <option value="files">Files only</option>
                                    <option value="folders">Folders only</option>
                                </select>
                            </label>
                            <label class="sp-view-check">
                                <input type="checkbox" id="sharepoint-compare-unique-only">
                                <span>Unique only</span>
                            </label>
                            <details class="sp-compare-columns-picker" id="sharepoint-compare-columns-picker">
                                <summary class="sp-view-btn" title="Show or hide table columns">Columns</summary>
                                <div class="sp-compare-columns-menu" role="group" aria-label="Visible columns">
                                    <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="type" checked> Type</label>
                                    <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="size"> Size</label>
                                    <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="modified" checked> Modified</label>
                                    <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="created" checked> Created</label>
                                    <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="modified_by"> Modified By</label>
                                    <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="created_by" checked> Created By</label>
                                    <label class="sp-compare-col-option"><input type="checkbox" data-col-toggle="diff" checked> Diff</label>
                                </div>
                            </details>
                        </div>
                        <div class="sharepoint-dialog-search-controls" id="sharepoint-compare-search-controls">
                            <div class="sp-search-toggle-group sp-dialog-word-mode" role="group" aria-label="Match spaced words with AND or OR" hidden>
                                <button type="button" class="sp-search-toggle is-active" data-word-mode="and" title="Match only when every word is found" aria-pressed="true">AND</button>
                                <button type="button" class="sp-search-toggle" data-word-mode="or" title="Match when any word is found" aria-pressed="false">OR</button>
                            </div>
                            <button type="button" class="sp-search-toggle sp-search-fuzzy sp-dialog-fuzzy" title="Match similar-sounding words and common misspellings" aria-pressed="false">Fuzzy</button>
                        </div>
                        <p class="panel-help sharepoint-compare-filter-hint">Each panel has its own search and extension filters.</p>
                        <div class="sharepoint-compare-hidden-bar" id="sharepoint-compare-hidden-bar" hidden>
                            <span class="sharepoint-compare-hidden-label" id="sharepoint-compare-hidden-label">0 hidden</span>
                            <div class="sharepoint-compare-hidden-chips" id="sharepoint-compare-hidden-chips" role="list"></div>
                            <button type="button" class="button ghost" id="sharepoint-compare-show-all-hidden">Show all</button>
                        </div>
                    </div>
                    <div class="sharepoint-compare-panels" id="sharepoint-compare-panels" data-panel-count="2">
                        <section class="sharepoint-compare-panel" data-side="left">
                            <header class="sharepoint-compare-panel-head">
                                <div class="sharepoint-compare-panel-top">
                                    <div class="sharepoint-compare-panel-identity">
                                        <h4 id="sharepoint-compare-left-title">Left</h4>
                                        <p id="sharepoint-compare-left-sub"></p>
                                    </div>
                                    <div class="sharepoint-compare-panel-tools" data-side="left">
                                        <button type="button" class="sp-compare-tool-btn sp-compare-swap-btn" data-side="left" data-swap="prev" title="Swap with previous panel" aria-label="Swap left with previous">⇄←</button>
                                        <button type="button" class="sp-compare-tool-btn sp-compare-swap-btn" data-side="left" data-swap="next" title="Swap with next panel" aria-label="Swap left with next">⇄→</button>
                                        <button type="button" class="sp-compare-tool-btn sp-compare-close-btn" data-side="left" title="Remove this panel" aria-label="Remove left panel">✕</button>
                                    </div>
                                </div>
                                <div class="sharepoint-compare-panel-chrome">
                                    <div class="sharepoint-compare-panel-actions" id="sharepoint-compare-left-actions"></div>
                                    <div class="sharepoint-compare-panel-filters" data-side="left">
                                        <label class="sharepoint-compare-search-field">
                                            <span class="sharepoint-compare-search-icon" aria-hidden="true">🔎</span>
                                            <input type="search" class="sp-compare-panel-search" data-side="left" placeholder="Search this panel…" autocomplete="off" aria-label="Search left panel">
                                        </label>
                                        <div class="sharepoint-compare-filter-row">
                                            <div class="sharepoint-dialog-search-chips" role="group" aria-label="Left panel extensions">
                                                <button type="button" class="sp-dialog-chip" data-ext="vsdx" data-side="left">.vsdx</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="pdf" data-side="left">.pdf</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="xlsx" data-side="left">.xlsx</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="docx" data-side="left">.docx</button>
                                            </div>
                                            <button type="button" class="button ghost sp-dialog-search-clear sp-compare-panel-clear" data-side="left" hidden>Clear</button>
                                        </div>
                                        <p class="sharepoint-dialog-search-meta sp-compare-panel-meta" data-side="left" aria-live="polite"></p>
                                    </div>
                                </div>
                            </header>
                            <div class="table-wrap sharepoint-compare-table-wrap">
                                <table class="sharepoint-projects-table sharepoint-dialog-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="is-sortable is-sorted-asc" data-col="name" data-sort="name" aria-sort="ascending">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="name">📄 Name</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="type" data-sort="type" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="type">🏷️ Type</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="size" data-sort="size" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="size">📦 Size</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="modified" data-sort="modified" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="modified">🕒 Modified</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="created" data-sort="created" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="created">📅 Created</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="modified_by" data-sort="modified_by" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="modified_by">👤 Modified By</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="created_by" data-sort="created_by" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="created_by">🙋 Created By</button>
                                            </th>
                                            <th scope="col" data-col="diff">Diff</th>
                                            <th scope="col" data-col="hide" class="sp-compare-hide-col"><span class="visually-hidden">Hide</span></th>
                                        </tr>
                                    </thead>
                                    <tbody id="sharepoint-compare-left-rows">
                                        <tr><td colspan="9" class="sharepoint-dialog-empty">Select folders to compare.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section class="sharepoint-compare-panel" data-side="mid" hidden>
                            <header class="sharepoint-compare-panel-head">
                                <div class="sharepoint-compare-panel-top">
                                    <div class="sharepoint-compare-panel-identity">
                                        <h4 id="sharepoint-compare-mid-title">Middle</h4>
                                        <p id="sharepoint-compare-mid-sub"></p>
                                    </div>
                                    <div class="sharepoint-compare-panel-tools" data-side="mid">
                                        <button type="button" class="sp-compare-tool-btn sp-compare-swap-btn" data-side="mid" data-swap="prev" title="Swap with previous panel" aria-label="Swap middle with previous">⇄←</button>
                                        <button type="button" class="sp-compare-tool-btn sp-compare-swap-btn" data-side="mid" data-swap="next" title="Swap with next panel" aria-label="Swap middle with next">⇄→</button>
                                        <button type="button" class="sp-compare-tool-btn sp-compare-close-btn" data-side="mid" title="Remove this panel" aria-label="Remove middle panel">✕</button>
                                    </div>
                                </div>
                                <div class="sharepoint-compare-panel-chrome">
                                    <div class="sharepoint-compare-panel-actions" id="sharepoint-compare-mid-actions"></div>
                                    <div class="sharepoint-compare-panel-filters" data-side="mid">
                                        <label class="sharepoint-compare-search-field">
                                            <span class="sharepoint-compare-search-icon" aria-hidden="true">🔎</span>
                                            <input type="search" class="sp-compare-panel-search" data-side="mid" placeholder="Search this panel…" autocomplete="off" aria-label="Search middle panel">
                                        </label>
                                        <div class="sharepoint-compare-filter-row">
                                            <div class="sharepoint-dialog-search-chips" role="group" aria-label="Middle panel extensions">
                                                <button type="button" class="sp-dialog-chip" data-ext="vsdx" data-side="mid">.vsdx</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="pdf" data-side="mid">.pdf</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="xlsx" data-side="mid">.xlsx</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="docx" data-side="mid">.docx</button>
                                            </div>
                                            <button type="button" class="button ghost sp-dialog-search-clear sp-compare-panel-clear" data-side="mid" hidden>Clear</button>
                                        </div>
                                        <p class="sharepoint-dialog-search-meta sp-compare-panel-meta" data-side="mid" aria-live="polite"></p>
                                    </div>
                                </div>
                            </header>
                            <div class="table-wrap sharepoint-compare-table-wrap">
                                <table class="sharepoint-projects-table sharepoint-dialog-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="is-sortable is-sorted-asc" data-col="name" data-sort="name" aria-sort="ascending">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="name">📄 Name</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="type" data-sort="type" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="type">🏷️ Type</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="size" data-sort="size" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="size">📦 Size</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="modified" data-sort="modified" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="modified">🕒 Modified</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="created" data-sort="created" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="created">📅 Created</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="modified_by" data-sort="modified_by" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="modified_by">👤 Modified By</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="created_by" data-sort="created_by" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="created_by">🙋 Created By</button>
                                            </th>
                                            <th scope="col" data-col="diff">Diff</th>
                                            <th scope="col" data-col="hide" class="sp-compare-hide-col"><span class="visually-hidden">Hide</span></th>
                                        </tr>
                                    </thead>
                                    <tbody id="sharepoint-compare-mid-rows">
                                        <tr><td colspan="9" class="sharepoint-dialog-empty">Select folders to compare.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section class="sharepoint-compare-panel" data-side="right">
                            <header class="sharepoint-compare-panel-head">
                                <div class="sharepoint-compare-panel-top">
                                    <div class="sharepoint-compare-panel-identity">
                                        <h4 id="sharepoint-compare-right-title">Right</h4>
                                        <p id="sharepoint-compare-right-sub"></p>
                                    </div>
                                    <div class="sharepoint-compare-panel-tools" data-side="right">
                                        <button type="button" class="sp-compare-tool-btn sp-compare-swap-btn" data-side="right" data-swap="prev" title="Swap with previous panel" aria-label="Swap right with previous">⇄←</button>
                                        <button type="button" class="sp-compare-tool-btn sp-compare-swap-btn" data-side="right" data-swap="next" title="Swap with next panel" aria-label="Swap right with next">⇄→</button>
                                        <button type="button" class="sp-compare-tool-btn sp-compare-close-btn" data-side="right" title="Remove this panel" aria-label="Remove right panel">✕</button>
                                    </div>
                                </div>
                                <div class="sharepoint-compare-panel-chrome">
                                    <div class="sharepoint-compare-panel-actions" id="sharepoint-compare-right-actions"></div>
                                    <div class="sharepoint-compare-panel-filters" data-side="right">
                                        <label class="sharepoint-compare-search-field">
                                            <span class="sharepoint-compare-search-icon" aria-hidden="true">🔎</span>
                                            <input type="search" class="sp-compare-panel-search" data-side="right" placeholder="Search this panel…" autocomplete="off" aria-label="Search right panel">
                                        </label>
                                        <div class="sharepoint-compare-filter-row">
                                            <div class="sharepoint-dialog-search-chips" role="group" aria-label="Right panel extensions">
                                                <button type="button" class="sp-dialog-chip" data-ext="vsdx" data-side="right">.vsdx</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="pdf" data-side="right">.pdf</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="xlsx" data-side="right">.xlsx</button>
                                                <button type="button" class="sp-dialog-chip" data-ext="docx" data-side="right">.docx</button>
                                            </div>
                                            <button type="button" class="button ghost sp-dialog-search-clear sp-compare-panel-clear" data-side="right" hidden>Clear</button>
                                        </div>
                                        <p class="sharepoint-dialog-search-meta sp-compare-panel-meta" data-side="right" aria-live="polite"></p>
                                    </div>
                                </div>
                            </header>
                            <div class="table-wrap sharepoint-compare-table-wrap">
                                <table class="sharepoint-projects-table sharepoint-dialog-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="is-sortable is-sorted-asc" data-col="name" data-sort="name" aria-sort="ascending">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="name">📄 Name</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="type" data-sort="type" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="type">🏷️ Type</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="size" data-sort="size" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="size">📦 Size</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="modified" data-sort="modified" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="modified">🕒 Modified</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="created" data-sort="created" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="created">📅 Created</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="modified_by" data-sort="modified_by" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="modified_by">👤 Modified By</button>
                                            </th>
                                            <th scope="col" class="is-sortable" data-col="created_by" data-sort="created_by" aria-sort="none">
                                                <button type="button" class="sp-dialog-sort-btn" data-sort="created_by">🙋 Created By</button>
                                            </th>
                                            <th scope="col" data-col="diff">Diff</th>
                                            <th scope="col" data-col="hide" class="sp-compare-hide-col"><span class="visually-hidden">Hide</span></th>
                                        </tr>
                                    </thead>
                                    <tbody id="sharepoint-compare-right-rows">
                                        <tr><td colspan="9" class="sharepoint-dialog-empty">Select folders to compare.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>
            </dialog>

<?php
$qrBranding = \RiskAssessment\Branding::current();
$qrLogoUrl = (string) ($qrBranding->logoUrl() ?: '');
// Center QR mark uses the uploaded favicon only — never the wide logo.
$qrFaviconUrl = $qrBranding->hasCustomFavicon()
    ? (string) ($qrBranding->faviconUrl() ?: '')
    : '';
?>
            <dialog
                class="response-dialog sharepoint-qr-dialog"
                id="sharepoint-qr-dialog"
                aria-labelledby="sharepoint-qr-dialog-title"
                data-brand-logo="<?= e($qrLogoUrl) ?>"
                data-brand-favicon="<?= e($qrFaviconUrl) ?>"
                data-brand-title="<?= e((string) $qrBranding->brandTitle()) ?>"
            >
                <div class="response-dialog-form sharepoint-qr-dialog-body">
                    <div class="response-dialog-head sharepoint-qr-head">
                        <div class="sharepoint-qr-head-brand" id="sharepoint-qr-head-brand" aria-hidden="true"></div>
                        <div class="sharepoint-qr-head-copy">
                            <div class="eyebrow">📱 Mobile scan</div>
                            <h3 id="sharepoint-qr-dialog-title">QR Code</h3>
                            <p class="response-dialog-sub" id="sharepoint-qr-dialog-sub">Scan with your phone camera to open this link.</p>
                            <div class="sharepoint-qr-catalog-wrap" id="sharepoint-qr-catalog-wrap" hidden></div>
                        </div>
                        <button type="button" class="button ghost response-dialog-close" id="sharepoint-qr-dialog-close" aria-label="Close">✕</button>
                    </div>
                    <div class="sharepoint-qr-frame" id="sharepoint-qr-frame" aria-live="polite"></div>
                    <p class="sharepoint-qr-hint">Scan with your phone camera to open this link</p>
                    <p class="sharepoint-qr-size" id="sharepoint-qr-size" hidden></p>
                    <div class="sharepoint-qr-actions" role="toolbar" aria-label="QR code actions">
                        <div class="sharepoint-qr-actions-left">
                            <a
                                class="sharepoint-qr-icon-btn"
                                id="sharepoint-qr-open"
                                href="#"
                                target="_blank"
                                rel="noopener noreferrer"
                                title="Open link"
                                aria-label="Open link"
                            >
                                <span aria-hidden="true">🔗</span>
                            </a>
                            <button
                                type="button"
                                class="sharepoint-qr-icon-btn"
                                id="sharepoint-qr-share"
                                title="Share link"
                                aria-label="Share link"
                            >
                                <span aria-hidden="true">↗</span>
                            </button>
                            <button
                                type="button"
                                class="sharepoint-qr-icon-btn"
                                id="sharepoint-qr-copy"
                                data-copy-url=""
                                title="Copy URL"
                                aria-label="Copy URL"
                            >
                                <span aria-hidden="true">📋</span>
                            </button>
                            <button
                                type="button"
                                class="sharepoint-qr-icon-btn"
                                id="sharepoint-qr-print"
                                title="Print QR code"
                                aria-label="Print QR code"
                            >
                                <span aria-hidden="true">🖨️</span>
                            </button>
                        </div>
                        <button
                            type="button"
                            class="sharepoint-qr-icon-btn sharepoint-qr-icon-btn--close"
                            id="sharepoint-qr-done"
                            value="cancel"
                            title="Close"
                            aria-label="Close"
                        >
                            <span aria-hidden="true">✕</span>
                            <span class="sharepoint-qr-close-label">Close</span>
                        </button>
                    </div>
                </div>
            </dialog>
