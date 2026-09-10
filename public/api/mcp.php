<?php

declare(strict_types=1);

/**
 * Risk Register MCP (Model Context Protocol) endpoint.
 *
 * JSON-RPC 2.0 over HTTP POST with Bearer token authentication.
 * Follows LinkNest pattern for AI assistant integration.
 *
 * Spec: https://modelcontextprotocol.io/specification/2025-03-26
 */

require __DIR__ . '/../bootstrap.php';

use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\Repositories\SharePointSourceRepository;
use RiskAssessment\Repositories\SharePointSearchTagRepository;

// MCP is Bearer-token auth only — release the PHP session lock so Cursor
// can run concurrent initialize/tools/list calls without hanging.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('MCP-Protocol-Version: 2025-03-26');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, MCP-Protocol-Version, Mcp-Session-Id');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle OPTIONS for CORS
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Streamable HTTP clients may send DELETE to end a session
if ($method === 'DELETE') {
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    http_response_code(405);
    header('Allow: POST, DELETE, OPTIONS');
    echo json_encode([
        'error' => 'Use HTTP POST with a JSON-RPC body for MCP. GET SSE streams are not required by this server.',
        'endpoint' => 'POST /api/mcp',
        'auth' => 'Authorization: Bearer ramcp_…',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Only POST allowed for JSON-RPC
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, DELETE, OPTIONS');
    echo json_encode([
        'error' => 'Method not allowed. Use POST with JSON-RPC body.',
        'endpoint' => 'POST /api/mcp',
        'auth' => 'Authorization: Bearer ramcp_…',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Parse JSON-RPC request
$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    mcpJsonRpcError(null, -32700, 'Parse error: empty body', 400);
}

$decoded = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    mcpJsonRpcError(null, -32700, 'Parse error: invalid JSON', 400);
}

// Authenticate via Bearer token
$user = getMcpUser();
if (!$user) {
    header('WWW-Authenticate: Bearer realm="Risk Register MCP"');
    http_response_code(401);
    $id = is_array($decoded) && !isBatchRequest($decoded) ? ($decoded['id'] ?? null) : null;
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => [
            'code' => -32001,
            'message' => 'Authentication required. Create an MCP token in Admin → MCP / AI and send it as Authorization: Bearer ramcp_…',
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Handle batch or single request
if (isBatchRequest($decoded)) {
    $out = [];
    foreach ($decoded as $message) {
        if (!is_array($message)) {
            continue;
        }
        $result = handleMessage($message, $user);
        if ($result !== null) {
            $out[] = $result;
        }
    }
    
    if ($out === []) {
        http_response_code(202);
        exit;
    }
    
    sendJson($out);
}

$result = handleMessage($decoded, $user);
if ($result === null) {
    http_response_code(202);
    exit;
}

sendJson($result);

// ============================================================================
// Helper Functions
// ============================================================================

function isBatchRequest(array $decoded): bool
{
    if ($decoded === []) {
        return true;
    }
    return array_keys($decoded) === range(0, count($decoded) - 1);
}

function sendJson($data): never
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mcpJsonRpcError($id, int $code, string $message, int $http = 400): never
{
    http_response_code($http);
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => $code, 'message' => $message],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function mcpResult($id, $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function mcpError($id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function mcpTextResult($id, $payload, bool $isError = false): array
{
    $text = is_string($payload)
        ? $payload
        : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    return mcpResult($id, [
        'content' => [['type' => 'text', 'text' => $text]],
        'isError' => $isError,
    ]);
}

function getMcpUser(): ?array
{
    global $pdo;
    
    $auth = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $auth = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
    }
    if ($auth === '') {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');
    }
    if ($auth === '') {
        return null;
    }
    
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
        return null;
    }
    
    $token = trim((string) $matches[1]);
    if (!str_starts_with($token, 'ramcp_')) {
        return null;
    }
    
    $hash = hash('sha256', $token);
    
    $stmt = $pdo->prepare(
        'SELECT t.id, t.user_id, t.scopes, t.expires_at, t.revoked_at, t.last_used_at,
                u.username, u.display_name, u.is_admin, u.is_approved, u.is_disabled
         FROM mcp_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ?
         LIMIT 1'
    );
    
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    
    if (!$row) {
        return null;
    }
    
    // Check if revoked
    if (!empty($row['revoked_at'])) {
        return null;
    }
    
    // Check if expired
    if (!empty($row['expires_at']) && (int) $row['expires_at'] < time()) {
        return null;
    }
    
    // Check user status
    if (empty($row['is_approved']) || !empty($row['is_disabled'])) {
        return null;
    }
    
    // Update last_used_at (throttle to once per minute to reduce writes)
    $lastUsed = $row['last_used_at'] ? (int) $row['last_used_at'] : 0;
    if (time() - $lastUsed > 60) {
        $pdo->prepare('UPDATE mcp_tokens SET last_used_at = ? WHERE id = ?')
            ->execute([time(), (int) $row['id']]);
    }
    
    $scopes = array_filter(array_map('trim', explode(',', (string) $row['scopes'])));
    
    return [
        'id' => (int) $row['user_id'],
        'username' => $row['username'],
        'display_name' => $row['display_name'] ?? $row['username'],
        'is_admin' => !empty($row['is_admin']),
        '_mcp_scopes' => $scopes,
        '_mcp_token_id' => (int) $row['id'],
    ];
}

function hasWriteScope(array $user): bool
{
    return in_array('write', $user['_mcp_scopes'] ?? ['read'], true);
}

function handleMessage(array $message, array $user): ?array
{
    if (($message['jsonrpc'] ?? '') !== '2.0') {
        $id = $message['id'] ?? null;
        if (!array_key_exists('id', $message)) {
            return null;
        }
        return mcpError($id, -32600, 'Invalid Request: jsonrpc must be "2.0"');
    }
    
    $method = (string) ($message['method'] ?? '');
    $id = $message['id'] ?? null;
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];
    $isNotification = !array_key_exists('id', $message);
    
    switch ($method) {
        case 'initialize':
            if ($isNotification) {
                return null;
            }
            return mcpResult($id, [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name' => 'risk-register',
                    'version' => '1.0.0',
                ],
                'instructions' => 'SharePoint catalog search for Risk Register. Use search_sharepoint_catalog for full-text search, list_sharepoint_projects to browse, get_sharepoint_project for details. Operators: tag:name, ext:pdf, person:"name", "exact phrase", -exclude.',
            ]);
        
        case 'notifications/initialized':
        case 'notifications/cancelled':
            return null;
        
        case 'ping':
            if ($isNotification) {
                return null;
            }
            return mcpResult($id, new \stdClass());
        
        case 'tools/list':
            if ($isNotification) {
                return null;
            }
            return mcpResult($id, ['tools' => getTools()]);
        
        case 'tools/call':
            if ($isNotification) {
                return null;
            }
            $name = (string) ($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            return callTool($id, $name, $args, $user);
        
        default:
            if ($isNotification) {
                return null;
            }
            return mcpError($id, -32601, 'Method not found: ' . $method);
    }
}

function getTools(): array
{
    return [
        [
            'name' => 'search_sharepoint_catalog',
            'description' => 'Search the SharePoint catalog for projects, files, and folders. Supports full-text search with operators: tag:name, ext:pdf, person:"name", path:folder, has:pdf, "exact phrase", -exclude. Use Deep files mode to search nested file names.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query with optional operators (tag:, ext:, person:, etc.)',
                    ],
                    'source' => [
                        'type' => 'string',
                        'description' => 'Optional catalog source key (default, public, private). If omitted, searches all sources.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum results (1-100, default: 25)',
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name' => 'list_sharepoint_projects',
            'description' => 'List SharePoint projects with pagination. Returns project summaries with item counts, file counts, and modification info.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'source' => [
                        'type' => 'string',
                        'description' => 'Catalog source key (default: "default")',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Optional search query to filter projects',
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => 'Page number (default: 1)',
                        'minimum' => 1,
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Results per page (1-100, default: 25)',
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                ],
            ],
        ],
        [
            'name' => 'get_sharepoint_project',
            'description' => 'Get detailed information about a specific SharePoint project including all nested files, folders, sizes, URLs, and metadata.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project' => [
                        'type' => 'string',
                        'description' => 'Project name (folder name)',
                    ],
                    'source' => [
                        'type' => 'string',
                        'description' => 'Catalog source key (default: "default")',
                    ],
                ],
                'required' => ['project'],
            ],
        ],
        [
            'name' => 'list_sharepoint_sources',
            'description' => 'List all available SharePoint catalog sources with sync status and item counts.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
            ],
        ],
        [
            'name' => 'get_sharepoint_source',
            'description' => 'Get detailed information about a specific catalog source including configuration and sync status.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'source' => [
                        'type' => 'string',
                        'description' => 'Source key to retrieve',
                    ],
                ],
                'required' => ['source'],
            ],
        ],
        [
            'name' => 'search_sharepoint_tags',
            'description' => 'Search for tags used to organize SharePoint projects. Returns tag names, colors, and usage counts.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Optional search query to filter tags by name',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum tags to return (1-100, default: 50)',
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                ],
            ],
        ],
        [
            'name' => 'get_sharepoint_catalog_stats',
            'description' => 'Get overview statistics for all SharePoint catalog sources including total items, projects, and sync status.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [],
            ],
        ],
    ];
}

