<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\Repositories\CatalogShareRepository;
use RiskAssessment\Repositories\SharePointSourceRepository;
use RiskAssessment\SharePoint\SharePointOwnerDashboard;

header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$token = trim((string) ($_GET['t'] ?? $_GET['token'] ?? ''));
$shareRepository = new CatalogShareRepository($pdo, $crypto);
$share = $shareRepository->findActiveByToken($token);

if ($share === null || ($share['kind'] ?? '') !== CatalogShareRepository::KIND_OWNERS) {
    http_response_code(404);
    $shareUnavailableMessage = 'This public project-owners link is invalid, expired, or has been revoked.';
    require __DIR__ . '/includes/share-unavailable.php';
    exit;
}

$sourcesRepo = new SharePointSourceRepository($pdo);
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

if ($actionParam === 'owner_stats') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=30');

    $sourcesParam = trim((string) ($_GET['sources'] ?? ''));
    $fallbackKey = trim((string) ($_GET['source'] ?? $activeSourceKey));
    $wantedKeys = [];
    // Missing sources = the checked catalog only. Use sources=all or a key list for more.
    if ($sourcesParam === 'all') {
        $wantedKeys = array_keys($allowedKeys);
    } elseif ($sourcesParam !== '') {
        foreach (array_map('trim', explode(',', $sourcesParam)) as $key) {
            if ($key !== '' && isset($allowedKeys[$key])) {
                $wantedKeys[] = $key;
            }
        }
    }
    if ($wantedKeys === [] && $fallbackKey !== '' && isset($allowedKeys[$fallbackKey])) {
        $wantedKeys = [$fallbackKey];
    }

    $sourceTitles = [];
    $selectedKeys = [];
    foreach ($allSources as $src) {
        $key = (string) ($src['source_key'] ?? '');
        if ($key === '' || !in_array($key, $wantedKeys, true)) {
            continue;
        }
        $selectedKeys[] = $key;
        $sourceTitles[$key] = (string) ($src['title'] ?? $key);
    }
    if ($selectedKeys === []) {
        $selectedKeys = [$activeSourceKey];
        $sourceTitles[$activeSourceKey] = (string) ($activeSource['title'] ?? $activeSourceKey);
    }

    $dashboard = new SharePointOwnerDashboard($pdo);
    $payload = $dashboard->build($selectedKeys, $sourceTitles);
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

