<?php

declare(strict_types=1);

/**
 * ServiceNow console sync for Ticket Dossier.
 *
 * prepare_browser_sync — signed-in session + CSRF
 * browser_sync_import / attachment / complete — short-lived token + CORS from SN origin
 */
require_once dirname(__DIR__) . '/bootstrap.php';

use RiskAssessment\AppUrl;
use RiskAssessment\ServiceNow\ServiceNowBrowserSync;

// Ticket Dossier data stack without forcing requireAuth() on every request.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/FileClassifier.php';
require_once __DIR__ . '/includes/parsers/DdrJsonParser.php';
require_once __DIR__ . '/includes/parsers/ServicenowPdfParser.php';
require_once __DIR__ . '/includes/parsers/ServicenowTaskPacketParser.php';
require_once __DIR__ . '/includes/ProjectRepository.php';
require_once __DIR__ . '/includes/ProjectImporter.php';
require_once __DIR__ . '/includes/DossierFileManager.php';

if (!is_dir(TD_DATABASE_DIR)) {
    mkdir(TD_DATABASE_DIR, 0755, true);
}
if (!is_dir(TD_STORAGE_DIR)) {
    mkdir(TD_STORAGE_DIR, 0755, true);
}

$pdo = getDb();
$browserSync = new ServiceNowBrowserSync($pdo);
$browserSync->ensureSchema();

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

$jsonOut = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$readJsonBody = static function (): array {
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $payload = json_decode($raw, true);

    return is_array($payload) ? $payload : [];
};

$resolveToken = static function (array $payload = []): string {
    $fromHeader = trim((string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? ''));
    if ($fromHeader !== '') {
        return $fromHeader;
    }

    $fromQuery = trim((string) ($_GET['token'] ?? ''));
    if ($fromQuery !== '') {
        return $fromQuery;
    }

    return trim((string) ($payload['token'] ?? $_POST['token'] ?? ''));
};

$projectUrl = static function (int $projectId): string {
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/ticket-dossier/browser-sync.php')));
    $scriptDir = rtrim($scriptDir, '/');

    return AppUrl::scheme() . '://' . AppUrl::host() . $scriptDir . '/project.php?id=' . $projectId;
};

// CORS preflight (no auth).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
    && in_array($action, ['browser_sync_import', 'browser_sync_attachment', 'browser_sync_complete'], true)
) {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $token = $resolveToken();
    $allowed = '';
    if ($token !== '') {
        try {
            $row = $browserSync->assertValidToken($token);
            $allowed = (string) ($row['instance_origin'] ?? '');
        } catch (Throwable) {
            // Still answer preflight with no Allow-Origin if token bad.
        }
    }
    if ($allowed !== '') {
        $browserSync->applyCorsHeaders($origin, $allowed);
    }
    http_response_code(204);
    exit;
}

