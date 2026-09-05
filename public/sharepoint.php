<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\PaginationPreference;
use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\Repositories\SharePointSourceRepository;
use RiskAssessment\SharePoint\SharePointBrowserSync;
use RiskAssessment\SharePoint\SharePointGraphClient;
use RiskAssessment\SharePoint\SharePointListingImporter;

$catalog = new SharePointCatalogRepository($pdo);
$sourcesRepo = new SharePointSourceRepository($pdo);
$graph = new SharePointGraphClient($settings, $crypto, $catalog);
$importer = new SharePointListingImporter($catalog, $settings);
$browserSync = new SharePointBrowserSync($sourcesRepo);

$sourcesRepo->ensureDefaultSource([
    'folder_url' => (string) $settings->get('sharepoint_folder_url', ''),
    'site_host' => (string) $settings->get('sharepoint_site_host', ''),
    'site_path' => (string) $settings->get('sharepoint_site_path', ''),
    'folder_path' => (string) $settings->get('sharepoint_folder_path', ''),
    'last_synced_at' => (string) $settings->get('sharepoint_last_synced_at', ''),
    'last_sync_status' => (string) $settings->get('sharepoint_last_sync_status', ''),
    'last_item_count' => (int) $settings->get('sharepoint_last_item_count', '0'),
]);

$actionParam = (string) ($_GET['action'] ?? '');

$resolveBrowserSyncSourceKey = static function () use ($sourcesRepo): string {
    $fromHeader = trim((string) ($_SERVER['HTTP_X_SOURCE_KEY'] ?? ''));
    if ($fromHeader !== '') {
        return $fromHeader;
    }

    return '';
};

$corsSiteHostForOrigin = static function (string $origin, string $preferredHost): string {
    if ($preferredHost !== '') {
        return $preferredHost;
    }
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));

    return $host !== '' ? $host : 'ahsonline.sharepoint.com';
};

// CORS preflight for MFA browser sync from SharePoint tab (no login required).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS' && $actionParam === 'browser_sync_import') {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $sourceKey = $resolveBrowserSyncSourceKey();
    $siteHost = '';
    if ($sourceKey !== '') {
        $preflightSource = $sourcesRepo->findByKey($sourceKey);
        $siteHost = (string) ($preflightSource['site_host'] ?? '');
    }
    $browserSync->applyCorsHeaders($origin, $corsSiteHostForOrigin($origin, $siteHost));
    http_response_code(204);
    exit;
}

