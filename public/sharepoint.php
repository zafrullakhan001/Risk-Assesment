<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\AppUrl;
use RiskAssessment\Mail\EmailTemplates;
use RiskAssessment\Mail\SmtpMailer;
use RiskAssessment\Mail\SmtpSettings;
use RiskAssessment\PaginationPreference;
use RiskAssessment\Repositories\CatalogShareRepository;
use RiskAssessment\Repositories\SharePointArchiveRepository;
use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\Repositories\SharePointSearchTagRepository;
use RiskAssessment\Repositories\SharePointSourceRepository;
use RiskAssessment\SharePoint\SharePointBrowserSync;
use RiskAssessment\SharePoint\SharePointGraphClient;
use RiskAssessment\SharePoint\SharePointListingImporter;
use RiskAssessment\SharePoint\SharePointOwnerDashboard;
use RiskAssessment\SqliteMaintenance;

$catalog = new SharePointCatalogRepository($pdo);
$sourcesRepo = new SharePointSourceRepository($pdo);
$catalogShareRepository = new CatalogShareRepository($pdo, $crypto);
$searchTags = new SharePointSearchTagRepository($pdo);
$archives = new SharePointArchiveRepository($pdo);
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
$catalogShareFlash = '';
$ownersShareFlash = '';
$catalogShareFlashType = 'success';
$ownersShareFlashType = 'success';
$testResult = null;
$freshCatalogShareUrl = null;
$freshOwnersShareUrl = null;
$freshCatalogShareLabel = '';
$freshOwnersShareLabel = '';

$allSources = $sourcesRepo->listAll();
$requestedSourceKey = trim((string) ($_GET['source'] ?? $_POST['source'] ?? $_POST['source_key'] ?? ''));
$activeSource = $requestedSourceKey !== ''
    ? $sourcesRepo->findByKey($requestedSourceKey)
    : null;
