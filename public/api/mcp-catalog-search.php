<?php

declare(strict_types=1);

/**
 * MCP API Endpoint for SharePoint Catalog Search
 * 
 * This endpoint provides programmatic access to the SharePoint catalog
 * for MCP (Model Context Protocol) servers and AI assistants.
 * 
 * Authentication: API key via X-API-Key header
 */

require __DIR__ . '/../bootstrap.php';

use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\Repositories\SharePointSourceRepository;
use RiskAssessment\Repositories\SharePointArchiveRepository;
use RiskAssessment\Repositories\SharePointSearchTagRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

// CORS headers for MCP server access
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    // In production, restrict this to specific origins
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Send JSON response
 */
function sendJson(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 */
function sendError(string $message, int $code = 400): never
{
    sendJson(['error' => $message, 'success' => false], $code);
}

// API Key authentication
$apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
$configuredKey = trim((string) $settings->get('mcp_api_key', ''));

if ($configuredKey === '') {
    sendError('MCP API not configured. Please set mcp_api_key in settings.', 500);
}

if ($apiKey === '' || !hash_equals($configuredKey, $apiKey)) {
    sendError('Invalid or missing API key', 401);
}

// Only allow GET and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    sendError('Method not allowed', 405);
}

// Parse request
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '') {
    sendError('Missing action parameter');
}

// Initialize repositories
$catalog = new SharePointCatalogRepository($pdo);
$sourcesRepo = new SharePointSourceRepository($pdo);
$archivesRepo = new SharePointArchiveRepository($pdo);
$tagsRepo = new SharePointSearchTagRepository($pdo);

try {
    match ($action) {
        'search' => handleSearch($catalog, $sourcesRepo),
        'list_projects' => handleListProjects($catalog, $sourcesRepo),
        'get_project' => handleGetProject($catalog, $sourcesRepo),
        'list_sources' => handleListSources($sourcesRepo),
        'get_source' => handleGetSource($sourcesRepo),
        'search_tags' => handleSearchTags($tagsRepo),
        'stats' => handleStats($catalog, $sourcesRepo),
        default => sendError("Unknown action: {$action}")
    };
} catch (Throwable $e) {
    error_log("MCP API Error [{$action}]: " . $e->getMessage());
    sendError('Internal server error: ' . $e->getMessage(), 500);
}

/**
 * Search catalog across all sources or specific source
 */
function handleSearch(SharePointCatalogRepository $catalog, SharePointSourceRepository $sourcesRepo): never
{
    $query = trim($_GET['query'] ?? $_POST['query'] ?? '');
    $sourceKey = trim($_GET['source'] ?? $_POST['source'] ?? '');
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 25)));
    
    if ($query === '') {
        sendError('Missing query parameter');
    }
    
    // If no source specified, search all sources
    if ($sourceKey === '') {
        $sources = $sourcesRepo->listAll();
        $results = [];
        
        foreach ($sources as $source) {
            $key = (string) ($source['source_key'] ?? '');
            if ($key === '') continue;
            
            $sourceResults = $catalog->search($query, $key, $limit);
            foreach ($sourceResults as $result) {
                $result['source_key'] = $key;
                $result['source_title'] = (string) ($source['title'] ?? $key);
                $results[] = $result;
            }
        }
        
        sendJson([
            'success' => true,
            'query' => $query,
            'results' => $results,
            'result_count' => count($results),
            'searched_sources' => count($sources)
        ]);
    }
    
    // Search specific source
    $source = $sourcesRepo->findByKey($sourceKey);
    if ($source === null) {
        sendError("Source not found: {$sourceKey}", 404);
    }
    
    $results = $catalog->search($query, $sourceKey, $limit);
    
    sendJson([
        'success' => true,
        'query' => $query,
        'source_key' => $sourceKey,
        'source_title' => (string) ($source['title'] ?? $sourceKey),
        'results' => $results,
        'result_count' => count($results)
    ]);
}

/**
 * List projects with pagination
 */
function handleListProjects(SharePointCatalogRepository $catalog, SharePointSourceRepository $sourcesRepo): never
{
    $sourceKey = trim($_GET['source'] ?? $_POST['source'] ?? 'default');
    $query = trim($_GET['query'] ?? $_POST['query'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? $_POST['page'] ?? 1));
    $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? $_POST['per_page'] ?? 25)));
    
    $source = $sourcesRepo->findByKey($sourceKey);
    if ($source === null) {
        sendError("Source not found: {$sourceKey}", 404);
    }
    
    $projects = $catalog->listProjects($query, $page, $perPage, $sourceKey);
    $totalCount = $query === '' 
        ? $catalog->countProjects($sourceKey)
        : $catalog->countMatchingProjects($query, $sourceKey);
    
    sendJson([
        'success' => true,
        'source_key' => $sourceKey,
        'source_title' => (string) ($source['title'] ?? $sourceKey),
        'query' => $query,
        'page' => $page,
        'per_page' => $perPage,
        'total_projects' => $totalCount,
        'projects' => $projects
    ]);
}