// MFA browser sync import (token auth + CORS — runs before session login).
if ($actionParam === 'browser_sync_import' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $sourceKey = '';
    try {
        $raw = (string) file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Expected JSON body with rows.');
        }
        $sourceKey = trim((string) ($_SERVER['HTTP_X_SOURCE_KEY'] ?? $payload['source_key'] ?? ''));
        if ($sourceKey === '') {
            throw new RuntimeException('Missing source_key for MFA sync import.');
        }
        $source = $sourcesRepo->requireByKey($sourceKey);
        $browserSync->applyCorsHeaders($origin, (string) $source['site_host']);
        $token = trim((string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? $payload['token'] ?? ''));
        $browserSync->assertValidToken($sourceKey, $token);
        $rows = $payload['rows'] ?? null;
        if (!is_array($rows)) {
            throw new RuntimeException('JSON must include a rows array.');
        }
        if (count($rows) > SharePointBrowserSync::maxRows()) {
            throw new RuntimeException('Too many rows (max ' . SharePointBrowserSync::maxRows() . ').');
        }
        $sourceOverlay = [
            'source_key' => (string) $source['source_key'],
            'site_host' => (string) $source['site_host'],
            'site_path' => (string) $source['site_path'],
            'folder_path' => (string) $source['folder_path'],
        ];
        $result = $importer->importAssocRows($rows, 'mfa_sync', $sourceOverlay);
        $sourcesRepo->markSynced(
            (string) $source['source_key'],
            'mfa_sync',
            (int) ($result['count'] ?? 0)
        );
        $browserSync->clearToken($sourceKey);
        try {
            $auth->users()->logAudit(
                'sharepoint.mfa_sync',
                0,
                'browser-sync',
                null,
                null,
                [
                    'count' => $result['count'] ?? 0,
                    'projects' => $result['projects'] ?? 0,
                    'source_key' => $sourceKey,
                ]
            );
        } catch (Throwable) {
            // Audit is best-effort for token sync.
        }
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        if ($sourceKey !== '') {
            $failed = $sourcesRepo->findByKey($sourceKey);
            $browserSync->applyCorsHeaders($origin, (string) ($failed['site_host'] ?? $corsSiteHostForOrigin($origin, '')));
        } else {
            $browserSync->applyCorsHeaders($origin, $corsSiteHostForOrigin($origin, ''));
        }
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$currentUser = $auth->requireAuth();
$isAdmin = !empty($currentUser['is_admin']);

$error = '';
$flash = '';
$testResult = null;

$allSources = $sourcesRepo->listAll();
$requestedSourceKey = trim((string) ($_GET['source'] ?? $_POST['source'] ?? $_POST['source_key'] ?? ''));
$activeSource = $requestedSourceKey !== ''
    ? $sourcesRepo->findByKey($requestedSourceKey)
    : null;
if ($activeSource === null) {
    $activeSource = $allSources[0] ?? $sourcesRepo->ensureDefaultSource();
}
$activeSourceKey = (string) $activeSource['source_key'];

$query = trim((string) ($_GET['q'] ?? ''));
$allowedPerPage = [10, 25, 50, 100];
$perPage = PaginationPreference::resolve(
    PaginationPreference::KEY_SHAREPOINT,
    isset($_GET['per']) ? (int) $_GET['per'] : null,
    25,
    $allowedPerPage
);
$page = max(1, (int) ($_GET['page'] ?? 1));

$sourceOverlayFrom = static function (array $source): array {
    return [
        'source_key' => (string) ($source['source_key'] ?? ''),
        'site_host' => (string) ($source['site_host'] ?? ''),
        'site_path' => (string) ($source['site_path'] ?? ''),
        'folder_path' => (string) ($source['folder_path'] ?? ''),
    ];
};

$injectActiveFolderIntoPost = static function (array $post, array $source): array {
    $post['sharepoint_site_host'] = (string) ($source['site_host'] ?? '');
    $post['sharepoint_site_path'] = (string) ($source['site_path'] ?? '');
    $post['sharepoint_folder_path'] = (string) ($source['folder_path'] ?? '');
    $post['sharepoint_folder_url'] = (string) ($source['folder_url'] ?? '');

    return $post;
};

// JSON project detail for the dialog (any signed-in user).
if ($actionParam === 'project_detail') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $detailSourceKey = trim((string) ($_GET['source'] ?? $activeSourceKey));
    $name = trim((string) ($_GET['name'] ?? ''));
    $detail = $catalog->getProject($name, $detailSourceKey !== '' ? $detailSourceKey : $activeSourceKey);
    if ($detail === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Project not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $detailSource = $sourcesRepo->findByKey($detailSourceKey !== '' ? $detailSourceKey : $activeSourceKey);
    $detail['source_key'] = (string) ($detailSource['source_key'] ?? $detailSourceKey);
    $detail['source_title'] = (string) ($detailSource['title'] ?? $detail['source_key']);
    echo json_encode(['ok' => true, 'project' => $detail], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Lightweight catalog index for LinkNest-style client search (one or many folders).
if ($actionParam === 'search_index') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');

    $sourcesParam = trim((string) ($_GET['sources'] ?? ''));
    $indexSourceKey = trim((string) ($_GET['source'] ?? $activeSourceKey));
    /** @var list<array{source_key: string, title: string}> $indexSources */
    $indexSources = [];

    if ($sourcesParam === 'all') {
        foreach ($allSources as $src) {
            $indexSources[] = [
                'source_key' => (string) ($src['source_key'] ?? ''),
                'title' => (string) ($src['title'] ?? $src['source_key'] ?? ''),
            ];
        }
    } elseif ($sourcesParam !== '') {
        $wanted = array_values(array_filter(array_map('trim', explode(',', $sourcesParam))));
        $byKey = [];
        foreach ($allSources as $src) {
            $byKey[(string) ($src['source_key'] ?? '')] = $src;
        }
        foreach ($wanted as $key) {
            if (!isset($byKey[$key])) {
                continue;
            }
            $indexSources[] = [
                'source_key' => $key,
                'title' => (string) ($byKey[$key]['title'] ?? $key),
            ];
        }
    }

    if ($indexSources === []) {
        if ($indexSourceKey === '') {
            $indexSourceKey = $activeSourceKey;
        }
        $indexSource = $sourcesRepo->findByKey($indexSourceKey) ?? $activeSource;
        $indexSources = [[
            'source_key' => (string) ($indexSource['source_key'] ?? $indexSourceKey),
            'title' => (string) ($indexSource['title'] ?? $indexSourceKey),
        ]];
    }

    $index = $catalog->listSearchIndexForSources($indexSources);
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
        'last_synced_at' => (string) ($primary['last_synced_at'] ?? ''),
        'last_sync_status' => (string) ($primary['last_sync_status'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        if (!$isAdmin) {
            throw new RuntimeException('Only administrators can manage the SharePoint catalog.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add_source') {
            $created = $sourcesRepo->createFromUrl(
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['folder_url'] ?? '')
            );
            $auth->users()->logAudit(
                'sharepoint.source_added',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['source_key' => $created['source_key']]
            );
            header('Location: sharepoint.php?source=' . rawurlencode((string) $created['source_key']) . '#sharepoint-search');
            exit;
        } elseif ($action === 'update_source') {
            $key = trim((string) ($_POST['source_key'] ?? ''));
            $updated = $sourcesRepo->update($key, [
                'title' => (string) ($_POST['title'] ?? ''),
                'folder_url' => (string) ($_POST['folder_url'] ?? ''),
            ]);
            $activeSource = $updated;
            $activeSourceKey = (string) $updated['source_key'];
            $allSources = $sourcesRepo->listAll();
            $auth->users()->logAudit(
                'sharepoint.source_updated',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['source_key' => $activeSourceKey]
            );
            $flash = 'SharePoint folder "' . (string) $updated['title'] . '" updated.';
        } elseif ($action === 'delete_source') {
            $key = trim((string) ($_POST['source_key'] ?? ''));
            $sourcesRepo->delete($key, $catalog);
            $auth->users()->logAudit(
                'sharepoint.source_deleted',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['source_key' => $key]
            );
            $allSources = $sourcesRepo->listAll();
            $fallback = $allSources[0] ?? $sourcesRepo->ensureDefaultSource();
            header('Location: sharepoint.php?source=' . rawurlencode((string) $fallback['source_key']));
            exit;
        } elseif ($action === 'save_settings') {
            $payload = [
                'sharepoint_tenant_id' => (string) ($_POST['sharepoint_tenant_id'] ?? ''),
                'sharepoint_client_id' => (string) ($_POST['sharepoint_client_id'] ?? ''),
                'sharepoint_site_host' => (string) $activeSource['site_host'],
                'sharepoint_site_path' => (string) $activeSource['site_path'],
                'sharepoint_folder_path' => (string) $activeSource['folder_path'],
                'sharepoint_folder_url' => (string) $activeSource['folder_url'],
            ];
            $secret = trim((string) ($_POST['sharepoint_client_secret'] ?? ''));
            if ($secret !== '') {
                $payload['sharepoint_client_secret'] = $secret;
            }
            $graph->saveSettings($payload);
            $auth->users()->logAudit(
                'settings.sharepoint',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['graph_only' => true]
            );
            $flash = 'SharePoint Graph credentials saved.';
        } elseif ($action === 'prepare_browser_sync') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            $prepareKey = trim((string) ($_POST['source_key'] ?? $activeSourceKey));
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/sharepoint.php'))), '/');
            $importUrl = $scheme . '://' . $hostHeader . $basePath . '/sharepoint.php?action=browser_sync_import';
            $prepared = $browserSync->prepare($prepareKey, $importUrl);
            echo json_encode(['ok' => true] + $prepared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } elseif ($action === 'clear_secret') {
            $graph->clearSecret();
            $auth->users()->logAudit(
                'settings.sharepoint_secret_cleared',
                (int) $currentUser['id'],
                (string) $currentUser['username']
            );
            $flash = 'SharePoint client secret removed.';
        } elseif ($action === 'test_connection') {
            $post = $injectActiveFolderIntoPost($_POST, $activeSource);
            $testResult = $graph->testConnectionFromPost($post);
            $flash = (string) ($testResult['message'] ?? 'Connection OK.');
            if (!empty($testResult['site_name'])) {
                $flash .= ' Site: ' . $testResult['site_name'];
            }
        } elseif ($action === 'sync') {
            $post = $injectActiveFolderIntoPost($_POST, $activeSource);
            $result = $graph->syncFromPost($post);
            $sourcesRepo->markSynced(
                $activeSourceKey,
                'ok',
                (int) ($result['count'] ?? 0)
            );
            $activeSource = $sourcesRepo->requireByKey($activeSourceKey);
            $allSources = $sourcesRepo->listAll();
            $flash = (string) ($result['message'] ?? 'Sync complete.');
            $auth->users()->logAudit(
                'sharepoint.sync',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'count' => $result['count'] ?? 0,
                    'projects' => $result['projects'] ?? 0,
                    'source_key' => $activeSourceKey,
                ]
            );
        } elseif ($action === 'import') {
            $file = $_FILES['import_file'] ?? null;
            if (!is_array($file)) {
                throw new RuntimeException('Please choose an Excel or CSV file to import.');
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The import upload failed. Please try again.');
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0 || $size > 10 * 1024 * 1024) {
                throw new RuntimeException('Import file must be between 1 byte and 10 MB.');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('Invalid import upload.');
            }
            $original = (string) ($file['name'] ?? 'import.csv');
            $result = $importer->importFile($tmp, $original, $sourceOverlayFrom($activeSource));
            $sourcesRepo->markSynced(
                $activeSourceKey,
                'imported',
                (int) ($result['count'] ?? 0)
            );
            $activeSource = $sourcesRepo->requireByKey($activeSourceKey);
            $allSources = $sourcesRepo->listAll();
            $flash = (string) ($result['message'] ?? 'Import complete.');
            $auth->users()->logAudit(
                'sharepoint.import',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'count' => $result['count'] ?? 0,
                    'file' => basename($original),
                    'source_key' => $activeSourceKey,
                ]
            );
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'prepare_browser_sync') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $error = $exception->getMessage();
    }
}

$status = $graph->status();
// Drop autofill junk that is not a GUID (e.g. password-manager short codes).
$thisClientIdSafe = (string) ($status['client_id'] ?? '');
if ($thisClientIdSafe !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $thisClientIdSafe)) {
    $settings->set('sharepoint_client_id', '');
    $thisClientIdSafe = '';
    $status['client_id'] = '';
}