if ($activeSource === null) {
    $activeSource = $allSources[0] ?? $sourcesRepo->ensureDefaultSource();
}
$activeSourceKey = (string) $activeSource['source_key'];
$archiveIndex = $archives->indexForSources(array_values(array_filter(array_map(
    static fn (array $src): string => (string) ($src['source_key'] ?? ''),
    $allSources
))));
$archivedSourceKeys = $archiveIndex['sources'];
if (!$isAdmin && isset($archivedSourceKeys[$activeSourceKey])) {
    foreach ($allSources as $src) {
        $key = (string) ($src['source_key'] ?? '');
        if ($key !== '' && !isset($archivedSourceKeys[$key])) {
            $activeSource = $src;
            $activeSourceKey = $key;
            break;
        }
    }
}

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
    $resolvedSourceKey = $detailSourceKey !== '' ? $detailSourceKey : $activeSourceKey;
    $detailSource = $sourcesRepo->findByKey($resolvedSourceKey);
    $detail['source_key'] = (string) ($detailSource['source_key'] ?? $resolvedSourceKey);
    $detail['source_title'] = (string) ($detailSource['title'] ?? $detail['source_key']);
    $detail = $searchTags->attachTagsToProjectDetail(
        $detail,
        $detail['source_key'],
        $searchTags->listProjectTags($detail['source_key'], (string) ($detail['project_name'] ?? '')),
        $searchTags->mapItemTagsForSources([$detail['source_key']])
    );
    $detailArchiveIndex = $archives->indexForSources([$detail['source_key']]);
    $detail = $archives->attachToProjectDetail($detail, $detail['source_key'], $detailArchiveIndex);
    if (!$isAdmin && !empty($detail['archived'])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Project not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'project' => $detail,
        'can_edit_tags' => $isAdmin,
        'can_archive' => $isAdmin,
        'all_tags' => $isAdmin ? $searchTags->listAll() : [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Lightweight catalog index for LinkNest-style client search (one or many folders).
if ($actionParam === 'search_index') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');

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
    $indexSourceKeys = array_values(array_filter(array_map(
        static fn (array $src): string => (string) ($src['source_key'] ?? ''),
        $indexSources
    )));
    $index = $searchTags->attachTagsToSearchIndex(
        $index,
        $searchTags->mapProjectTagsForSources($indexSourceKeys),
        $searchTags->mapItemTagsForSources($indexSourceKeys)
    );
    $index = $archives->attachToSearchIndex(
        $index,
        $archives->indexForSources($indexSourceKeys)
    );
    if (!$isAdmin) {
        $index = $archives->excludeArchivedFromSearchIndex($index);
    }
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
        'can_edit_tags' => $isAdmin,
        'can_archive' => $isAdmin,
        'last_synced_at' => (string) ($primary['last_synced_at'] ?? ''),
        'last_sync_status' => (string) ($primary['last_sync_status'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($actionParam === 'owner_stats') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=30');

    $sourcesParam = trim((string) ($_GET['sources'] ?? ''));
    $wantedKeys = [];
    if ($sourcesParam === 'all' || $sourcesParam === '') {
        foreach ($allSources as $src) {
            $wantedKeys[] = (string) ($src['source_key'] ?? '');
        }
    } else {
        $wantedKeys = array_values(array_filter(array_map('trim', explode(',', $sourcesParam))));
    }

    $byKey = [];
    foreach ($allSources as $src) {
        $key = (string) ($src['source_key'] ?? '');
        if ($key !== '') {
            $byKey[$key] = $src;
        }
    }

    $selectedKeys = [];
    $sourceTitles = [];
    foreach ($wantedKeys as $key) {
        if (!isset($byKey[$key])) {
            continue;
        }
        $selectedKeys[] = $key;
        $sourceTitles[$key] = (string) ($byKey[$key]['title'] ?? $key);
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
            $source = $sourcesRepo->requireByKey($key);
            $expected = trim((string) ($source['title'] ?? ''));
            if ($expected === '') {
                $expected = (string) $source['source_key'];
            }
            $confirmTitle = trim((string) ($_POST['confirm_title'] ?? ''));
            if ($confirmTitle === '' || !hash_equals($expected, $confirmTitle)) {
                throw new RuntimeException('Type the folder display name exactly to confirm deletion.');
            }
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
                'sharepoint_enable_one_click_sync' => isset($_POST['sharepoint_enable_one_click_sync']) ? '1' : '0',
                'sharepoint_enable_console_sync' => isset($_POST['sharepoint_enable_console_sync']) ? '1' : '0',
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
                [
                    'graph_only' => true,
                    'enable_one_click_sync' => $payload['sharepoint_enable_one_click_sync'] === '1',
                    'enable_console_sync' => $payload['sharepoint_enable_console_sync'] === '1',
                ]
            );
            $flash = 'SharePoint Graph credentials saved.';
        } elseif ($action === 'prepare_browser_sync') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            $prepareKey = trim((string) ($_POST['source_key'] ?? $activeSourceKey));
            $importUrl = AppUrl::absolute('sharepoint.php?action=browser_sync_import');
            $prepared = $browserSync->prepare($prepareKey, $importUrl);
            echo json_encode(['ok' => true] + $prepared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } elseif ($action === 'delegated_sync') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            $syncKey = trim((string) ($_POST['source_key'] ?? $activeSourceKey));
            $accessToken = trim((string) ($_POST['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new RuntimeException('Missing Microsoft access token. Sign in again and retry Sync.');
            }
            $syncSource = $sourcesRepo->requireByKey($syncKey);
            $result = $graph->sync([
                'source_key' => (string) $syncSource['source_key'],
                'site_host' => (string) $syncSource['site_host'],
                'site_path' => (string) $syncSource['site_path'],
                'folder_path' => (string) $syncSource['folder_path'],
                'access_token' => $accessToken,
            ]);
            $sourcesRepo->markSynced(
                (string) $syncSource['source_key'],
                'msal_sync',
                (int) ($result['count'] ?? 0)
            );
            $auth->users()->logAudit(
                'sharepoint.msal_sync',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'count' => $result['count'] ?? 0,
                    'projects' => $result['projects'] ?? 0,
                    'source_key' => (string) $syncSource['source_key'],
                ]
            );
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        } elseif (
            $action === 'create_catalog_share_link'
            || $action === 'create_owners_share_link'
            || $action === 'revoke_catalog_share_link'
            || $action === 'revoke_owners_share_link'
            || $action === 'purge_catalog_share_history'
            || $action === 'purge_owners_share_history'
            || $action === 'email_catalog_share_link'
            || $action === 'email_owners_share_link'
        ) {
            $shareKind = str_contains($action, 'owners')
                ? CatalogShareRepository::KIND_OWNERS
                : CatalogShareRepository::KIND_CATALOG;
            $shareFlag = $shareKind === CatalogShareRepository::KIND_OWNERS ? 'owners_shared' : 'catalog_shared';
            $postedView = trim((string) ($_POST['view'] ?? $_GET['view'] ?? ''));
            $redirectParams = [
                'source' => $activeSourceKey,
                $shareFlag => '1',
            ];
            if (in_array($postedView, ['owners', 'catalog', 'folders'], true)) {
                $redirectParams['view'] = $postedView;
            }
            // No URL hash: native hash scrolling fights section-board reorder and causes page jumps.
            // Client JS restores the pre-submit scroll position instead.
            $shareRedirect = 'sharepoint.php?' . http_build_query($redirectParams);

            if (str_starts_with($action, 'email_')) {
                $smtpSettings = new SmtpSettings($settings, $crypto);
                if (!$smtpSettings->isEnabled()) {
                    throw new RuntimeException('Outbound email is not enabled. Configure Admin → Email first.');
                }
                $shareId = filter_var($_POST['share_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
                $linkUrl = '';
                $linkLabel = '';
                $matchedId = 0;
                foreach ($catalogShareRepository->listRecent(50, $shareKind) as $link) {
                    if ((int) ($link['id'] ?? 0) === $shareId
                        && !empty($link['is_active'])
                        && !empty($link['can_copy'])
                        && trim((string) ($link['url'] ?? '')) !== ''
                    ) {
                        $linkUrl = trim((string) $link['url']);
                        $linkLabel = trim((string) ($link['label'] ?? ''));
                        $matchedId = (int) $link['id'];
                        break;
                    }
                }
                if ($linkUrl === '' || $matchedId <= 0) {
                    throw new RuntimeException('That public link is not available to email. Copy an active link first.');
                }
                $recipients = SmtpSettings::normalizeRecipients((string) ($_POST['email_to'] ?? ''));
                if ($recipients === []) {
                    throw new RuntimeException('Enter at least one valid recipient email address.');
                }
                $note = trim((string) ($_POST['email_note'] ?? ''));
                if (mb_strlen($note) > 1000) {
                    $note = mb_substr($note, 0, 1000);
                }
                $senderName = trim((string) ($currentUser['display_name'] ?? ''));
                if ($senderName === '') {
                    $senderName = trim((string) ($currentUser['username'] ?? ''));
                }
                $emailKind = $shareKind === CatalogShareRepository::KIND_OWNERS ? 'owners' : 'catalog';
                $templates = new EmailTemplates($branding);
                $message = $templates->shareLink($emailKind, $linkUrl, $senderName, $note, $linkLabel);
                $mailer = new SmtpMailer();
                $result = $mailer->send(
                    $recipients,
                    $message['subject'],
                    $message['text'],
                    $smtpSettings->mailerConfig(),
                    [],
                    [
                        'html' => $message['html'],
                        'text' => $message['text'],
                    ]
                );
                if ($result !== true) {
                    throw new RuntimeException('Failed to send email: ' . (string) $result);
                }
                $auth->users()->logAudit(
                    'share.email',
                    (int) $currentUser['id'],
                    (string) $currentUser['username'],
                    null,
                    null,
                    [
                        'kind' => $emailKind,
                        'share_id' => $matchedId,
                        'recipients' => count($recipients),
                    ]
                );
                $redirectParams['emailed'] = (string) count($recipients);
                $shareRedirect = 'sharepoint.php?' . http_build_query($redirectParams);
                header('Location: ' . $shareRedirect);
                exit;
            }

            if (str_starts_with($action, 'create_')) {
                $postedKeys = $_POST['share_source_keys'] ?? [];
                if (!is_array($postedKeys)) {
                    $postedKeys = [];
                }
                $registeredKeys = [];
                foreach ($allSources as $src) {
                    $key = trim((string) ($src['source_key'] ?? ''));
                    if ($key !== '') {
                        $registeredKeys[] = $key;
                    }
                }
                $selected = [];
                foreach ($postedKeys as $key) {
                    $value = trim((string) $key);
                    if ($value !== '' && in_array($value, $registeredKeys, true)) {
                        $selected[] = $value;
                    }
                }
                if (count($registeredKeys) > 1 && $selected === []) {
                    throw new RuntimeException('Select at least one catalog card to share.');
                }
                if (count($selected) === count($registeredKeys)) {
                    $selected = [];
                }
                $shareTag = CatalogShareRepository::normalizeLabel((string) ($_POST['share_tag'] ?? ''));
                $created = $catalogShareRepository->create($selected, $currentUser, $shareKind, $shareTag);
                $sessionUrlKey = $shareKind === CatalogShareRepository::KIND_OWNERS
                    ? 'fresh_owners_share_url'
                    : 'fresh_catalog_share_url';
                $sessionLabelKey = $shareKind === CatalogShareRepository::KIND_OWNERS
                    ? 'fresh_owners_share_label'
                    : 'fresh_catalog_share_label';
                $_SESSION[$sessionUrlKey] = CatalogShareRepository::absoluteUrl($created['token'], $shareKind);
                $_SESSION[$sessionLabelKey] = (string) ($created['label'] ?? $shareTag);
                $sessionIdKey = $shareKind === CatalogShareRepository::KIND_OWNERS
                    ? 'fresh_owners_share_id'
                    : 'fresh_catalog_share_id';
                $_SESSION[$sessionIdKey] = (int) $created['id'];
                $auth->users()->logAudit(
                    $shareKind === CatalogShareRepository::KIND_OWNERS
                        ? 'sharepoint.owners_share_created'
                        : 'sharepoint.catalog_share_created',
                    (int) $currentUser['id'],
                    (string) $currentUser['username'],
                    null,
                    null,
                    [
                        'source_keys' => $created['source_keys'],
                        'kind' => $shareKind,
                        'label' => $created['label'],
                        'share_id' => $created['id'],
                    ]
                );
            } elseif (str_starts_with($action, 'revoke_')) {
                $shareId = filter_var($_POST['share_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
                if ($shareId <= 0) {
                    throw new RuntimeException('Choose which public link to revoke.');
                }
                if (!$catalogShareRepository->revokeById($shareId, $shareKind)) {
                    throw new RuntimeException('That public link is already revoked or was not found.');
                }
                unset(
                    $_SESSION['fresh_catalog_share_url'],
                    $_SESSION['fresh_owners_share_url'],
                    $_SESSION['fresh_catalog_share_label'],
                    $_SESSION['fresh_owners_share_label'],
                    $_SESSION['fresh_catalog_share_id'],
                    $_SESSION['fresh_owners_share_id']
                );
                $auth->users()->logAudit(
                    $shareKind === CatalogShareRepository::KIND_OWNERS
                        ? 'sharepoint.owners_share_revoked'
                        : 'sharepoint.catalog_share_revoked',
                    (int) $currentUser['id'],
                    (string) $currentUser['username'],
                    null,
                    null,
                    ['kind' => $shareKind, 'share_id' => $shareId]
                );
            } else {
                $purged = $catalogShareRepository->purgeHistory($shareKind);
                $auth->users()->logAudit(
                    $shareKind === CatalogShareRepository::KIND_OWNERS
                        ? 'sharepoint.owners_share_purged'
                        : 'sharepoint.catalog_share_purged',
                    (int) $currentUser['id'],
                    (string) $currentUser['username'],
                    null,
                    null,
                    ['kind' => $shareKind, 'deleted' => $purged]
                );
            }
            header('Location: ' . $shareRedirect);
            exit;
        } elseif ($action === 'reindex') {
            @set_time_limit(120);
            $result = $catalog->reindex();
            $flash = (string) ($result['message'] ?? 'SharePoint search reindex complete.');
            $auth->users()->logAudit(
                'sharepoint.reindex',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'item_count' => $result['item_count'] ?? 0,
                    'fts_count' => $result['fts_count'] ?? 0,
                    'fts_available' => !empty($result['fts_available']),
                    'duration_ms' => $result['duration_ms'] ?? 0,
                ]
            );
        } elseif ($action === 'create_search_tag') {
            $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
                || (string) ($_POST['ajax'] ?? '') === '1';
            $tag = $searchTags->create((string) ($_POST['label'] ?? ''), $currentUser);
            $auth->users()->logAudit(
                'sharepoint.tag_created',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['tag_id' => $tag['id'], 'label' => $tag['label']]
            );
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'tag' => $tag, 'tags' => $searchTags->listAll()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $flash = 'Search tag “' . $tag['label'] . '” created.';
        } elseif ($action === 'delete_search_tag') {
            $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
                || (string) ($_POST['ajax'] ?? '') === '1';
            $tagId = (int) ($_POST['tag_id'] ?? 0);
            $existing = $searchTags->findById($tagId);
            $searchTags->delete($tagId);
            $auth->users()->logAudit(
                'sharepoint.tag_deleted',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['tag_id' => $tagId, 'label' => (string) ($existing['label'] ?? '')]
            );
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'tags' => $searchTags->listAll()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $flash = 'Search tag deleted.';
        } elseif ($action === 'save_project_tags') {
            $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
                || (string) ($_POST['ajax'] ?? '') === '1';
            $tagSource = trim((string) ($_POST['source_key'] ?? $activeSourceKey));
            $projectName = trim((string) ($_POST['project_name'] ?? ''));
            $rawIds = $_POST['tag_ids'] ?? [];
            if (!is_array($rawIds)) {
                $rawIds = $rawIds === '' || $rawIds === null ? [] : explode(',', (string) $rawIds);
            }
            $assigned = $searchTags->setProjectTags($tagSource, $projectName, $rawIds);
            $auth->users()->logAudit(
                'sharepoint.tag_assigned',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'scope' => 'project',
                    'source_key' => $tagSource,
                    'project_name' => $projectName,
                    'tag_ids' => array_column($assigned, 'id'),
                ]
            );
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'tags' => $assigned,
                    'scope' => 'project',
                    'source_key' => $tagSource,
                    'project_name' => $projectName,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $flash = 'Project tags updated.';
        } elseif ($action === 'save_item_tags') {
            $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
                || (string) ($_POST['ajax'] ?? '') === '1';
            $tagSource = trim((string) ($_POST['source_key'] ?? $activeSourceKey));
            $projectName = trim((string) ($_POST['project_name'] ?? ''));
            $relativePath = trim((string) ($_POST['relative_path'] ?? ''));
            $rawIds = $_POST['tag_ids'] ?? [];
            if (!is_array($rawIds)) {
                $rawIds = $rawIds === '' || $rawIds === null ? [] : explode(',', (string) $rawIds);
            }
            $assigned = $searchTags->setItemTags($tagSource, $projectName, $relativePath, $rawIds);
            $auth->users()->logAudit(
                'sharepoint.tag_assigned',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'scope' => 'item',
                    'source_key' => $tagSource,
                    'project_name' => $projectName,
                    'relative_path' => $relativePath,
                    'tag_ids' => array_column($assigned, 'id'),
                ]
            );
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'tags' => $assigned,
                    'scope' => 'item',
                    'source_key' => $tagSource,
                    'project_name' => $projectName,
                    'relative_path' => $relativePath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $flash = 'File/folder tags updated.';
        } elseif ($action === 'set_archive') {
            $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
                || (string) ($_POST['ajax'] ?? '') === '1';
            $archiveSource = trim((string) ($_POST['source_key'] ?? $activeSourceKey));
            $archiveScope = trim((string) ($_POST['scope'] ?? 'project'));
            $archiveProject = trim((string) ($_POST['project_name'] ?? ''));
            $archivePath = trim((string) ($_POST['relative_path'] ?? ''));
            $rawArchived = $_POST['archived'] ?? '1';
            $archived = $rawArchived === true
                || $rawArchived === 1
                || $rawArchived === '1'
                || strtolower((string) $rawArchived) === 'true';
            $result = $archives->setArchived(
                $archiveSource,
                $archiveScope,
                $archived,
                $archiveProject,
                $archivePath,
                $currentUser
            );
            $auth->users()->logAudit(
                $archived ? 'sharepoint.archived' : 'sharepoint.unarchived',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                $result
            );
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $flash = $archived ? 'Archived. It is hidden from the catalog until you unarchive it.' : 'Restored to the catalog.';
            header('Location: sharepoint.php?source=' . rawurlencode($activeSourceKey) . '#sharepoint-sources');
            exit;
        } elseif ($action === 'purge_catalog') {
            $confirm = trim((string) ($_POST['confirm_purge'] ?? ''));
            if (strcasecmp($confirm, 'PURGE') !== 0) {
                throw new RuntimeException('Type PURGE to confirm catalog purge.');
            }
            $rawSources = $_POST['purge_sources'] ?? [];
            if (!is_array($rawSources)) {
                $rawSources = [];
            }
            $purgeKeys = [];
            $known = [];
            foreach ($allSources as $src) {
                $key = (string) ($src['source_key'] ?? '');
                if ($key !== '') {
                    $known[$key] = true;
                }
            }
            foreach ($rawSources as $raw) {
                $key = trim((string) $raw);
                if ($key !== '' && isset($known[$key]) && !in_array($key, $purgeKeys, true)) {
                    $purgeKeys[] = $key;
                }
            }
            if ($purgeKeys === []) {
                throw new RuntimeException('Select at least one SharePoint folder catalog to purge.');
            }

            $takeSnapshot = !empty($_POST['take_snapshot']);
            $clearTags = !empty($_POST['clear_tags']);
            $deleteUnusedTags = !empty($_POST['delete_unused_tags']);
            $runVacuum = !empty($_POST['run_vacuum']);

            @set_time_limit(180);
            $snapshotFilename = null;
            if ($takeSnapshot) {
                $dbConfig = require dirname(__DIR__) . '/config/database.php';
                $maintenance = SqliteMaintenance::fromConfig($pdo, $dbConfig);
                $created = $maintenance->createSnapshot('sharepoint-purge');
                $snapshotFilename = (string) ($created['filename'] ?? '');
            }

            $purgeResult = $catalog->purgeItemsForSources($purgeKeys);
            $sourcesRepo->resetSyncStatusForSources($purgeKeys);
            $tagPurge = ['assignments_deleted' => 0, 'tags_deleted' => 0];
            if ($clearTags) {
                $tagPurge = $searchTags->purgeForSources($purgeKeys, $deleteUnusedTags);
            }

            $vacuumNote = '';
            if ($runVacuum) {
                $dbConfig = $dbConfig ?? require dirname(__DIR__) . '/config/database.php';
                $maintenance = $maintenance ?? SqliteMaintenance::fromConfig($pdo, $dbConfig);
                $before = is_file($maintenance->databasePath()) ? (int) filesize($maintenance->databasePath()) : 0;
                $maintenance->vacuum();
                clearstatcache(true, $maintenance->databasePath());
                $after = is_file($maintenance->databasePath()) ? (int) filesize($maintenance->databasePath()) : 0;
                $vacuumNote = ' VACUUM ' . SqliteMaintenance::formatBytes($before)
                    . ' → ' . SqliteMaintenance::formatBytes($after) . '.';
            }

            $auth->users()->logAudit(
                'sharepoint.catalog_purged',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'sources' => $purgeKeys,
                    'items_deleted' => $purgeResult['items_deleted'] ?? 0,
                    'clear_tags' => $clearTags,
                    'assignments_deleted' => $tagPurge['assignments_deleted'],
                    'tags_deleted' => $tagPurge['tags_deleted'],
                    'snapshot' => $snapshotFilename,
                    'vacuum' => $runVacuum,
                ]
            );

            $flash = 'Purged ' . (int) ($purgeResult['items_deleted'] ?? 0)
                . ' catalog item(s) from ' . count($purgeKeys) . ' folder(s).'
                . ($snapshotFilename !== null && $snapshotFilename !== '' ? ' Snapshot: ' . $snapshotFilename . '.' : '')
                . ($clearTags
                    ? ' Cleared ' . (int) $tagPurge['assignments_deleted'] . ' tag assignment(s)'
                        . ((int) $tagPurge['tags_deleted'] > 0 ? ' and ' . (int) $tagPurge['tags_deleted'] . ' unused tag(s)' : '')
                        . '.'
                    : ' Search tags kept for re-attach after resync.')
                . $vacuumNote
                . ' Sync again to refill the catalog.';
            header('Location: sharepoint.php?source=' . rawurlencode($activeSourceKey) . '#sharepoint-admin-purge');
            exit;
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'prepare_browser_sync' || $action === 'delegated_sync'
            || $action === 'create_search_tag' || $action === 'delete_search_tag'
            || $action === 'save_project_tags' || $action === 'save_item_tags'
            || $action === 'set_archive') {
            $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
                || (string) ($_POST['ajax'] ?? '') === '1'
                || $action === 'prepare_browser_sync'
                || $action === 'delegated_sync';
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $error = $exception->getMessage();
        $action = (string) ($_POST['action'] ?? '');
        if (str_contains($action, 'share')) {
            if (str_contains($action, 'owners')) {
                $ownersShareFlash = $error;
                $ownersShareFlashType = 'error';
            } else {
                $catalogShareFlash = $error;
                $catalogShareFlashType = 'error';
            }
            $error = '';
        }
    }
}

$status = $graph->status();
$enableOneClickSync = !empty($status['enable_one_click_sync']);
$enableConsoleSync = !empty($status['enable_console_sync']);
// Drop autofill junk that is not a GUID (e.g. password-manager short codes).
$thisClientIdSafe = (string) ($status['client_id'] ?? '');
if ($thisClientIdSafe !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $thisClientIdSafe)) {
    $settings->set('sharepoint_client_id', '');
    $thisClientIdSafe = '';
    $status['client_id'] = '';
}

$msalRedirectUri = AppUrl::absoluteMatchingRequest('sharepoint.php');

if (isset($_GET['emailed']) && $catalogShareFlash === '' && $ownersShareFlash === '') {
    $emailedCount = max(1, (int) $_GET['emailed']);
    $emailedMsg = $emailedCount === 1
        ? 'Public link emailed to 1 recipient.'
        : 'Public link emailed to ' . $emailedCount . ' recipients.';
    if (isset($_GET['owners_shared'])) {
        $ownersShareFlash = $emailedMsg;
    } else {
        $catalogShareFlash = $emailedMsg;
    }
} elseif (isset($_GET['catalog_shared']) && $catalogShareFlash === '') {
    $catalogShareFlash = 'Catalog share settings updated.';
} elseif (isset($_GET['owners_shared']) && $ownersShareFlash === '') {
    $ownersShareFlash = 'Project owners share settings updated.';
}

$smtpSettingsUi = new SmtpSettings($settings, $crypto);
$smtpAllUi = $smtpSettingsUi->all(false);
$smtpEnabled = $smtpSettingsUi->isEnabled();
$smtpConfigured = trim((string) $smtpAllUi['host']) !== ''
    && trim((string) $smtpAllUi['from_email']) !== '';
$viewerIsAdmin = $isAdmin;
$freshCatalogShareId = null;
$freshOwnersShareId = null;
if (isset($_SESSION['fresh_catalog_share_url'])) {
    $freshCatalogShareUrl = (string) $_SESSION['fresh_catalog_share_url'];
    unset($_SESSION['fresh_catalog_share_url']);
}
if (isset($_SESSION['fresh_catalog_share_label'])) {
    $freshCatalogShareLabel = (string) $_SESSION['fresh_catalog_share_label'];
    unset($_SESSION['fresh_catalog_share_label']);
}
if (isset($_SESSION['fresh_catalog_share_id'])) {
    $freshCatalogShareId = (int) $_SESSION['fresh_catalog_share_id'];
    unset($_SESSION['fresh_catalog_share_id']);
}
if (isset($_SESSION['fresh_owners_share_url'])) {
    $freshOwnersShareUrl = (string) $_SESSION['fresh_owners_share_url'];
    unset($_SESSION['fresh_owners_share_url']);
}
if (isset($_SESSION['fresh_owners_share_label'])) {
    $freshOwnersShareLabel = (string) $_SESSION['fresh_owners_share_label'];
    unset($_SESSION['fresh_owners_share_label']);
}
if (isset($_SESSION['fresh_owners_share_id'])) {
    $freshOwnersShareId = (int) $_SESSION['fresh_owners_share_id'];
    unset($_SESSION['fresh_owners_share_id']);
}

$shareHistoryPerPage = CatalogShareRepository::HISTORY_PER_PAGE;
$catalogShareHistPage = max(1, (int) ($_GET['cshare_page'] ?? 1));
$ownersShareHistPage = max(1, (int) ($_GET['oshare_page'] ?? 1));
$catalogShareTotal = $catalogShareRepository->countHistory(CatalogShareRepository::KIND_CATALOG);
$ownersShareTotal = $catalogShareRepository->countHistory(CatalogShareRepository::KIND_OWNERS);
$catalogSharePages = $catalogShareTotal > 0 ? max(1, (int) ceil($catalogShareTotal / $shareHistoryPerPage)) : 1;
$ownersSharePages = $ownersShareTotal > 0 ? max(1, (int) ceil($ownersShareTotal / $shareHistoryPerPage)) : 1;
if ($catalogShareHistPage > $catalogSharePages) {
    $catalogShareHistPage = $catalogSharePages;
}
if ($ownersShareHistPage > $ownersSharePages) {
    $ownersShareHistPage = $ownersSharePages;
}
$catalogShareLinks = $catalogShareRepository->listPage(
    CatalogShareRepository::KIND_CATALOG,
    $catalogShareHistPage,
    $shareHistoryPerPage
);
$ownersShareLinks = $catalogShareRepository->listPage(
    CatalogShareRepository::KIND_OWNERS,
    $ownersShareHistPage,
    $shareHistoryPerPage
);
$catalogShareActiveCount = $catalogShareRepository->countActive(CatalogShareRepository::KIND_CATALOG);
$ownersShareActiveCount = $catalogShareRepository->countActive(CatalogShareRepository::KIND_OWNERS);

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

$purgeDbConfig = require dirname(__DIR__) . '/config/database.php';
$purgeMaintenance = SqliteMaintenance::fromConfig($pdo, $purgeDbConfig);
$purgeDbStatus = $purgeMaintenance->status(false);
$purgeTableStats = [
    [
        'name' => 'sharepoint_items',
        'label' => 'Catalog items',
        'rows' => $catalog->countAll(),
        'bytes' => $catalog->approximateTableBytes('sharepoint_items'),
    ],
    [
        'name' => 'sharepoint_items_fts',
        'label' => 'Search index (FTS)',
        'rows' => $catalog->countFts(),
        'bytes' => $catalog->approximateTableBytes('sharepoint_items_fts'),
    ],
    [
        'name' => 'sharepoint_sources',
        'label' => 'Registered folders',
        'rows' => count($allSources),
        'bytes' => $catalog->approximateTableBytes('sharepoint_sources'),
    ],
    [
        'name' => 'sharepoint_search_tags',
        'label' => 'Search tags',
        'rows' => $searchTags->countTags(),
        'bytes' => $catalog->approximateTableBytes('sharepoint_search_tags'),
    ],
    [
        'name' => 'sharepoint_search_tag_assignments',
        'label' => 'Tag assignments',
        'rows' => $searchTags->countAssignments(),
        'bytes' => $catalog->approximateTableBytes('sharepoint_search_tag_assignments'),
    ],
];

$catalogTones = [];
$catalogToneExtra = 0;
$catalogToneExtras = ['violet', 'sky', 'lime', 'slate'];
foreach ($allSources as $src) {
    $key = (string) ($src['source_key'] ?? '');
    if ($key === '') {
        continue;
    }
    $hay = strtolower(trim((string) ($src['title'] ?? '') . ' ' . $key));
    if (preg_match('/\bprivate\b/', $hay) === 1) {
        $catalogTones[$key] = 'private';
    } elseif (preg_match('/\bpublic\b/', $hay) === 1) {
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

$viewMode = trim((string) ($_GET['view'] ?? ''));
$ownerSolo = $viewMode === 'owners';
$catalogSolo = $viewMode === 'catalog';
$foldersSolo = $viewMode === 'folders';
$panelSolo = $ownerSolo || $catalogSolo || $foldersSolo;

$sharepointListUrl = static function (array $overrides = []) use ($query, $perPage, $page, $activeSourceKey, $catalogSolo): string {
    $params = array_merge([
        'source' => $activeSourceKey,
        'q' => $query,
        'page' => $page,
        'per' => $perPage,
    ], $overrides);
    if ($catalogSolo) {
        $params['view'] = 'catalog';
    }
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
    if (($params['view'] ?? '') === '') {
        unset($params['view']);
    }
    $qs = http_build_query($params);

    return 'sharepoint.php' . ($qs !== '' ? '?' . $qs : '') . '#sharepoint-search';
};

$shareListUrl = static function (array $overrides = []) use ($activeSourceKey, $viewMode, $catalogShareHistPage, $ownersShareHistPage): string {
    $params = array_merge([
        'source' => $activeSourceKey,
        'cshare_page' => $catalogShareHistPage,
        'oshare_page' => $ownersShareHistPage,
    ], $overrides);
    unset($params['_hash']);
    if (in_array($viewMode, ['owners', 'catalog', 'folders'], true) && ($params['view'] ?? '') === '') {
        $params['view'] = $viewMode;
    }
    if ((int) ($params['cshare_page'] ?? 1) <= 1) {
        unset($params['cshare_page']);
    }
    if ((int) ($params['oshare_page'] ?? 1) <= 1) {
        unset($params['oshare_page']);
    }
    if (($params['source'] ?? '') === '') {
        unset($params['source']);
    }
    if (($params['view'] ?? '') === '') {
        unset($params['view']);
    }
    $qs = http_build_query($params);

    // Keep pagination on the same viewport via JS scroll restore (no hash jump).
    return 'sharepoint.php' . ($qs !== '' ? '?' . $qs : '');
};

$sourceTitleByKey = [];
foreach ($allSources as $src) {
    $sourceTitleByKey[(string) ($src['source_key'] ?? '')] = (string) ($src['title'] ?? $src['source_key'] ?? '');
}

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

$projectFileMeta = static function (string $name): array {
    static $map = [
        'pdf' => ['emoji' => '📕', 'label' => 'PDF', 'tone' => 'pdf'],
        'doc' => ['emoji' => '📘', 'label' => 'Word', 'tone' => 'word'],
        'docx' => ['emoji' => '📘', 'label' => 'Word', 'tone' => 'word'],
        'rtf' => ['emoji' => '📘', 'label' => 'Word', 'tone' => 'word'],
        'xls' => ['emoji' => '📊', 'label' => 'Excel', 'tone' => 'excel'],
        'xlsx' => ['emoji' => '📊', 'label' => 'Excel', 'tone' => 'excel'],
        'xlsm' => ['emoji' => '📊', 'label' => 'Excel', 'tone' => 'excel'],
        'csv' => ['emoji' => '📑', 'label' => 'CSV', 'tone' => 'excel'],
        'ppt' => ['emoji' => '📙', 'label' => 'PowerPoint', 'tone' => 'ppt'],
        'pptx' => ['emoji' => '📙', 'label' => 'PowerPoint', 'tone' => 'ppt'],
        'vsd' => ['emoji' => '📐', 'label' => 'Visio', 'tone' => 'visio'],
        'vsdx' => ['emoji' => '📐', 'label' => 'Visio', 'tone' => 'visio'],
        'jpg' => ['emoji' => '🖼️', 'label' => 'Image', 'tone' => 'image'],
        'jpeg' => ['emoji' => '🖼️', 'label' => 'Image', 'tone' => 'image'],
        'png' => ['emoji' => '🖼️', 'label' => 'Image', 'tone' => 'image'],
        'gif' => ['emoji' => '🖼️', 'label' => 'Image', 'tone' => 'image'],
        'webp' => ['emoji' => '🖼️', 'label' => 'Image', 'tone' => 'image'],
        'svg' => ['emoji' => '🖼️', 'label' => 'Image', 'tone' => 'image'],
        'txt' => ['emoji' => '📝', 'label' => 'Text', 'tone' => 'text'],
        'md' => ['emoji' => '📝', 'label' => 'Markdown', 'tone' => 'text'],
        'json' => ['emoji' => '🧾', 'label' => 'JSON', 'tone' => 'code'],
        'xml' => ['emoji' => '🧾', 'label' => 'XML', 'tone' => 'code'],
        'html' => ['emoji' => '🌐', 'label' => 'HTML', 'tone' => 'code'],
        'zip' => ['emoji' => '📦', 'label' => 'Archive', 'tone' => 'archive'],
        'rar' => ['emoji' => '📦', 'label' => 'Archive', 'tone' => 'archive'],
        '7z' => ['emoji' => '📦', 'label' => 'Archive', 'tone' => 'archive'],
        'msg' => ['emoji' => '✉️', 'label' => 'Email', 'tone' => 'email'],
        'eml' => ['emoji' => '✉️', 'label' => 'Email', 'tone' => 'email'],
        'mp4' => ['emoji' => '🎬', 'label' => 'Video', 'tone' => 'media'],
        'one' => ['emoji' => '📓', 'label' => 'OneNote', 'tone' => 'onenote'],
        'agent' => ['emoji' => '📄', 'label' => 'AGENT', 'tone' => 'file'],
    ];
    $base = basename(str_replace('\\', '/', $name));
    $dot = strrpos($base, '.');
    if ($dot !== false && $dot > 0 && $dot < strlen($base) - 1) {
        $ext = strtolower(substr($base, $dot + 1));
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if ($ext !== '') {
            return ['emoji' => '📄', 'label' => strtoupper($ext), 'tone' => 'file'];
        }
    }

    return ['emoji' => '📁', 'label' => 'Folder', 'tone' => 'folder'];
};

$activeTitle = (string) ($activeSource['title'] ?? 'SharePoint folder');
$activeFolderPath = (string) ($activeSource['folder_path'] ?? '');
$activeSiteHost = (string) ($activeSource['site_host'] ?? '');
$activeSitePath = (string) ($activeSource['site_path'] ?? '');
$activeFolderUrl = (string) ($activeSource['folder_url'] ?? '');
$activeLastSynced = (string) ($activeSource['last_synced_at'] ?? '');
$activeLastStatus = (string) ($activeSource['last_sync_status'] ?? '');
$activeLastError = (string) ($activeSource['last_sync_error'] ?? '');
$ownerDashUrl = 'sharepoint.php?view=owners';
$catalogDashUrl = 'sharepoint.php?view=catalog&source=' . rawurlencode($activeSourceKey);
if ($query !== '') {
    $catalogDashUrl .= '&q=' . rawurlencode($query);
}
$foldersDashUrl = 'sharepoint.php?view=folders';
$pageHeading = $ownerSolo
    ? '👤 Project owners'
    : ($foldersSolo ? '📁 SharePoint folders' : ($catalogSolo ? '🔎 architecture project catalog(s)' : '📁 SharePoint catalog'));
$pageTitle = $ownerSolo
    ? 'Project owners'
    : ($foldersSolo ? 'SharePoint folders' : ($catalogSolo ? 'architecture project catalog(s)' : $activeTitle));
$soloPageClass = $ownerSolo
    ? ' sharepoint-owner-solo-page'
    : ($catalogSolo ? ' sharepoint-catalog-solo-page' : ($foldersSolo ? ' sharepoint-folders-solo-page' : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · SharePoint · <?= e($branding->documentTitle()) ?></title>
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
    <?php if ($isAdmin && !$foldersSolo): ?>
    <script>
    (function () {
        try {
            if ('scrollRestoration' in history) {
                history.scrollRestoration = 'manual';
            }
            var search = String(location.search || '');
            var shouldPin = /[?&](catalog_shared|owners_shared|emailed|cshare_page|oshare_page)=/.test(search);
            if (!shouldPin) {
                return;
            }
            var raw = sessionStorage.getItem('riskregister_sp_share_scroll');
            var panelId = sessionStorage.getItem('riskregister_sp_share_panel') || '';
            var rawOffset = sessionStorage.getItem('riskregister_sp_share_panel_offset');
            if (raw === null || raw === '') {
                return;
            }
            var top = parseInt(raw, 10);
            if (!isFinite(top) || top < 0) {
                return;
            }
            var offset = rawOffset === null || rawOffset === '' ? null : parseInt(rawOffset, 10);
            var pin = function () {
                var next = top;
                if (panelId && isFinite(offset)) {
                    var card = document.getElementById(panelId);
                    if (card) {
                        next = Math.max(0, Math.round(card.getBoundingClientRect().top + window.scrollY - offset));
                    }
                }
                var se = document.scrollingElement || document.documentElement;
                if (se) {
                    se.scrollTop = next;
                }
                window.scrollTo(0, next);
            };
            pin();
            document.addEventListener('DOMContentLoaded', pin);
            window.addEventListener('load', pin);
        } catch (e) { /* ignore */ }
    })();
    </script>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="shell upload-page sharepoint-catalog-page<?= e($soloPageClass) ?>">
        <header class="topbar topbar-uplift">
            <a class="brand brand-link" href="index.php#find-projects" title="Find projects by name">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1><?= e($pageHeading) ?></h1>
                </div>
            </a>
            <div class="topbar-actions">
                <?php require __DIR__ . '/includes/topbar-menu-start.php'; ?>
                <a class="button ghost home-link" data-menu-group="risk" data-menu-tone="sky" href="index.php#find-projects" title="Search and open saved risk assessments by name, vendor, owner, and more"><span class="topbar-menu-emoji" aria-hidden="true">🔎</span>Find projects</a>
                <a class="button ghost home-link" data-menu-group="risk" data-menu-tone="mint" href="index.php#upload" title="Upload an Architecture Risk Assessment workbook (.xlsx) to generate a dashboard"><span class="topbar-menu-emoji" aria-hidden="true">📤</span>Upload</a>
                <a class="button ghost home-link" data-menu-group="risk" data-menu-tone="lavender" href="templates.php" title="Browse and manage assessment workbook templates"><span class="topbar-menu-emoji" aria-hidden="true">📚</span>Templates</a>
                <a class="button ghost home-link<?= !$panelSolo ? ' is-active' : '' ?>" data-menu-group="sharepoint" data-menu-tone="peach" href="sharepoint.php?source=<?= e($activeSourceKey) ?>"<?= !$panelSolo ? ' aria-current="page"' : '' ?> title="Browse SharePoint folders, sync projects, and search architecture work"><span class="topbar-menu-emoji" aria-hidden="true">📁</span>SharePoint</a>
                <?php require __DIR__ . '/includes/catalog-nav-link.php'; ?>
                <?php
                $ownersNavUrl = $ownerDashUrl;
                require __DIR__ . '/includes/owners-nav-link.php';
                ?>
                <?php require __DIR__ . '/includes/ticket-dossier-nav-link.php'; ?>

                <?php require __DIR__ . '/includes/updates-nav.php'; ?>
                <?php require __DIR__ . '/includes/topbar-menu-end.php'; ?>
                <div class="updated template-count-chip"><?= (int) $projectCount ?> project<?= $projectCount === 1 ? '' : 's' ?></div>
            </div>
        </header>

        <main>
            <?php if (!$ownerSolo): ?>
            <?php if (!$panelSolo): ?>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">SharePoint catalog</div>
                            <h2 id="sharepoint-hero-title"><?= e($activeTitle) ?></h2>
                            <p>
                                Search project folders and open every stored link from
                                <strong id="sharepoint-hero-folder"><?= e($activeFolderPath) ?></strong>
                                on
                                <strong id="sharepoint-hero-site"><?= e($activeSiteHost . $activeSitePath) ?></strong>.
                            </p>
                        </div>
                        <?php require __DIR__ . '/includes/hero-medallion.php'; renderHeroMedallion((int) $itemCount, 'catalog items'); ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error">⚠️ <?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($flash !== ''): ?>
                <div class="alert alert-success">✅ <?= e($flash) ?></div>
            <?php endif; ?>

            <?php if (!$panelSolo): ?>
            <?php $homeTab = 'sharepoint'; require __DIR__ . '/includes/home-section-tabs.php'; ?>
            <?php endif; ?>

            <?php if (!$panelSolo): ?>
            <?php $showSectionMove = true; ?>
            <div id="sharepoint-section-board" class="sharepoint-section-board" data-layout="stack">
                <div class="sharepoint-section-board-toolbar" data-no-toggle>
                    <button type="button" class="button ghost" id="sharepoint-section-reset" title="Restore default section order">Reset section order</button>
                </div>
            <?php else: ?>
            <?php $showSectionMove = false; ?>
            <?php endif; ?>

            <?php if (!$catalogSolo): ?>
            <section class="upload-card sharepoint-sources-panel" id="sharepoint-sources" data-sp-section="folders" data-folders-view="compact" data-solo="<?= $foldersSolo ? '1' : '0' ?>">
                <details class="sharepoint-sources-shell" id="sharepoint-sources-shell" open>
                    <summary class="card-heading sharepoint-sources-heading sharepoint-sources-summary">
                        <div>
                            <h2>📁 SharePoint folders</h2>
                            <p class="panel-help">Each folder has its own catalog and search. Use <strong>Sync</strong> for one-click Microsoft login (MFA in popup), or Console sync as a fallback.</p>
                        </div>
                        <div class="sharepoint-sources-summary-tools" data-no-toggle onclick="event.stopPropagation()">
                            <?php require __DIR__ . '/includes/sharepoint-section-move.php'; ?>
                            <div class="sp-view-toggle sharepoint-folders-view-toggle" role="group" aria-label="Folder layout">
                                <button type="button" class="sp-view-btn" data-folders-view="comfort" aria-pressed="false" title="Roomier folder cards">Comfort</button>
                                <button type="button" class="sp-view-btn is-active" data-folders-view="compact" aria-pressed="true" title="Shrink the folder panel so catalog search has more room">Compact</button>
                                <button type="button" class="sp-view-btn" data-folders-view="table" aria-pressed="false" title="Table view">Table</button>
                            </div>
                            <?php if ($foldersSolo): ?>
                                <a class="button ghost" href="sharepoint.php?source=<?= e($activeSourceKey) ?>">← SharePoint</a>
                            <?php else: ?>
                                <a class="button ghost-light" id="sp-folders-open-tab" href="<?= e($foldersDashUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open SharePoint folders in a new browser tab">↗ New tab</a>
                                <button type="button" class="button ghost" id="sp-folders-open-window" title="Open SharePoint folders in a separate window">🗗 Window</button>
                                <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
                            <?php endif; ?>
                        </div>
                    </summary>
                    <div class="sharepoint-sources-body">
                <div class="sharepoint-sources-grid" id="sharepoint-sources-grid">
                    <?php foreach ($allSources as $src): ?>
                        <?php
                        $srcKey = (string) ($src['source_key'] ?? '');
                        $srcArchived = isset($archivedSourceKeys[$srcKey]);
                        if (!$isAdmin && $srcArchived) {
                            continue;
                        }
                        $isActiveCard = $srcKey === $activeSourceKey;
                        $srcCount = (int) ($sourceCounts[$srcKey] ?? 0);
                        $srcSynced = (string) ($src['last_synced_at'] ?? '');
                        $srcStatus = (string) ($src['last_sync_status'] ?? '');
                        $srcUrl = (string) ($src['folder_url'] ?? '');
                        $srcTitle = (string) ($src['title'] ?? $srcKey);
                        $srcFolderPath = (string) ($src['folder_path'] ?? '');
                        $srcSite = (string) (($src['site_host'] ?? '') . ($src['site_path'] ?? ''));
                        $srcTone = (string) ($catalogTones[$srcKey] ?? 'slate');
                        $srcToneHex = (string) ($catalogToneHex[$srcTone] ?? '#475569');
                        ?>
                        <article class="sharepoint-source-card<?= $isActiveCard ? ' is-active' : '' ?><?= $srcArchived ? ' is-archived' : '' ?>" data-source-key="<?= e($srcKey) ?>" data-catalog-tone="<?= e($srcTone) ?>" style="--catalog-tone: <?= e($srcToneHex) ?>"<?= $srcArchived ? ' data-archived="1"' : '' ?>>
                            <div class="sharepoint-source-card-head">
                                <h3>
                                    <span class="sp-card-emoji" data-tone="folder" aria-hidden="true">📂</span>
                                    <span class="sharepoint-source-card-title"><?= e($srcTitle) ?></span>
                                </h3>
                                <div class="sharepoint-source-card-tools">
                                    <?php require __DIR__ . '/includes/sharepoint-source-color-btn.php'; ?>
                                    <?php if ($srcArchived): ?>
                                        <span class="sharepoint-source-badge sharepoint-archive-badge">Archived</span>
                                    <?php elseif ($isActiveCard): ?>
                                        <span class="sharepoint-source-badge">Active</span>
                                    <?php endif; ?>
                                </div>
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
                                <a class="button ghost-light" href="sharepoint.php?source=<?= e($srcKey) ?>&amp;sources=<?= e($srcKey) ?>#sharepoint-search"><span class="sp-card-emoji" data-tone="folder" aria-hidden="true">📂</span> Open catalog</a>
                                <?php if ($isAdmin): ?>
                                    <?php if ($enableOneClickSync): ?>
                                        <button type="button" class="button button-primary sharepoint-msal-sync-btn" data-source-key="<?= e($srcKey) ?>"><span class="sp-card-emoji" data-tone="sync" aria-hidden="true">🔄</span> Sync</button>
                                    <?php endif; ?>
                                    <?php if ($enableConsoleSync): ?>
                                        <button type="button" class="button ghost-light sharepoint-mfa-prepare-btn" data-source-key="<?= e($srcKey) ?>" title="Prepare, copy script, and open SharePoint"><span class="sp-card-emoji" data-tone="lock" aria-hidden="true">🔐</span> Console sync</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($srcUrl !== ''): ?>
                                    <a class="button ghost" href="<?= e($srcUrl) ?>" target="_blank" rel="noopener noreferrer"><span class="sp-card-emoji" data-tone="link" aria-hidden="true">🔗</span> Open in SharePoint</a>
                                <?php endif; ?>
                                <?php if ($isAdmin): ?>
                                    <form method="post" class="sharepoint-archive-source-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_archive">
                                        <input type="hidden" name="scope" value="source">
                                        <input type="hidden" name="source_key" value="<?= e($srcKey) ?>">
                                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                                        <input type="hidden" name="archived" value="<?= $srcArchived ? '0' : '1' ?>">
                                        <button type="submit" class="button ghost-light sp-archive-source-btn" title="<?= $srcArchived ? 'Show this catalog on the dashboard again' : 'Hide this catalog from the dashboard. You can unarchive it later.' ?>">
                                            <span class="sp-card-emoji" aria-hidden="true"><?= $srcArchived ? '↩️' : '📦' ?></span>
                                            <?= $srcArchived ? 'Unarchive' : 'Archive' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php if ($isAdmin): ?>
                                <?php
                                $srcIdSafe = preg_replace('/[^a-zA-Z0-9_-]/', '', $srcKey) ?: 'source';
                                $editFormId = 'sp-src-edit-' . $srcIdSafe;
                                ?>
                                <details class="sharepoint-source-edit">
                                    <summary>
                                        <span class="sp-card-emoji" data-tone="edit" aria-hidden="true">✏️</span>
                                        <span>Edit</span>
                                    </summary>
                                    <div class="sharepoint-source-edit-toolbar">
                                        <button type="submit" class="button button-primary sharepoint-source-save-btn" form="<?= e($editFormId) ?>" title="Save folder">
                                            <span class="sp-card-emoji" data-tone="save" aria-hidden="true">💾</span>
                                            Save
                                        </button>
                                    </div>
                                    <form method="post" class="sharepoint-source-edit-form" id="<?= e($editFormId) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_source">
                                        <input type="hidden" name="source_key" value="<?= e($srcKey) ?>">
                                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                                        <label class="sharepoint-add-field">
                                            <span class="sharepoint-add-field-label"><span class="sp-card-emoji" data-tone="tag" aria-hidden="true">🏷️</span> Display name</span>
                                            <input type="text" name="title" value="<?= e($srcTitle) ?>" required maxlength="200" autocomplete="off">
                                        </label>
                                        <label class="sharepoint-add-field sharepoint-folder-url-label">
                                            <span class="sharepoint-add-field-label"><span class="sp-card-emoji" data-tone="link" aria-hidden="true">🔗</span> Folder URL</span>
                                            <input type="url" name="folder_url" value="<?= e($srcUrl) ?>" required autocomplete="off" spellcheck="false">
                                        </label>
                                    </form>
                                    <?php if (count($allSources) > 1): ?>
                                        <div class="sharepoint-source-edit-foot">
                                            <button
                                                type="button"
                                                class="sharepoint-source-delete-btn"
                                                title="Delete this catalog"
                                                data-source-key="<?= e($srcKey) ?>"
                                                data-title="<?= e($srcTitle) ?>"
                                                data-item-count="<?= (int) $srcCount ?>"
                                            >
                                                <span class="sp-card-emoji" data-tone="danger" aria-hidden="true">🗑️</span>
                                                <span class="visually-hidden">Delete catalog</span>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="sharepoint-sources-table-wrap" id="sharepoint-sources-table-wrap" hidden>
                    <table class="sharepoint-sources-table" aria-label="SharePoint folders">
                        <thead>
                            <tr>
                                <th scope="col">Folder</th>
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
                                $srcArchived = isset($archivedSourceKeys[$srcKey]);
                                if (!$isAdmin && $srcArchived) {
                                    continue;
                                }
                                $isActiveCard = $srcKey === $activeSourceKey;
                                $srcCount = (int) ($sourceCounts[$srcKey] ?? 0);
                                $srcSynced = (string) ($src['last_synced_at'] ?? '');
                                $srcStatus = (string) ($src['last_sync_status'] ?? '');
                                $srcUrl = (string) ($src['folder_url'] ?? '');
                                $srcTitle = (string) ($src['title'] ?? $srcKey);
                                $srcFolderPath = (string) ($src['folder_path'] ?? '');
                                $srcSite = (string) (($src['site_host'] ?? '') . ($src['site_path'] ?? ''));
                                $srcTone = (string) ($catalogTones[$srcKey] ?? 'slate');
                                $srcToneHex = (string) ($catalogToneHex[$srcTone] ?? '#475569');
                                ?>
                                <tr class="sharepoint-source-row<?= $isActiveCard ? ' is-active' : '' ?><?= $srcArchived ? ' is-archived' : '' ?>" data-source-key="<?= e($srcKey) ?>" data-catalog-tone="<?= e($srcTone) ?>" style="--catalog-tone: <?= e($srcToneHex) ?>"<?= $srcArchived ? ' data-archived="1"' : '' ?>>
                                    <td>
                                        <div class="sharepoint-source-table-title">
                                            <span class="sp-card-emoji" data-tone="folder" aria-hidden="true">📂</span>
                                            <strong><?= e($srcTitle) ?></strong>
                                            <?php require __DIR__ . '/includes/sharepoint-source-color-btn.php'; ?>
                                            <?php if ($srcArchived): ?>
                                                <span class="sharepoint-source-badge sharepoint-archive-badge">Archived</span>
                                            <?php elseif ($isActiveCard): ?>
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
                                            <a class="button ghost-light" href="sharepoint.php?source=<?= e($srcKey) ?>&amp;sources=<?= e($srcKey) ?>#sharepoint-search"><span class="sp-card-emoji" data-tone="folder" aria-hidden="true">📂</span> Open</a>
                                            <?php if ($isAdmin): ?>
                                                <?php if ($enableOneClickSync): ?>
                                                    <button type="button" class="button button-primary sharepoint-msal-sync-btn" data-source-key="<?= e($srcKey) ?>"><span class="sp-card-emoji" data-tone="sync" aria-hidden="true">🔄</span> Sync</button>
                                                <?php endif; ?>
                                                <?php if ($enableConsoleSync): ?>
                                                    <button type="button" class="button ghost-light sharepoint-mfa-prepare-btn" data-source-key="<?= e($srcKey) ?>" title="Prepare, copy script, and open SharePoint"><span class="sp-card-emoji" data-tone="lock" aria-hidden="true">🔐</span> Console</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($srcUrl !== ''): ?>
                                                <a class="button ghost" href="<?= e($srcUrl) ?>" target="_blank" rel="noopener noreferrer"><span class="sp-card-emoji" data-tone="link" aria-hidden="true">🔗</span> SP</a>
                                            <?php endif; ?>
                                            <?php if ($isAdmin): ?>
                                                <form method="post" class="sharepoint-archive-source-form">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="set_archive">
                                                    <input type="hidden" name="scope" value="source">
                                                    <input type="hidden" name="source_key" value="<?= e($srcKey) ?>">
                                                    <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                                                    <input type="hidden" name="archived" value="<?= $srcArchived ? '0' : '1' ?>">
                                                    <button type="submit" class="button ghost-light sp-archive-source-btn" title="<?= $srcArchived ? 'Show this catalog on the dashboard again' : 'Hide this catalog from the dashboard' ?>">
                                                        <?= $srcArchived ? '↩️ Unarchive' : '📦 Archive' ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($isAdmin): ?>
                    <details class="sharepoint-add-source-shell" id="sharepoint-add-source-shell">
                        <summary class="sharepoint-add-source-summary">
                            <div>
                                <div class="eyebrow">New catalog</div>
                                <h3>➕ Add SharePoint folder</h3>
                            </div>
                            <span class="sharepoint-add-source-pill">Admin</span>
                        </summary>
                        <form method="post" class="sharepoint-add-source-form sharepoint-add-source-uplift" id="sharepoint-add-source">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_source">
                            <p class="panel-help">Paste a library or folder URL from SharePoint. RiskRegister will store it as its own searchable catalog.</p>
                            <div class="sharepoint-add-source-fields">
                                <label class="sharepoint-add-field">
                                    <span class="sharepoint-add-field-label">Display name</span>
                                    <input type="text" name="title" placeholder="e.g. Architectural Projects [Public]" required maxlength="200" autocomplete="off">
                                    <span class="sharepoint-add-field-hint">Shown on cards, search scope chips, and the active catalog title.</span>
                                </label>
                                <label class="sharepoint-add-field sharepoint-folder-url-label">
                                    <span class="sharepoint-add-field-label">SharePoint folder URL</span>
                                    <input type="url" name="folder_url" required
                                           placeholder="https://….sharepoint.com/…/Shared Documents/Forms/AllItems.aspx"
                                           autocomplete="off" spellcheck="false">
                                    <span class="sharepoint-add-field-hint">Copy the browser address while viewing the library or folder in SharePoint. AllItems.aspx links work, including library home links that only have viewid=….</span>
                                </label>
                            </div>
                            <div class="sharepoint-add-source-actions">
                                <button type="submit" class="button button-primary">➕ Add folder</button>
                                <span class="sharepoint-add-source-note">You can sync items after the folder is added.</span>
                            </div>
                        </form>
                    </details>
                    <?php if (count($allSources) > 1): ?>
                        <dialog class="sp-source-delete-dialog" id="sp-source-delete-dialog" aria-labelledby="sp-source-delete-title">
                            <form method="post" class="sp-source-delete-dialog-form" id="sp-source-delete-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_source">
                                <input type="hidden" name="source_key" id="sp-source-delete-key" value="">
                                <div class="sp-source-delete-dialog-head">
                                    <span class="sp-card-emoji" data-tone="danger" aria-hidden="true">🗑️</span>
                                    <div>
                                        <h3 id="sp-source-delete-title">Delete this catalog?</h3>
                                        <p>This permanently removes the folder card and its related SharePoint catalog rows from the database. This cannot be undone.</p>
                                    </div>
                                </div>
                                <div class="sp-source-delete-dialog-body">
                                    <p class="sp-source-delete-target">
                                        <strong data-delete-name></strong>
                                        <span>· <span data-delete-count>0</span> catalog items</span>
                                    </p>
                                    <label class="sharepoint-add-field">
                                        <span class="sharepoint-add-field-label">Type <code data-delete-phrase></code> to confirm</span>
                                        <input type="text" name="confirm_title" id="sp-source-delete-confirm" required maxlength="200" autocomplete="off" spellcheck="false" placeholder="Folder display name">
                                    </label>
                                </div>
                                <div class="sp-source-delete-dialog-actions">
                                    <button type="button" class="button ghost" data-delete-cancel>Cancel</button>
                                    <button type="submit" class="button sharepoint-source-delete-confirm-btn" id="sp-source-delete-submit" disabled>Delete catalog</button>
                                </div>
                            </form>
                        </dialog>
                    <?php endif; ?>
                <?php endif; ?>
                    </div>
                </details>
            </section>
            <?php endif; ?>

            <?php if ($isAdmin && !$panelSolo): ?>
            <?php
            $shareKind = CatalogShareRepository::KIND_CATALOG;
            $sharePanelId = 'catalog-share-panel';
            $shareHeading = 'Share catalog cards';
            $shareHelp = 'Create public links so people can browse <strong>only the catalog cards</strong> and search project folders without signing in. Recipients cannot sync, edit folders, or open assessments. You can keep up to ' . CatalogShareRepository::MAX_ACTIVE . ' active links. Tag each one so you know who it is for. You can copy any active link again from the list below.';
            $shareFreshLabel = 'Public catalog link created';
            $shareFreshTag = $freshCatalogShareLabel;
            $shareHasActive = $catalogShareActiveCount > 0;
            $shareActiveCount = $catalogShareActiveCount;
            $shareFreshUrl = $freshCatalogShareUrl;
            $shareFreshId = $freshCatalogShareId;
            $shareLinks = $catalogShareLinks;
            $shareHistoryPage = $catalogShareHistPage;
            $shareHistoryPages = $catalogSharePages;
            $shareHistoryTotal = $catalogShareTotal;
            $sharePageParam = 'cshare_page';
            $shareCreateAction = 'create_catalog_share_link';
            $shareRevokeAction = 'revoke_catalog_share_link';
            $sharePurgeAction = 'purge_catalog_share_history';
            $shareEmailAction = 'email_catalog_share_link';
            $sharePanelFlash = $catalogShareFlash;
            $sharePanelFlashType = $catalogShareFlashType;
            $shareForceOpen = ($freshCatalogShareUrl !== null && $freshCatalogShareUrl !== '')
                || $catalogShareFlash !== ''
                || isset($_GET['catalog_shared'])
                || isset($_GET['emailed'])
                || (int) ($_GET['cshare_page'] ?? 0) > 0
                || ($error !== '' && str_contains((string) ($_POST['action'] ?? ''), 'catalog_share'));
            $shareView = '';
            $shareSectionKey = 'catalog-share';
            require __DIR__ . '/includes/sharepoint-public-share-card.php';
            ?>
            <?php endif; ?>
            <?php if ($foldersSolo && $isAdmin): ?>
                <?php if ($enableOneClickSync): ?>
                    <section class="visually-hidden" id="sharepoint-msal-sync"
                             data-csrf="<?= e((string) ($_SESSION['csrf_token'] ?? '')) ?>"
                             data-source-key="<?= e($activeSourceKey) ?>"
                             data-tenant-id="<?= e((string) ($status['tenant_id'] ?? '')) ?>"
                             data-client-id="<?= e($thisClientIdSafe) ?>">
                        <p id="sharepoint-msal-status" aria-live="polite"></p>
                    </section>
                <?php endif; ?>
                <?php if ($enableConsoleSync): ?>
                    <section class="visually-hidden" id="sharepoint-mfa-sync"
                             data-csrf="<?= e((string) ($_SESSION['csrf_token'] ?? '')) ?>"
                             data-source-key="<?= e($activeSourceKey) ?>">
                        <a id="sharepoint-mfa-open" href="<?= e($activeFolderUrl) ?>" hidden></a>
                        <input type="hidden" id="sharepoint-mfa-source-key" value="<?= e($activeSourceKey) ?>">
                        <p id="sharepoint-mfa-status" aria-live="polite"></p>
                        <textarea id="sharepoint-mfa-script" hidden readonly></textarea>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$catalogSolo && !$foldersSolo): ?>
            <section
                class="upload-card sp-owner-dash"
                id="sharepoint-owner-dash"
                data-sp-section="owners"
                data-solo="<?= $ownerSolo ? '1' : '0' ?>"
                data-active-source="<?= e($activeSourceKey) ?>"
                data-sources="<?= e(json_encode(array_map(static function (array $src) use ($catalogTones): array {
                    $key = (string) ($src['source_key'] ?? '');
                    return [
                        'source_key' => $key,
                        'title' => (string) ($src['title'] ?? $src['source_key'] ?? ''),
                        'tone' => (string) ($catalogTones[$key] ?? 'slate'),
                    ];
                }, $allSources), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
            >
                <details class="sp-owner-dash-shell" id="sharepoint-owner-dash-shell"<?= $ownerSolo ? ' open' : '' ?>>
                    <summary class="sp-owner-dash-summary">
                        <div class="sp-owner-dash-intro">
                            <div class="eyebrow">People over time</div>
                            <h2>👤 Project owners</h2>
                            <p>
                                Who created the project folders in the catalogs you select — and how that work landed by month, quarter, and year.
                            </p>
                        </div>
                        <div class="sp-owner-dash-summary-tools" data-no-toggle onclick="event.stopPropagation()">
                            <?php require __DIR__ . '/includes/sharepoint-section-move.php'; ?>
                            <?php if ($ownerSolo): ?>
                                <a class="button ghost" href="sharepoint.php?source=<?= e($activeSourceKey) ?>">← Catalog</a>
                            <?php else: ?>
                                <a class="button ghost-light" id="sp-owner-open-tab" href="<?= e($ownerDashUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open Project owners in a new browser tab">↗ New tab</a>
                                <button type="button" class="button ghost" id="sp-owner-open-window" title="Open Project owners in a separate window">🗗 Window</button>
                                <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
                            <?php endif; ?>
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
                                        <b class="sp-od-scopes-count" id="sp-owner-scopes-count"><?= count($allSources) ?> of <?= count($allSources) ?></b>
                                    </span>
                                    <button type="button" class="sp-od-scopes-all is-active" id="sp-owner-scopes-all" disabled>All selected</button>
                                    <button type="button" class="sp-od-scopes-colors is-active" id="sp-owner-scopes-colors" aria-pressed="true" title="Color each catalog differently so they are easier to tell apart">🎨 Colors</button>
                                    <button type="button" class="sp-od-scopes-colors sharepoint-scopes-color-reset" id="sp-owner-scopes-color-reset" hidden>Reset colors</button>
                                </div>
                                <div class="sp-od-scopes-list">
                                    <?php foreach ($allSources as $src): ?>
                                        <?php
                                        $srcKey = (string) ($src['source_key'] ?? '');
                                        $srcTitle = (string) ($src['title'] ?? $srcKey);
                                        $srcTone = (string) ($catalogTones[$srcKey] ?? 'slate');
                                        $srcHex = (string) ($catalogToneHex[$srcTone] ?? '#475569');
                                        ?>
                                        <div class="sharepoint-scope-chip is-active" data-catalog-tone="<?= e($srcTone) ?>" data-source-key="<?= e($srcKey) ?>">
                                            <label class="sharepoint-scope-chip-main">
                                                <input type="checkbox" class="sp-owner-scope-check" value="<?= e($srcKey) ?>" checked>
                                                <span><?= e($srcTitle) ?></span>
                                            </label>
                                            <button type="button" class="sharepoint-scope-color-btn" data-source-key="<?= e($srcKey) ?>" title="Choose color for <?= e($srcTitle) ?>" aria-label="Choose color for <?= e($srcTitle) ?>" aria-haspopup="dialog" aria-expanded="false" style="--catalog-tone: <?= e($srcHex) ?>"></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="sp-od-body" id="sp-owner-dash-body">
                            <p class="panel-help"><?= $ownerSolo ? 'Loading owner insights…' : 'Expand to load owner insights, or open in a new tab or window.' ?></p>
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
            <?php if ($isAdmin): ?>
            <?php
            $shareKind = CatalogShareRepository::KIND_OWNERS;
            $sharePanelId = 'owners-share-panel';
            $shareHeading = 'Share project owner cards';
            $shareHelp = 'Create public links so people can browse <strong>only the project owner cards</strong> without signing in. Recipients cannot sync, edit folders, or open assessments. You can keep up to ' . CatalogShareRepository::MAX_ACTIVE . ' active links. Tag each one so you know who it is for. You can copy any active link again from the list below.';
            $shareFreshLabel = 'Public owners link created';
            $shareFreshTag = $freshOwnersShareLabel;
            $shareHasActive = $ownersShareActiveCount > 0;
            $shareActiveCount = $ownersShareActiveCount;
            $shareFreshUrl = $freshOwnersShareUrl;
            $shareFreshId = $freshOwnersShareId;
            $shareLinks = $ownersShareLinks;
            $shareHistoryPage = $ownersShareHistPage;
            $shareHistoryPages = $ownersSharePages;
            $shareHistoryTotal = $ownersShareTotal;
            $sharePageParam = 'oshare_page';
            $shareCreateAction = 'create_owners_share_link';
            $shareRevokeAction = 'revoke_owners_share_link';
            $sharePurgeAction = 'purge_owners_share_history';
            $shareEmailAction = 'email_owners_share_link';
            $sharePanelFlash = $ownersShareFlash;
            $sharePanelFlashType = $ownersShareFlashType;
            $shareForceOpen = ($freshOwnersShareUrl !== null && $freshOwnersShareUrl !== '')
                || $ownersShareFlash !== ''
                || isset($_GET['owners_shared'])
                || isset($_GET['emailed'])
                || (int) ($_GET['oshare_page'] ?? 0) > 0
                || ($error !== '' && str_contains((string) ($_POST['action'] ?? ''), 'owners_share'));
            $shareView = $ownerSolo ? 'owners' : '';
            $shareSectionKey = 'owners-share';
            require __DIR__ . '/includes/sharepoint-public-share-card.php';
            ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$ownerSolo && !$foldersSolo): ?>
            <?php
            $searchCardPublic = false;
            $searchFormAction = 'sharepoint.php';
            $metaProjectCount = $matchedProjectCount;
            require __DIR__ . '/includes/sharepoint-search-card.php';
            ?>

            <section class="upload-card sharepoint-table-card is-compact-rows" aria-label="SharePoint project table" id="sharepoint-table-card" data-sp-section="projects" data-density="compact">
                <details class="sharepoint-catalog-table-shell" id="sharepoint-catalog-table-shell" open>
                    <summary class="sharepoint-table-toolbar sharepoint-catalog-table-summary">
                    <div class="sharepoint-table-summary-lead">
                        <h2 class="sharepoint-table-collapsed-title">📋 Project list</h2>
                        <span class="result-count" id="sharepoint-result-count">Showing <?= (int) $from ?>–<?= (int) $to ?> of <?= (int) $matchedProjectCount ?></span>
                    </div>
                    <div class="sharepoint-table-toolbar-tools" data-no-toggle onclick="event.stopPropagation()">
                        <?php require __DIR__ . '/includes/sharepoint-section-move.php'; ?>
                        <div class="sp-view-toggle" role="group" aria-label="Row density">
                            <button type="button" class="sp-view-btn" data-list-density="comfort" title="Taller rows with badges under the name" aria-pressed="false">Comfort</button>
                            <button type="button" class="sp-view-btn is-active" data-list-density="compact" title="Shrink rows to a single line" aria-pressed="true">Compact</button>
                        </div>
                        <button type="button" class="sp-view-btn is-active" id="sharepoint-filters-toggle" title="Show or hide column filters" aria-controls="sharepoint-table-filters" aria-pressed="true">Filters</button>
                        <?php require __DIR__ . '/includes/sharepoint-list-columns-picker.php'; ?>
                        <div class="sharepoint-compare-bar" id="sharepoint-compare-bar">
                            <span class="sharepoint-compare-hint" id="sharepoint-compare-hint">Select 2–3 folders to compare side by side</span>
                            <button type="button" class="button button-primary" id="sharepoint-compare-open" disabled>⚖️ Compare selected</button>
                            <button type="button" class="button ghost" id="sharepoint-compare-clear" hidden>Clear selection</button>
                        </div>
                        <?php if (!$catalogSolo): ?>
                            <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
                        <?php endif; ?>
                    </div>
                    </summary>
                    <div class="sharepoint-catalog-table-body">
                <div class="table-wrap sharepoint-projects-wrap">
                    <table class="sharepoint-projects-table" id="sharepoint-projects-table">
                        <thead>
                            <tr>
                                <th scope="col" class="sharepoint-select-col" data-col="select">
                                    <span class="visually-hidden">Select</span>
                                </th>
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
                                <th scope="col" data-col="name">
                                    <input type="search" class="sharepoint-col-filter" data-filter="name" placeholder="Filter project…" autocomplete="off" aria-label="Filter by project name">
                                </th>
                                <th scope="col" data-col="match">
                                    <input type="search" class="sharepoint-col-filter" data-filter="match" placeholder="Type / catalog…" autocomplete="off" aria-label="Filter by match, type, or catalog">
                                </th>
                                <th scope="col" data-col="items">
                                    <input type="search" class="sharepoint-col-filter" data-filter="items" placeholder="Count…" autocomplete="off" aria-label="Filter by item counts">
                                </th>
                                <th scope="col" data-col="modified">
                                    <input type="search" class="sharepoint-col-filter" data-filter="modified" placeholder="Date…" autocomplete="off" aria-label="Filter by modified date">
                                </th>
                                <th scope="col" data-col="modified_by">
                                    <input type="search" class="sharepoint-col-filter" data-filter="modified_by" placeholder="Name…" autocomplete="off" aria-label="Filter by modified by">
                                </th>
                                <th scope="col" data-col="created_by">
                                    <input type="search" class="sharepoint-col-filter" data-filter="created_by" placeholder="Name…" autocomplete="off" aria-label="Filter by created by">
                                </th>
                                <th scope="col" class="sharepoint-filter-actions" data-col="actions">
                                    <button type="button" class="button ghost sharepoint-filters-clear is-hidden" id="sharepoint-filters-clear" title="Clear column filters">Clear</button>
                                </th>
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
                                    $fileCount = (int) ($project['file_count'] ?? 0);
                                    $folderCount = (int) ($project['folder_count'] ?? 0);
                                    $rowMeta = $projectFileMeta($projectName);
                                    $rowTypeLabel = ($rowMeta['tone'] ?? '') === 'folder' ? '📁 Folder' : (string) $rowMeta['label'];
                                    $modifiedDisplay = $formatModified((string) ($project['last_modified'] ?? ''));
                                    $modifiedBy = (string) ($project['modified_by'] ?? '');
                                    $person = (string) ($project['person'] ?? '');
                                    $selectId = $activeSourceKey . '::' . $projectName;
                                    ?>
                                    <tr class="sharepoint-project-row" data-project-name="<?= e($projectName) ?>" data-source-key="<?= e($activeSourceKey) ?>" tabindex="0">
                                        <td class="sharepoint-select-col" data-col="select" onclick="event.stopPropagation()">
                                            <label class="sharepoint-row-select">
                                                <input type="checkbox" class="sharepoint-compare-check" value="<?= e($selectId) ?>" data-project-name="<?= e($projectName) ?>" data-source-key="<?= e($activeSourceKey) ?>" aria-label="Select <?= e($projectName) ?> for compare">
                                            </label>
                                        </td>
                                        <td data-col="name">
                                            <div class="sp-project-cell">
                                                <div class="sp-tree-cell">
                                                    <button type="button" class="sharepoint-project-open sp-file-link" data-project-name="<?= e($projectName) ?>" data-source-key="<?= e($activeSourceKey) ?>">
                                                        <span class="sp-file-icon sp-file-icon--<?= e((string) $rowMeta['tone']) ?>" aria-hidden="true"><?= e((string) $rowMeta['emoji']) ?></span>
                                                        <span class="sp-file-copy">
                                                            <span class="sp-file-name"><?= e($projectName) ?></span>
                                                        </span>
                                                    </button>
                                                </div>
                                                <div class="sp-project-meta-line">
                                                    <span class="sp-type-badge sp-type-badge--<?= e((string) $rowMeta['tone']) ?>"><?= e($rowTypeLabel) ?></span>
                                                    <span class="sp-catalog-badge" data-catalog-tone="<?= e((string) ($catalogTones[$activeSourceKey] ?? 'slate')) ?>" data-source-key="<?= e($activeSourceKey) ?>"><?= e($activeTitle) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="sp-match-cell" data-col="match"><span class="sp-match-placeholder">—</span></td>
                                        <td class="sp-meta-cell" data-col="items">
                                            <span class="sharepoint-item-counts" title="<?= (int) $folderCount ?> folders · <?= (int) $fileCount ?> files">
                                                <?php if ($folderCount > 0): ?>
                                                    <span class="sp-type-badge sp-type-badge--folder">📁 <?= (int) $folderCount ?></span>
                                                <?php endif; ?>
                                                <?php if ($fileCount > 0): ?>
                                                    <span class="sp-type-badge sp-type-badge--file">📄 <?= (int) $fileCount ?></span>
                                                <?php endif; ?>
                                                <?php if ($folderCount === 0 && $fileCount === 0): ?>
                                                    <span class="sp-type-badge sp-type-badge--folder">📁 0</span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="sp-meta-cell" data-col="modified"><?= $modifiedDisplay !== '' ? e($modifiedDisplay) : '—' ?></td>
                                        <td class="sp-meta-cell" data-col="modified_by"><?= $modifiedBy !== '' ? '👤 ' . e($modifiedBy) : '—' ?></td>
                                        <td class="sp-meta-cell" data-col="created_by"><?= $person !== '' ? '🙋 ' . e($person) : '—' ?></td>
                                        <td class="sharepoint-project-actions" data-col="actions">
                                            <?php if ($folderUrl !== ''): ?>
                                                <div class="sharepoint-project-action-group">
                                                    <a class="button ghost-light sharepoint-open-sp" href="<?= e($folderUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open in SharePoint" onclick="event.stopPropagation()">🔗</a>
                                                    <button type="button" class="button ghost-light sp-copy-link-btn sp-project-copy-btn" data-copy-url="<?= e($folderUrl) ?>" data-label="📋" title="Copy SharePoint link" aria-label="Copy link for <?= e($projectName) ?>" onclick="event.stopPropagation()">📋</button>
                                                    <button type="button" class="button ghost-light sp-qr-btn" data-qr-url="<?= e($folderUrl) ?>" data-qr-label="<?= e($projectName) ?>" data-qr-source-key="<?= e($activeSourceKey) ?>" data-qr-catalog="<?= e($activeTitle) ?>" title="Show QR code for mobile scan" aria-label="Show QR code for <?= e($projectName) ?>" onclick="event.stopPropagation()">QR</button>
                                                </div>
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
                        <?php if ($catalogSolo): ?>
                            <input type="hidden" name="view" value="catalog">
                        <?php endif; ?>
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
                    </div>
                </details>
            </section>

            <?php require __DIR__ . '/includes/sharepoint-catalog-dialogs.php'; ?>
            <?php endif; ?>

            <?php if ($isAdmin && !$panelSolo): ?>
                <section class="upload-card sharepoint-admin-card" id="sharepoint-admin" data-sp-section="admin">
                    <details class="sharepoint-admin-shell" id="sharepoint-admin-shell" open>
                        <summary class="sharepoint-admin-shell-summary">
                            <div>
                                <div class="eyebrow">Administrators</div>
                                <h2>⚙️ SharePoint sync &amp; import</h2>
                            </div>
                            <div class="sharepoint-admin-shell-tools" data-no-toggle onclick="event.stopPropagation()">
                                <?php require __DIR__ . '/includes/sharepoint-section-move.php'; ?>
                                <span class="sharepoint-admin-shell-hint" aria-hidden="true"></span>
                            </div>
                        </summary>

                        <p class="panel-help">
                            Prefer <strong>One-click Sync</strong> (Tenant ID + Client ID only) or keep using <strong>Console sync</strong>.
                            Client secret / Graph sync is optional and needs stronger IT permissions.
                            Folder paths are managed in <strong>SharePoint folders</strong> above — sync uses the active folder
                            (<em><?= e($activeTitle) ?></em>).
                        </p>

                        <div class="sharepoint-admin-blocks">
                    <details class="sharepoint-admin-block sharepoint-setup-guide" id="sharepoint-entra-setup">
                        <summary>📋 Entra / Graph setup instructions</summary>
                        <div class="sharepoint-admin-block-body sharepoint-setup-guide-body">
                            <div class="alert sharepoint-setup-callout">
                                <strong>Conditional Access note:</strong> Error <code>53003</code> (“sign-in successful but does not meet criteria”)
                                usually means Azure Portal / Entra is blocked on an unmanaged device.
                                Do the Entra setup steps on a <strong>company-managed / Intune office device</strong> (or ask IT).
                                Until then, use <strong>Console sync</strong> below — it does not need Entra.
                                One-click Sync uses a <strong>local</strong> Microsoft sign-in library (no <code>alcdn.msauth.net</code> download).
                            </div>

                            <h3>One-click Sync (recommended — no client secret)</h3>
                            <ol class="sharepoint-mfa-steps">
                                <li>
                                    Open <a href="https://entra.microsoft.com" target="_blank" rel="noopener noreferrer">entra.microsoft.com</a>
                                    → <strong>Applications</strong> → <strong>App registrations</strong> → <strong>New registration</strong>.
                                </li>
                                <li>
                                    Name: <code>RiskRegister SharePoint Catalog</code> ·
                                    <strong>Accounts in this organizational directory only</strong> → <strong>Register</strong>.
                                </li>
                                <li>
                                    On <strong>Overview</strong>, copy:
                                    <ul>
                                        <li><strong>Application (client) ID</strong></li>
                                        <li><strong>Directory (tenant) ID</strong> (AdventHealth often <code>6ac36678-7785-476f-be03-b68b403734c2</code>)</li>
                                    </ul>
                                </li>
                                <li>
                                    <strong>Authentication</strong> → <strong>Add a platform</strong> → <strong>Single-page application</strong>.
                                    Redirect URI (must match exactly):
                                    <code class="sharepoint-setup-redirect" id="sharepoint-setup-redirect"><?= e($msalRedirectUri) ?></code>
                                    <button type="button" class="button ghost sharepoint-copy-redirect" data-copy-target="sharepoint-setup-redirect">Copy URI</button>
                                </li>
                                <li>
                                    <strong>API permissions</strong> → Microsoft Graph → <strong>Delegated</strong> →
                                    add <code>Sites.Read.All</code> (and <code>User.Read</code> if missing) →
                                    <strong>Grant admin consent</strong> for your org.
                                </li>
                                <li>
                                    Paste <strong>Tenant ID</strong> + <strong>Client ID</strong> into the form below →
                                    <strong>Save settings</strong> (leave Client secret empty) → click <strong>Sync</strong> on a folder card.
                                </li>
                            </ol>

                            <h3>If you cannot open Entra — ask IT</h3>
                            <p class="panel-help">Send this request:</p>
                            <textarea class="sharepoint-setup-it-request" id="sharepoint-setup-it-request" readonly rows="8" aria-label="IT request text">Please create (or update) Entra app "RiskRegister SharePoint Catalog" for our RiskRegister SharePoint catalog:

1) Authentication → Single-page application redirect URI:
<?= $msalRedirectUri ?>

