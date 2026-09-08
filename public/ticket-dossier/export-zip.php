<?php

declare(strict_types=1);

/**
 * Download one Ticket Dossier project, or every project, as a ZIP backup.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$projectId = (int) ($_GET['id'] ?? $_GET['project_id'] ?? 0);

try {
    ProjectPackager::download($projectId);
} catch (InvalidArgumentException $e) {
    flashSet('error', $e->getMessage());
    if ($projectId > 0) {
        redirect('project.php?id=' . $projectId);
    }
    redirect('index.php#find-projects');
} catch (Throwable $e) {
    flashSet('error', 'Could not export ZIP: ' . $e->getMessage());
    if ($projectId > 0) {
        redirect('project.php?id=' . $projectId);
    }
    redirect('index.php#find-projects');
}
