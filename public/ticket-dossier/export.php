<?php

declare(strict_types=1);

/**
 * Export a complete Ticket Dossier project as JSON for offline / AI analysis.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$projectId = (int) ($_GET['id'] ?? $_GET['project_id'] ?? 0);
$project = $projectId > 0 ? ProjectRepository::find($projectId) : null;

if ($project === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Project not found.';
    exit;
}

$payload = ProjectJsonExport::buildPayload($project);
$json = json_encode(
    $payload,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

$slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $project['title']) ?? 'dossier';
$slug = trim($slug, '-._');
if ($slug === '') {
    $slug = 'dossier';
}
if (strlen($slug) > 60) {
    $slug = substr($slug, 0, 60);
}
$filename = sprintf('ticket-dossier-%d-%s.json', $projectId, $slug);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string) strlen($json));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $json;
exit;