2) API permissions → Microsoft Graph → Delegated → Sites.Read.All (+ User.Read)
3) Grant admin consent for the tenant
4) Reply with Directory (tenant) ID and Application (client) ID
(No client secret needed for one-click Sync.)</textarea>
                            <button type="button" class="button ghost" id="sharepoint-copy-it-request" data-copy-target="sharepoint-setup-it-request">📋 Copy IT request</button>

                            <h3>Optional: Graph app-only sync (client secret)</h3>
                            <p class="panel-help">
                                Only if IT wants daemon sync without your interactive login:
                                add Graph <strong>Application</strong> permission <code>Sites.Read.All</code> (or <code>Sites.Selected</code>),
                                grant admin consent, create a client secret, paste Tenant ID + Client ID + Secret below →
                                <strong>Test connection</strong> → <strong>Graph sync</strong>.
                            </p>

                            <p class="panel-help">
                                Full notes also live in <code>docs/SHAREPOINT_CATALOG.md</code> in the project folder.
                            </p>
                        </div>
                    </details>

                    <?php if ($activeLastStatus === 'error' && $activeLastError !== ''): ?>
                        <div class="alert alert-error">Last sync error: <?= e($activeLastError) ?></div>
                    <?php endif; ?>

                    <details class="sharepoint-admin-block" id="sharepoint-admin-settings" open>
                        <summary>💾 Connection settings</summary>
                        <div class="sharepoint-admin-block-body">
                    <form method="post" class="sharepoint-settings-form sharepoint-settings-uplift" id="sharepoint-settings-form" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                        <div class="sharepoint-settings-intro">
                            <div class="sharepoint-settings-active">
                                <span class="sharepoint-add-field-label">Active folder</span>
                                <strong><?= e($activeFolderPath !== '' ? $activeFolderPath : $activeTitle) ?></strong>
                                <span class="sharepoint-add-field-hint"><?= e($activeSiteHost . $activeSitePath) ?></span>
                            </div>
                            <p class="panel-help sharepoint-settings-lead">
                                For <strong>One-click Sync</strong>, save Tenant ID + Client ID only.
                                Client secret is only for optional <strong>Graph sync</strong>.
                                <strong>Console sync</strong> needs nothing filled in.
                            </p>
                        </div>
                        <div class="sharepoint-settings-fields">
                            <label class="sharepoint-add-field">
                                <span class="sharepoint-add-field-label">Tenant ID</span>
                                <input type="text" name="sharepoint_tenant_id" value="<?= e($status['tenant_id']) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off" spellcheck="false">
                                <span class="sharepoint-add-field-hint">Directory (tenant) ID from Entra app Overview — AdventHealth often uses <code>6ac36678-7785-476f-be03-b68b403734c2</code>.</span>
                            </label>
                            <label class="sharepoint-add-field">
                                <span class="sharepoint-add-field-label">Client ID</span>
                                <input type="text" name="sharepoint_client_id" value="<?= e($thisClientIdSafe) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" autocomplete="off" spellcheck="false">
                                <span class="sharepoint-add-field-hint">Application (client) ID GUID from the Entra app registration.</span>
                            </label>
                            <label class="sharepoint-add-field">
                                <span class="sharepoint-add-field-label">Client secret <em>optional</em><?= $status['has_secret'] ? ' · leave blank to keep current' : '' ?></span>
                                <input type="password" name="sharepoint_client_secret" value="" placeholder="<?= $status['has_secret'] ? '•••••••• (saved)' : 'Only for Graph app-only sync' ?>" autocomplete="new-password">
                                <span class="sharepoint-add-field-hint">Only needed for daemon <strong>Graph sync</strong>. One-click Sync and Console sync do not use a secret.</span>
                            </label>
                        </div>
                        <div class="sharepoint-feature-toggles" role="group" aria-label="Folder action features">
                            <div class="sharepoint-feature-toggles-head">
                                <span class="sharepoint-add-field-label">Folder action buttons</span>
                                <span class="sharepoint-add-field-hint">Choose which sync actions appear on SharePoint folder cards (table and card views).</span>
                            </div>
                            <label class="sharepoint-feature-toggle<?= $enableOneClickSync ? ' is-on' : '' ?>">
                                <span class="sharepoint-feature-toggle-copy">
                                    <strong>🔄 One-click Sync</strong>
                                    <span>Microsoft login popup (MFA). Needs Tenant ID + Client ID.</span>
                                </span>
                                <span class="sharepoint-feature-switch">
                                    <input type="checkbox" name="sharepoint_enable_one_click_sync" value="1"<?= $enableOneClickSync ? ' checked' : '' ?>>
                                    <span class="sharepoint-feature-switch-ui" aria-hidden="true"></span>
                                </span>
                            </label>
                            <label class="sharepoint-feature-toggle<?= $enableConsoleSync ? ' is-on' : '' ?>">
                                <span class="sharepoint-feature-toggle-copy">
                                    <strong>🔐 Console sync</strong>
                                    <span>Prepare script, copy, and open SharePoint for F12 console paste.</span>
                                </span>
                                <span class="sharepoint-feature-switch">
                                    <input type="checkbox" name="sharepoint_enable_console_sync" value="1"<?= $enableConsoleSync ? ' checked' : '' ?>>
                                    <span class="sharepoint-feature-switch-ui" aria-hidden="true"></span>
                                </span>
                            </label>
                        </div>
                        <div class="sharepoint-add-source-actions sharepoint-admin-actions sharepoint-admin-actions-row">
                            <button type="submit" name="action" value="save_settings" class="button button-primary">💾 Save settings</button>
                            <button type="submit" name="action" value="test_connection" class="button ghost-light">🔌 Test connection</button>
                            <button type="submit" name="action" value="sync" class="button ghost-light" title="Requires Entra app client secret">🔄 Graph sync</button>
                            <?php if ($status['has_secret']): ?>
                                <button type="submit" name="action" value="clear_secret" class="button ghost" onclick="return confirm('Remove the stored SharePoint client secret?');">🗑️ Clear secret</button>
                            <?php endif; ?>
                        </div>
                    </form>
                        </div>
                    </details>

                    <?php if ($enableOneClickSync): ?>
                    <details class="sharepoint-admin-block" id="sharepoint-admin-msal" open>
                        <summary>🔄 One-click Sync (Microsoft login)</summary>
                        <div class="sharepoint-admin-block-body">
                    <section class="sharepoint-msal-sync" id="sharepoint-msal-sync"
                             data-csrf="<?= e((string) ($_SESSION['csrf_token'] ?? '')) ?>"
                             data-source-key="<?= e($activeSourceKey) ?>"
                             data-tenant-id="<?= e($status['tenant_id']) ?>"
                             data-client-id="<?= e($thisClientIdSafe) ?>">
                        <p class="panel-help">
                            Click <strong>Sync</strong> — sign in with your AdventHealth account (MFA in the popup).
                            RiskRegister crawls the folder via Microsoft Graph using <em>your</em> access — no console paste.
                            Requires Tenant ID + Client ID above. Setup steps:
                            <a href="#sharepoint-entra-setup">Entra / Graph setup instructions</a>.
                        </p>
                        <div class="sharepoint-admin-actions sharepoint-admin-actions-row">
                            <button type="button" class="button button-primary btn-accent-violet-solid sharepoint-msal-sync-btn" id="sharepoint-msal-sync-btn" data-source-key="<?= e($activeSourceKey) ?>">🔄 Sync <?= e($activeTitle) ?></button>
                        </div>
                        <p class="panel-help" id="sharepoint-msal-status" aria-live="polite">Ready when Tenant ID and Client ID are saved.</p>
                    </section>
                        </div>
                    </details>
                    <?php endif; ?>

                    <?php if ($enableConsoleSync): ?>
                    <details class="sharepoint-admin-block" id="sharepoint-admin-mfa">
                        <summary>🔐 Advanced: console sync on SharePoint tab</summary>
                        <div class="sharepoint-admin-block-body">
                    <section class="sharepoint-mfa-sync" id="sharepoint-mfa-sync"
                             data-csrf="<?= e((string) ($_SESSION['csrf_token'] ?? '')) ?>"
                             data-source-key="<?= e($activeSourceKey) ?>">
                        <p class="panel-help">
                            One click prepares the token, <strong>copies the sync script</strong>, and opens the SharePoint folder.
                            On that tab: <kbd>F12</kbd> → <strong>Console</strong> → <kbd>Ctrl+V</kbd> → <kbd>Enter</kbd>.
                        </p>
                        <ol class="sharepoint-mfa-steps">
                            <li>Click <strong>Console sync</strong> on a folder card (or <strong>Prepare console sync</strong> below).</li>
                            <li>Complete MFA on the SharePoint tab if prompted.</li>
                            <li>Paste (<kbd>Ctrl+V</kbd>) in the Console and press <kbd>Enter</kbd>.</li>
                            <li>Wait for green <strong>✅ Sync complete</strong> (large libraries can take several minutes).</li>
                        </ol>
                        <div class="sharepoint-admin-actions sharepoint-admin-actions-row">
                            <button type="button" class="button button-primary" id="sharepoint-mfa-prepare" data-source-key="<?= e($activeSourceKey) ?>">🔐 Prepare + copy + open</button>
                            <a class="button ghost-light" id="sharepoint-mfa-open" href="<?= e($activeFolderUrl) ?>" target="_blank" rel="noopener noreferrer">📂 Open SharePoint folder</a>
                            <button type="button" class="button ghost" id="sharepoint-mfa-copy" disabled>📋 Copy script again</button>
                        </div>
                        <input type="hidden" id="sharepoint-mfa-source-key" name="source_key" value="<?= e($activeSourceKey) ?>">
                        <p class="panel-help" id="sharepoint-mfa-status" aria-live="polite">Not prepared yet.</p>
                        <textarea id="sharepoint-mfa-script" class="sharepoint-mfa-script" readonly hidden rows="6" aria-label="SharePoint console sync script"></textarea>
                    </section>
                        </div>
                    </details>
                    <?php endif; ?>

                    <details class="sharepoint-admin-block" id="sharepoint-admin-reindex">
                        <summary>⚡ Search index maintenance</summary>
                        <div class="sharepoint-admin-block-body">
                    <p class="panel-help">
                        After large syncs, rebuild B-tree indexes and the FTS search index so catalog search stays fast.
                        Sync and import already refresh the index for the folder that was updated; use this for a full reindex of all SharePoint tables.
                    </p>
                    <form method="post" class="sharepoint-reindex-form" onsubmit="return confirm('Rebuild SharePoint search indexes now? This is usually quick and safe.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reindex">
                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                        <div class="sharepoint-admin-actions sharepoint-admin-actions-row">
                            <button type="submit" class="button button-primary">📇 Reindex SharePoint search</button>
                        </div>
                    </form>
                        </div>
                    </details>

                    <details class="sharepoint-admin-block" id="sharepoint-admin-purge">
                        <summary>🧹 Purge catalog data (fresh resync)</summary>
                        <div class="sharepoint-admin-block-body">
                    <p class="panel-help">
                        Clear synced SharePoint file/folder rows so you can sync again from scratch.
                        Registered folder catalogs stay. Assessment data and users are never touched.
                        Prefer a snapshot before purge.
                    </p>
                    <div class="sharepoint-purge-stats" aria-label="SharePoint table sizes">
                        <div class="sharepoint-purge-stat">
                            <span class="sharepoint-purge-stat-label">Database file</span>
                            <strong><?= e((string) ($purgeDbStatus['sizeLabel'] ?? '—')) ?></strong>
                        </div>
                        <?php foreach ($purgeTableStats as $stat): ?>
                            <div class="sharepoint-purge-stat">
                                <span class="sharepoint-purge-stat-label"><?= e((string) $stat['label']) ?></span>
                                <strong><?= number_format((int) $stat['rows']) ?> rows</strong>
                                <?php if ($stat['bytes'] !== null): ?>
                                    <span class="sharepoint-purge-stat-bytes"><?= e(SqliteMaintenance::formatBytes((int) $stat['bytes'])) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form method="post" class="sharepoint-purge-form" id="sharepoint-purge-form"
                          onsubmit="return (function (form) {
                            var typed = (form.querySelector('[name=confirm_purge]') || {}).value || '';
                            if (String(typed).toUpperCase() !== 'PURGE') {
                              alert('Type PURGE to confirm.');
                              return false;
                            }
                            return confirm('Permanently delete catalog items for the selected folders? This cannot be undone (except from a snapshot restore).');
                          })(this);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="purge_catalog">
                        <input type="hidden" name="source" value="<?= e($activeSourceKey) ?>">
                        <fieldset class="sharepoint-purge-sources">
                            <legend>Folders to purge</legend>
                            <?php foreach ($allSources as $src): ?>
                                <?php
                                $purgeKey = (string) ($src['source_key'] ?? '');
                                if ($purgeKey === '') {
                                    continue;
                                }
                                $purgeTitle = (string) ($src['title'] ?? $purgeKey);
                                $purgeCount = (int) ($sourceCounts[$purgeKey] ?? 0);
                                ?>
                                <label class="sharepoint-purge-source">
                                    <input type="checkbox" name="purge_sources[]" value="<?= e($purgeKey) ?>" checked>
                                    <span>
                                        <strong><?= e($purgeTitle) ?></strong>
                                        <em><?= number_format($purgeCount) ?> items</em>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <div class="sharepoint-purge-options">
                            <label class="sharepoint-feature-toggle is-on">
                                <span class="sharepoint-feature-toggle-copy">
                                    <strong>📸 Take snapshot first</strong>
                                    <span>Recommended. Saves a copy under <code>database/snapshots/</code>.</span>
                                </span>
                                <span class="sharepoint-feature-switch">
                                    <input type="checkbox" name="take_snapshot" value="1" checked>
                                    <span class="sharepoint-feature-switch-ui" aria-hidden="true"></span>
                                </span>
                            </label>
                            <label class="sharepoint-feature-toggle">
                                <span class="sharepoint-feature-toggle-copy">
                                    <strong>🏷️ Also clear search tags</strong>
                                    <span>Off by default so tags re-attach after resync by path.</span>
                                </span>
                                <span class="sharepoint-feature-switch">
                                    <input type="checkbox" name="clear_tags" value="1" id="sharepoint-purge-clear-tags">
                                    <span class="sharepoint-feature-switch-ui" aria-hidden="true"></span>
                                </span>
                            </label>
                            <label class="sharepoint-feature-toggle" id="sharepoint-purge-unused-wrap">
                                <span class="sharepoint-feature-toggle-copy">
                                    <strong>Delete unused tag names</strong>
                                    <span>Only applies when clearing tags.</span>
                                </span>
                                <span class="sharepoint-feature-switch">
                                    <input type="checkbox" name="delete_unused_tags" value="1">
                                    <span class="sharepoint-feature-switch-ui" aria-hidden="true"></span>
                                </span>
                            </label>
                            <label class="sharepoint-feature-toggle">
                                <span class="sharepoint-feature-toggle-copy">
                                    <strong>VACUUM after purge</strong>
                                    <span>Reclaims disk space. Can take a while and locks the database.</span>
                                </span>
                                <span class="sharepoint-feature-switch">
                                    <input type="checkbox" name="run_vacuum" value="1">
                                    <span class="sharepoint-feature-switch-ui" aria-hidden="true"></span>
                                </span>
                            </label>
                        </div>
                        <label class="sharepoint-add-field">
                            <span class="sharepoint-add-field-label">Type <code>PURGE</code> to confirm</span>
                            <input type="text" name="confirm_purge" value="" autocomplete="off" spellcheck="false" placeholder="PURGE" required>
                        </label>
                        <div class="sharepoint-admin-actions sharepoint-admin-actions-row">
                            <button type="submit" class="button button-primary">🧹 Purge selected catalogs</button>
                        </div>
                    </form>
                        </div>
                    </details>

                    <details class="sharepoint-admin-block" id="sharepoint-admin-import">
                        <summary>📥 Import Excel / CSV</summary>
                        <div class="sharepoint-admin-block-body">
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
                        </div>
                    </details>
                        </div>
                    </details>
                </section>
            <?php endif; ?>
            <?php if (!$panelSolo): ?>
            </div>
            <?php endif; ?>
        </main>
        <?php require __DIR__ . '/includes/sharepoint-catalog-color-pop.php'; ?>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="assets/vendor/qrcode-generator.js?v=<?= filemtime(__DIR__ . '/assets/vendor/qrcode-generator.js') ?>"></script>
    <script src="assets/js/fuzzy-search.js?v=<?= filemtime(__DIR__ . '/assets/js/fuzzy-search.js') ?>"></script>
    <script src="assets/js/sharepoint-catalog.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-catalog.js') ?>"></script>
    <script src="assets/js/sharepoint-search-dashboard.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-search-dashboard.js') ?>"></script>
    <?php if (!$panelSolo): ?>
    <script src="assets/js/sharepoint-section-board.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-section-board.js') ?>"></script>
    <?php endif; ?>
    <?php if ($isAdmin && !$foldersSolo): ?>
    <script src="assets/js/sharepoint-public-share.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-public-share.js') ?>"></script>
    <?php endif; ?>
    <?php if (!$catalogSolo && !$foldersSolo): ?>
    <script src="assets/js/sharepoint-owner-stats.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-owner-stats.js') ?>"></script>
    <?php endif; ?>
        <?php if (!$ownerSolo && !$catalogSolo && $isAdmin): ?>
        <script src="assets/js/sharepoint-source-delete.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-source-delete.js') ?>"></script>
        <?php endif; ?>
        <?php if (!$ownerSolo && !$catalogSolo && $isAdmin && $enableOneClickSync): ?>
        <script src="assets/vendor/msal-browser.min.js?v=<?= filemtime(__DIR__ . '/assets/vendor/msal-browser.min.js') ?>"></script>
        <script src="assets/js/sharepoint-msal-sync.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-msal-sync.js') ?>"></script>
        <?php endif; ?>
        <?php if (!$ownerSolo && !$catalogSolo && $isAdmin && $enableConsoleSync): ?>
        <script src="assets/js/sharepoint-mfa-sync.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-mfa-sync.js') ?>"></script>
        <script src="assets/js/sharepoint-mfa-ui.js?v=<?= filemtime(__DIR__ . '/assets/js/sharepoint-mfa-ui.js') ?>"></script>
        <?php endif; ?>
</body>
</html>