$itemCount = $catalog->count($activeSourceKey);
$projectCount = $catalog->countProjects($activeSourceKey);
$matchedProjectCount = $catalog->countMatchingProjects($query, $activeSourceKey);
$totalPages = max(1, (int) ceil($matchedProjectCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$projects = $catalog->listProjects($query, $page, $perPage, $activeSourceKey);
$from = $matchedProjectCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
$to = min($matchedProjectCount, $page * $perPage);

$sourceCounts = [];
foreach ($allSources as $src) {
    $key = (string) ($src['source_key'] ?? '');
    $sourceCounts[$key] = $catalog->count($key);
}

$sharepointListUrl = static function (array $overrides = []) use ($query, $perPage, $page, $activeSourceKey): string {
    $params = array_merge([
        'source' => $activeSourceKey,
        'q' => $query,
        'page' => $page,
        'per' => $perPage,
    ], $overrides);
    if (($params['q'] ?? '') === '') {
        unset($params['q']);
    }
    if ((int) ($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }
    if ((int) ($params['per'] ?? 25) === 25) {
        unset($params['per']);
    }
    if (($params['source'] ?? '') === '') {
        unset($params['source']);
    }
    $qs = http_build_query($params);

    return 'sharepoint.php' . ($qs !== '' ? '?' . $qs : '') . '#sharepoint-search';
};

$formatModified = static function (string $modified): string {
    $modified = trim($modified);
    if ($modified === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $modified) === 1) {
        try {
            return (new DateTimeImmutable($modified))->format('M j, Y g:i A');
        } catch (Throwable) {
            return $modified;
        }
    }

    return $modified;
};

$activeTitle = (string) ($activeSource['title'] ?? 'SharePoint folder');
$activeFolderPath = (string) ($activeSource['folder_path'] ?? '');
$activeSiteHost = (string) ($activeSource['site_host'] ?? '');
$activeSitePath = (string) ($activeSource['site_path'] ?? '');
$activeFolderUrl = (string) ($activeSource['folder_url'] ?? '');
$activeLastSynced = (string) ($activeSource['last_synced_at'] ?? '');
$activeLastStatus = (string) ($activeSource['last_sync_status'] ?? '');
$activeLastError = (string) ($activeSource['last_sync_error'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($activeTitle) ?> · SharePoint · <?= e($branding->documentTitle()) ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="shell upload-page sharepoint-catalog-page">
        <header class="topbar topbar-uplift">
            <a class="brand brand-link" href="index.php#find-projects" title="Find projects by name">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1>📁 SharePoint catalog</h1>
                </div>
            </a>
            <div class="topbar-actions">
                <a class="button ghost home-link" href="index.php#find-projects">🔎 Find projects</a>
                <a class="button ghost home-link" href="index.php#upload">📤 Upload</a>
                <a class="button ghost home-link" href="templates.php">📚 Templates</a>
                <a class="button ghost home-link is-active" href="sharepoint.php?source=<?= e($activeSourceKey) ?>" aria-current="page">📁 SharePoint</a>
                <?php require __DIR__ . '/includes/updates-nav.php'; ?>
                <?php require __DIR__ . '/includes/theme-controls.php'; ?>
                <div class="updated template-count-chip"><?= (int) $projectCount ?> project<?= $projectCount === 1 ? '' : 's' ?></div>
            </div>
        </header>

        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">SharePoint catalog</div>
                            <h2><?= e($activeTitle) ?></h2>
                            <p>
                                Search project folders and open every stored link from
                                <strong><?= e($activeFolderPath) ?></strong>
                                on
                                <strong><?= e($activeSiteHost . $activeSitePath) ?></strong>.
                            </p>
                        </div>
                        <?php require __DIR__ . '/includes/hero-medallion.php'; renderHeroMedallion((int) $itemCount, 'catalog items'); ?>
                    </div>
                </div>
            </section>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error">⚠️ <?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($flash !== ''): ?>
                <div class="alert alert-success">✅ <?= e($flash) ?></div>
            <?php endif; ?>

            <?php $homeTab = 'sharepoint'; require __DIR__ . '/includes/home-section-tabs.php'; ?>

            <section class="upload-card sharepoint-sources-panel" id="sharepoint-sources">
                <div class="card-heading">
                    <div>
                        <h2>📁 SharePoint folders</h2>
                        <p class="panel-help">Each folder has its own catalog, MFA sync, and search. Open a catalog or sync with your SharePoint login.</p>
                    </div>
                </div>
                <div class="sharepoint-sources-grid">
                    <?php foreach ($allSources as $src): ?>
                        <?php
                        $srcKey = (string) ($src['source_key'] ?? '');
                        $isActiveCard = $srcKey === $activeSourceKey;
                        $srcCount = (int) ($sourceCounts[$srcKey] ?? 0);
                        $srcSynced = (string) ($src['last_synced_at'] ?? '');
                        $srcStatus = (string) ($src['last_sync_status'] ?? '');
                        $srcUrl = (string) ($src['folder_url'] ?? '');
                        ?>
                        <article class="sharepoint-source-card<?= $isActiveCard ? ' is-active' : '' ?>" data-source-key="<?= e($srcKey) ?>">
                            <div class="sharepoint-source-card-head">
                                <h3><?= e((string) ($src['title'] ?? $srcKey)) ?></h3>
                                <?php if ($isActiveCard): ?>
                                    <span class="sharepoint-source-badge">Active</span>
                                <?php endif; ?>
                            </div>
                            <p class="sharepoint-source-meta">
                                <span><?= e((string) ($src['folder_path'] ?? '')) ?></span>
                                <span><?= e((string) (($src['site_host'] ?? '') . ($src['site_path'] ?? ''))) ?></span>
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
                                <a class="button ghost-light" href="sharepoint.php?source=<?= e($srcKey) ?>#sharepoint-search">📂 Open catalog</a>
                                <?php if ($isAdmin): ?>
                                    <button type="button" class="button ghost-light sharepoint-mfa-prepare-btn" data-source-key="<?= e($srcKey) ?>">🔐 MFA sync</button>
                                <?php endif; ?>
                                <?php if ($srcUrl !== ''): ?>
                                    <a class="button ghost" href="<?= e($srcUrl) ?>" target="_blank" rel="noopener noreferrer">🔗 Open in SharePoint</a>
                                <?php endif; ?>
                            </div>
                            <?php if ($isAdmin): ?>
                                <details class="sharepoint-source-edit">
                                    <summary>Edit folder</summary>
                                    <form method="post" class="sharepoint-source-edit-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_source">
                                        <input type="hidden" name="source_key" value="<?= e($srcKey) ?>">
                                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                                        <label>
                                            <span>Display name</span>
                                            <input type="text" name="title" value="<?= e((string) ($src['title'] ?? '')) ?>" required maxlength="200" autocomplete="off">
                                        </label>
                                        <label>
                                            <span>Folder URL</span>
                                            <input type="url" name="folder_url" value="<?= e($srcUrl) ?>" required autocomplete="off" spellcheck="false">
                                        </label>
                                        <div class="sharepoint-source-edit-actions">
                                            <button type="submit" class="button button-primary">Save</button>
                                        </div>
                                    </form>
                                    <?php if (count($allSources) > 1): ?>
                                        <form method="post" class="sharepoint-source-delete-form" onsubmit="return confirm('Delete this SharePoint folder and its catalog items?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_source">
                                            <input type="hidden" name="source_key" value="<?= e($srcKey) ?>">
                                            <button type="submit" class="button ghost">🗑️ Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($isAdmin): ?>
                    <form method="post" class="sharepoint-add-source-form" id="sharepoint-add-source">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_source">
                        <h3>Add SharePoint folder</h3>
                        <div class="form-grid sharepoint-form-grid">
                            <label>
                                <span>Display name</span>
                                <input type="text" name="title" placeholder="e.g. Architecture Standards" required maxlength="200" autocomplete="off">
                            </label>
                            <label class="sharepoint-folder-url-label">
                                <span>SharePoint folder URL</span>
                                <input type="url" name="folder_url" required
                                       placeholder="https://….sharepoint.com/…/AllItems.aspx?id=/teams/…/Shared Documents/…"
                                       autocomplete="off" spellcheck="false">
                            </label>
                        </div>
                        <button type="submit" class="button button-primary">➕ Add folder</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="upload-card search-card" id="sharepoint-search"
                     data-source-key="<?= e($activeSourceKey) ?>"
                     data-source-title="<?= e($activeTitle) ?>"
                     data-sources="<?= e(json_encode(array_map(static function (array $src): array {
                         return [
                             'source_key' => (string) ($src['source_key'] ?? ''),
                             'title' => (string) ($src['title'] ?? $src['source_key'] ?? ''),
                         ];
                     }, $allSources), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                     data-initial-query="<?= e($query) ?>"
                     data-per-page="<?= (int) $perPage ?>"
                     data-item-count="<?= (int) $itemCount ?>"
                     data-project-count="<?= (int) $projectCount ?>"
                     data-last-synced="<?= e($activeLastSynced) ?>"
                     data-last-status="<?= e($activeLastStatus) ?>">
                <h2 id="sharepoint-search-heading">🔎 <?= e($activeTitle) ?></h2>
                <p>Find a project folder — live search on project name, files, paths, Modified By, or Person. Typo-tolerant when Fuzzy is on.</p>
                <?php if (count($allSources) > 1): ?>
                    <div class="sharepoint-search-scopes" id="sharepoint-search-scopes" role="group" aria-label="Catalogs to search">
                        <div class="sharepoint-search-scopes-head">
                            <span class="sharepoint-search-scopes-label">Search in</span>
                            <button type="button" class="button ghost sharepoint-scopes-all" id="sharepoint-scopes-all">All catalogs</button>
                            <button type="button" class="button ghost sharepoint-scopes-active" id="sharepoint-scopes-active">This catalog only</button>
                        </div>
                        <div class="sharepoint-search-scopes-list">
                            <?php foreach ($allSources as $src): ?>
                                <?php
                                $srcKey = (string) ($src['source_key'] ?? '');
                                $srcTitle = (string) ($src['title'] ?? $srcKey);
                                $checked = $srcKey === $activeSourceKey;
                                ?>
                                <label class="sharepoint-scope-chip<?= $checked ? ' is-active' : '' ?>">
                                    <input type="checkbox" class="sharepoint-scope-check" value="<?= e($srcKey) ?>"<?= $checked ? ' checked' : '' ?>>
                                    <span><?= e($srcTitle) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="panel-help sharepoint-scopes-hint">Select more than one catalog to compare — results show where a project is found and where it is missing.</p>
                    </div>
                <?php endif; ?>
                <form method="get" class="search-form sharepoint-live-search-form" action="sharepoint.php" id="sharepoint-search-form" role="search">
                    <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                    <div class="search-wrap search-wrap-wide sharepoint-search-main">
                        <span aria-hidden="true">Find</span>
                        <input type="search" name="q" id="sharepoint-search-input" value="<?= e($query) ?>"
                               placeholder="Search projects, files, people…" autocomplete="off" autofocus
                               aria-label="Search SharePoint catalog">
                        <button type="button" class="button ghost sharepoint-search-clear<?= $query === '' ? ' is-hidden' : '' ?>" id="sharepoint-search-clear" title="Clear search" aria-label="Clear search">Clear</button>
                        <noscript>
                            <button type="submit" class="button button-primary">Search</button>
                        </noscript>
                    </div>
                    <div class="sharepoint-search-controls" id="sharepoint-search-controls" hidden>
                        <div class="sp-search-toggle-group" role="group" aria-label="Match spaced words with AND or OR" id="sharepoint-word-mode" hidden>
                            <button type="button" class="sp-search-toggle is-active" data-word-mode="and" title="Match only when every word is found" aria-pressed="true">AND</button>
                            <button type="button" class="sp-search-toggle" data-word-mode="or" title="Match when any word is found" aria-pressed="false">OR</button>
                        </div>
                        <button type="button" class="sp-search-toggle sp-search-fuzzy" id="sharepoint-fuzzy-toggle" title="Match similar-sounding words and common misspellings" aria-pressed="false">Fuzzy</button>
                        <div class="sp-refine-wrap">
                            <input type="search" id="sharepoint-refine-input" placeholder="Refine results…" autocomplete="off" aria-label="Search within current results" title="Search within the current results">
                            <button type="button" class="sp-refine-clear is-hidden" id="sharepoint-refine-clear" title="Clear refine search" aria-label="Clear refine search">✕</button>
                        </div>
                    </div>
                </form>
                <div class="sharepoint-search-stats is-hidden" id="sharepoint-search-stats" aria-live="polite"></div>
                <p class="panel-help sharepoint-catalog-meta" id="sharepoint-catalog-meta">
                    <?= (int) $matchedProjectCount ?> project<?= $matchedProjectCount === 1 ? '' : 's' ?>
                    <?php if ($query !== ''): ?> matched<?php endif; ?>
                    · <?= (int) $itemCount ?> catalog item<?= $itemCount === 1 ? '' : 's' ?> total
                    <?php if ($activeLastSynced !== ''): ?>
                        · Last update <?= e($activeLastSynced) ?>
                        (<?= e($activeLastStatus !== '' ? $activeLastStatus : 'unknown') ?>)
                    <?php endif; ?>
                </p>
            </section>

            <section class="upload-card sharepoint-table-card" aria-label="SharePoint project table" id="sharepoint-table-card">
                <div class="sharepoint-table-toolbar">
                    <span class="result-count" id="sharepoint-result-count">Showing <?= (int) $from ?>–<?= (int) $to ?> of <?= (int) $matchedProjectCount ?></span>
                    <div class="sharepoint-compare-bar" id="sharepoint-compare-bar">
                        <span class="sharepoint-compare-hint" id="sharepoint-compare-hint">Select 2 folders to compare side by side</span>
                        <button type="button" class="button button-primary" id="sharepoint-compare-open" disabled>⚖️ Compare selected</button>
                        <button type="button" class="button ghost" id="sharepoint-compare-clear" hidden>Clear selection</button>
                    </div>
                </div>
                <div class="table-wrap sharepoint-projects-wrap">
                    <table class="sharepoint-projects-table" id="sharepoint-projects-table">
                        <thead>
                            <tr>
                                <th scope="col" class="sharepoint-select-col">
                                    <span class="visually-hidden">Select</span>
                                </th>
                                <th scope="col">Project</th>
                                <th scope="col">Match</th>
                                <th scope="col">Items</th>
                                <th scope="col">Modified</th>
                                <th scope="col">Modified By</th>
                                <th scope="col">Person</th>
                                <th scope="col"><span class="visually-hidden">Open</span></th>
                            </tr>
                        </thead>
                        <tbody id="sharepoint-projects-tbody">
                            <?php if ($projects === []): ?>
                                <tr class="sharepoint-empty-row">
                                    <td colspan="8">
                                        <?php if ($itemCount === 0): ?>
                                            📁 No catalog items yet.
                                            <?php if ($isAdmin): ?>
                                                Use <strong>Sync from SharePoint</strong> or <strong>Import Excel/CSV</strong> below.
                                            <?php else: ?>
                                                Ask an administrator to sync or import the Architectural Projects listing.
                                            <?php endif; ?>
                                        <?php else: ?>
                                            No projects matched <strong><?= e($query) ?></strong>. Try another name.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($projects as $project): ?>
                                    <?php
                                    $projectName = (string) ($project['project_name'] ?? '');
                                    $folderUrl = (string) ($project['folder_url'] ?? '');
                                    $itemTotal = (int) ($project['item_count'] ?? 0);
                                    $fileCount = (int) ($project['file_count'] ?? 0);
                                    $folderCount = (int) ($project['folder_count'] ?? 0);
                                    $modifiedDisplay = $formatModified((string) ($project['last_modified'] ?? ''));
                                    $modifiedBy = (string) ($project['modified_by'] ?? '');
                                    $person = (string) ($project['person'] ?? '');
                                    $selectId = $activeSourceKey . '::' . $projectName;
                                    ?>
                                    <tr class="sharepoint-project-row" data-project-name="<?= e($projectName) ?>" data-source-key="<?= e($activeSourceKey) ?>" tabindex="0">
                                        <td class="sharepoint-select-col" onclick="event.stopPropagation()">
                                            <label class="sharepoint-row-select">
                                                <input type="checkbox" class="sharepoint-compare-check" value="<?= e($selectId) ?>" data-project-name="<?= e($projectName) ?>" data-source-key="<?= e($activeSourceKey) ?>" aria-label="Select <?= e($projectName) ?> for compare">
                                            </label>
                                        </td>
                                        <td>
                                            <button type="button" class="sharepoint-project-open" data-project-name="<?= e($projectName) ?>" data-source-key="<?= e($activeSourceKey) ?>">
                                                <span class="sharepoint-project-open-icon" aria-hidden="true">📂</span>
                                                <span><?= e($projectName) ?></span>
                                            </button>
                                            <div class="sp-project-meta-line">
                                                <span class="sp-catalog-badge"><?= e($activeTitle) ?></span>
                                            </div>
                                        </td>
                                        <td class="sp-match-cell"><span class="sp-match-placeholder">—</span></td>
                                        <td>
                                            <span class="sharepoint-item-counts" title="<?= (int) $folderCount ?> folders · <?= (int) $fileCount ?> files">
                                                <?= (int) $itemTotal ?>
                                            </span>
                                        </td>
                                        <td><?= $modifiedDisplay !== '' ? e($modifiedDisplay) : '—' ?></td>
                                        <td><?= $modifiedBy !== '' ? e($modifiedBy) : '—' ?></td>
                                        <td><?= $person !== '' ? e($person) : '—' ?></td>
                                        <td class="sharepoint-project-actions">
                                            <?php if ($folderUrl !== ''): ?>
                                                <a class="button ghost-light sharepoint-open-sp" href="<?= e($folderUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open in SharePoint" onclick="event.stopPropagation()">🔗</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <nav class="pagination" aria-label="SharePoint project pages" id="sharepoint-pagination">
                    <div class="pagination-controls" id="sharepoint-pagination-controls">
                        <?php if ($totalPages > 1): ?>
                            <?php if ($page > 1): ?>
                                <a class="button ghost" href="<?= e($sharepointListUrl(['page' => $page - 1])) ?>">← Previous</a>
                            <?php else: ?>
                                <span class="button ghost is-disabled" aria-disabled="true">← Previous</span>
                            <?php endif; ?>
                            <span class="pagination-pages">
                                <?php
                                $windowStart = max(1, $page - 2);
                                $windowEnd = min($totalPages, $page + 2);
                                for ($pageNum = $windowStart; $pageNum <= $windowEnd; $pageNum++):
                                ?>
                                    <?php if ($pageNum === $page): ?>
                                        <span class="pagination-page is-current" aria-current="page"><?= $pageNum ?></span>
                                    <?php else: ?>
                                        <a class="pagination-page" href="<?= e($sharepointListUrl(['page' => $pageNum])) ?>"><?= $pageNum ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </span>
                            <?php if ($page < $totalPages): ?>
                                <a class="button ghost" href="<?= e($sharepointListUrl(['page' => $page + 1])) ?>">Next →</a>
                            <?php else: ?>
                                <span class="button ghost is-disabled" aria-disabled="true">Next →</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <form method="get" class="pagination-per-page" action="sharepoint.php#sharepoint-search" id="sharepoint-per-page-form">
                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                        <?php if ($query !== ''): ?>
                            <input type="hidden" name="q" value="<?= e($query) ?>" id="sharepoint-per-page-q">
                        <?php endif; ?>
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
            </section>

            <dialog class="response-dialog sharepoint-project-dialog" id="sharepoint-project-dialog" aria-labelledby="sharepoint-project-dialog-title">
                <div class="response-dialog-form sharepoint-project-dialog-body">
                    <div class="response-dialog-head">
                        <div>
                            <div class="eyebrow">📂 SharePoint project</div>
                            <h3 id="sharepoint-project-dialog-title">Project</h3>
                            <p class="response-dialog-sub" id="sharepoint-project-dialog-sub"></p>
                        </div>
                        <button type="button" class="button ghost response-dialog-close" id="sharepoint-project-dialog-close" aria-label="Close">✕</button>
                    </div>
                    <div class="sharepoint-project-dialog-actions" id="sharepoint-project-dialog-actions"></div>
                    <div class="table-wrap sharepoint-dialog-table-wrap">
                        <table class="sharepoint-projects-table sharepoint-dialog-table">
                            <thead>
                                <tr>
                                    <th scope="col">📄 Name</th>
                                    <th scope="col">🏷️ Type</th>
                                    <th scope="col">🕒 Modified</th>
                                    <th scope="col">👤 Modified By</th>
                                    <th scope="col">🙋 Person</th>
                                </tr>
                            </thead>
                            <tbody id="sharepoint-project-dialog-rows">
                                <tr><td colspan="5" class="sharepoint-dialog-empty">⏳ Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </dialog>

            <dialog class="response-dialog sharepoint-compare-dialog" id="sharepoint-compare-dialog" aria-labelledby="sharepoint-compare-dialog-title">
                <div class="response-dialog-form sharepoint-compare-dialog-body">
                    <div class="response-dialog-head">
                        <div>
                            <div class="eyebrow">⚖️ Side-by-side compare</div>
                            <h3 id="sharepoint-compare-dialog-title">Compare folders</h3>
                            <p class="response-dialog-sub" id="sharepoint-compare-dialog-sub">Select two project folders to compare files and folders.</p>
                        </div>
                        <button type="button" class="button ghost response-dialog-close" id="sharepoint-compare-dialog-close" aria-label="Close compare">✕</button>
                    </div>
                    <div class="sharepoint-compare-legend" id="sharepoint-compare-legend" hidden>
                        <span class="sp-diff-pill sp-diff-pill--both">In both</span>
                        <span class="sp-diff-pill sp-diff-pill--left">Only in left</span>
                        <span class="sp-diff-pill sp-diff-pill--right">Only in right</span>
                    </div>
                    <div class="sharepoint-compare-panels" id="sharepoint-compare-panels">
                        <section class="sharepoint-compare-panel" data-side="left">
                            <header class="sharepoint-compare-panel-head">
                                <h4 id="sharepoint-compare-left-title">Left</h4>
                                <p id="sharepoint-compare-left-sub"></p>
                                <div class="sharepoint-compare-panel-actions" id="sharepoint-compare-left-actions"></div>
                            </header>
                            <div class="table-wrap sharepoint-compare-table-wrap">
                                <table class="sharepoint-projects-table sharepoint-dialog-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Name</th>
                                            <th scope="col">Type</th>
                                            <th scope="col">Diff</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sharepoint-compare-left-rows">
                                        <tr><td colspan="3" class="sharepoint-dialog-empty">Select folders to compare.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section class="sharepoint-compare-panel" data-side="right">
                            <header class="sharepoint-compare-panel-head">
                                <h4 id="sharepoint-compare-right-title">Right</h4>
                                <p id="sharepoint-compare-right-sub"></p>
                                <div class="sharepoint-compare-panel-actions" id="sharepoint-compare-right-actions"></div>
                            </header>
                            <div class="table-wrap sharepoint-compare-table-wrap">
                                <table class="sharepoint-projects-table sharepoint-dialog-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Name</th>
                                            <th scope="col">Type</th>
                                            <th scope="col">Diff</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sharepoint-compare-right-rows">
                                        <tr><td colspan="3" class="sharepoint-dialog-empty">Select folders to compare.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>
            </dialog>

            <?php if ($isAdmin): ?>
                <section class="upload-card sharepoint-admin-card" id="sharepoint-admin">
                    <div class="card-heading">
                        <div>
                            <div class="eyebrow">Administrators</div>
                            <h2>⚙️ SharePoint sync &amp; import</h2>
                        </div>
                    </div>
                    <p class="panel-help">
                        Register an Entra ID app with application permission <code>Sites.Read.All</code>
                        (or <code>Sites.Selected</code> granted on this site), admin-consent it, then paste the credentials below.
                        The client secret is stored encrypted and is never shown again.
                        Folder paths are managed in <strong>SharePoint folders</strong> above — Graph sync uses the active folder
                        (<em><?= e($activeTitle) ?></em>).
                    </p>

                    <?php if ($activeLastStatus === 'error' && $activeLastError !== ''): ?>
                        <div class="alert alert-error">Last sync error: <?= e($activeLastError) ?></div>
                    <?php endif; ?>

                    <form method="post" class="sharepoint-settings-form" id="sharepoint-settings-form" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                        <p class="panel-help sharepoint-active-folder-note">
                            Active folder (read-only):
                            <strong><?= e($activeFolderPath) ?></strong>
                            on <strong><?= e($activeSiteHost . $activeSitePath) ?></strong>
                        </p>
                        <div class="form-grid sharepoint-form-grid">
                            <label>
                                <span>Tenant ID</span>
                                <input type="text" name="sharepoint_tenant_id" value="<?= e($status['tenant_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off" spellcheck="false">
                            </label>
                            <label>
                                <span>Client ID (Application ID GUID)</span>
                                <input type="text" name="sharepoint_client_id" value="<?= e($thisClientIdSafe) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off" spellcheck="false">
                            </label>
                            <label>
                                <span>Client secret<?= $status['has_secret'] ? ' (leave blank to keep current)' : '' ?></span>
                                <input type="password" name="sharepoint_client_secret" value="" placeholder="<?= $status['has_secret'] ? '•••••••• (saved)' : 'Paste new secret Value' ?>" autocomplete="new-password">
                            </label>
                        </div>
                        <?php if (!$status['has_secret'] || $thisClientIdSafe === ''): ?>
                            <p class="panel-help">
                                Paste a real Entra <strong>Application (client) ID</strong> GUID and a <strong>client secret Value</strong>, then click
                                <strong>Test connection</strong> (it saves these fields first). CSV import and <strong>MFA browser sync</strong> below work without Graph credentials.
                            </p>
                        <?php endif; ?>
                        <div class="sharepoint-admin-actions sharepoint-admin-actions-row">
                            <button type="submit" name="action" value="save_settings" class="button button-primary">💾 Save settings</button>
                            <button type="submit" name="action" value="test_connection" class="button ghost-light">🔌 Test connection</button>
                            <button type="submit" name="action" value="sync" class="button ghost-light" title="Requires Entra app client secret">🔄 Graph sync</button>
                            <?php if ($status['has_secret']): ?>
                                <button type="submit" name="action" value="clear_secret" class="button ghost" onclick="return confirm('Remove the stored SharePoint client secret?');">🗑️ Clear secret</button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <hr class="sharepoint-admin-divider">

                    <section class="sharepoint-mfa-sync" id="sharepoint-mfa-sync"
                             data-csrf="<?= e((string) ($_SESSION['csrf_token'] ?? '')) ?>"
                             data-source-key="<?= e($activeSourceKey) ?>">
                        <h3>🔐 Sync with my SharePoint login (MFA)</h3>
                        <p class="panel-help">
                            Your PHP server cannot see SharePoint MFA cookies. Use this helper instead:
                            open the active folder (<strong><?= e($activeTitle) ?></strong>) while signed in, then run a one-click script in that tab.
                            It deep-crawls every subfolder (paginated file/folder lists, including Visio/PDF/etc.)
                            with your session and posts them into RiskRegister (replaces this source’s catalog).
                        </p>
                        <ol class="sharepoint-mfa-steps">
                            <li>Confirm the active folder above (or use <strong>MFA sync</strong> on a folder card).</li>
                            <li>Click <strong>Prepare MFA sync</strong> (creates a 30‑minute token).</li>
                            <li>Click <strong>Open SharePoint folder</strong> and complete MFA if prompted.</li>
                            <li>On the SharePoint tab press <kbd>F12</kbd> → <strong>Console</strong> → paste the script → <kbd>Enter</kbd>.</li>
                            <li>Wait for the crawl (large libraries can take several minutes). <code>Promise pending</code> is normal — look for green <strong>✅ Sync complete</strong>.</li>
                        </ol>
                        <div class="sharepoint-admin-actions sharepoint-admin-actions-row">
                            <button type="button" class="button button-primary btn-accent-violet-solid" id="sharepoint-mfa-prepare" data-source-key="<?= e($activeSourceKey) ?>">🔐 Prepare MFA sync</button>
                            <a class="button ghost-light" id="sharepoint-mfa-open" href="<?= e($activeFolderUrl) ?>" target="_blank" rel="noopener noreferrer">📂 Open SharePoint folder</a>
                            <button type="button" class="button ghost" id="sharepoint-mfa-copy" disabled>📋 Copy console script</button>
                        </div>
                        <input type="hidden" id="sharepoint-mfa-source-key" name="source_key" value="<?= e($activeSourceKey) ?>">
                        <p class="panel-help" id="sharepoint-mfa-status" aria-live="polite">Not prepared yet.</p>
                        <textarea id="sharepoint-mfa-script" class="sharepoint-mfa-script" readonly hidden rows="6" aria-label="SharePoint console sync script"></textarea>
                    </section>

                    <hr class="sharepoint-admin-divider">

                    <h3>📥 Import Excel / CSV</h3>
                    <p class="panel-help">
                        Columns (flexible names): <strong>Name</strong>, <strong>Path</strong> / Folder, <strong>Type</strong>, <strong>URL</strong> / Link.
                        If URL is blank, a SharePoint link is built from the active folder’s site and path.
                        Import replaces the catalog for <strong><?= e($activeTitle) ?></strong>.
                    </p>
                    <form method="post" enctype="multipart/form-data" class="sharepoint-import-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="import">
                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                        <div class="sharepoint-import-row">
                            <input type="file" name="import_file" accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                            <button type="submit" class="button button-primary">Import listing</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/fuzzy-search.js?v=<?= filemtime(__DIR__ . '/assets/js/fuzzy-search.js') ?>"></script>
    <script src="assets/js/sharepoint-catalog.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-catalog.js') ?>"></script>
    <?php if ($isAdmin): ?>
        <script src="assets/js/sharepoint-mfa-sync.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-mfa-sync.js') ?>"></script>
        <script src="assets/js/sharepoint-mfa-ui.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-mfa-ui.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