/**
 * Get detailed project information
 */
function handleGetProject(SharePointCatalogRepository $catalog, SharePointSourceRepository $sourcesRepo): never
{
    $projectName = trim($_GET['project'] ?? $_POST['project'] ?? '');
    $sourceKey = trim($_GET['source'] ?? $_POST['source'] ?? 'default');
    
    if ($projectName === '') {
        sendError('Missing project parameter');
    }
    
    $source = $sourcesRepo->findByKey($sourceKey);
    if ($source === null) {
        sendError("Source not found: {$sourceKey}", 404);
    }
    
    $project = $catalog->getProject($projectName, $sourceKey);
    if ($project === null) {
        sendError("Project not found: {$projectName}", 404);
    }
    
    sendJson([
        'success' => true,
        'source_key' => $sourceKey,
        'source_title' => (string) ($source['title'] ?? $sourceKey),
        'project' => $project
    ]);
}

/**
 * List all available catalog sources
 */
function handleListSources(SharePointSourceRepository $sourcesRepo): never
{
    $sources = $sourcesRepo->listAll();
    
    $formatted = array_map(function (array $source): array {
        return [
            'source_key' => (string) ($source['source_key'] ?? ''),
            'title' => (string) ($source['title'] ?? ''),
            'folder_url' => (string) ($source['folder_url'] ?? ''),
            'site_host' => (string) ($source['site_host'] ?? ''),
            'last_synced_at' => (string) ($source['last_synced_at'] ?? ''),
            'last_sync_status' => (string) ($source['last_sync_status'] ?? ''),
            'last_item_count' => (int) ($source['last_item_count'] ?? 0)
        ];
    }, $sources);
    
    sendJson([
        'success' => true,
        'sources' => $formatted,
        'count' => count($formatted)
    ]);
}

/**
 * Get specific source details
 */
function handleGetSource(SharePointSourceRepository $sourcesRepo): never
{
    $sourceKey = trim($_GET['source'] ?? $_POST['source'] ?? '');
    
    if ($sourceKey === '') {
        sendError('Missing source parameter');
    }
    
    $source = $sourcesRepo->findByKey($sourceKey);
    if ($source === null) {
        sendError("Source not found: {$sourceKey}", 404);
    }
    
    sendJson([
        'success' => true,
        'source' => [
            'source_key' => (string) ($source['source_key'] ?? ''),
            'title' => (string) ($source['title'] ?? ''),
            'folder_url' => (string) ($source['folder_url'] ?? ''),
            'site_host' => (string) ($source['site_host'] ?? ''),
            'site_path' => (string) ($source['site_path'] ?? ''),
            'folder_path' => (string) ($source['folder_path'] ?? ''),
            'last_synced_at' => (string) ($source['last_synced_at'] ?? ''),
            'last_sync_status' => (string) ($source['last_sync_status'] ?? ''),
            'last_item_count' => (int) ($source['last_item_count'] ?? 0)
        ]
    ]);
}

/**
 * Search available tags
 */
function handleSearchTags(SharePointSearchTagRepository $tagsRepo): never
{
    $query = trim($_GET['query'] ?? $_POST['query'] ?? '');
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 50)));
    
    $tags = $tagsRepo->listAll();
    
    // Filter by query if provided
    if ($query !== '') {
        $queryLower = mb_strtolower($query);
        $tags = array_filter($tags, function (array $tag) use ($queryLower): bool {
            $name = mb_strtolower((string) ($tag['tag_name'] ?? ''));
            return str_contains($name, $queryLower);
        });
    }
    
    // Limit results
    $tags = array_slice($tags, 0, $limit);
    
    sendJson([
        'success' => true,
        'query' => $query,
        'tags' => array_map(function (array $tag): array {
            return [
                'tag_name' => (string) ($tag['tag_name'] ?? ''),
                'tag_color' => (string) ($tag['tag_color'] ?? ''),
                'usage_count' => (int) ($tag['usage_count'] ?? 0)
            ];
        }, $tags),
        'count' => count($tags)
    ]);
}

/**
 * Get catalog statistics
 */
function handleStats(SharePointCatalogRepository $catalog, SharePointSourceRepository $sourcesRepo): never
{
    $sources = $sourcesRepo->listAll();
    $stats = [];
    
    foreach ($sources as $source) {
        $key = (string) ($source['source_key'] ?? '');
        if ($key === '') continue;
        
        $stats[] = [
            'source_key' => $key,
            'title' => (string) ($source['title'] ?? $key),
            'total_items' => $catalog->count($key),
            'total_projects' => $catalog->countProjects($key),
            'last_synced_at' => (string) ($source['last_synced_at'] ?? ''),
            'last_sync_status' => (string) ($source['last_sync_status'] ?? '')
        ];
    }
    
    sendJson([
        'success' => true,
        'total_sources' => count($sources),
        'sources' => $stats
    ]);
}
