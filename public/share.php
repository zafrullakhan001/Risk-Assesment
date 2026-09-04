<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\AssessmentComparer;
use RiskAssessment\DashboardRenderer;
use RiskAssessment\Repositories\AssessmentChangeLogRepository;
use RiskAssessment\Repositories\AssessmentRepository;
use RiskAssessment\Repositories\FinalEvaluationRepository;
use RiskAssessment\Repositories\FindingStatusRepository;
use RiskAssessment\Repositories\ItemResponseRepository;
use RiskAssessment\Repositories\ProjectLinksRepository;
use RiskAssessment\Repositories\ProjectMermaidRepository;
use RiskAssessment\Repositories\ProjectPicturesRepository;
use RiskAssessment\Repositories\ProjectShareRepository;

header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$token = trim((string) ($_GET['t'] ?? $_GET['token'] ?? ''));
$shareRepository = new ProjectShareRepository($pdo);
$share = $shareRepository->findActiveByToken($token);

if ($share === null) {
    http_response_code(404);
    $branding = \RiskAssessment\Branding::current();
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Share link unavailable · <?= htmlspecialchars($branding->brandTitle(), ENT_QUOTES, 'UTF-8') ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <style>
        .share-unavailable { max-width: 28rem; margin: 4rem auto; padding: 1.5rem; text-align: center; }
        .share-unavailable h1 { margin: 0 0 0.75rem; font-size: 1.35rem; }
        .share-unavailable p { margin: 0 0 1rem; color: var(--muted, #64748b); }
    </style>
</head>
<body>
    <main class="share-unavailable">
        <h1>Share link unavailable</h1>
        <p>This public link is invalid, expired, or has been revoked.</p>
        <p><a class="button button-primary" href="login.php">Sign in</a></p>
    </main>
</body>
</html><?php
    exit;
}

$assessmentId = (int) $share['assessment_id'];
$repository = new AssessmentRepository($pdo);
$projectPicturesRepository = new ProjectPicturesRepository($pdo);

if (($_GET['action'] ?? '') === 'view_project_picture') {
    $pictureId = filter_input(INPUT_GET, 'picture_id', FILTER_VALIDATE_INT) ?: 0;
    $picture = $projectPicturesRepository->findForView((int) $pictureId, $assessmentId);
    if ($picture === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Picture not found.';
        exit;
    }

    header('Content-Type: ' . $picture['mime_type']);
    header('Content-Length: ' . (string) strlen($picture['bytes']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $picture['bytes'];
    exit;
}

$record = $repository->findById($assessmentId);
if ($record === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Assessment not found.';
    exit;
}

$assessment = $record['assessment'];
$solutionName = $assessment->getMetadata('solution_name');
$prior = $repository->findPreviousVersion($solutionName, $assessmentId);
$comparer = new AssessmentComparer();
$comparison = $comparer->compare(
    $assessment,
    $prior['assessment'] ?? null,
    (int) ($prior['id'] ?? 0),
    (string) ($prior['uploaded_at'] ?? '')
);

$responseRepository = new ItemResponseRepository($pdo);
$evaluationRepository = new FinalEvaluationRepository($pdo);
$projectLinksRepository = new ProjectLinksRepository($pdo);
$projectMermaidRepository = new ProjectMermaidRepository($pdo);
$findingStatusRepository = new FindingStatusRepository($pdo);
$changeLogRepository = new AssessmentChangeLogRepository($pdo);

$versions = $repository->listVersionsBySolutionName($solutionName);
$responses = $responseRepository->listForAssessment($assessmentId);
$evaluation = $evaluationRepository->findByAssessmentId($assessmentId);
$projectLinks = $projectLinksRepository->listForAssessment($assessmentId);
$projectDiagrams = $projectMermaidRepository->listForAssessment($assessmentId);
$projectPictures = $projectPicturesRepository->listForAssessment($assessmentId);
$findingStatuses = $findingStatusRepository->listForAssessment($assessmentId);
$itemResponseHistory = $changeLogRepository->listItemResponseHistory($assessmentId);
$evaluationHistory = $changeLogRepository->listForEntity(
    $assessmentId,
    AssessmentChangeLogRepository::ENTITY_FINAL_EVALUATION,
    ''
);

$renderer = new DashboardRenderer();
echo $renderer->render(
    $assessment,
    $record['source_filename'],
    $assessmentId,
    $comparison,
    $versions,
    '',
    '',
    $responses,
    $evaluation,
    $projectLinks,
    $projectDiagrams,
    $findingStatuses,
    $record['executive_override'] ?? $repository->findExecutiveOverride($assessmentId),
    $itemResponseHistory,
    $evaluationHistory,
    [],
    $projectPictures,
    true,
    $token,
    [],
    null
);
