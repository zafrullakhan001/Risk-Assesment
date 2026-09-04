<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\Actor;
use RiskAssessment\AssessmentComparer;
use RiskAssessment\AssessmentInsights;
use RiskAssessment\DashboardRenderer;
use RiskAssessment\ExcelParser;
use RiskAssessment\Models\Assessment;
use RiskAssessment\GoliveGate;
use RiskAssessment\Repositories\AssessmentChangeLogRepository;
use RiskAssessment\Repositories\AssessmentRepository;
use RiskAssessment\Repositories\FinalEvaluationRepository;
use RiskAssessment\Repositories\FindingStatusRepository;
use RiskAssessment\Repositories\ItemResponseRepository;
use RiskAssessment\ProjectImageConverter;
use RiskAssessment\Repositories\ProjectLinksRepository;
use RiskAssessment\Repositories\ProjectMermaidRepository;
use RiskAssessment\Repositories\ProjectPicturesRepository;

$currentUser = $auth->requireAuth();
$actor = Actor::fromUser($currentUser);
$repository = new AssessmentRepository($pdo);
$responseRepository = new ItemResponseRepository($pdo);
$evaluationRepository = new FinalEvaluationRepository($pdo);
$changeLogRepository = new AssessmentChangeLogRepository($pdo);
$projectLinksRepository = new ProjectLinksRepository($pdo);
$projectMermaidRepository = new ProjectMermaidRepository($pdo);
$projectPicturesRepository = new ProjectPicturesRepository($pdo);
$projectImageConverter = new ProjectImageConverter();
$findingStatusRepository = new FindingStatusRepository($pdo);
$goliveGate = new GoliveGate();

$error = '';
$flash = '';
$dashboardHtml = '';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchPage = max(1, (int) ($_GET['page'] ?? 1));
$searchPerPage = 20;
$searchTotal = $repository->countProjects($searchQuery);
$searchTotalPages = max(1, (int) ceil($searchTotal / $searchPerPage));
if ($searchPage > $searchTotalPages) {
    $searchPage = $searchTotalPages;
}
$searchResults = $repository->searchProjects($searchQuery, $searchPage, $searchPerPage);
$searchFrom = $searchTotal === 0 ? 0 : (($searchPage - 1) * $searchPerPage) + 1;
$searchTo = min($searchTotal, $searchPage * $searchPerPage);

$assessmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if (($_GET['action'] ?? '') === 'view_project_picture') {
    $pictureId = filter_input(INPUT_GET, 'picture_id', FILTER_VALIDATE_INT) ?: 0;
    $targetId = filter_input(INPUT_GET, 'assessment_id', FILTER_VALIDATE_INT) ?: 0;
    $picture = $projectPicturesRepository->findForView((int) $pictureId, (int) $targetId);
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

if (isset($_SESSION['dashboard_html']) && ($_GET['view'] ?? '') === '1' && $assessmentId <= 0) {
    $dashboardHtml = (string) $_SESSION['dashboard_html'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = (string) ($_POST['action'] ?? 'upload');

    if ($postedAction === 'save_item_response' || $postedAction === 'save_item_responses_bulk') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $responseAction = (string) ($_POST['response_action'] ?? 'open');
            $comment = (string) ($_POST['comment'] ?? '');

            if ($targetId <= 0) {
                throw new RuntimeException('Invalid response payload.');
            }

            if ($postedAction === 'save_item_responses_bulk') {
                $rawKeys = $_POST['item_keys'] ?? '[]';
                if (is_string($rawKeys)) {
                    $decoded = json_decode($rawKeys, true);
                    $itemKeys = is_array($decoded) ? $decoded : [];
                } elseif (is_array($rawKeys)) {
                    $itemKeys = $rawKeys;
                } else {
                    $itemKeys = [];
                }
                $itemKeys = array_values(array_filter(array_map(
                    static fn ($key): string => trim((string) $key),
                    $itemKeys
                ), static fn (string $key): bool => $key !== ''));

                if ($itemKeys === []) {
                    throw new RuntimeException('Select at least one row to update.');
                }
                if (count($itemKeys) > 500) {
                    throw new RuntimeException('Too many rows selected at once.');
                }

                $savedRows = $responseRepository->upsertMany($targetId, $itemKeys, $responseAction, $comment, $actor);
                $normalizedAction = ItemResponseRepository::normalizeAction($responseAction);
                $actionLabel = ItemResponseRepository::label($responseAction);
                $summary = 'Marked as ' . $actionLabel;
                if (trim($comment) !== '') {
                    $summary .= ' — comment posted';
                }
                foreach ($savedRows as $savedRow) {
                    $changeLogRepository->record(
                        $targetId,
                        AssessmentChangeLogRepository::ENTITY_ITEM_RESPONSE,
                        (string) ($savedRow['item_key'] ?? ''),
                        $actor,
                        $summary,
                        [
                            'action' => $normalizedAction,
                            'action_label' => $actionLabel,
                            'comment' => (string) ($savedRow['comment'] ?? ''),
                        ]
                    );
                }
                echo json_encode([
                    'ok' => true,
                    'saved' => count($savedRows),
                    'action' => $normalizedAction,
                    'label' => $actionLabel,
                    'responses' => $savedRows,
                    'updated_by_label' => $actor['label'],
                    'updated_at' => $savedRows[0]['updated_at'] ?? date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $itemKey = trim((string) ($_POST['item_key'] ?? ''));
            if ($itemKey === '') {
                throw new RuntimeException('Invalid response payload.');
            }

            $savedRow = $responseRepository->upsert($targetId, $itemKey, $responseAction, $comment, $actor);
            if ($savedRow === null) {
                throw new RuntimeException('Unable to save response.');
            }

            $normalizedAction = ItemResponseRepository::normalizeAction($responseAction);
            $actionLabel = ItemResponseRepository::label($responseAction);
            $historySummary = 'Marked as ' . $actionLabel;
            if (trim($comment) !== '') {
                $historySummary .= ' — comment posted';
            }
            $changeLogRepository->record(
                $targetId,
                AssessmentChangeLogRepository::ENTITY_ITEM_RESPONSE,
                $itemKey,
                $actor,
                $historySummary,
                [
                    'action' => $normalizedAction,
                    'action_label' => $actionLabel,
                    'comment' => (string) ($savedRow['comment'] ?? ''),
                ]
            );

            echo json_encode([
                'ok' => true,
                'action' => $normalizedAction,
                'label' => $actionLabel,
                'response' => $savedRow,
                'updated_by_label' => $actor['label'],
                'updated_at' => $savedRow['updated_at'],
                'history_entry' => [
                    'summary' => $historySummary,
                    'details' => [
                        'action' => $normalizedAction,
                        'action_label' => $actionLabel,
                        'comment' => (string) ($savedRow['comment'] ?? ''),
                    ],
                    'actor_username' => $actor['username'],
                    'actor_display_name' => $actor['display_name'],
                    'actor_auth_source' => $actor['auth_source'],
                    'created_at' => $savedRow['updated_at'],
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'save_project_mermaid') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $rawDiagrams = $_POST['diagrams'] ?? '[]';
            if (is_string($rawDiagrams)) {
                $decoded = json_decode($rawDiagrams, true);
                $diagrams = is_array($decoded) ? $decoded : [];
            } elseif (is_array($rawDiagrams)) {
                $diagrams = $rawDiagrams;
            } else {
                $diagrams = [];
            }

            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment before saving diagrams.');
            }

            if (!$projectMermaidRepository->replaceForAssessment($targetId, $diagrams)) {
                throw new RuntimeException('Unable to save diagrams.');
            }

            $saved = $projectMermaidRepository->listForAssessment($targetId);
            echo json_encode([
                'ok' => true,
                'diagrams' => $saved,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'save_project_links') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $rawLinks = $_POST['links'] ?? '[]';
            if (is_string($rawLinks)) {
                $decoded = json_decode($rawLinks, true);
                $links = is_array($decoded) ? $decoded : [];
            } elseif (is_array($rawLinks)) {
                $links = $rawLinks;
            } else {
                $links = [];
            }

            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment before saving links.');
            }

            if (!$projectLinksRepository->replaceForAssessment($targetId, $links)) {
                throw new RuntimeException('Unable to save links.');
            }

            $saved = $projectLinksRepository->listForAssessment($targetId);
            echo json_encode([
                'ok' => true,
                'links' => $saved,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'upload_project_picture') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment before uploading pictures.');
            }

            if (!isset($_FILES['picture']) || !is_array($_FILES['picture'])) {
                throw new RuntimeException('Choose a picture to upload.');
            }

            $converted = $projectImageConverter->fromUploadedFile($_FILES['picture']);
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') {
                $title = $projectImageConverter->titleFromFilename($converted['original_filename']);
            }

            $saved = $projectPicturesRepository->addForAssessment($targetId, [
                'title' => $title,
                'mime_type' => $converted['mime_type'],
                'base64' => $converted['base64'],
                'original_filename' => $converted['original_filename'],
            ]);
            if ($saved === null) {
                throw new RuntimeException('Unable to save the picture.');
            }

            echo json_encode([
                'ok' => true,
                'picture' => $saved,
                'view_url' => $projectPicturesRepository->viewUrl($targetId, $saved['id']),
                'count' => $projectPicturesRepository->countForAssessment($targetId),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'save_project_picture_titles') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $rawTitles = $_POST['pictures'] ?? '[]';
            if (is_string($rawTitles)) {
                $decoded = json_decode($rawTitles, true);
                $titles = is_array($decoded) ? $decoded : [];
            } elseif (is_array($rawTitles)) {
                $titles = $rawTitles;
            } else {
                $titles = [];
            }

            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment before saving picture titles.');
            }

            if (!$projectPicturesRepository->updateTitlesForAssessment($targetId, $titles)) {
                throw new RuntimeException('Unable to save picture titles.');
            }

            echo json_encode([
                'ok' => true,
                'pictures' => $projectPicturesRepository->listForAssessment($targetId),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'delete_project_picture') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $pictureId = filter_var($_POST['picture_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($targetId <= 0 || $pictureId <= 0) {
                throw new RuntimeException('Invalid picture delete request.');
            }

            if (!$projectPicturesRepository->deleteOne($pictureId, $targetId)) {
                throw new RuntimeException('Unable to delete that picture.');
            }

            echo json_encode([
                'ok' => true,
                'count' => $projectPicturesRepository->countForAssessment($targetId),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'save_finding_status') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $findingId = trim((string) ($_POST['finding_id'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'Open');

            if ($targetId <= 0 || $findingId === '') {
                throw new RuntimeException('Invalid exception status payload.');
            }

            if (!$findingStatusRepository->upsert($targetId, $findingId, $status)) {
                throw new RuntimeException('Unable to save exception status.');
            }

            $record = $repository->findById($targetId);
            if ($record === null) {
                throw new RuntimeException('Assessment not found.');
            }

            $responses = $responseRepository->listForAssessment($targetId);
            $findingStatuses = $findingStatusRepository->listForAssessment($targetId);
            $evaluation = $evaluationRepository->findByAssessmentId($targetId);
            $notes = (string) ($evaluation['notes'] ?? '');
            $gate = $goliveGate->evaluate($record['assessment'], $responses, $findingStatuses, $notes);

            echo json_encode([
                'ok' => true,
                'status' => FindingStatusRepository::normalizeStatus($status),
                'gates' => $gate,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'save_final_evaluation') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $evaluatorName = (string) ($_POST['evaluator_name'] ?? '');
            $evaluatorEmail = (string) ($_POST['evaluator_email'] ?? '');
            $notes = (string) ($_POST['notes'] ?? '');
            $readyRaw = $_POST['ready_to_golive'] ?? '0';
            $readyToGolive = $readyRaw === '1' || $readyRaw === 1 || $readyRaw === true || $readyRaw === 'true' || $readyRaw === 'on';

            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment before saving the final evaluation.');
            }

            if ($readyToGolive) {
                $record = $repository->findById($targetId);
                if ($record === null) {
                    throw new RuntimeException('Assessment not found.');
                }
                $responses = $responseRepository->listForAssessment($targetId);
                $findingStatuses = $findingStatusRepository->listForAssessment($targetId);
                $gate = $goliveGate->evaluate($record['assessment'], $responses, $findingStatuses, $notes);
                if (!$gate['ready_allowed']) {
                    throw new RuntimeException($goliveGate->formatFailureMessage($gate));
                }
            }

            if (!$evaluationRepository->upsert($targetId, $evaluatorName, $evaluatorEmail, $notes, $readyToGolive, $actor)) {
                throw new RuntimeException('Unable to save final evaluation.');
            }

            $changeLogRepository->record(
                $targetId,
                AssessmentChangeLogRepository::ENTITY_FINAL_EVALUATION,
                '',
                $actor,
                ($readyToGolive ? 'Ready to go-live' : 'Not ready to go-live') . ' — evaluation saved',
                [
                    'evaluator_name' => trim($evaluatorName),
                    'evaluator_email' => trim($evaluatorEmail),
                    'ready_to_golive' => $readyToGolive,
                    'action_label' => $readyToGolive ? 'Ready to go-live' : 'Not ready to go-live',
                    'notes' => trim($notes),
                    'comment' => trim($notes),
                ]
            );

            if (array_key_exists('executive_verdict', $_POST) || array_key_exists('executive_summary', $_POST)) {
                if (!$repository->saveExecutiveOverride(
                    $targetId,
                    (string) ($_POST['executive_verdict'] ?? ''),
                    (string) ($_POST['executive_summary'] ?? '')
                )) {
                    throw new RuntimeException('Unable to save executive summary.');
                }
            }

            $saved = $evaluationRepository->findByAssessmentId($targetId);
            $executiveOverride = $repository->findExecutiveOverride($targetId);
            $record = $repository->findById($targetId);
            $responses = $record !== null ? $responseRepository->listForAssessment($targetId) : [];
            $findingStatuses = $record !== null ? $findingStatusRepository->listForAssessment($targetId) : [];
            $gate = $record !== null
                ? $goliveGate->evaluate($record['assessment'], $responses, $findingStatuses, $notes)
                : ['ready_allowed' => false, 'rules' => []];
            $executive = [
                'verdict' => $executiveOverride['verdict'],
                'summary' => $executiveOverride['summary'],
                'auto_verdict' => '',
                'auto_summary' => '',
                'custom_verdict' => $executiveOverride['verdict'],
                'custom_summary' => $executiveOverride['summary'],
                'is_custom' => $executiveOverride['verdict'] !== '' || $executiveOverride['summary'] !== '',
            ];
            if ($record !== null) {
                $insightBuilder = new AssessmentInsights();
                $insights = $insightBuilder->applyExecutiveOverride(
                    $insightBuilder->build($record['assessment'], $responses, $findingStatuses),
                    $executiveOverride['verdict'],
                    $executiveOverride['summary']
                );
                $readiness = $insights['readiness'] ?? [];
                $executive = [
                    'verdict' => (string) ($readiness['verdict'] ?? $executive['verdict']),
                    'summary' => (string) ($readiness['summary'] ?? $executive['summary']),
                    'auto_verdict' => (string) ($readiness['auto_verdict'] ?? ''),
                    'auto_summary' => (string) ($readiness['auto_summary'] ?? ''),
                    'custom_verdict' => $executiveOverride['verdict'],
                    'custom_summary' => $executiveOverride['summary'],
                    'is_custom' => !empty($readiness['is_custom']),
                ];
            }
            echo json_encode([
                'ok' => true,
                'evaluation' => $saved,
                'executive' => $executive,
                'gates' => $gate,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'list_change_history') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $entityType = trim((string) ($_POST['entity_type'] ?? ''));
            $entityKey = (string) ($_POST['entity_key'] ?? '');
            $query = trim((string) ($_POST['q'] ?? ''));
            $page = max(1, (int) ($_POST['page'] ?? 1));
            $perPage = max(1, min(20, (int) ($_POST['per_page'] ?? 5)));

            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment to load history.');
            }
            if (
                $entityType !== AssessmentChangeLogRepository::ENTITY_ITEM_RESPONSE
                && $entityType !== AssessmentChangeLogRepository::ENTITY_FINAL_EVALUATION
            ) {
                throw new RuntimeException('Invalid history type.');
            }

            $result = $changeLogRepository->searchForEntity(
                $targetId,
                $entityType,
                $entityKey,
                $query,
                $page,
                $perPage
            );

            echo json_encode([
                'ok' => true,
                'entries' => $result['entries'],
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total_pages' => $result['total_pages'],
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'save_executive_summary') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment before saving the executive summary.');
            }

            $verdict = (string) ($_POST['executive_verdict'] ?? '');
            $summary = (string) ($_POST['executive_summary'] ?? '');
            if (!$repository->saveExecutiveOverride($targetId, $verdict, $summary)) {
                throw new RuntimeException('Unable to save executive summary.');
            }

            $saved = $repository->findExecutiveOverride($targetId);
            $record = $repository->findById($targetId);
            $readiness = [
                'verdict' => $saved['verdict'],
                'summary' => $saved['summary'],
                'auto_verdict' => '',
                'auto_summary' => '',
                'is_custom' => $saved['verdict'] !== '' || $saved['summary'] !== '',
            ];
            if ($record !== null) {
                $insightBuilder = new AssessmentInsights();
                $insights = $insightBuilder->applyExecutiveOverride(
                    $insightBuilder->build(
                        $record['assessment'],
                        $responseRepository->listForAssessment($targetId),
                        $findingStatusRepository->listForAssessment($targetId)
                    ),
                    $saved['verdict'],
                    $saved['summary']
                );
                $readiness = $insights['readiness'] ?? $readiness;
            }

            echo json_encode([
                'ok' => true,
                'executive' => [
                    'verdict' => (string) ($readiness['verdict'] ?? ''),
                    'summary' => (string) ($readiness['summary'] ?? ''),
                    'auto_verdict' => (string) ($readiness['auto_verdict'] ?? ''),
                    'auto_summary' => (string) ($readiness['auto_summary'] ?? ''),
                    'custom_verdict' => $saved['verdict'],
                    'custom_summary' => $saved['summary'],
                    'is_custom' => !empty($readiness['is_custom']),
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    try {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid form submission. Please refresh and try again.');
        }

        $action = $postedAction;

        if ($action === 'delete_assessment') {
            $deleteId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            $redirectId = filter_var($_POST['redirect_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($deleteId <= 0) {
                throw new RuntimeException('Invalid assessment selected for deletion.');
            }

            $deletedRecord = $repository->findById($deleteId);
            if ($deletedRecord === null) {
                throw new RuntimeException('Assessment not found.');
            }

            $solutionName = $deletedRecord['assessment']->getMetadata('solution_name');
            $repository->deleteById($deleteId);

            if ($redirectId === $deleteId) {
                $siblings = $repository->listVersionsBySolutionName($solutionName, 1);
                $redirectId = $siblings !== [] ? (int) $siblings[0]['id'] : 0;
            }

            if ($redirectId > 0 && $repository->findById($redirectId) !== null) {
                header('Location: index.php?view=1&id=' . $redirectId . '&deleted=1');
                exit;
            }

            header('Location: index.php?deleted=1');
            exit;
        }

        if ($action === 'delete_older_versions') {
            $keepId = filter_var($_POST['keep_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($keepId <= 0) {
                throw new RuntimeException('Invalid assessment selected.');
            }

            $keepRecord = $repository->findById($keepId);
            if ($keepRecord === null) {
                throw new RuntimeException('Assessment not found.');
            }

            $solutionName = $keepRecord['assessment']->getMetadata('solution_name');
            $removed = $repository->deleteOlderVersions($solutionName, $keepId);
            header('Location: index.php?view=1&id=' . $keepId . '&deleted_older=' . $removed);
            exit;
        }

        if (!isset($_FILES['assessment_file']) || !is_array($_FILES['assessment_file'])) {
            throw new RuntimeException('Please choose an Excel file to upload.');
        }

        $uploadedFiles = normalize_assessment_uploads($_FILES['assessment_file']);
        if ($uploadedFiles === []) {
            throw new RuntimeException('Please choose an Excel file to upload.');
        }

        $maxFiles = max(1, (int) ($config['max_upload_files'] ?? 10));
        if (count($uploadedFiles) > $maxFiles) {
            throw new RuntimeException('You can upload up to ' . $maxFiles . ' workbooks at once.');
        }

        if (!is_dir($config['upload_dir']) && !mkdir($config['upload_dir'], 0755, true) && !is_dir($config['upload_dir'])) {
            throw new RuntimeException('Unable to prepare the upload directory.');
        }

        $parser = new ExcelParser();
        $savedIds = [];
        $failures = [];
        $lastAssessment = null;
        $lastOriginalName = '';
        $lastStoredName = '';

        foreach ($uploadedFiles as $file) {
            $originalName = (string) ($file['name'] ?? '');
            $label = $originalName !== '' ? $originalName : 'workbook';

            try {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed size.',
                        UPLOAD_ERR_NO_FILE => 'Please choose an Excel file to upload.',
                        default => 'The file upload failed. Please try again.',
                    });
                }

                if (($file['size'] ?? 0) > $config['max_upload_bytes']) {
                    throw new RuntimeException('The uploaded file exceeds the 5 MB limit.');
                }

                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($extension, $config['allowed_extensions'], true)) {
                    throw new RuntimeException('Only .xlsx files are supported.');
                }

                $tmpName = (string) ($file['tmp_name'] ?? '');
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $tmpName !== '' ? ($finfo->file($tmpName) ?: '') : '';
                if (!in_array($mimeType, $config['allowed_mime_types'], true)) {
                    throw new RuntimeException('The uploaded file is not a valid Excel workbook.');
                }

                $storedName = bin2hex(random_bytes(16)) . '.xlsx';
                $destination = $config['upload_dir'] . DIRECTORY_SEPARATOR . $storedName;

                if (!move_uploaded_file($tmpName, $destination)) {
                    throw new RuntimeException('Unable to store the uploaded file.');
                }

                try {
                    $assessment = $parser->parse($destination);
                    $savedId = $repository->save($assessment, $destination, $originalName);
                    $priorVersion = $repository->findPreviousVersion($assessment->getMetadata('solution_name'), $savedId);
                    if ($priorVersion !== null) {
                        $findingStatusRepository->copyMissingFromAssessment((int) $priorVersion['id'], $savedId);
                    }
                } catch (Throwable $parseException) {
                    if (is_file($destination)) {
                        @unlink($destination);
                    }
                    throw $parseException;
                }

                $savedIds[] = $savedId;
                $lastAssessment = $assessment;
                $lastOriginalName = $originalName;
                $lastStoredName = $storedName;
            } catch (Throwable $fileException) {
                $failures[] = $label . ': ' . $fileException->getMessage();
            }
        }

        if ($savedIds === []) {
            throw new RuntimeException(implode(' ', $failures) ?: 'Please choose an Excel file to upload.');
        }

        if ($lastAssessment !== null) {
            $_SESSION['assessment'] = [
                'id' => $savedIds[array_key_last($savedIds)],
                'metadata' => $lastAssessment->metadata,
                'items' => $lastAssessment->items,
                'due_diligence_items' => $lastAssessment->dueDiligenceItems,
                'workbook' => $lastAssessment->workbook,
                'summary' => $lastAssessment->summary,
                'source_filename' => $lastOriginalName,
                'stored_filename' => $lastStoredName,
            ];
        }

        if ($failures !== []) {
            $error = implode(' ', $failures);
            $savedCount = count($savedIds);
            $flash = $savedCount === 1
                ? '1 workbook was saved. Fix the files that failed and try again.'
                : $savedCount . ' workbooks were saved. Fix the files that failed and try again.';
            $searchTotal = $repository->countProjects($searchQuery);
            $searchTotalPages = max(1, (int) ceil($searchTotal / $searchPerPage));
            if ($searchPage > $searchTotalPages) {
                $searchPage = $searchTotalPages;
            }
            $searchResults = $repository->searchProjects($searchQuery, $searchPage, $searchPerPage);
            $searchFrom = $searchTotal === 0 ? 0 : (($searchPage - 1) * $searchPerPage) + 1;
            $searchTo = min($searchTotal, $searchPage * $searchPerPage);
        } else {
            $savedId = $savedIds[array_key_last($savedIds)];
            $uploadedCount = count($savedIds);
            $location = 'index.php?view=1&id=' . $savedId;
            if ($uploadedCount > 1) {
                $location .= '&uploaded=' . $uploadedCount;
            }
            header('Location: ' . $location);
            exit;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (isset($_GET['deleted'])) {
    $flash = 'Assessment version deleted.';
}

if (isset($_GET['uploaded']) && $flash === '') {
    $uploadedCount = max(1, (int) $_GET['uploaded']);
    $flash = $uploadedCount === 1
        ? 'Workbook uploaded.'
        : $uploadedCount . ' workbooks uploaded.';
}

if (isset($_GET['deleted_older'])) {
    $removedCount = max(0, (int) $_GET['deleted_older']);
    $flash = $removedCount === 1
        ? '1 older version deleted. Current version kept.'
        : $removedCount . ' older versions deleted. Current version kept.';
}

if ($dashboardHtml === '' && ($_GET['view'] ?? '') === '1') {
    if ($assessmentId > 0) {
        $record = $repository->findById($assessmentId);
        if ($record === null) {
            $error = 'Assessment not found.';
        } else {
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
            $evaluatorDefaults = Actor::evaluatorDefaults($currentUser);
            if ($prior !== null) {
                $findingStatusRepository->copyMissingFromAssessment((int) $prior['id'], $assessmentId);
                $findingStatuses = $findingStatusRepository->listForAssessment($assessmentId);
            }
            $renderer = new DashboardRenderer();
            $dashboardHtml = $renderer->render(
                $assessment,
                $record['source_filename'],
                $assessmentId,
                $comparison,
                $versions,
                (string) $_SESSION['csrf_token'],
                $flash,
                $responses,
                $evaluation,
                $projectLinks,
                $projectDiagrams,
                $findingStatuses,
                $record['executive_override'] ?? $repository->findExecutiveOverride($assessmentId),
                $itemResponseHistory,
                $evaluationHistory,
                $evaluatorDefaults,
                $projectPictures
            );
        }
    } elseif (isset($_SESSION['assessment'])) {
        $stored = $_SESSION['assessment'];
        $assessment = Assessment::fromParsedData(
            $stored['metadata'] ?? [],
            $stored['items'] ?? [],
            $stored['due_diligence_items'] ?? [],
            $stored['workbook'] ?? []
        );
        $storedId = (int) ($stored['id'] ?? 0);
        $prior = $storedId > 0
            ? $repository->findPreviousVersion($assessment->getMetadata('solution_name'), $storedId)
            : null;
        $comparer = new AssessmentComparer();
        $comparison = $comparer->compare(
            $assessment,
            $prior['assessment'] ?? null,
            (int) ($prior['id'] ?? 0),
            (string) ($prior['uploaded_at'] ?? '')
        );
        $versions = $repository->listVersionsBySolutionName($assessment->getMetadata('solution_name'));
        $responses = $storedId > 0 ? $responseRepository->listForAssessment($storedId) : [];
        $evaluation = $storedId > 0 ? $evaluationRepository->findByAssessmentId($storedId) : null;
        $projectLinks = $storedId > 0 ? $projectLinksRepository->listForAssessment($storedId) : [];
        $projectDiagrams = $storedId > 0 ? $projectMermaidRepository->listForAssessment($storedId) : [];
        $projectPictures = $storedId > 0 ? $projectPicturesRepository->listForAssessment($storedId) : [];
        $findingStatuses = $storedId > 0 ? $findingStatusRepository->listForAssessment($storedId) : [];
        $itemResponseHistory = $storedId > 0 ? $changeLogRepository->listItemResponseHistory($storedId) : [];
        $evaluationHistory = $storedId > 0
            ? $changeLogRepository->listForEntity($storedId, AssessmentChangeLogRepository::ENTITY_FINAL_EVALUATION, '')
            : [];
        $evaluatorDefaults = Actor::evaluatorDefaults($currentUser);
        if ($storedId > 0 && $prior !== null) {
            $findingStatusRepository->copyMissingFromAssessment((int) $prior['id'], $storedId);
            $findingStatuses = $findingStatusRepository->listForAssessment($storedId);
        }
        $renderer = new DashboardRenderer();
        $dashboardHtml = $renderer->render(
            $assessment,
            (string) ($stored['source_filename'] ?? ''),
            $storedId,
            $comparison,
            $versions,
            (string) $_SESSION['csrf_token'],
            $flash,
            $responses,
            $evaluation,
            $projectLinks,
            $projectDiagrams,
            $findingStatuses,
            $storedId > 0 ? $repository->findExecutiveOverride($storedId) : [],
            $itemResponseHistory,
            $evaluationHistory,
            $evaluatorDefaults,
            $projectPictures
        );
    }
}

if ($dashboardHtml !== '') {
    echo $dashboardHtml;
    exit;
}

$totalProjects = $repository->countAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($branding->documentTitle()) ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="shell upload-page">
        <header class="topbar">
            <a class="brand brand-link" href="index.php#find-projects" title="Find projects by name">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div>
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1><?= e($branding->brandSubtitle()) ?></h1>
                </div>
            </a>
            <div class="topbar-actions">
                <a class="button ghost home-link" href="#find-projects">Find by name</a>
                <?php require __DIR__ . '/includes/updates-nav.php'; ?>
                <?php require __DIR__ . '/includes/theme-controls.php'; ?>
                <div class="updated"><?= (int) $totalProjects ?> saved project<?= $totalProjects === 1 ? '' : 's' ?></div>
            </div>
        </header>

        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow"><?= e($branding->heroEyebrow()) ?></div>
                            <h2><?= $branding->heroHeadingHtml() ?></h2>
                            <p><?= e($branding->heroIntro()) ?></p>
                        </div>
                        <?php require __DIR__ . '/includes/hero-medallion.php'; renderHeroMedallion((int) $totalProjects, 'saved projects'); ?>
                    </div>
                </div>
            </section>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($flash !== ''): ?>
                <div class="alert alert-success"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <section class="upload-card search-card" id="find-projects">
                <h2><?= e($branding->heroHeadingPlain()) ?></h2>
                <p>Search any project field: name, vendor, scope, reviewer, architecture, filename, evaluator, executive summary, dates, or go-live status (try “ready”, “not ready”, “no final”). Leave blank to browse all saved versions.</p>
                <form method="get" class="search-form" action="index.php#find-projects">
                    <div class="search-wrap search-wrap-wide">
                        <span>Find</span>
                        <input
                            type="search"
                            name="q"
                            value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Name, vendor, reviewer, evaluator, filename…"
                            autofocus
                        >
                    </div>
                    <button type="submit" class="button button-primary">Find project</button>
                </form>

                <?php if ($searchResults === []): ?>
                    <p class="empty-results">No saved projects found<?= $searchQuery !== '' ? ' for that search.' : ' yet.' ?></p>
                <?php else: ?>
                    <p class="search-result-meta">Showing <?= (int) $searchFrom ?>–<?= (int) $searchTo ?> of <?= (int) $searchTotal ?><?= $searchQuery !== '' ? ' matching “' . htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') . '”' : '' ?></p>
                    <div class="project-list">
                        <?php foreach ($searchResults as $project): ?>
                            <?php $goliveStatus = AssessmentRepository::goliveCardStatus($project); ?>
                            <div class="project-item project-item-row">
                                <a href="index.php?view=1&amp;id=<?= (int) $project['id'] ?>">
                                    <div>
                                        <strong><?= htmlspecialchars((string) $project['solution_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars((string) $project['vendor'], ENT_QUOTES, 'UTF-8') ?> · #<?= (int) $project['id'] ?></span>
                                        <em class="project-status is-<?= htmlspecialchars($goliveStatus['key'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($goliveStatus['title'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($goliveStatus['label'], ENT_QUOTES, 'UTF-8') ?>
                                        </em>
                                    </div>
                                    <div class="project-meta">
                                        <span><?= htmlspecialchars((string) ($project['assessment_date'] ?: 'No date'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars((string) $project['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </a>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this saved version permanently?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete_assessment">
                                    <input type="hidden" name="assessment_id" value="<?= (int) $project['id'] ?>">
                                    <button type="submit" class="button danger-btn">Delete</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($searchTotalPages > 1): ?>
                        <?php
                        $pageQuery = static function (int $page) use ($searchQuery): string {
                            $params = ['page' => $page];
                            if ($searchQuery !== '') {
                                $params['q'] = $searchQuery;
                            }
                            return 'index.php?' . http_build_query($params) . '#find-projects';
                        };
                        ?>
                        <nav class="pagination" aria-label="Project list pages">
                            <?php if ($searchPage > 1): ?>
                                <a class="button ghost" href="<?= htmlspecialchars($pageQuery($searchPage - 1), ENT_QUOTES, 'UTF-8') ?>">← Previous</a>
                            <?php else: ?>
                                <span class="button ghost is-disabled" aria-disabled="true">← Previous</span>
                            <?php endif; ?>
                            <span class="pagination-pages">
                                <?php
                                $windowStart = max(1, $searchPage - 2);
                                $windowEnd = min($searchTotalPages, $searchPage + 2);
                                for ($pageNum = $windowStart; $pageNum <= $windowEnd; $pageNum++):
                                ?>
                                    <?php if ($pageNum === $searchPage): ?>
                                        <span class="pagination-page is-current" aria-current="page"><?= $pageNum ?></span>
                                    <?php else: ?>
                                        <a class="pagination-page" href="<?= htmlspecialchars($pageQuery($pageNum), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNum ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </span>
                            <?php if ($searchPage < $searchTotalPages): ?>
                                <a class="button ghost" href="<?= htmlspecialchars($pageQuery($searchPage + 1), ENT_QUOTES, 'UTF-8') ?>">Next →</a>
                            <?php else: ?>
                                <span class="button ghost is-disabled" aria-disabled="true">Next →</span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="upload-card" id="upload">
                <h2>Upload assessment</h2>
                <p>Drop one or more Architecture Risk Assessment workbooks, including Due Diligence Extension, Governance Summary, and Scoring Legend tabs.</p>
                <ul class="format-list">
                    <li>Architecture sheet: metadata in rows 2–7, headers in row 8, checks from row 9</li>
                    <li>Due Diligence Extension: category items with status, risk, actions, and sources</li>
                    <li>JSON Due Diligence Summary: ratings, recommendations, and exception findings</li>
                </ul>

                <form method="post" enctype="multipart/form-data" class="upload-form" id="upload-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="file-drop" id="file-drop" data-max-files="<?= (int) ($config['max_upload_files'] ?? 10) ?>">
                        <span class="file-drop-caption">Excel workbook (.xlsx)</span>
                        <div class="file-drop-zone" id="file-drop-zone">
                            <input
                                type="file"
                                name="assessment_file[]"
                                id="assessment-file"
                                class="file-drop-input"
                                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                aria-label="Excel workbooks"
                                multiple
                                required
                            >
                            <div class="file-drop-copy" id="file-drop-copy">
                                <strong>Drop workbooks here</strong>
                                <em>or click to browse — multiple .xlsx files allowed</em>
                            </div>
                        </div>
                        <p class="file-drop-status" id="file-drop-status" hidden></p>
                        <ul class="file-drop-list" id="file-drop-list" hidden></ul>
                    </div>
                    <button type="submit" class="button button-primary">Generate dashboard</button>
                </form>
            </section>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="assets/js/upload.js?v=<?= filemtime(__DIR__ . '/assets/js/upload.js') ?>"></script>
</body>
</html>
<?php

/**
 * @param array<string, mixed> $filesField
 * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
 */
function normalize_assessment_uploads(array $filesField): array
{
    if (!isset($filesField['name'])) {
        return [];
    }

    if (!is_array($filesField['name'])) {
        $error = (int) ($filesField['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE && (string) $filesField['name'] === '') {
            return [];
        }

        return [[
            'name' => (string) $filesField['name'],
            'type' => (string) ($filesField['type'] ?? ''),
            'tmp_name' => (string) ($filesField['tmp_name'] ?? ''),
            'error' => $error,
            'size' => (int) ($filesField['size'] ?? 0),
        ]];
    }

    $normalized = [];
    foreach ($filesField['name'] as $index => $name) {
        $error = (int) ($filesField['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE && (string) $name === '') {
            continue;
        }

        $normalized[] = [
            'name' => (string) $name,
            'type' => (string) ($filesField['type'][$index] ?? ''),
            'tmp_name' => (string) ($filesField['tmp_name'][$index] ?? ''),
            'error' => $error,
            'size' => (int) ($filesField['size'][$index] ?? 0),
        ];
    }

    return $normalized;
}
