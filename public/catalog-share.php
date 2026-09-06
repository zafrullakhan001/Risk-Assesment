<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\PaginationPreference;
use RiskAssessment\Repositories\CatalogShareRepository;
use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\Repositories\SharePointSearchTagRepository;
use RiskAssessment\Repositories\SharePointSourceRepository;

header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$token = trim((string) ($_GET['t'] ?? $_GET['token'] ?? ''));
$shareRepository = new CatalogShareRepository($pdo);
$share = $shareRepository->findActiveByToken($token);

if ($share === null || ($share['kind'] ?? CatalogShareRepository::KIND_CATALOG) !== CatalogShareRepository::KIND_CATALOG) {
    http_response_code(404);
    $shareUnavailableMessage = 'This public catalog link is invalid, expired, or has been revoked.';
    require __DIR__ . '/includes/share-unavailable.php';
    exit;
}

$catalog = new SharePointCatalogRepository($pdo);
$sourcesRepo = new SharePointSourceRepository($pdo);
$searchTags = new SharePointSearchTagRepository($pdo);
$allRegistered = $sourcesRepo->listAll();
$allSources = $shareRepository->filterSources($allRegistered, $share['source_keys']);
if ($allSources === []) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No catalogs are available on this share link.';
    exit;
}

$allowedKeys = [];
foreach ($allSources as $src) {
    $key = (string) ($src['source_key'] ?? '');
    if ($key !== '') {
        $allowedKeys[$key] = true;
    }
}

$actionParam = (string) ($_GET['action'] ?? '');
$requestedSourceKey = trim((string) ($_GET['source'] ?? ''));
$activeSource = $requestedSourceKey !== '' && isset($allowedKeys[$requestedSourceKey])
    ? ($sourcesRepo->findByKey($requestedSourceKey) ?? null)
    : null;
if ($activeSource === null) {
    $activeSource = $allSources[0];
}
$activeSourceKey = (string) ($activeSource['source_key'] ?? '');