// --- Token-auth endpoints (before requireAuth) ---
if (in_array($action, ['browser_sync_import', 'browser_sync_attachment', 'browser_sync_complete'], true)
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
) {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');

    try {
        if ($action === 'browser_sync_import') {
            $payload = $readJsonBody();
            $token = $resolveToken($payload);
            $row = $browserSync->assertValidToken($token);
            $allowedOrigin = (string) ($row['instance_origin'] ?? '');
            $browserSync->applyCorsHeaders($origin, $allowedOrigin);

            if ((int) ($row['project_id'] ?? 0) > 0) {
                throw new RuntimeException('This sync token already created a project. Prepare a new console pull.');
            }

            $packet = $payload['packet'] ?? $payload;
            if (!is_array($packet) || empty($packet['tickets'])) {
                throw new RuntimeException('JSON must include a packet with a tickets array.');
            }

            $expectedTask = strtoupper((string) ($row['task_number'] ?? ''));
            $rootNumber = strtoupper(trim((string) ($packet['root_number'] ?? '')));
            if ($expectedTask !== '' && $rootNumber !== '' && $rootNumber !== $expectedTask) {
                throw new RuntimeException(
                    'Packet root task ' . $rootNumber . ' does not match prepared task ' . $expectedTask . '.'
                );
            }

            $packetInstance = ServiceNowBrowserSync::normalizeInstanceOrigin(
                (string) ($packet['instance'] ?? $allowedOrigin)
            );
            if (rtrim(strtolower($packetInstance), '/') !== rtrim(strtolower($allowedOrigin), '/')) {
                // Soft: allow if packet omitted instance; hard-fail on mismatch.
                if (trim((string) ($packet['instance'] ?? '')) !== '') {
                    throw new RuntimeException('Packet instance does not match the prepared ServiceNow origin.');
                }
            }

            $owner = [
                'owner_user_id' => (int) ($row['owner_user_id'] ?? 0) ?: null,
                'owner_username' => (string) ($row['owner_username'] ?? ''),
                'owner_display_name' => (string) ($row['owner_display_name'] ?? ''),
                'owner_auth_source' => (string) ($row['owner_auth_source'] ?? ''),
            ];

            $result = ProjectImporter::importServiceNowPacket($packet, $owner);
            $projectId = (int) ($result['project_id'] ?? 0);
            if ($projectId <= 0) {
                throw new RuntimeException('Failed to create Ticket Dossier project.');
            }

            $browserSync->bindProject($token, $projectId);

            $jsonOut([
                'ok' => true,
                'project_id' => $projectId,
                'message' => 'Packet imported. Upload attachments next.',
                'warnings' => $result['warnings'] ?? [],
            ]);
        }

        if ($action === 'browser_sync_attachment') {
            $token = $resolveToken($_POST);
            $projectId = (int) ($_SERVER['HTTP_X_PROJECT_ID'] ?? $_POST['project_id'] ?? 0);
            $row = $browserSync->assertTokenForProject($token, $projectId);
            $browserSync->applyCorsHeaders($origin, (string) ($row['instance_origin'] ?? ''));

            if ($projectId <= 0 || ProjectRepository::find($projectId) === null) {
                throw new RuntimeException('Project not found for attachment upload.');
            }

            if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
                throw new RuntimeException('Missing file upload field "file".');
            }

            $upload = $_FILES['file'];
            $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Attachment upload failed (error ' . $error . ').');
            }

            $tmp = (string) ($upload['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('Invalid attachment upload.');
            }

            $size = (int) ($upload['size'] ?? filesize($tmp) ?: 0);
            if ($size <= 0 || $size > ServiceNowBrowserSync::MAX_ATTACHMENT_BYTES) {
                throw new RuntimeException(
                    'Attachment exceeds the ' . (int) round(ServiceNowBrowserSync::MAX_ATTACHMENT_BYTES / 1024 / 1024) . ' MB limit.'
                );
            }

            $ticketNumber = strtoupper(trim((string) ($_POST['ticket_number'] ?? '')));
            $relativePath = trim((string) ($_POST['relative_path'] ?? ''));
            $originalName = ServiceNowBrowserSync::sanitizeStoredFilename(
                (string) ($upload['name'] ?? 'attachment.bin')
            );

            if ($relativePath !== '') {
                // Prefer basename from relative path; keep ticket prefix in original_name for UI.
                $base = ServiceNowBrowserSync::sanitizeStoredFilename(basename(str_replace('\\', '/', $relativePath)));
                if ($base !== '') {
                    $originalName = $base;
                }
            }
            if (
                preg_match('/DDR\d+/i', $originalName)
                && preg_match('/\.json\.txt$/i', $originalName)
            ) {
                $originalName = (string) preg_replace('/\.txt$/i', '', $originalName);
                $relativePath = (string) preg_replace('/\.txt$/i', '', $relativePath);
            }

            $displayName = $ticketNumber !== ''
                ? $ticketNumber . '/' . $originalName
                : $originalName;

            $kind = 'attachment';
            if (preg_match('/^STRY\d+$/i', $ticketNumber)) {
                $kind = 'story';
            } elseif (preg_match('/^DMND\d+$/i', $ticketNumber)) {
                $kind = 'demand';
            } elseif (preg_match('/^DDR\d+$/i', $ticketNumber)) {
                $kind = 'ddr';
            } elseif (preg_match('/^TASK\d+$/i', $ticketNumber)) {
                $kind = 'task';
            }
            if (
                preg_match('/DDR\d+/i', $originalName)
                && preg_match('/\.json(?:\.txt)?$/i', $originalName)
            ) {
                $kind = 'ddr';
            }

            $storageDir = TD_STORAGE_DIR . '/' . $projectId;
            if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                throw new RuntimeException('Could not create storage directory.');
            }

            $ext = extensionOf($originalName);
            if ($ext === '') {
                $ext = 'bin';
            }
            $storedName = 'att_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $storageDir . '/' . $storedName;
            // Create a new destination instead of renaming PHP's temporary file.
            // On Windows, rename/move can preserve a restrictive temp-directory
            // ACL and make the stored attachment unreadable after this request.
            if (!copy($tmp, $dest)) {
                throw new RuntimeException('Could not store attachment.');
            }
            @chmod($dest, 0644);
            if (!is_file($dest) || (int) filesize($dest) !== $size) {
                @unlink($dest);
                throw new RuntimeException('Stored attachment failed size verification.');
            }

            $db = getDb();
            $stmt = $db->prepare(
                'INSERT INTO project_files (project_id, kind, original_name, stored_name, size_bytes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $projectId,
                $kind,
                $displayName,
                $storedName,
                $size,
                nowUtc(),
            ]);
            $fileId = (int) $db->lastInsertId();
            $reparseMessage = '';
            if (
                str_starts_with(str_replace('\\', '/', $relativePath), 'pdf/')
                || $kind === 'ddr'
            ) {
                // Ticket PDFs and DDR JSON/text attachments enrich the dossier
                // as soon as the console sync stores them.
                $reparseMessage = DossierFileManager::reparseFile($projectId, $fileId);
            }

            $jsonOut([
                'ok' => true,
                'file_id' => $fileId,
                'original_name' => $displayName,
                'size_bytes' => $size,
                'reparsed' => $reparseMessage !== '',
                'reparse_message' => $reparseMessage,
            ]);
        }

        if ($action === 'browser_sync_complete') {
            $payload = $readJsonBody();
            if ($payload === []) {
                $payload = $_POST;
            }
            $token = $resolveToken($payload);
            $projectId = (int) ($payload['project_id'] ?? $_SERVER['HTTP_X_PROJECT_ID'] ?? 0);
            $row = $browserSync->assertTokenForProject($token, $projectId);
            $browserSync->applyCorsHeaders($origin, (string) ($row['instance_origin'] ?? ''));

            $browserSync->clearToken($token);

            try {
                $auth->users()->logAudit(
                    'ticket_dossier.servicenow_console_sync',
                    (int) ($row['owner_user_id'] ?? 0),
                    (string) ($row['owner_username'] ?? 'browser-sync'),
                    null,
                    null,
                    [
                        'project_id' => $projectId,
                        'task_number' => (string) ($row['task_number'] ?? ''),
                        'instance' => (string) ($row['instance_origin'] ?? ''),
                    ]
                );
            } catch (Throwable) {
                // Audit must not break completion.
            }

            $jsonOut([
                'ok' => true,
                'project_id' => $projectId,
                'project_url' => $projectUrl($projectId),
                'message' => 'ServiceNow packet import complete.',
            ]);
        }
    } catch (Throwable $e) {
        $token = $resolveToken();
        $allowed = '';
        if ($token !== '') {
            try {
                $row = $browserSync->assertValidToken($token);
                $allowed = (string) ($row['instance_origin'] ?? '');
            } catch (Throwable) {
            }
        }
        if ($allowed !== '') {
            $browserSync->applyCorsHeaders($origin, $allowed);
        }
        $jsonOut(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

// --- Authenticated prepare ---
$currentUser = $auth->requireAuth();
\RiskAssessment\AppModules::instance()->require(\RiskAssessment\AppModules::TICKET, $currentUser);

if ($action === 'prepare_browser_sync' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        require_valid_csrf();

        $instance = trim((string) ($_POST['instance_url'] ?? $_POST['instance_origin'] ?? ''));
        $taskNumber = trim((string) ($_POST['task_number'] ?? ''));

        $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/ticket-dossier/browser-sync.php')));
        $scriptDir = rtrim($scriptDir, '/');
        $endpointBase = AppUrl::scheme() . '://' . AppUrl::host() . $scriptDir . '/browser-sync.php';
        $importUrl = $endpointBase . '?action=browser_sync_import';
        $attachmentUrl = $endpointBase . '?action=browser_sync_attachment';
        $completeUrl = $endpointBase . '?action=browser_sync_complete';

        $owner = projectOwnerFromUser(is_array($currentUser) ? $currentUser : null);
        $prepared = $browserSync->prepare(
            $instance,
            $taskNumber,
            $importUrl,
            $attachmentUrl,
            $completeUrl,
            $owner
        );
        $tokenQuery = '&token=' . rawurlencode((string) $prepared['token']);
        $prepared['import_url'] .= $tokenQuery;
        $prepared['attachment_url'] .= $tokenQuery;
        $prepared['complete_url'] .= $tokenQuery;

        $jsonOut(['ok' => true] + $prepared + [
            'open_url' => $prepared['instance_origin'] . '/nav_to.do?uri=task.do?sysparm_query=number=' . rawurlencode($prepared['task_number']),
        ]);
    } catch (Throwable $e) {
        $jsonOut(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

$jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