function callTool($id, string $name, array $args, array $user): array
{
    global $pdo;
    
    try {
        $catalog = new SharePointCatalogRepository($pdo);
        $sourcesRepo = new SharePointSourceRepository($pdo);
        $tagsRepo = new SharePointSearchTagRepository($pdo);
        
        switch ($name) {
            case 'search_sharepoint_catalog':
                $query = trim((string) ($args['query'] ?? ''));
                if ($query === '') {
                    return mcpTextResult($id, ['error' => 'query is required'], true);
                }
                
                $sourceKey = trim((string) ($args['source'] ?? ''));
                $limit = max(1, min(100, (int) ($args['limit'] ?? 25)));
                @set_time_limit(120);
                
                if ($sourceKey === '') {
                    // Search all sources (cap per-source and total to avoid timeouts)
                    $sources = $sourcesRepo->listAll();
                    $results = [];
                    $perSourceLimit = max(1, min($limit, 10));
                    
                    foreach ($sources as $source) {
                        if (count($results) >= $limit) {
                            break;
                        }
                        
                        $key = (string) ($source['source_key'] ?? '');
                        if ($key === '') {
                            continue;
                        }
                        
                        $remaining = $limit - count($results);
                        $sourceResults = $catalog->search($query, $key, min($perSourceLimit, $remaining));
                        foreach ($sourceResults as $result) {
                            $result['source_key'] = $key;
                            $result['source_title'] = (string) ($source['title'] ?? $key);
                            $results[] = $result;
                            if (count($results) >= $limit) {
                                break;
                            }
                        }
                    }
                    
                    return mcpTextResult($id, [
                        'success' => true,
                        'query' => $query,
                        'results' => $results,
                        'result_count' => count($results),
                        'searched_sources' => count($sources),
                    ]);
                }
                
                // Search specific source
                $source = $sourcesRepo->findByKey($sourceKey);
                if ($source === null) {
                    return mcpTextResult($id, ['error' => "Source not found: {$sourceKey}"], true);
                }
                
                $results = $catalog->search($query, $sourceKey, $limit);
                
                return mcpTextResult($id, [
                    'success' => true,
                    'query' => $query,
                    'source_key' => $sourceKey,
                    'source_title' => (string) ($source['title'] ?? $sourceKey),
                    'results' => $results,
                    'result_count' => count($results),
                ]);
            
            case 'list_sharepoint_projects':
                $sourceKey = trim((string) ($args['source'] ?? 'default'));
                $query = trim((string) ($args['query'] ?? ''));
                $page = max(1, (int) ($args['page'] ?? 1));
                $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));
                
                $source = $sourcesRepo->findByKey($sourceKey);
                if ($source === null) {
                    return mcpTextResult($id, ['error' => "Source not found: {$sourceKey}"], true);
                }
                
                $projects = $catalog->listProjects($query, $page, $perPage, $sourceKey);
                $totalCount = $query === ''
                    ? $catalog->countProjects($sourceKey)
                    : $catalog->countMatchingProjects($query, $sourceKey);
                
                return mcpTextResult($id, [
                    'success' => true,
                    'source_key' => $sourceKey,
                    'source_title' => (string) ($source['title'] ?? $sourceKey),
                    'query' => $query,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_projects' => $totalCount,
                    'projects' => $projects,
                ]);
            
            case 'get_sharepoint_project':
                $projectName = trim((string) ($args['project'] ?? ''));
                if ($projectName === '') {
                    return mcpTextResult($id, ['error' => 'project is required'], true);
                }
                
                $sourceKey = trim((string) ($args['source'] ?? 'default'));
                
                $source = $sourcesRepo->findByKey($sourceKey);
                if ($source === null) {
                    return mcpTextResult($id, ['error' => "Source not found: {$sourceKey}"], true);
                }
                
                $project = $catalog->getProject($projectName, $sourceKey);
                if ($project === null) {
                    return mcpTextResult($id, ['error' => "Project not found: {$projectName}"], true);
                }
                
                return mcpTextResult($id, [
                    'success' => true,
                    'source_key' => $sourceKey,
                    'source_title' => (string) ($source['title'] ?? $sourceKey),
                    'project' => $project,
                ]);
            
            case 'list_sharepoint_sources':
                $sources = $sourcesRepo->listAll();
                
                $formatted = array_map(function (array $source): array {
                    return [
                        'source_key' => (string) ($source['source_key'] ?? ''),
                        'title' => (string) ($source['title'] ?? ''),
                        'folder_url' => (string) ($source['folder_url'] ?? ''),
                        'last_synced_at' => (string) ($source['last_synced_at'] ?? ''),
                        'last_sync_status' => (string) ($source['last_sync_status'] ?? ''),
                        'last_item_count' => (int) ($source['last_item_count'] ?? 0),
                    ];
                }, $sources);
                
                return mcpTextResult($id, [
                    'success' => true,
                    'sources' => $formatted,
                    'count' => count($formatted),
                ]);
            
            case 'get_sharepoint_source':
                $sourceKey = trim((string) ($args['source'] ?? ''));
                if ($sourceKey === '') {
                    return mcpTextResult($id, ['error' => 'source is required'], true);
                }
                
                $source = $sourcesRepo->findByKey($sourceKey);
                if ($source === null) {
                    return mcpTextResult($id, ['error' => "Source not found: {$sourceKey}"], true);
                }
                
                return mcpTextResult($id, [
                    'success' => true,
                    'source' => [
                        'source_key' => (string) ($source['source_key'] ?? ''),
                        'title' => (string) ($source['title'] ?? ''),
                        'folder_url' => (string) ($source['folder_url'] ?? ''),
                        'last_synced_at' => (string) ($source['last_synced_at'] ?? ''),
                        'last_sync_status' => (string) ($source['last_sync_status'] ?? ''),
                        'last_item_count' => (int) ($source['last_item_count'] ?? 0),
                    ],
                ]);
            
            case 'search_sharepoint_tags':
                $query = trim((string) ($args['query'] ?? ''));
                $limit = max(1, min(100, (int) ($args['limit'] ?? 50)));
                
                $tags = $tagsRepo->listAll();
                
                if ($query !== '') {
                    $queryLower = mb_strtolower($query);
                    $tags = array_filter($tags, function (array $tag) use ($queryLower): bool {
                        $name = mb_strtolower((string) ($tag['tag_name'] ?? ''));
                        return str_contains($name, $queryLower);
                    });
                }
                
                $tags = array_slice($tags, 0, $limit);
                
                return mcpTextResult($id, [
                    'success' => true,
                    'query' => $query,
                    'tags' => array_map(function (array $tag): array {
                        return [
                            'tag_name' => (string) ($tag['tag_name'] ?? ''),
                            'tag_color' => (string) ($tag['tag_color'] ?? ''),
                            'usage_count' => (int) ($tag['usage_count'] ?? 0),
                        ];
                    }, $tags),
                    'count' => count($tags),
                ]);
            
            case 'get_sharepoint_catalog_stats':
                $sources = $sourcesRepo->listAll();
                $stats = [];
                
                foreach ($sources as $source) {
                    $key = (string) ($source['source_key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    
                    $stats[] = [
                        'source_key' => $key,
                        'title' => (string) ($source['title'] ?? $key),
                        'total_items' => $catalog->count($key),
                        'total_projects' => $catalog->countProjects($key),
                        'last_synced_at' => (string) ($source['last_synced_at'] ?? ''),
                        'last_sync_status' => (string) ($source['last_sync_status'] ?? ''),
                    ];
                }
                
                return mcpTextResult($id, [
                    'success' => true,
                    'total_sources' => count($sources),
                    'sources' => $stats,
                ]);
            
            default:
                return mcpError($id, -32602, 'Unknown tool: ' . $name);
        }
    } catch (\Throwable $e) {
        error_log('MCP tool error [' . $name . ']: ' . $e->getMessage());
        return mcpTextResult($id, ['error' => $e->getMessage()], true);
    }
}
