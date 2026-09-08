<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$projectId = (int) ($_GET['project_id'] ?? 0);
$fileId = (int) ($_GET['file_id'] ?? 0);

if ($projectId <= 0 || $fileId <= 0) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$project = ProjectRepository::find($projectId);
$file = ProjectRepository::findFile($fileId, $projectId);

if ($project === null || $file === null) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$path = TD_STORAGE_DIR . '/' . $projectId . '/' . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    echo 'File missing on disk.';
    exit;
}

$original = safeBasename((string) $file['original_name']);
$ext = extensionOf($original);
$mime = $ext === 'json' ? 'application/json' : 'application/pdf';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $original) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
