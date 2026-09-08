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

$parsed = json_decode((string) ($project['parsed_json'] ?? ''), true);
if (!is_array($parsed)) {
    $parsed = [];
}

$sources = json_decode((string) ($project['sources_json'] ?? ''), true);
if (!is_array($sources)) {
    $sources = [];
}

$files = ProjectRepository::filesFor($projectId);
$fileManifest = [];
foreach ($files as $file) {
    $fileManifest[] = [
        'id' => (int) ($file['id'] ?? 0),
        'kind' => (string) ($file['kind'] ?? ''),
        'original_name' => (string) ($file['original_name'] ?? ''),
        'size_bytes' => (int) ($file['size_bytes'] ?? 0),
        'created_at' => (string) ($file['created_at'] ?? ''),
    ];
}

$payload = [
    'format' => 'architecture-risk.ticket-dossier.v1',
    'exported_at' => gmdate('c'),
    'project' => [
        'id' => (int) $project['id'],
        'title' => (string) $project['title'],
        'vendor' => (string) ($project['vendor'] ?? ''),
        'demand_number' => (string) ($project['demand_number'] ?? ''),
        'story_number' => (string) ($project['story_number'] ?? ''),
        'task_number' => (string) ($project['task_number'] ?? ''),
        'ddr_number' => (string) ($project['ddr_number'] ?? ''),
        'demand_state' => (string) ($project['demand_state'] ?? ''),
        'story_state' => (string) ($project['story_state'] ?? ''),
        'task_state' => (string) ($project['task_state'] ?? ''),
        'ddr_state' => (string) ($project['ddr_state'] ?? ''),
        'owner' => projectOwnerName($project),
        'owner_username' => (string) ($project['owner_username'] ?? ''),
        'owner_display_name' => (string) ($project['owner_display_name'] ?? ''),
        'created_at' => (string) ($project['created_at'] ?? ''),
        'updated_at' => (string) ($project['updated_at'] ?? ''),
    ],
    'sources' => [
        'demand' => !empty($sources['demand']),
        'story' => !empty($sources['story']),
        'task' => !empty($sources['task']),
        'ddr' => !empty($sources['ddr']),
    ],
    'dossier' => $parsed,
    'files' => $fileManifest,
];

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
