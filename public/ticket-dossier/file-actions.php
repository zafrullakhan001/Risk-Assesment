<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('index.php');
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$redirectTo = 'project.php?id=' . max(0, $projectId) . '#section-files';

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    flashSet('error', 'Invalid security token. Please refresh and try again.');
    redirect($redirectTo);
}

if ($projectId <= 0 || ProjectRepository::find($projectId) === null) {
    flashSet('error', 'Project not found.');
    redirect('index.php');
}

$fileIds = $_POST['file_ids'] ?? [];
if (!is_array($fileIds)) {
    $fileIds = [];
}
$fileIds = array_map('intval', $fileIds);
$action = trim((string) ($_POST['file_action'] ?? ''));

try {
    if ($action === 'delete') {
        $result = DossierFileManager::deleteFiles($projectId, $fileIds);
        if ($result['deleted'] < 1) {
            throw new RuntimeException('No selected files were deleted.');
        }
        flashSet(
            'success',
            'Deleted ' . $result['deleted'] . ' selected file'
            . ($result['deleted'] === 1 ? '' : 's')
            . '. Parsed dossier information was retained.'
        );
        redirect($redirectTo);
    }

    if ($action === 'reparse') {
        $result = DossierFileManager::reparseFiles($projectId, $fileIds);
        $message = 'Reparsed ' . $result['parsed'] . ' selected file'
            . ($result['parsed'] === 1 ? '' : 's') . '.';
        if ($result['skipped'] > 0) {
            $message .= ' ' . $result['skipped'] . ' could not be parsed.';
        }
        if ($result['messages'] !== []) {
            $message .= ' ' . implode(' ', $result['messages']);
        }
        flashSet($result['parsed'] > 0 ? 'success' : 'error', $message);
        redirect($redirectTo);
    }

    throw new RuntimeException('Unknown file action.');
} catch (Throwable $e) {
    flashSet('error', $e->getMessage());
    redirect($redirectTo);
}