$resolveIndexSources = static function (string $sourcesParam, string $fallbackKey) use ($allSources, $allowedKeys): array {
    $indexSources = [];
    if ($sourcesParam === 'all' || $sourcesParam === '') {
        foreach ($allSources as $src) {
            $key = (string) ($src['source_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $indexSources[] = [
                'source_key' => $key,
                'title' => (string) ($src['title'] ?? $key),
            ];
        }

        return $indexSources;
    }

    $wanted = array_values(array_filter(array_map('trim', explode(',', $sourcesParam))));
    $byKey = [];
    foreach ($allSources as $src) {
        $byKey[(string) ($src['source_key'] ?? '')] = $src;
    }
    foreach ($wanted as $key) {
        if (!isset($allowedKeys[$key], $byKey[$key])) {
            continue;
        }
        $indexSources[] = [
            'source_key' => $key,
            'title' => (string) ($byKey[$key]['title'] ?? $key),
        ];
    }
    if ($indexSources === [] && $fallbackKey !== '' && isset($byKey[$fallbackKey])) {
        $indexSources[] = [
            'source_key' => $fallbackKey,
            'title' => (string) ($byKey[$fallbackKey]['title'] ?? $fallbackKey),
        ];
    }

    return $indexSources;
};

if ($actionParam === 'project_detail') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $detailSourceKey = trim((string) ($_GET['source'] ?? $activeSourceKey));
    if ($detailSourceKey === '' || !isset($allowedKeys[$detailSourceKey])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Project not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $name = trim((string) ($_GET['name'] ?? ''));
    $detail = $catalog->getProject($name, $detailSourceKey);
    if ($detail === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Project not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $detailSource = $sourcesRepo->findByKey($detailSourceKey);
    $detail['source_key'] = (string) ($detailSource['source_key'] ?? $detailSourceKey);
    $detail['source_title'] = (string) ($detailSource['title'] ?? $detail['source_key']);
    $detail = $searchTags->attachTagsToProjectDetail(
        $detail,
        $detail['source_key'],
        $searchTags->listProjectTags($detail['source_key'], (string) ($detail['project_name'] ?? '')),
        $searchTags->mapItemTagsForSources([$detail['source_key']])
    );
    echo json_encode([
        'ok' => true,
        'project' => $detail,
        'can_edit_tags' => false,
        'all_tags' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($actionParam === 'search_index') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');

    $indexSources = $resolveIndexSources(
        trim((string) ($_GET['sources'] ?? '')),
        trim((string) ($_GET['source'] ?? $activeSourceKey))
    );
    if ($indexSources === []) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No catalogs available.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $index = $catalog->listSearchIndexForSources($indexSources);
    $indexSourceKeys = array_values(array_filter(array_map(
        static fn (array $src): string => (string) ($src['source_key'] ?? ''),
        $indexSources
    )));
    $index = $searchTags->attachTagsToSearchIndex(
        $index,
        $searchTags->mapProjectTagsForSources($indexSourceKeys),
        $searchTags->mapItemTagsForSources($indexSourceKeys)
    );
    $itemCountTotal = 0;
    $metaSources = [];
    foreach ($indexSources as $srcMeta) {
        $key = (string) $srcMeta['source_key'];
        $row = $sourcesRepo->findByKey($key);
        $count = $catalog->count($key);
        $itemCountTotal += $count;
        $metaSources[] = [
            'source_key' => $key,
            'title' => (string) $srcMeta['title'],
            'item_count' => $count,
            'last_synced_at' => (string) ($row['last_synced_at'] ?? ''),
            'last_sync_status' => (string) ($row['last_sync_status'] ?? ''),
        ];
    }
    $primary = $metaSources[0] ?? null;
    echo json_encode([
        'ok' => true,
        'source_key' => (string) ($primary['source_key'] ?? $activeSourceKey),
        'sources' => $metaSources,
        'item_count' => $itemCountTotal,
        'project_count' => count($index),
        'projects' => $index,
        'tags' => $searchTags->listAll(),
        'can_edit_tags' => false,
        'last_synced_at' => (string) ($primary['last_synced_at'] ?? ''),
        'last_sync_status' => (string) ($primary['last_sync_status'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
$allowedPerPage = [10, 25, 50, 100];
$perPage = PaginationPreference::resolve(
    PaginationPreference::KEY_SHAREPOINT,
    isset($_GET['per']) ? (int) $_GET['per'] : null,
    25,
    $allowedPerPage
);

$sourceCounts = [];
$itemCount = 0;
$projectCount = 0;
foreach ($allSources as $src) {
    $key = (string) ($src['source_key'] ?? '');
    $count = $catalog->count($key);
    $sourceCounts[$key] = $count;
    $itemCount += $count;
    $projectCount += $catalog->countProjects($key);
}

$catalogTones = [];
$catalogToneExtra = 0;
$catalogToneExtras = ['violet', 'sky', 'lime', 'slate'];
foreach ($allSources as $src) {
    $key = (string) ($src['source_key'] ?? '');
    $hay = strtolower($key . ' ' . (string) ($src['title'] ?? ''));
    if (str_contains($hay, 'private')) {
        $catalogTones[$key] = 'private';
    } elseif (str_contains($hay, 'public')) {
        $catalogTones[$key] = 'public';
    } elseif (preg_match('/\b(tprm|dump|legacy)\b/', $hay) === 1) {
        $catalogTones[$key] = 'dump';
    } else {
        $catalogTones[$key] = $catalogToneExtras[$catalogToneExtra % count($catalogToneExtras)];
        $catalogToneExtra++;
    }
}
$catalogToneHex = [
    'public' => '#0f766e',
    'private' => '#e11d48',
    'dump' => '#d97706',
    'violet' => '#7c3aed',
    'sky' => '#0284c7',
    'lime' => '#65a30d',
    'slate' => '#475569',
];
$catalogColorPresets = [
    '#0f766e' => 'Teal',
    '#e11d48' => 'Rose',
    '#d97706' => 'Amber',
    '#7c3aed' => 'Violet',
    '#0284c7' => 'Sky',
    '#65a30d' => 'Lime',
    '#2563eb' => 'Blue',
    '#db2777' => 'Pink',
    '#4f46e5' => 'Indigo',
    '#0891b2' => 'Cyan',
    '#c2410c' => 'Rust',
    '#475569' => 'Slate',
];

$activeTitle = (string) ($activeSource['title'] ?? 'SharePoint catalog');
$activeLastSynced = (string) ($activeSource['last_synced_at'] ?? '');
$activeLastStatus = (string) ($activeSource['last_sync_status'] ?? '');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Catalog · <?= e($branding->documentTitle()) ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <script>
    (function () {
        try {
            var on = localStorage.getItem('riskregister_sp_catalog_colors') !== '0';
            document.documentElement.setAttribute('data-catalog-colors', on ? 'distinct' : 'uniform');
        } catch (e) {
            document.documentElement.setAttribute('data-catalog-colors', 'distinct');
        }
    })();
    </script>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body class="is-public-catalog-share">
    <div class="shell upload-page sharepoint-catalog-page sharepoint-catalog-public-page">
        <header class="topbar topbar-uplift">
            <div class="brand brand-static">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1>📁 SharePoint catalogs</h1>
                </div>
            </div>
            <div class="topbar-actions">
                <span class="share-readonly-pill" title="Public catalog cards only — sign-in is not required">🔓 Public catalog</span>
                <?php require __DIR__ . '/includes/theme-controls.php'; ?>
                <div class="updated template-count-chip"><?= (int) $projectCount ?> project<?= $projectCount === 1 ? '' : 's' ?></div>
                <a class="button ghost home-link" href="login.php">Sign in</a>
            </div>
        </header>

        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">Shared catalog cards</div>
                            <h2 id="sharepoint-hero-title"><?= e($activeTitle) ?></h2>
                            <p>Browse project folders from the shared catalogs. This page is read-only and does not require a RiskRegister account.</p>
                        </div>
                        <?php require __DIR__ . '/includes/hero-medallion.php'; renderHeroMedallion((int) $itemCount, 'catalog items'); ?>
                    </div>
                </div>
            </section>

            <section class="upload-card sharepoint-sources-panel" id="sharepoint-sources" data-folders-view="compact" data-solo="0" data-public="1">
                <details class="sharepoint-sources-shell" id="sharepoint-sources-shell" open>
                    <summary class="card-heading sharepoint-sources-heading sharepoint-sources-summary">
                        <div>
                            <h2>📁 Catalog cards</h2>
                            <p class="panel-help">Open a catalog card to search its project folders. Links to SharePoint still require your Microsoft account.</p>
                        </div>
                        <div class="sharepoint-sources-summary-tools" data-no-toggle onclick="event.stopPropagation()">
                            <div class="sp-view-toggle sharepoint-folders-view-toggle" role="group" aria-label="Folder layout">
                                <button type="button" class="sp-view-btn" data-folders-view="comfort" aria-pressed="false" title="Roomier folder cards">Comfort</button>
                                <button type="button" class="sp-view-btn is-active" data-folders-view="compact" aria-pressed="true" title="Compact folder cards">Compact</button>
                                <button type="button" class="sp-view-btn" data-folders-view="table" aria-pressed="false" title="Table view">Table</button>
                            </div>
                            <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
                        </div>
                    </summary>
                    <div class="sharepoint-sources-body">
                        <div class="sharepoint-sources-grid" id="sharepoint-sources-grid">
                            <?php foreach ($allSources as $src): ?>
                                <?php
                                $srcKey = (string) ($src['source_key'] ?? '');
                                $isActiveCard = $srcKey === $activeSourceKey;
                                $srcCount = (int) ($sourceCounts[$srcKey] ?? 0);
                                $srcSynced = (string) ($src['last_synced_at'] ?? '');
                                $srcStatus = (string) ($src['last_sync_status'] ?? '');
                                $srcUrl = (string) ($src['folder_url'] ?? '');
                                $srcTitle = (string) ($src['title'] ?? $srcKey);
                                $srcFolderPath = (string) ($src['folder_path'] ?? '');
                                $srcSite = (string) (($src['site_host'] ?? '') . ($src['site_path'] ?? ''));
                                ?>
                                <article class="sharepoint-source-card<?= $isActiveCard ? ' is-active' : '' ?>" data-source-key="<?= e($srcKey) ?>">
                                    <div class="sharepoint-source-card-head">
                                        <h3>
                                            <span class="sp-card-emoji" data-tone="folder" aria-hidden="true">📂</span>
                                            <span class="sharepoint-source-card-title"><?= e($srcTitle) ?></span>
                                        </h3>
                                        <?php if ($isActiveCard): ?>
                                            <span class="sharepoint-source-badge">Active</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="sharepoint-source-meta">
                                        <span><?= e($srcFolderPath) ?></span>
                                        <span><?= e($srcSite) ?></span>
                                    </p>
                                    <p class="sharepoint-source-stats">
                                        <?= $srcCount ?> item<?= $srcCount === 1 ? '' : 's' ?>
                                        <?php if ($srcSynced !== ''): ?>
                                            · Last sync <?= e($srcSynced) ?>
                                            <?php if ($srcStatus !== ''): ?>(<?= e($srcStatus) ?>)<?php endif; ?>
                                        <?php else: ?>
                                            · Not synced yet
                                        <?php endif; ?>
                                    </p>
                                    <div class="sharepoint-source-actions">
                                        <button type="button" class="button ghost-light sharepoint-open-catalog" data-source-key="<?= e($srcKey) ?>">
                                            <span class="sp-card-emoji" data-tone="folder" aria-hidden="true">📂</span> Open catalog
                                        </button>
                                        <?php if ($srcUrl !== ''): ?>
                                            <a class="button ghost" href="<?= e($srcUrl) ?>" target="_blank" rel="noopener noreferrer">
                                                <span class="sp-card-emoji" data-tone="link" aria-hidden="true">🔗</span> Open in SharePoint
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <div class="sharepoint-sources-table-wrap" id="sharepoint-sources-table-wrap" hidden>
                            <table class="sharepoint-sources-table" aria-label="SharePoint catalogs">
                                <thead>
                                    <tr>
                                        <th scope="col">Catalog</th>
                                        <th scope="col">Path</th>
                                        <th scope="col">Items</th>
                                        <th scope="col">Last sync</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allSources as $src): ?>
                                        <?php
                                        $srcKey = (string) ($src['source_key'] ?? '');
                                        $isActiveCard = $srcKey === $activeSourceKey;
                                        $srcCount = (int) ($sourceCounts[$srcKey] ?? 0);
                                        $srcSynced = (string) ($src['last_synced_at'] ?? '');
                                        $srcStatus = (string) ($src['last_sync_status'] ?? '');
                                        $srcUrl = (string) ($src['folder_url'] ?? '');
                                        $srcTitle = (string) ($src['title'] ?? $srcKey);
                                        $srcFolderPath = (string) ($src['folder_path'] ?? '');
                                        $srcSite = (string) (($src['site_host'] ?? '') . ($src['site_path'] ?? ''));
                                        ?>
                                        <tr class="sharepoint-source-row<?= $isActiveCard ? ' is-active' : '' ?>" data-source-key="<?= e($srcKey) ?>">
                                            <td>
                                                <div class="sharepoint-source-table-title">
                                                    <span class="sp-card-emoji" data-tone="folder" aria-hidden="true">📂</span>
                                                    <strong><?= e($srcTitle) ?></strong>
                                                    <?php if ($isActiveCard): ?>
                                                        <span class="sharepoint-source-badge">Active</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($srcSite !== ''): ?>
                                                    <div class="sharepoint-source-table-site"><?= e($srcSite) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="sharepoint-source-table-path"><?= e($srcFolderPath !== '' ? $srcFolderPath : '—') ?></td>
                                            <td><?= $srcCount ?></td>
                                            <td>
                                                <?php if ($srcSynced !== ''): ?>
                                                    <?= e($srcSynced) ?>
                                                    <?php if ($srcStatus !== ''): ?>
                                                        <span class="sharepoint-source-table-status">(<?= e($srcStatus) ?>)</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="sharepoint-source-table-muted">Not synced</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="sharepoint-source-actions sharepoint-source-actions--table">
                                                    <button type="button" class="button ghost-light sharepoint-open-catalog" data-source-key="<?= e($srcKey) ?>">
                                                        <span class="sp-card-emoji" data-tone="folder" aria-hidden="true">📂</span> Open
                                                    </button>
                                                    <?php if ($srcUrl !== ''): ?>
                                                        <a class="button ghost" href="<?= e($srcUrl) ?>" target="_blank" rel="noopener noreferrer">
                                                            <span class="sp-card-emoji" data-tone="link" aria-hidden="true">🔗</span> SP
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            </section>

            <?php
            $searchCardPublic = true;
            $catalogSolo = false;
            $searchFormAction = 'catalog-share.php';
            $searchShareToken = $token;
            $metaProjectCount = $projectCount;
            require __DIR__ . '/includes/sharepoint-search-card.php';
            ?>

            <section class="upload-card sharepoint-table-card is-compact-rows" aria-label="SharePoint project table" id="sharepoint-table-card" data-density="compact">
                <details class="sharepoint-catalog-table-shell" id="sharepoint-catalog-table-shell" open>
                    <summary class="sharepoint-table-toolbar sharepoint-catalog-table-summary">
                        <span class="result-count" id="sharepoint-result-count">Loading catalog…</span>
                        <div class="sharepoint-table-toolbar-tools" data-no-toggle onclick="event.stopPropagation()">
                            <div class="sp-view-toggle" role="group" aria-label="Row density">
                                <button type="button" class="sp-view-btn" data-list-density="comfort" title="Taller rows" aria-pressed="false">Comfort</button>
                                <button type="button" class="sp-view-btn is-active" data-list-density="compact" title="Compact rows" aria-pressed="true">Compact</button>
                            </div>
                            <button type="button" class="sp-view-btn is-active" id="sharepoint-filters-toggle" title="Show or hide column filters" aria-controls="sharepoint-table-filters" aria-pressed="true">Filters</button>
                            <div class="sharepoint-compare-bar" id="sharepoint-compare-bar">
                                <span class="sharepoint-compare-hint" id="sharepoint-compare-hint">Select 2–3 folders to compare side by side</span>
                                <button type="button" class="button button-primary" id="sharepoint-compare-open" disabled>⚖️ Compare selected</button>
                                <button type="button" class="button ghost" id="sharepoint-compare-clear" hidden>Clear selection</button>
                            </div>
                            <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
                        </div>
                    </summary>
                    <div class="sharepoint-catalog-table-body">
                        <div class="table-wrap sharepoint-projects-wrap">
                            <table class="sharepoint-projects-table" id="sharepoint-projects-table">
                                <thead>
                                    <tr>
                                        <th scope="col" class="sharepoint-select-col" data-col="select"><span class="visually-hidden">Select</span></th>
                                        <th scope="col" class="is-sortable is-sorted-asc" data-sort="name" data-col="name" aria-sort="ascending">
                                            <button type="button" class="sp-dialog-sort-btn" data-sort="name" title="Sort by project name">📂 Project</button>
                                        </th>
                                        <th scope="col" class="is-sortable" data-sort="match" data-col="match" aria-sort="none">
                                            <button type="button" class="sp-dialog-sort-btn" data-sort="match" title="Sort by match, type, or catalog">🎯 Match</button>
                                        </th>
                                        <th scope="col" class="is-sortable" data-sort="items" data-col="items" aria-sort="none">
                                            <button type="button" class="sp-dialog-sort-btn" data-sort="items" title="Sort by folder and file count">📦 Items</button>
                                        </th>
                                        <th scope="col" class="is-sortable" data-sort="modified" data-col="modified" aria-sort="none">
                                            <button type="button" class="sp-dialog-sort-btn" data-sort="modified" title="Sort by modified date">🕒 Modified</button>
                                        </th>
                                        <th scope="col" class="is-sortable" data-sort="modified_by" data-col="modified_by" aria-sort="none">
                                            <button type="button" class="sp-dialog-sort-btn" data-sort="modified_by" title="Sort by who last modified">👤 Modified By</button>
                                        </th>
                                        <th scope="col" class="is-sortable" data-sort="created_by" data-col="created_by" aria-sort="none">
                                            <button type="button" class="sp-dialog-sort-btn" data-sort="created_by" title="Sort by who created">🙋 Created By</button>
                                        </th>
                                        <th scope="col" data-col="actions"><span class="visually-hidden">Actions</span></th>
                                    </tr>
                                    <tr class="sharepoint-table-filters" id="sharepoint-table-filters">
                                        <th scope="col" class="sharepoint-select-col" data-col="select"></th>
                                        <th scope="col" data-col="name"><input type="search" class="sharepoint-col-filter" data-filter="name" placeholder="Filter project…" autocomplete="off" aria-label="Filter by project name"></th>
                                        <th scope="col" data-col="match"><input type="search" class="sharepoint-col-filter" data-filter="match" placeholder="Type / catalog…" autocomplete="off" aria-label="Filter by match, type, or catalog"></th>
                                        <th scope="col" data-col="items"><input type="search" class="sharepoint-col-filter" data-filter="items" placeholder="Count…" autocomplete="off" aria-label="Filter by item counts"></th>
                                        <th scope="col" data-col="modified"><input type="search" class="sharepoint-col-filter" data-filter="modified" placeholder="Date…" autocomplete="off" aria-label="Filter by modified date"></th>
                                        <th scope="col" data-col="modified_by"><input type="search" class="sharepoint-col-filter" data-filter="modified_by" placeholder="Name…" autocomplete="off" aria-label="Filter by modified by"></th>
                                        <th scope="col" data-col="created_by"><input type="search" class="sharepoint-col-filter" data-filter="created_by" placeholder="Name…" autocomplete="off" aria-label="Filter by created by"></th>
                                        <th scope="col" class="sharepoint-filter-actions" data-col="actions">
                                            <button type="button" class="button ghost sharepoint-filters-clear is-hidden" id="sharepoint-filters-clear" title="Clear column filters">Clear</button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="sharepoint-projects-tbody">
                                    <tr class="sharepoint-empty-row"><td colspan="8">⏳ Loading catalog…</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <nav class="pagination" aria-label="SharePoint project pages" id="sharepoint-pagination">
                            <div class="pagination-controls" id="sharepoint-pagination-controls"></div>
                            <form method="get" class="pagination-per-page" action="catalog-share.php" id="sharepoint-per-page-form">
                                <input type="hidden" name="t" value="<?= e($token) ?>">
                                <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                                <label>
                                    <span>Rows per page</span>
                                    <select name="per" id="sharepoint-per-page">
                                        <?php foreach ($allowedPerPage as $size): ?>
                                            <option value="<?= (int) $size ?>"<?= $perPage === $size ? ' selected' : '' ?>><?= (int) $size ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </form>
                        </nav>
                    </div>
                </details>
            </section>

            <?php require __DIR__ . '/includes/sharepoint-catalog-dialogs.php'; ?>
        </main>
        <div class="sharepoint-catalog-color-pop" id="sharepoint-catalog-color-pop" hidden role="dialog" aria-label="Choose catalog color">
            <p class="sharepoint-catalog-color-pop-kicker" id="sharepoint-catalog-color-pop-title">Catalog color</p>
            <div class="sharepoint-catalog-color-presets" role="list">
                <?php foreach ($catalogColorPresets as $hex => $name): ?>
                    <button type="button" class="sharepoint-catalog-color-preset" data-hex="<?= e($hex) ?>" title="<?= e($name) ?>" aria-label="<?= e($name) ?>" style="background: <?= e($hex) ?>"></button>
                <?php endforeach; ?>
            </div>
            <label class="sharepoint-catalog-color-custom">
                <span>Custom</span>
                <input type="color" id="sharepoint-catalog-color-native" value="#0f766e" aria-label="Custom catalog color">
            </label>
            <button type="button" class="sharepoint-catalog-color-reset-one" id="sharepoint-catalog-color-reset-one">Reset this catalog</button>
        </div>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="assets/vendor/qrcode-generator.js?v=<?= filemtime(__DIR__ . '/assets/vendor/qrcode-generator.js') ?>"></script>
    <script src="assets/js/fuzzy-search.js?v=<?= filemtime(__DIR__ . '/assets/js/fuzzy-search.js') ?>"></script>
    <script src="assets/js/sharepoint-catalog.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-catalog.js') ?>"></script>
</body>
</html>
