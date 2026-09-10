<?php

declare(strict_types=1);

/**
 * MCP Token Management API
 * 
 * Admin endpoint for creating, listing, and revoking MCP tokens.
 * Follows LinkNest pattern for token-based AI assistant access.
 */

require __DIR__ . '/../bootstrap.php';

use RiskAssessment\Actor;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

$currentUser = $auth->requireAuth();
if (!$currentUser || empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Administrator access required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$path = rtrim($pathInfo, '/') ?: '/';

/**
 * Normalize scope list
 */
function normalizeScopes($raw): string
{
    $parts = [];
    if (is_array($raw)) {
        $parts = $raw;
    } elseif (is_string($raw)) {
        $parts = explode(',', $raw);
    }
    
    $out = [];
    foreach ($parts as $p) {
        $p = strtolower(trim((string) $p));
        if (in_array($p, ['read', 'write'], true)) {
            $out[] = $p;
        }
    }
    
    $out = array_values(array_unique($out));
    if (!in_array('read', $out, true)) {
        array_unshift($out, 'read');
    }
    
    return implode(',', $out);
}

/**
 * Map database row to API response
 */
function mapTokenRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'userId' => (int) $row['user_id'],
        'username' => $row['username'] ?? null,
        'name' => $row['name'],
        'tokenPrefix' => $row['token_prefix'],
        'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) $row['scopes'])))),
        'lastUsedAt' => $row['last_used_at'] !== null ? (int) $row['last_used_at'] : null,
        'expiresAt' => $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
        'createdAt' => (int) $row['created_at'],
        'revokedAt' => $row['revoked_at'] !== null ? (int) $row['revoked_at'] : null,
    ];
}

/**
 * Send JSON response
 */
function sendJson(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    switch ($method) {
        case 'GET':
            if ($path !== '/') {
                sendJson(['error' => 'Invalid endpoint'], 404);
            }
            
            $includeRevoked = isset($_GET['include_revoked']) 
                && in_array(strtolower((string) $_GET['include_revoked']), ['1', 'true', 'yes'], true);
            
            $sql = 'SELECT t.*, u.username FROM mcp_tokens t 
                    LEFT JOIN users u ON u.id = t.user_id';
            
            if (!$includeRevoked) {
                $sql .= ' WHERE t.revoked_at IS NULL';
            }
            
            $sql .= ' ORDER BY t.created_at DESC';
            
            $stmt = $pdo->query($sql);
            $tokens = [];
            
            while ($row = $stmt->fetch()) {
                $tokens[] = mapTokenRow($row);
            }
            
            sendJson(['data' => $tokens]);
            break;
        
        case 'POST':
            if ($path !== '/') {
                sendJson(['error' => 'Invalid endpoint'], 404);
            }
            
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            
            if (!is_array($data)) {
                sendJson(['error' => 'Invalid JSON'], 400);
            }
            
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                sendJson(['error' => 'Name is required'], 400);
            }
            
            if (strlen($name) > 80) {
                $name = substr($name, 0, 80);
            }
            
            $userId = isset($data['userId']) ? (int) $data['userId'] : (int) $currentUser['id'];
            if ($userId < 1) {
                $userId = (int) $currentUser['id'];
            }
            
            $userStmt = $pdo->prepare(
                'SELECT id, username, is_admin, is_approved, is_disabled 
                 FROM users WHERE id = ?'
            );
            $userStmt->execute([$userId]);
            $target = $userStmt->fetch();
            
            if (!$target || empty($target['is_approved'])) {
                sendJson(['error' => 'Target user not found or not approved'], 400);
            }
            
            if (!empty($target['is_disabled'])) {
                sendJson(['error' => 'Target user is disabled'], 400);
            }
            
            $scopes = normalizeScopes($data['scopes'] ?? ['read']);
            
            $expiresAt = null;
            if (isset($data['expiresInDays']) && is_numeric($data['expiresInDays'])) {
                $days = (int) $data['expiresInDays'];
                if ($days > 0) {
                    $expiresAt = time() + ($days * 86400);
                }
            } elseif (!empty($data['expiresAt']) && is_numeric($data['expiresAt'])) {
                $expiresAt = (int) $data['expiresAt'];
            }
            
            // Generate token
            $raw = 'ramcp_' . bin2hex(random_bytes(32));
            $hash = hash('sha256', $raw);
            $prefix = substr($raw, 0, 12) . '…';
            $now = time();
            
            $ins = $pdo->prepare(
                'INSERT INTO mcp_tokens 
                (user_id, name, token_hash, token_prefix, scopes, expires_at, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            
            $ins->execute([$userId, $name, $hash, $prefix, $scopes, $expiresAt, $now]);
            $id = (int) $pdo->lastInsertId();
            
            // Log activity
            try {
                $actor = Actor::fromCurrentUser($currentUser);
                $actor->log('mcp.token_created', [
                    'token_id' => $id,
                    'name' => $name,
                    'target_user_id' => $userId,
                    'target_username' => $target['username'],
                    'scopes' => $scopes,
                ]);
            } catch (\Throwable $e) {
                error_log('Failed to log MCP token creation: ' . $e->getMessage());
            }
            
            sendJson([
                'data' => [
                    'id' => $id,
                    'userId' => $userId,
                    'username' => $target['username'],
                    'name' => $name,
                    'tokenPrefix' => $prefix,
                    'scopes' => explode(',', $scopes),
                    'expiresAt' => $expiresAt,
                    'createdAt' => $now,
                    'token' => $raw,
                ],
            ], 201);
            break;
        
        case 'DELETE':
            if (!preg_match('#^/(\d+)$#', $path, $m)) {
                sendJson(['error' => 'Invalid endpoint'], 404);
            }
            
            $id = (int) $m[1];
            
            $stmt = $pdo->prepare(
                'SELECT t.*, u.username FROM mcp_tokens t 
                 LEFT JOIN users u ON u.id = t.user_id 
                 WHERE t.id = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            
            if (!$row) {
                sendJson(['error' => 'Token not found'], 404);
            }
            
            if (!empty($row['revoked_at'])) {
                sendJson(['data' => mapTokenRow($row)]);
            }
            
            $pdo->prepare('UPDATE mcp_tokens SET revoked_at = ? WHERE id = ?')
                ->execute([time(), $id]);
            
            // Log activity
            try {
                $actor = Actor::fromCurrentUser($currentUser);
                $actor->log('mcp.token_revoked', [
                    'token_id' => $id,
                    'name' => $row['name'],
                    'target_user_id' => (int) $row['user_id'],
                    'target_username' => $row['username'] ?? null,
                ]);
            } catch (\Throwable $e) {
                error_log('Failed to log MCP token revocation: ' . $e->getMessage());
            }
            
            $row['revoked_at'] = time();
            sendJson(['data' => mapTokenRow($row)]);
            break;
        
        default:
            sendJson(['error' => 'Method not allowed'], 405);
    }
} catch (\Throwable $e) {
    error_log('MCP Token API error: ' . $e->getMessage());
    sendJson(['error' => 'Internal server error'], 500);
}
