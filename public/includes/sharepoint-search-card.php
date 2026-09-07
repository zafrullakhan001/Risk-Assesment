<?php

declare(strict_types=1);

/**
 * Shared SharePoint / public catalog live-search card.
 *
 * Expected variables:
 * - $activeSourceKey, $activeTitle, $allSources, $catalogTones, $catalogToneHex
 * - $query, $perPage, $itemCount, $projectCount (or $matchedProjectCount for owner)
 * - $activeLastSynced, $activeLastStatus
 * - $searchCardPublic (bool) — public catalog share
 * - $catalogSolo (bool) — owner solo catalog view
 * - $searchFormAction (string)
 * - $searchShareToken (string, public only)
 * - $sourcesJson (string|null) — optional prebuilt JSON; else built from $allSources
 * - $currentUser (array|null) — for modified:me (owner page)
 * - $catalogDashUrl (string|null) — New tab link on owner page
 * - $searchIntroHtml (string|null) — optional intro paragraph override
 * - $metaProjectCount (int|null) — count shown in meta line
 */

$searchCardPublic = !empty($searchCardPublic);
$catalogSolo = !empty($catalogSolo);
$searchFormAction = (string) ($searchFormAction ?? ($searchCardPublic ? 'catalog-share.php' : 'sharepoint.php'));
$searchShareToken = (string) ($searchShareToken ?? '');
$metaProjectCount = (int) ($metaProjectCount ?? $matchedProjectCount ?? $projectCount ?? 0);
$searchIntroHtml = (string) ($searchIntroHtml ?? '');
if ($searchIntroHtml === '') {
    $searchIntroHtml = $searchCardPublic
        ? 'Find a project folder — live search on project name, nested files, subfolders, paths, Modified By, Created By, or search tags. Use operators like <code>tag:name</code>, <code>ext:pdf</code>, <code>person:name</code>, <code>"exact phrase"</code>, or <code>-exclude</code>.'
        : 'Find a project folder — live search on project name, nested files, subfolders, paths, Modified By, Created By, or search tags. Turn on <strong>Deep files</strong> to walk every cataloged file alongside the folder (names and paths, not file contents). Typo-tolerant when Fuzzy is on. Operators: <code>tag:name</code>, <code>ext:pdf</code>, <code>person:name</code>, <code>"exact"</code>, <code>-exclude</code>.';
}

