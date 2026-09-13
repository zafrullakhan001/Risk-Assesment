<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$redirectTo = $projectId > 0 ? 'project.php?id=' . $projectId : 'index.php';

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    flashSet('error', 'Invalid security token. Please try again.');
    redirect($redirectTo);
}

$encodedPath = (string) ($_POST['field_path'] ?? '');
$path = json_decode($encodedPath, true);
if (!is_array($path) || !array_is_list($path)) {
    flashSet('error', 'Invalid dossier field.');
    redirect($redirectTo);
}

try {
    DossierFieldEditor::update($projectId, $path, (string) ($_POST['field_value'] ?? ''));
    flashSet('success', 'Dossier field corrected.');
} catch (Throwable $e) {
    flashSet('error', $e->getMessage());
}

redirect($redirectTo);
