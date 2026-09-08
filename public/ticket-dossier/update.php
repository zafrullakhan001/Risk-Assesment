<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$redirectTo = 'project.php?id=' . max(0, $projectId);

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    flashSet('error', 'Invalid security token. Please try again.');
    redirect($projectId > 0 ? $redirectTo : 'index.php');
}

if ($projectId <= 0 || ProjectRepository::find($projectId) === null) {
    flashSet('error', 'Project not found.');
    redirect('index.php');
}

$uploads = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
    $names = $_FILES['files']['name'];
    $tmps = $_FILES['files']['tmp_name'];
    $sizes = $_FILES['files']['size'];
    $errors = $_FILES['files']['error'];
    $count = count($names);

    for ($i = 0; $i < $count; $i++) {
        if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $uploads[] = [
            'name' => (string) $names[$i],
            'tmp_name' => (string) $tmps[$i],
            'size' => (int) $sizes[$i],
            'error' => (int) $errors[$i],
            'is_local' => false,
        ];
    }
}

try {
    $result = ProjectImporter::mergeIntoProject($projectId, $uploads);
    $added = $result['added'] !== [] ? implode(', ', $result['added']) : 'files';
    $msg = 'Dossier updated with ' . $added . '.';
    if ($result['warnings'] !== []) {
        $msg .= ' ' . implode(' ', $result['warnings']);
    }
    flashSet('success', $msg);
    redirect('project.php?id=' . $result['project_id']);
} catch (Throwable $e) {
    flashSet('error', $e->getMessage());
    redirect($redirectTo);
}
