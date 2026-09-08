<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$redirectTo = $projectId > 0 ? 'project.php?id=' . $projectId . '#edit-details' : 'index.php';

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    flashSet('error', 'Invalid security token. Please try again.');
    redirect($projectId > 0 ? $redirectTo : 'index.php');
}

$project = $projectId > 0 ? ProjectRepository::find($projectId) : null;
if ($project === null) {
    flashSet('error', 'Project not found.');
    redirect('index.php');
}

$title = trim((string) ($_POST['title'] ?? ''));
$vendor = trim((string) ($_POST['vendor'] ?? ''));
$ownerName = trim((string) ($_POST['owner_name'] ?? ''));
$ownerUserId = (int) ($_POST['owner_user_id'] ?? 0);

if (strlen($title) > 200) {
    $title = substr($title, 0, 200);
}
if (strlen($vendor) > 200) {
    $vendor = substr($vendor, 0, 200);
}
if (strlen($ownerName) > 200) {
    $ownerName = substr($ownerName, 0, 200);
}

$ownerDetails = [
    'owner_user_id' => $project['owner_user_id'] ?? null,
    'owner_username' => (string) ($project['owner_username'] ?? ''),
    'owner_display_name' => (string) ($project['owner_display_name'] ?? ''),
    'owner_auth_source' => (string) ($project['owner_auth_source'] ?? ''),
];

if ($title === '') {
    flashSet('error', 'Project name is required.');
    redirect($redirectTo);
}

if ($ownerUserId > 0) {
    /** @var \RiskAssessment\Repositories\UserRepository $users */
    $ownerUser = $users->findById($ownerUserId);
    if ($ownerUser === null || empty($ownerUser['is_approved']) || !empty($ownerUser['is_disabled'])) {
        flashSet('error', 'Choose an approved, active user as the owner.');
        redirect($redirectTo);
    }
    $ownerDetails = projectOwnerFromUser($ownerUser);
}

if ($ownerName !== '') {
    $ownerDetails['owner_display_name'] = $ownerName;
} elseif ($ownerUserId <= 0) {
    $ownerDetails['owner_display_name'] = (string) ($project['owner_display_name'] ?? '');
}

try {
    ProjectRepository::updateDetails($projectId, [
        'title' => $title,
        'vendor' => $vendor,
        'owner_user_id' => $ownerDetails['owner_user_id'],
        'owner_username' => $ownerDetails['owner_username'],
        'owner_display_name' => $ownerDetails['owner_display_name'],
        'owner_auth_source' => $ownerDetails['owner_auth_source'],
    ]);
    flashSet('success', 'Project details saved.');
    redirect('project.php?id=' . $projectId);
} catch (Throwable $e) {
    flashSet('error', $e->getMessage());
    redirect($redirectTo);
}