if (!isset($sourcesJson) || $sourcesJson === null || $sourcesJson === '') {
    $sourcesJson = json_encode(array_map(static function (array $src) use ($catalogTones): array {
        $key = (string) ($src['source_key'] ?? '');

        return [
            'source_key' => $key,
            'title' => (string) ($src['title'] ?? $src['source_key'] ?? ''),
            'tone' => (string) ($catalogTones[$key] ?? 'slate'),
            'folder_path' => (string) ($src['folder_path'] ?? ''),
            'site_host' => (string) ($src['site_host'] ?? ''),
            'site_path' => (string) ($src['site_path'] ?? ''),
        ];
    }, $allSources), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$userName = '';
$userDisplay = '';
$userEmail = '';
if (!$searchCardPublic && is_array($currentUser ?? null)) {
    $userName = (string) ($currentUser['username'] ?? '');
    $userDisplay = (string) ($currentUser['display_name'] ?? $userName);
    $userEmail = (string) ($currentUser['email'] ?? '');
}

$presenceOptions = [];
$archivedSourceKeys = is_array($archivedSourceKeys ?? null) ? $archivedSourceKeys : [];
foreach ($allSources as $src) {
    $key = (string) ($src['source_key'] ?? '');
    if ($key === '') {
        continue;
    }
    $presenceOptions[] = [
        'key' => $key,
        'title' => (string) ($src['title'] ?? $key),
    ];
}
$showSectionMove = !empty($showSectionMove) && empty($searchCardPublic);
?>
<section class="upload-card search-card is-compact-chrome" id="sharepoint-search"
         data-sp-section="search"
         data-catalog-density="compact"
         data-source-key="<?= e($activeSourceKey) ?>"
         data-source-title="<?= e($activeTitle) ?>"
         data-solo="<?= $catalogSolo ? '1' : '0' ?>"
         data-can-edit-tags="<?= !empty($isAdmin) && empty($searchCardPublic) ? '1' : '0' ?>"
         data-can-archive="<?= !empty($isAdmin) && empty($searchCardPublic) ? '1' : '0' ?>"
         <?php if (!empty($isAdmin) && empty($searchCardPublic)): ?>
         data-csrf="<?= e((string) ($_SESSION['csrf_token'] ?? '')) ?>"
         <?php endif; ?>
         <?php if ($searchCardPublic): ?>
         data-public="1"
         data-api-base="catalog-share.php"
         data-share-token="<?= e($searchShareToken) ?>"
         <?php endif; ?>
         <?php if ($userName !== '' || $userDisplay !== ''): ?>
         data-user-name="<?= e($userName) ?>"
         data-user-display="<?= e($userDisplay) ?>"
         data-user-email="<?= e($userEmail) ?>"
         <?php endif; ?>
         data-sources="<?= e((string) $sourcesJson) ?>"
         data-initial-query="<?= e($query) ?>"
         data-per-page="<?= (int) $perPage ?>"
         data-item-count="<?= (int) $itemCount ?>"
         data-project-count="<?= (int) ($projectCount ?? $metaProjectCount) ?>"
         data-last-synced="<?= e($activeLastSynced) ?>"
         data-last-status="<?= e($activeLastStatus) ?>">
    <details class="sharepoint-catalog-shell" id="sharepoint-catalog-shell" open>
        <summary class="sharepoint-search-head sharepoint-catalog-summary">
            <div class="sharepoint-search-intro">
                <h2 id="sharepoint-search-heading">🔎 <?= e($activeTitle) ?></h2>
                <p><?= $searchIntroHtml ?></p>
            </div>
            <div class="sharepoint-search-head-tools" data-no-toggle onclick="event.stopPropagation()">
                <?php require __DIR__ . '/sharepoint-section-move.php'; ?>
                <div class="sp-view-toggle sharepoint-catalog-view-toggle" role="group" aria-label="Catalog layout">
                    <button type="button" class="sp-view-btn" data-catalog-density="comfort" title="Show the full search card" aria-pressed="false">Comfort</button>
                    <button type="button" class="sp-view-btn is-active" data-catalog-density="compact" title="Shrink the search card so the project list has more room" aria-pressed="true">Compact</button>
                </div>
                <?php if ($searchCardPublic): ?>
                    <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
                <?php elseif ($catalogSolo): ?>
                    <a class="button ghost" href="sharepoint.php?source=<?= e($activeSourceKey) ?>">← SharePoint</a>
                <?php else: ?>
                    <a class="button ghost-light" id="sp-catalog-open-tab" href="<?= e((string) ($catalogDashUrl ?? 'sharepoint.php?view=catalog')) ?>" target="_blank" rel="noopener noreferrer" title="Open this catalog in a new browser tab">↗ New tab</a>
                    <button type="button" class="button ghost" id="sp-catalog-open-window" title="Open this catalog in a separate window">🗗 Window</button>
                    <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
                <?php endif; ?>
            </div>
        </summary>
        <div class="sharepoint-catalog-body">
            <?php if (count($allSources) > 1): ?>
                <div class="sharepoint-search-scopes" id="sharepoint-search-scopes" role="group" aria-label="Catalogs to search">
                    <div class="sharepoint-search-scopes-head">
                        <span class="sharepoint-search-scopes-label">Search in</span>
                        <button type="button" class="button ghost sharepoint-scopes-all" id="sharepoint-scopes-all">All catalogs</button>
                        <button type="button" class="button ghost sharepoint-scopes-active" id="sharepoint-scopes-active">This catalog only</button>
                        <button type="button" class="button ghost sharepoint-scopes-colors is-active" id="sharepoint-scopes-colors" aria-pressed="true" title="Color each catalog differently so Public, Private, and other folders are easier to tell apart">🎨 Distinct colors</button>
                        <button type="button" class="button ghost sharepoint-scopes-color-reset" id="sharepoint-scopes-color-reset" hidden>Reset colors</button>
                    </div>
                    <div class="sharepoint-search-scopes-list">
                        <?php foreach ($allSources as $src): ?>
                            <?php
                            $srcKey = (string) ($src['source_key'] ?? '');
                            $srcTitle = (string) ($src['title'] ?? $srcKey);
                            $srcTone = (string) ($catalogTones[$srcKey] ?? 'slate');
                            $srcHex = (string) ($catalogToneHex[$srcTone] ?? '#475569');
                            $checked = $srcKey === $activeSourceKey;
                            ?>
                            <div class="sharepoint-scope-chip<?= $checked ? ' is-active' : '' ?><?= !empty($archivedSourceKeys[$srcKey]) ? ' is-archived' : '' ?>" data-catalog-tone="<?= e($srcTone) ?>" data-source-key="<?= e($srcKey) ?>"<?= !empty($archivedSourceKeys[$srcKey]) ? ' data-archived="1"' : '' ?>>
                                <label class="sharepoint-scope-chip-main">
                                    <input type="checkbox" class="sharepoint-scope-check" value="<?= e($srcKey) ?>"<?= $checked ? ' checked' : '' ?>>
                                    <span><?= e($srcTitle) ?></span>
                                    <span class="sharepoint-scope-hit-count" hidden aria-hidden="true"></span>
                                </label>
                                <button type="button" class="sharepoint-scope-color-btn" data-source-key="<?= e($srcKey) ?>" title="Choose color for <?= e($srcTitle) ?>" aria-label="Choose color for <?= e($srcTitle) ?>" aria-haspopup="dialog" aria-expanded="false" style="--catalog-tone: <?= e($srcHex) ?>"></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$searchCardPublic): ?>
                        <p class="panel-help sharepoint-scopes-hint">Select more than one catalog to compare — results show where a project is found and where it is missing.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="get" class="search-form sharepoint-live-search-form" action="<?= e($searchFormAction) ?>" id="sharepoint-search-form" role="search">
                <?php if ($searchCardPublic): ?>
                    <input type="hidden" name="t" value="<?= e($searchShareToken) ?>">
                <?php endif; ?>
                <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                <?php if ($catalogSolo && !$searchCardPublic): ?>
                    <input type="hidden" name="view" value="catalog">
                <?php endif; ?>

                <div class="sharepoint-search-find-row">
                    <div class="sharepoint-search-find-stack">
                        <div class="search-wrap search-wrap-wide sharepoint-search-main">
                            <span aria-hidden="true">🔎 Find</span>
                            <input type="search" name="q" id="sharepoint-search-input" value="<?= e($query) ?>"
                                   placeholder='Try: encore · tag:priority · ext:pdf · person:"Last, First" · -exclude'
                                   autocomplete="off"
                                   <?= $searchCardPublic ? '' : 'autofocus ' ?>
                                   aria-label="Search SharePoint catalog"
                                   title="Live search. Tips: tag:name · ext:pdf · type:visio · person:name · path:drawings · has:pdf · &quot;exact phrase&quot; · -exclude · Press / to focus"
                                   aria-autocomplete="list"
                                   aria-controls="sharepoint-search-suggest"
                                   aria-expanded="false">
                            <button type="button" class="sharepoint-search-clear<?= $query === '' ? ' is-hidden' : '' ?>" id="sharepoint-search-clear" title="Clear search (Esc)" aria-label="Clear search">×</button>
                            <noscript>
                                <button type="submit" class="button button-primary">Search</button>
                            </noscript>
                        </div>
                        <div class="sharepoint-search-suggest is-hidden" id="sharepoint-search-suggest" role="listbox" hidden aria-label="Search suggestions"></div>
                    </div>
                    <div class="sharepoint-search-find-toggles" role="group" aria-label="Search match options">
                        <button type="button" class="sp-search-toggle sp-search-suggest-toggle" id="sharepoint-suggest-toggle" title="Suggestions — show the search dropdown with project, file, people, and operator hints while typing" aria-pressed="false">▾ Suggest</button>
                        <button type="button" class="sp-search-toggle sp-search-fuzzy" id="sharepoint-fuzzy-toggle" title="Fuzzy — tolerate typos and similar-sounding words (e.g. Encore ≈ Encor)" aria-pressed="false">✨ Fuzzy</button>
                        <button type="button" class="sp-search-toggle sp-search-deep is-active" id="sharepoint-deep-toggle" title="Deep files — also search nested file and folder names/paths inside each project (not file contents)" aria-pressed="true">📂 Deep files</button>
                        <?php if (!empty($isAdmin) && empty($searchCardPublic)): ?>
                            <button type="button" class="sp-search-toggle sp-search-archived" id="sharepoint-archived-toggle" title="Show archived — include catalogs, projects, and files you hid from the dashboard so you can restore them" aria-pressed="false">📦 Show archived</button>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="sp-search-advanced-toggle" id="sharepoint-advanced-toggle" aria-expanded="false" aria-controls="sharepoint-search-advanced" title="Show date, person, presence, contains/lacks, and save/export options">
                        <span class="sp-adv-toggle-label">Advanced</span>
                        <span class="sp-adv-toggle-arrow" aria-hidden="true">▾</span>
                    </button>
                </div>

                <div class="sharepoint-search-controls" id="sharepoint-search-controls" hidden>
                    <div class="sp-search-cluster sp-search-cluster--mode" id="sharepoint-match-cluster" role="group" aria-label="Match style" hidden>
                        <span class="sp-search-cluster-label" title="How words are matched">⚙️ Match</span>
                        <div class="sp-search-toggle-group" role="group" aria-label="Match spaced words with AND or OR" id="sharepoint-word-mode" hidden>
                            <button type="button" class="sp-search-toggle is-active" data-word-mode="and" title="AND — every word must appear somewhere in the project" aria-pressed="true">AND</button>
                            <button type="button" class="sp-search-toggle" data-word-mode="or" title="OR — match if any word appears" aria-pressed="false">OR</button>
                        </div>
                    </div>

                    <div class="sp-search-cluster sp-search-cluster--types" role="group" aria-label="File type filters">
                        <span class="sp-search-cluster-label" title="Keep projects that include these kinds of files">📎 Types</span>
                        <div class="sharepoint-type-chips" id="sharepoint-type-chips" role="group" aria-label="File type filters">
                            <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--pdf" data-type-chip="pdf" title="Has at least one PDF file" aria-pressed="false">📕 PDF</button>
                            <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--visio" data-type-chip="visio" title="Has Visio diagrams (.vsdx / .vsd)" aria-pressed="false">📐 Visio</button>
                            <button type="button" class="sp-dialog-chip sp-type-chip sp-type-chip--folders" data-type-chip="folders" title="Has nested subfolders" aria-pressed="false">📂 Folders</button>
                        </div>
                    </div>

                    <div class="sp-search-cluster sp-search-cluster--refine">
                        <span class="sp-search-cluster-label" title="Second pass: keep only results that also match this text">🔍 Refine</span>
                        <div class="sp-refine-wrap">
                            <input type="search" id="sharepoint-refine-input" placeholder="Narrow current results…" autocomplete="off" aria-label="Search within current results" title="Refine — search again inside the projects already matched (same operators work here)">
                            <button type="button" class="sp-refine-clear is-hidden" id="sharepoint-refine-clear" title="Clear refine filter" aria-label="Clear refine search">✕</button>
                        </div>
                    </div>
                </div>

                <div class="sharepoint-search-advanced is-collapsed" id="sharepoint-search-advanced" hidden>
                    <div class="sp-search-cluster sp-search-cluster--scope" role="group" aria-label="Where to search">
                        <span class="sp-search-cluster-label" title="Limit which fields the Find box searches">🎯 Scope</span>
                        <div class="sp-search-toggle-group sp-match-scope-group" role="group" aria-label="Match scope" id="sharepoint-match-scope">
                            <button type="button" class="sp-search-toggle is-active" data-match-scope="all" title="Search everywhere: project names, nested files, and people" aria-pressed="true">🌐 All</button>
                            <button type="button" class="sp-search-toggle" data-match-scope="names" title="Names only — project folder and catalog titles" aria-pressed="false">📁 Names</button>
                            <button type="button" class="sp-search-toggle" data-match-scope="files" title="Files only — nested file and folder names/paths (turns on deep search)" aria-pressed="false">📄 Files</button>
                            <button type="button" class="sp-search-toggle" data-match-scope="people" title="People only — Modified By and Created By" aria-pressed="false">👤 People</button>
                        </div>
                    </div>

                    <label class="sp-adv-field sp-adv-field--date" title="Filter by how recently the project was last modified">
                        <span>🕒 Modified</span>
                        <select id="sharepoint-date-preset" aria-label="Modified date range" title="Show projects modified in this time window">
                            <option value="">Any time</option>
                            <option value="7d">Last 7 days</option>
                            <option value="30d">Last 30 days</option>
                            <option value="year">This year</option>
                            <option value="custom">Custom…</option>
                        </select>
                    </label>
                    <label class="sp-adv-field sp-adv-date-custom is-hidden" id="sharepoint-date-custom-wrap" hidden title="Custom range start">
                        <span>From</span>
                        <input type="date" id="sharepoint-date-from" aria-label="Modified from date">
                    </label>
                    <label class="sp-adv-field sp-adv-date-custom is-hidden" id="sharepoint-date-custom-to-wrap" hidden title="Custom range end">
                        <span>To</span>
                        <input type="date" id="sharepoint-date-to" aria-label="Modified to date">
                    </label>
                    <label class="sp-adv-field sp-adv-field--person" title="Filter by Created By or Modified By">
                        <span>👤 Person</span>
                        <select id="sharepoint-person-filter" aria-label="Filter by person" title="Keep projects where this person created or last modified the folder">
                            <option value="">Anyone</option>
                            <?php if ($userDisplay !== '' || $userName !== ''): ?>
                                <option value="me">Me (<?= e($userDisplay !== '' ? $userDisplay : $userName) ?>)</option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label class="sp-adv-field sp-adv-field--presence<?= count($allSources) < 2 ? ' is-hidden' : '' ?>" id="sharepoint-presence-wrap"<?= count($allSources) < 2 ? ' hidden' : '' ?> title="Compare where a project appears across selected catalogs">
                        <span>🗂️ Presence</span>
                        <select id="sharepoint-presence-filter" aria-label="Catalog presence filter" title="Any = in at least one selected catalog · All = in every selected catalog · Only = only in the active catalog · Missing = not in a chosen catalog">
                            <option value="any">Any selected catalog</option>
                            <option value="all">In all selected</option>
                            <option value="only">Only in this catalog</option>
                            <option value="missing">Missing from…</option>
                        </select>
                    </label>
                    <label class="sp-adv-field is-hidden" id="sharepoint-missing-wrap" hidden title="Which catalog should the project be missing from?">
                        <span>🚫 Missing from</span>
                        <select id="sharepoint-missing-source" aria-label="Catalog the project is missing from">
                            <?php foreach ($presenceOptions as $opt): ?>
                                <option value="<?= e($opt['key']) ?>"><?= e($opt['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="sp-adv-field sp-adv-field--has" title="Projects that include this content">
                        <span>✅ Contains</span>
                        <select id="sharepoint-has-filter" aria-label="Projects that contain" title="Keep projects that have this (PDF, Visio, empty, or stale)">
                            <option value="">—</option>
                            <option value="pdf">📕 PDF</option>
                            <option value="visio">📐 Visio</option>
                            <option value="empty">📭 Empty (no files)</option>
                            <option value="stale">⏳ Stale (90+ days)</option>
                        </select>
                    </label>
                    <label class="sp-adv-field sp-adv-field--lacks" title="Projects that are missing this content">
                        <span>⛔ Lacks</span>
                        <select id="sharepoint-lacks-filter" aria-label="Projects that lack" title="Keep projects that do not have this (useful for incomplete packages)">
                            <option value="">—</option>
                            <option value="pdf">📕 PDF</option>
                            <option value="visio">📐 Visio</option>
                            <option value="empty">📭 Empty (no files)</option>
                            <option value="stale">⏳ Stale (90+ days)</option>
                        </select>
                    </label>
                    <div class="sp-adv-actions">
                        <button type="button" class="button ghost sp-adv-save" id="sharepoint-save-search" title="Pin this query + filters so you can reopen it later from Saved">📌 Save search</button>
                        <button type="button" class="button ghost sp-adv-export" id="sharepoint-export-csv" title="Download all matching projects as a CSV (full filtered set, not just this page)">⬇️ Export CSV</button>
                    </div>
                </div>
            </form>

            <div class="sharepoint-saved-searches is-hidden" id="sharepoint-saved-searches" hidden>
                <span class="sharepoint-recent-label" title="Pinned searches you saved on this browser">📌 Saved</span>
                <div class="sharepoint-recent-chips" id="sharepoint-saved-chips" role="list" aria-label="Saved searches"></div>
            </div>
            <div class="sharepoint-recent-searches is-hidden" id="sharepoint-recent-searches" hidden>
                <span class="sharepoint-recent-label" title="Recent Find queries on this browser">🕘 Recent</span>
                <div class="sharepoint-recent-chips" id="sharepoint-recent-chips" role="list" aria-label="Recent searches"></div>
                <button type="button" class="sharepoint-recent-clear" id="sharepoint-recent-clear" hidden title="Clear recent search history">Clear</button>
            </div>
            <div class="sharepoint-search-stats is-hidden" id="sharepoint-search-stats" aria-live="polite"></div>
            <p class="panel-help sharepoint-catalog-meta" id="sharepoint-catalog-meta">
                <?= (int) $metaProjectCount ?> project<?= $metaProjectCount === 1 ? '' : 's' ?>
                <?php if ($query !== '' && !$searchCardPublic): ?> matched<?php endif; ?>
                · <?= (int) $itemCount ?> catalog item<?= $itemCount === 1 ? '' : 's' ?> total
                <?php if ($activeLastSynced !== '' && !$searchCardPublic): ?>
                    · Last update <?= e($activeLastSynced) ?>
                    (<?= e($activeLastStatus !== '' ? $activeLastStatus : 'unknown') ?>)
                <?php endif; ?>
            </p>
        </div>
    </details>
</section>