$sourcesJson = json_encode(array_map(static function (array $src) use ($catalogTones): array {
    $key = (string) ($src['source_key'] ?? '');

    return [
        'source_key' => $key,
        'title' => (string) ($src['title'] ?? $src['source_key'] ?? ''),
        'tone' => (string) ($catalogTones[$key] ?? 'slate'),
    ];
}, $allSources), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Project owners · <?= e($branding->documentTitle()) ?></title>
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
    <div class="shell upload-page sharepoint-catalog-page sharepoint-owner-solo-page sharepoint-catalog-public-page">
        <header class="topbar topbar-uplift">
            <div class="brand brand-static">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1>👤 Project owners</h1>
                </div>
            </div>
            <div class="topbar-actions">
                <span class="share-readonly-pill" title="Public project-owner cards only — sign-in is not required">🔓 Public owners</span>
                <?php require __DIR__ . '/includes/topbar-menu-start.php'; ?>
                <?php require __DIR__ . '/includes/topbar-menu-end.php'; ?>
            </div>
        </header>

        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">Shared owner cards</div>
                            <h2>Who created these project folders</h2>
                            <p>This page is read-only and does not require a RiskRegister account. Opening a folder in SharePoint still uses your Microsoft sign-in.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="upload-card sp-owner-dash"
                id="sharepoint-owner-dash"
                data-solo="1"
                data-public="1"
                data-api-base="owners-share.php"
                data-share-token="<?= e($token) ?>"
                data-active-source="<?= e($activeSourceKey) ?>"
                data-sources="<?= e((string) $sourcesJson) ?>"
            >
                <details class="sp-owner-dash-shell" id="sharepoint-owner-dash-shell" open>
                    <summary class="sp-owner-dash-summary">
                        <div class="sp-owner-dash-intro">
                            <div class="eyebrow">People over time</div>
                            <h2>👤 Project owners</h2>
                            <p>Who created the project folders in the shared catalogs — and how that work landed by month, quarter, and year.</p>
                        </div>
                    </summary>
                    <div class="sp-owner-dash-panel">
                        <div class="sp-owner-dash-toolbar">
                            <div class="sp-od-grain" role="group" aria-label="Time grouping">
                                <button type="button" class="sp-od-chip is-active" data-grain="month" aria-pressed="true">
                                    <span class="sp-od-chip-ico" aria-hidden="true">📅</span>
                                    <span>Months</span>
                                </button>
                                <button type="button" class="sp-od-chip" data-grain="quarter" aria-pressed="false">
                                    <span class="sp-od-chip-ico" aria-hidden="true">📊</span>
                                    <span>Quarters</span>
                                </button>
                                <button type="button" class="sp-od-chip" data-grain="year" aria-pressed="false">
                                    <span class="sp-od-chip-ico" aria-hidden="true">📆</span>
                                    <span>Years</span>
                                </button>
                            </div>
                            <label class="sp-od-year-label">
                                <span>Year</span>
                                <select id="sp-owner-year" aria-label="Filter by year">
                                    <option value="all">All years</option>
                                </select>
                            </label>
                            <label class="sp-od-search sp-od-search-toolbar">
                                <span class="visually-hidden">Filter people or project names</span>
                                <span class="sp-od-search-ico" aria-hidden="true">🔍</span>
                                <input type="search" id="sp-owner-query" placeholder="Filter people or projects…" autocomplete="off">
                            </label>
                        </div>
                        <div class="sp-owner-dash-toolbar sp-owner-dash-toolbar-extra">
                            <label class="sp-od-year-label">
                                <span>Sort</span>
                                <select id="sp-owner-sort" aria-label="Sort owners">
                                    <option value="projects">Most folders</option>
                                    <option value="this_year">This year</option>
                                    <option value="last_12">Last 12 months</option>
                                    <option value="activity">Last activity</option>
                                    <option value="first">Newest owners</option>
                                    <option value="streak">Longest streak</option>
                                    <option value="items">Most items</option>
                                </select>
                            </label>
                            <div class="sp-od-chips" id="sp-od-chips" role="group" aria-label="Owner filters">
                                <button type="button" class="sp-od-filter is-active" data-chip="" aria-pressed="true">All</button>
                                <button type="button" class="sp-od-filter" data-chip="this_year" aria-pressed="false">This year</button>
                                <button type="button" class="sp-od-filter" data-chip="active" aria-pressed="false">Touched this quarter</button>
                                <button type="button" class="sp-od-filter" data-chip="quiet" aria-pressed="false">Quiet 12+ months</button>
                                <button type="button" class="sp-od-filter" data-chip="unassigned" aria-pressed="false">Unassigned</button>
                                <button type="button" class="sp-od-filter" data-chip="undated" aria-pressed="false">Undated</button>
                                <button type="button" class="sp-od-filter" data-chip="assessments" aria-pressed="false">Has assessment</button>
                            </div>
                            <div class="sp-od-actions">
                                <button type="button" class="button ghost" id="sp-owner-export" title="Download the current owner view as CSV">⬇ CSV</button>
                                <button type="button" class="button ghost" id="sp-owner-print" title="Print or save a snapshot">🖨 Print</button>
                                <button type="button" class="button ghost" id="sp-owner-compare" disabled title="Select 2–3 owners in the leaderboard">⚖️ Compare</button>
                            </div>
                        </div>
                        <?php if (count($allSources) > 0): ?>
                            <div class="sp-od-scopes" id="sp-owner-scopes" role="group" aria-label="Catalogs for owner stats">
                                <div class="sp-od-scopes-head">
                                    <span class="sp-od-scopes-label">
                                        <span aria-hidden="true">📁</span>
                                        Folders
                                        <b class="sp-od-scopes-count" id="sp-owner-scopes-count"><?= count($allSources) > 0 ? '1 of ' . count($allSources) : '0' ?></b>
                                    </span>
                                    <button type="button" class="sp-od-scopes-all" id="sp-owner-scopes-all">Select all</button>
                                    <button type="button" class="sp-od-scopes-active is-active" id="sp-owner-scopes-active" disabled>This catalog only</button>
                                </div>
                                <div class="sp-od-scopes-list">
                                    <?php foreach ($allSources as $src): ?>
                                        <?php
                                        $srcKey = (string) ($src['source_key'] ?? '');
                                        $srcTitle = (string) ($src['title'] ?? $srcKey);
                                        $srcTone = (string) ($catalogTones[$srcKey] ?? 'slate');
                                        $srcHex = (string) ($catalogToneHex[$srcTone] ?? '#475569');
                                        $srcChecked = $srcKey === $activeSourceKey;
                                        ?>
                                        <div class="sharepoint-scope-chip<?= $srcChecked ? ' is-active' : '' ?>" data-catalog-tone="<?= e($srcTone) ?>" data-source-key="<?= e($srcKey) ?>">
                                            <label class="sharepoint-scope-chip-main">
                                                <input type="checkbox" class="sp-owner-scope-check" value="<?= e($srcKey) ?>"<?= $srcChecked ? ' checked' : '' ?>>
                                                <span><?= e($srcTitle) ?></span>
                                            </label>
                                            <span class="sharepoint-scope-color-btn" data-source-key="<?= e($srcKey) ?>" style="--catalog-tone: <?= e($srcHex) ?>" aria-hidden="true"></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="sp-od-body" id="sp-owner-dash-body">
                            <p class="panel-help">Loading owner insights…</p>
                        </div>
                    </div>
                </details>
            </section>
            <dialog class="response-dialog sp-od-cell-dialog" id="sp-od-cell-dialog" aria-labelledby="sp-od-cell-dialog-title">
                <div class="sp-od-cell-dialog-body">
                    <div class="sp-od-cell-dialog-head">
                        <div>
                            <div class="eyebrow">Project folders</div>
                            <h3 id="sp-od-cell-dialog-title">Owner · Period</h3>
                            <p class="response-dialog-sub" id="sp-od-cell-dialog-sub"></p>
                        </div>
                        <button type="button" class="button ghost response-dialog-close" id="sp-od-cell-dialog-close" aria-label="Close">✕</button>
                    </div>
                    <ul class="sp-od-cell-dialog-list" id="sp-od-cell-dialog-list"></ul>
                </div>
            </dialog>
            <dialog class="response-dialog sp-od-cell-dialog sp-od-compare-dialog" id="sp-od-compare-dialog" aria-labelledby="sp-od-compare-title">
                <div class="sp-od-cell-dialog-body">
                    <div class="sp-od-cell-dialog-head">
                        <div>
                            <div class="eyebrow">Owner compare</div>
                            <h3 id="sp-od-compare-title">Side by side</h3>
                            <p class="response-dialog-sub" id="sp-od-compare-sub"></p>
                        </div>
                        <button type="button" class="button ghost response-dialog-close" id="sp-od-compare-close" aria-label="Close">✕</button>
                    </div>
                    <div class="sp-od-compare-table-wrap" id="sp-od-compare-body"></div>
                </div>
            </dialog>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="assets/js/sharepoint-owner-stats.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-owner-stats.js') ?>"></script>
</body>
</html>
