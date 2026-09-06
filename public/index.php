<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\Actor;
use RiskAssessment\AssessmentComparer;
use RiskAssessment\AssessmentDate;
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
use RiskAssessment\Repositories\ProjectShareRepository;
use RiskAssessment\Repositories\SharePointArchiveRepository;
use RiskAssessment\Repositories\SharePointCatalogRepository;

$currentUser = $auth->requireAuth();
$actor = Actor::fromUser($currentUser);
$repository = new AssessmentRepository($pdo);
$responseRepository = new ItemResponseRepository($pdo);
$evaluationRepository = new FinalEvaluationRepository($pdo);
$changeLogRepository = new AssessmentChangeLogRepository($pdo);
$projectLinksRepository = new ProjectLinksRepository($pdo);
$projectMermaidRepository = new ProjectMermaidRepository($pdo);
$projectPicturesRepository = new ProjectPicturesRepository($pdo);
$projectShareRepository = new ProjectShareRepository($pdo);
$sharePointCatalogRepository = new SharePointCatalogRepository($pdo);
$sharePointArchives = new SharePointArchiveRepository($pdo);
$projectImageConverter = new ProjectImageConverter();
$findingStatusRepository = new FindingStatusRepository($pdo);
$goliveGate = new GoliveGate();

$error = '';
$flash = '';
$freshShareUrl = null;
$dashboardHtml = '';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchPage = max(1, (int) ($_GET['page'] ?? 1));
$allowedPerPage = [10, 25, 50, 100];
$searchPerPage = \RiskAssessment\PaginationPreference::resolve(
    \RiskAssessment\PaginationPreference::KEY_PROJECTS,
    isset($_GET['per']) ? (int) $_GET['per'] : null,
    10,
    $allowedPerPage
);
$allowedSorts = ['project', 'vendor', 'id', 'template', 'owner', 'status', 'assessed', 'uploaded'];
$searchSort = strtolower(trim((string) ($_GET['sort'] ?? 'uploaded')));
if (!in_array($searchSort, $allowedSorts, true)) {
    $searchSort = 'uploaded';
}
$searchDir = strtolower(trim((string) ($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
$searchFilters = [
    'project' => trim((string) ($_GET['f_project'] ?? '')),
    'vendor' => trim((string) ($_GET['f_vendor'] ?? '')),
    'id' => trim((string) ($_GET['f_id'] ?? '')),
    'template' => trim((string) ($_GET['f_template'] ?? '')),
    'owner' => trim((string) ($_GET['f_owner'] ?? '')),
    'status' => trim((string) ($_GET['f_status'] ?? '')),
    'assessed' => trim((string) ($_GET['f_assessed'] ?? '')),
    'uploaded' => trim((string) ($_GET['f_uploaded'] ?? '')),
];
$activeFilters = array_filter($searchFilters, static fn (string $value): bool => $value !== '');
$searchTotal = $repository->countProjects($searchQuery, $searchFilters);
$searchTotalPages = max(1, (int) ceil($searchTotal / $searchPerPage));
if ($searchPage > $searchTotalPages) {
    $searchPage = $searchTotalPages;
}
$searchResults = $repository->searchProjects(
    $searchQuery,
    $searchPage,
    $searchPerPage,
    $searchSort,
    $searchDir,
    $searchFilters
);
$searchFrom = $searchTotal === 0 ? 0 : (($searchPage - 1) * $searchPerPage) + 1;
$searchTo = min($searchTotal, $searchPage * $searchPerPage);

$projectListQueryParams = static function (
    array $overrides = []
) use (
    $searchQuery,
    $searchPage,
    $searchPerPage,
    $searchSort,
    $searchDir,
    $searchFilters
): array {
    $params = [
        'q' => $searchQuery,
        'page' => $searchPage,
        'per' => $searchPerPage,
        'sort' => $searchSort,
        'dir' => $searchDir,
    ];
    foreach ($searchFilters as $key => $value) {
        if ($value !== '') {
            $params['f_' . $key] = $value;
        }
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    if (($params['q'] ?? '') === '') {
        unset($params['q']);
    }
    if ((int) ($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }
    if ((int) ($params['per'] ?? 10) === 10) {
        unset($params['per']);
    }
    if (($params['sort'] ?? 'uploaded') === 'uploaded' && ($params['dir'] ?? 'desc') === 'desc') {
        unset($params['sort'], $params['dir']);
    }
    return $params;
};

$projectListUrl = static function (array $overrides = []) use ($projectListQueryParams): string {
    $params = $projectListQueryParams($overrides);
    $query = http_build_query($params);
    return 'index.php' . ($query !== '' ? '?' . $query : '') . '#find-projects';
};

$sortHeaderUrl = static function (string $column) use ($searchSort, $searchDir, $projectListUrl): string {
    $nextDir = ($searchSort === $column && $searchDir === 'asc') ? 'desc' : 'asc';
    if ($searchSort !== $column) {
        $nextDir = in_array($column, ['assessed', 'uploaded', 'id'], true) ? 'desc' : 'asc';
    }
    return $projectListUrl([
        'sort' => $column,
        'dir' => $nextDir,
        'page' => 1,
    ]);
};

$sortAria = static function (string $column) use ($searchSort, $searchDir): string {
    if ($searchSort !== $column) {
        return 'none';
    }
    return $searchDir === 'asc' ? 'ascending' : 'descending';
};

$sortClass = static function (string $column) use ($searchSort, $searchDir): string {
    if ($searchSort !== $column) {
        return 'is-sortable';
    }
    return 'is-sortable is-sorted is-sorted-' . $searchDir;
};

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

    if ($postedAction === 'add_assessment_item' || $postedAction === 'delete_assessment_item') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment before changing register rows.');
            }

            if ($postedAction === 'add_assessment_item') {
                $item = $repository->addManualItem($targetId, [
                    'item_type' => (string) ($_POST['item_type'] ?? 'architecture'),
                    'section' => (string) ($_POST['section'] ?? ''),
                    'check' => (string) ($_POST['check'] ?? ''),
                    'status' => (string) ($_POST['status'] ?? ''),
                    'risk_level' => (string) ($_POST['risk_level'] ?? ''),
                    'notes' => (string) ($_POST['notes'] ?? ''),
                    'mitigation' => (string) ($_POST['mitigation'] ?? ''),
                    'owner' => (string) ($_POST['owner'] ?? ''),
                    'remediation_timeline' => (string) ($_POST['remediation_timeline'] ?? ''),
                    'review_question' => (string) ($_POST['review_question'] ?? ''),
                    'source_reference' => (string) ($_POST['source_reference'] ?? ''),
                ]);
                $itemKey = AssessmentComparer::itemKey(
                    (string) ($item['item_type'] ?? 'architecture'),
                    (string) ($item['section'] ?? ''),
                    (string) ($item['check'] ?? '')
                );
                echo json_encode([
                    'ok' => true,
                    'item' => $item,
                    'item_key' => $itemKey,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $itemId = filter_var($_POST['item_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($itemId <= 0) {
                throw new RuntimeException('Invalid row to delete.');
            }

            $deleted = $repository->deleteItem($targetId, $itemId);
            if ($deleted === null) {
                throw new RuntimeException('Row not found.');
            }

            $itemKey = AssessmentComparer::itemKey(
                (string) ($deleted['item_type'] ?? 'architecture'),
                (string) ($deleted['section'] ?? ''),
                (string) ($deleted['check'] ?? '')
            );
            $responseRepository->deleteByKey($targetId, $itemKey);
            $changeLogRepository->deleteForEntity(
                $targetId,
                AssessmentChangeLogRepository::ENTITY_ITEM_RESPONSE,
                $itemKey
            );

            echo json_encode([
                'ok' => true,
                'item_id' => $itemId,
                'item_key' => $itemKey,
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
            $hasComment = array_key_exists('comment', $_POST);
            $hasLinks = array_key_exists('servicenow_links', $_POST);
            $comment = $hasComment ? (string) ($_POST['comment'] ?? '') : null;
            $links = null;
            if ($hasLinks) {
                $rawLinks = $_POST['servicenow_links'] ?? '[]';
                if (is_string($rawLinks)) {
                    $decoded = json_decode($rawLinks, true);
                    $links = is_array($decoded) ? $decoded : [];
                } elseif (is_array($rawLinks)) {
                    $links = $rawLinks;
                } else {
                    $links = [];
                }
            }

            if ($targetId <= 0 || $findingId === '') {
                throw new RuntimeException('Invalid exception status payload.');
            }

            if (!$findingStatusRepository->upsert($targetId, $findingId, $status, $comment, $links)) {
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
            $saved = $findingStatuses[$findingId] ?? [
                'status' => FindingStatusRepository::normalizeStatus($status),
                'comment' => FindingStatusRepository::normalizeComment((string) ($comment ?? '')),
                'servicenow_links' => FindingStatusRepository::normalizeLinks($links ?? []),
            ];

            echo json_encode([
                'ok' => true,
                'status' => $saved['status'],
                'comment' => $saved['comment'],
                'servicenow_links' => $saved['servicenow_links'],
                'gates' => $gate,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($postedAction === 'add_finding' || $postedAction === 'delete_finding') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($targetId <= 0) {
                throw new RuntimeException('Open a saved assessment before changing exceptions.');
            }

            if ($postedAction === 'add_finding') {
                $finding = $repository->addFinding($targetId, [
                    'finding' => (string) ($_POST['finding'] ?? ''),
                    'policy_reference' => (string) ($_POST['policy_reference'] ?? ''),
                    'impact' => (string) ($_POST['impact'] ?? ''),
                    'mitigation' => (string) ($_POST['mitigation'] ?? ''),
                    'owner' => (string) ($_POST['owner'] ?? ''),
                    'timeline' => (string) ($_POST['timeline'] ?? ''),
                ]);
                $findingId = (string) ($finding['id'] ?? '');
                $findingStatusRepository->upsert($targetId, $findingId, 'Open', '', []);

                $responses = $responseRepository->listForAssessment($targetId);
                $findingStatuses = $findingStatusRepository->listForAssessment($targetId);
                $record = $repository->findById($targetId);
                if ($record === null) {
                    throw new RuntimeException('Assessment not found.');
                }
                $evaluation = $evaluationRepository->findByAssessmentId($targetId);
                $notes = (string) ($evaluation['notes'] ?? '');
                $gate = $goliveGate->evaluate($record['assessment'], $responses, $findingStatuses, $notes);
                $meta = $findingStatuses[$findingId] ?? [
                    'status' => 'Open',
                    'comment' => '',
                    'servicenow_links' => [],
                ];

                echo json_encode([
                    'ok' => true,
                    'finding' => array_merge($finding, [
                        'status' => $meta['status'],
                        'comment' => $meta['comment'],
                        'servicenow_links' => $meta['servicenow_links'],
                    ]),
                    'gates' => $gate,
                    'open_findings' => (int) ($gate['residual']['open_findings'] ?? 0),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $findingId = trim((string) ($_POST['finding_id'] ?? ''));
            if ($findingId === '') {
                throw new RuntimeException('Invalid exception to delete.');
            }

            $deleted = $repository->deleteFinding($targetId, $findingId);
            if ($deleted === null) {
                throw new RuntimeException('Exception not found.');
            }
            $findingStatusRepository->deleteOne($targetId, $findingId);

            $responses = $responseRepository->listForAssessment($targetId);
            $findingStatuses = $findingStatusRepository->listForAssessment($targetId);
            $record = $repository->findById($targetId);
            if ($record === null) {
                throw new RuntimeException('Assessment not found.');
            }
            $evaluation = $evaluationRepository->findByAssessmentId($targetId);
            $notes = (string) ($evaluation['notes'] ?? '');
            $gate = $goliveGate->evaluate($record['assessment'], $responses, $findingStatuses, $notes);

            echo json_encode([
                'ok' => true,
                'finding_id' => $findingId,
                'gates' => $gate,
                'open_findings' => (int) ($gate['residual']['open_findings'] ?? 0),
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

    if ($postedAction === 'create_share_link' || $postedAction === 'revoke_share_link') {
        try {
            if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                throw new RuntimeException('Invalid form submission. Please refresh and try again.');
            }

            $targetId = filter_var($_POST['assessment_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            if ($targetId <= 0 || $repository->findById($targetId) === null) {
                throw new RuntimeException('Assessment not found.');
            }

            if ($postedAction === 'create_share_link') {
                $created = $projectShareRepository->create($targetId, $currentUser);
                $_SESSION['fresh_share_url'] = ProjectShareRepository::absoluteUrl($created['token']);
                $flash = 'Read-only share link created. Copy it from the Share tab — it is shown only once.';
            } else {
                $shareId = filter_var($_POST['share_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
                if ($shareId > 0) {
                    $projectShareRepository->revokeById($shareId, $targetId);
                } else {
                    $projectShareRepository->revokeAllForAssessment($targetId);
                }
                unset($_SESSION['fresh_share_url']);
                $flash = 'Public share link revoked.';
            }

            header('Location: index.php?view=1&id=' . $targetId . '&tab=actions&action_tab=share&shared=1');
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } else {
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
                    $savedId = $repository->save($assessment, $destination, $originalName, $actor);
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

if (isset($_GET['shared']) && $flash === '') {
    $flash = 'Share settings updated.';
}

if (isset($_SESSION['fresh_share_url'])) {
    $freshShareUrl = (string) $_SESSION['fresh_share_url'];
    unset($_SESSION['fresh_share_url']);
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
            $shareLinks = $projectShareRepository->listForAssessment($assessmentId);
            $sharePointCatalog = $sharePointArchives->applyToAssessmentMatch(
                $sharePointCatalogRepository->findMatchingProjectAnySource($solutionName),
                ''
            );
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
                $projectPictures,
                false,
                '',
                $shareLinks,
                $freshShareUrl,
                $sharePointCatalog
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
        $sharePointCatalog = $sharePointArchives->applyToAssessmentMatch(
            $sharePointCatalogRepository->findMatchingProjectAnySource(
                $assessment->getMetadata('solution_name')
            ),
            ''
        );
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
            $projectPictures,
            false,
            '',
            $storedId > 0 ? $projectShareRepository->listForAssessment($storedId) : [],
            $freshShareUrl,
            $sharePointCatalog
        );
    }
}

if ($dashboardHtml !== '') {
    echo $dashboardHtml;
    exit;
}

$totalProjects = $repository->countAll();

$renderProjectDelete = static function (array $project): void {
    ?>
    <form method="post" class="inline-form project-delete-form" onsubmit="return confirm('Delete this saved version permanently?');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="delete_assessment">
        <input type="hidden" name="assessment_id" value="<?= (int) $project['id'] ?>">
        <button type="submit" class="project-delete-btn" title="Delete this saved version" aria-label="Delete this saved version">
            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                <polyline points="3 6 5 6 21 6"></polyline>
                <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"></path>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                <line x1="10" y1="11" x2="10" y2="17"></line>
                <line x1="14" y1="11" x2="14" y2="17"></line>
            </svg>
        </button>
    </form>
    <?php
};
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
    <script>
    (function () {
        try {
            var view = localStorage.getItem('project-list-view-v2');
            if (!view) {
                view = 'table';
            }
            if (view === 'list') {
                view = 'strip';
            }
            if (view !== 'table' && view !== 'strip' && view !== 'cards') {
                view = 'table';
            }
            document.documentElement.setAttribute('data-project-list-view', view);
        } catch (error) {
            document.documentElement.setAttribute('data-project-list-view', 'table');
        }
    })();
    </script>
</head>
<body>
    <div class="shell upload-page">
        <header class="topbar topbar-uplift">
            <a class="brand brand-link" href="index.php#find-projects" title="Find projects by name">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1><?= e($branding->brandSubtitle()) ?></h1>
                </div>
            </a>
            <div class="topbar-actions">
                <a class="button ghost home-link" href="#find-projects">Find by name</a>
                <a class="button ghost home-link" href="#upload">Upload</a>
                <a class="button ghost home-link" href="templates.php">📚 Templates</a>
                <a class="button ghost home-link" href="sharepoint.php">📁 SharePoint</a>
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

            <?php $homeTab = 'find'; require __DIR__ . '/includes/home-section-tabs.php'; ?>

            <section class="upload-card search-card" id="find-projects">
                <h2><?= e($branding->heroHeadingPlain()) ?></h2>
                <p>Search any project field: name, vendor, owner, scope, reviewer, architecture, filename, evaluator, executive summary, dates, template format (try “adaptive” or “matured”), or go-live status (try “ready”, “not ready”, “no final”). Leave blank to browse all saved versions.</p>
                <form method="get" class="search-form" action="index.php#find-projects">
                    <?php if ($searchPerPage !== 10): ?>
                        <input type="hidden" name="per" value="<?= (int) $searchPerPage ?>">
                    <?php endif; ?>
                    <div class="search-wrap search-wrap-wide">
                        <span>Find</span>
                        <input
                            type="search"
                            name="q"
                            value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Name, vendor, owner, reviewer, evaluator…"
                            autofocus
                        >
                    </div>
                    <button type="submit" class="button button-primary">Find project</button>
                </form>

                <?php if ($searchTotal === 0 && $searchQuery === '' && $activeFilters === []): ?>
                    <p class="empty-results">No saved projects found yet.</p>
                <?php else: ?>
                    <div class="project-list-toolbar">
                        <p class="search-result-meta">Showing <?= (int) $searchFrom ?>–<?= (int) $searchTo ?> of <?= (int) $searchTotal ?><?= $searchQuery !== '' ? ' matching “' . htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') . '”' : '' ?><?= $activeFilters !== [] ? ' · filtered' : '' ?></p>
                        <div class="project-list-toolbar-actions">
                            <button
                                type="button"
                                class="button ghost project-filter-toggle<?= $activeFilters !== [] ? ' is-active' : '' ?>"
                                id="project-filter-toggle"
                                aria-controls="project-table-filters"
                                aria-expanded="<?= $activeFilters !== [] ? 'true' : 'false' ?>"
                            ><?= $activeFilters !== [] ? 'Hide filters' : 'Show filters' ?></button>
                            <div class="project-list-view-switcher" id="project-list-view-switcher" role="tablist" aria-label="Project list view">
                                <button type="button" class="project-list-view-btn" role="tab" aria-selected="false" data-project-view="cards">▦ Cards</button>
                                <button type="button" class="project-list-view-btn is-active" role="tab" aria-selected="true" data-project-view="table">⊞ Table</button>
                                <button type="button" class="project-list-view-btn" role="tab" aria-selected="false" data-project-view="strip">▬ Strip</button>
                            </div>
                        </div>
                    </div>
                    <div class="project-list" id="project-list" data-project-view="table">
                        <?php if ($searchResults === []): ?>
                            <p class="empty-results">No projects match<?= $searchQuery !== '' || $activeFilters !== [] ? ' these filters.' : '.' ?></p>
                        <?php endif; ?>
                        <?php foreach ($searchResults as $project): ?>
                            <?php
                            $goliveStatus = AssessmentRepository::goliveCardStatus($project);
                            $templateStatus = AssessmentRepository::templateCardStatus($project);
                            $ownerStatus = AssessmentRepository::ownerCardStatus($project);
                            $assessedLabel = AssessmentDate::display((string) ($project['assessment_date'] ?? ''));
                            ?>
                            <div class="project-item project-item-row is-<?= htmlspecialchars($goliveStatus['key'], ENT_QUOTES, 'UTF-8') ?>">
                                <a href="index.php?view=1&amp;id=<?= (int) $project['id'] ?>">
                                    <div class="project-item-main">
                                        <strong><?= htmlspecialchars((string) $project['solution_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span class="project-item-vendor"><?= htmlspecialchars((string) $project['vendor'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="project-item-id">#<?= (int) $project['id'] ?></span>
                                        <span class="project-item-owner is-<?= htmlspecialchars($ownerStatus['key'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($ownerStatus['title'], ENT_QUOTES, 'UTF-8') ?>">Owner · <?= htmlspecialchars($ownerStatus['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="project-item-badges">
                                            <em class="project-template is-<?= htmlspecialchars($templateStatus['key'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($templateStatus['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($templateStatus['label'], ENT_QUOTES, 'UTF-8') ?>
                                            </em>
                                            <em class="project-status is-<?= htmlspecialchars($goliveStatus['key'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($goliveStatus['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($goliveStatus['label'], ENT_QUOTES, 'UTF-8') ?>
                                            </em>
                                        </span>
                                    </div>
                                    <div class="project-meta">
                                        <span><?= htmlspecialchars($assessedLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars((string) $project['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </a>
                                <?php $renderProjectDelete($project); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="project-table-wrap table-scroll" id="project-table-wrap">
                        <form method="get" class="project-table-filter-form" action="index.php#find-projects">
                            <?php if ($searchQuery !== ''): ?>
                                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endif; ?>
                            <?php if ($searchPerPage !== 10): ?>
                                <input type="hidden" name="per" value="<?= (int) $searchPerPage ?>">
                            <?php endif; ?>
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($searchSort, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="dir" value="<?= htmlspecialchars($searchDir, ENT_QUOTES, 'UTF-8') ?>">
                            <table class="project-table">
                                <thead>
                                    <tr>
                                        <th scope="col" class="<?= htmlspecialchars($sortClass('id'), ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= htmlspecialchars($sortAria('id'), ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="project-sort-link" href="<?= htmlspecialchars($sortHeaderUrl('id'), ENT_QUOTES, 'UTF-8') ?>">ID</a>
                                        </th>
                                        <th scope="col" class="<?= htmlspecialchars($sortClass('project'), ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= htmlspecialchars($sortAria('project'), ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="project-sort-link" href="<?= htmlspecialchars($sortHeaderUrl('project'), ENT_QUOTES, 'UTF-8') ?>">Project</a>
                                        </th>
                                        <th scope="col" class="<?= htmlspecialchars($sortClass('vendor'), ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= htmlspecialchars($sortAria('vendor'), ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="project-sort-link" href="<?= htmlspecialchars($sortHeaderUrl('vendor'), ENT_QUOTES, 'UTF-8') ?>">Vendor</a>
                                        </th>
                                        <th scope="col" class="<?= htmlspecialchars($sortClass('template'), ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= htmlspecialchars($sortAria('template'), ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="project-sort-link" href="<?= htmlspecialchars($sortHeaderUrl('template'), ENT_QUOTES, 'UTF-8') ?>">Template</a>
                                        </th>
                                        <th scope="col" class="<?= htmlspecialchars($sortClass('owner'), ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= htmlspecialchars($sortAria('owner'), ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="project-sort-link" href="<?= htmlspecialchars($sortHeaderUrl('owner'), ENT_QUOTES, 'UTF-8') ?>">Owner</a>
                                        </th>
                                        <th scope="col" class="<?= htmlspecialchars($sortClass('status'), ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= htmlspecialchars($sortAria('status'), ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="project-sort-link" href="<?= htmlspecialchars($sortHeaderUrl('status'), ENT_QUOTES, 'UTF-8') ?>">Status</a>
                                        </th>
                                        <th scope="col" class="<?= htmlspecialchars($sortClass('assessed'), ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= htmlspecialchars($sortAria('assessed'), ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="project-sort-link" href="<?= htmlspecialchars($sortHeaderUrl('assessed'), ENT_QUOTES, 'UTF-8') ?>">Assessed</a>
                                        </th>
                                        <th scope="col" class="<?= htmlspecialchars($sortClass('uploaded'), ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= htmlspecialchars($sortAria('uploaded'), ENT_QUOTES, 'UTF-8') ?>">
                                            <a class="project-sort-link" href="<?= htmlspecialchars($sortHeaderUrl('uploaded'), ENT_QUOTES, 'UTF-8') ?>">Uploaded</a>
                                        </th>
                                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                                    </tr>
                                    <tr class="project-table-filters<?= $activeFilters === [] ? ' is-collapsed' : '' ?>" id="project-table-filters"<?= $activeFilters === [] ? ' hidden' : '' ?>>
                                        <th scope="col"><input type="search" name="f_id" value="<?= htmlspecialchars($searchFilters['id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="#" aria-label="Filter by ID"></th>
                                        <th scope="col"><input type="search" name="f_project" value="<?= htmlspecialchars($searchFilters['project'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Filter…" aria-label="Filter by project"></th>
                                        <th scope="col"><input type="search" name="f_vendor" value="<?= htmlspecialchars($searchFilters['vendor'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Filter…" aria-label="Filter by vendor"></th>
                                        <th scope="col"><input type="search" name="f_template" value="<?= htmlspecialchars($searchFilters['template'], ENT_QUOTES, 'UTF-8') ?>" placeholder="adaptive / matured" aria-label="Filter by template"></th>
                                        <th scope="col"><input type="search" name="f_owner" value="<?= htmlspecialchars($searchFilters['owner'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Filter…" aria-label="Filter by owner"></th>
                                        <th scope="col"><input type="search" name="f_status" value="<?= htmlspecialchars($searchFilters['status'], ENT_QUOTES, 'UTF-8') ?>" placeholder="ready / no final" aria-label="Filter by status"></th>
                                        <th scope="col"><input type="search" name="f_assessed" value="<?= htmlspecialchars($searchFilters['assessed'], ENT_QUOTES, 'UTF-8') ?>" placeholder="YYYY-MM-DD" aria-label="Filter by assessed date"></th>
                                        <th scope="col"><input type="search" name="f_uploaded" value="<?= htmlspecialchars($searchFilters['uploaded'], ENT_QUOTES, 'UTF-8') ?>" placeholder="YYYY-MM-DD" aria-label="Filter by uploaded date"></th>
                                        <th scope="col" class="project-table-filter-actions">
                                            <button type="submit" class="button ghost project-filter-apply">Filter</button>
                                            <?php if ($activeFilters !== []): ?>
                                                <a class="button ghost project-filter-clear" href="<?= htmlspecialchars($projectListUrl([
                                                    'f_project' => null,
                                                    'f_vendor' => null,
                                                    'f_id' => null,
                                                    'f_template' => null,
                                                    'f_owner' => null,
                                                    'f_status' => null,
                                                    'f_assessed' => null,
                                                    'f_uploaded' => null,
                                                    'page' => 1,
                                                ]), ENT_QUOTES, 'UTF-8') ?>">Clear</a>
                                            <?php endif; ?>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($searchResults === []): ?>
                                        <tr class="project-table-empty">
                                            <td colspan="9">No projects match<?= $searchQuery !== '' || $activeFilters !== [] ? ' these filters.' : '.' ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($searchResults as $project): ?>
                                        <?php
                                        $goliveStatus = AssessmentRepository::goliveCardStatus($project);
                                        $templateStatus = AssessmentRepository::templateCardStatus($project);
                                        $ownerStatus = AssessmentRepository::ownerCardStatus($project);
                                        $assessedLabel = AssessmentDate::display((string) ($project['assessment_date'] ?? ''));
                                        ?>
                                        <tr class="is-<?= htmlspecialchars($goliveStatus['key'], ENT_QUOTES, 'UTF-8') ?>">
                                            <td class="project-table-id">#<?= (int) $project['id'] ?></td>
                                            <td class="project-table-name">
                                                <a href="index.php?view=1&amp;id=<?= (int) $project['id'] ?>">
                                                    <?= htmlspecialchars((string) $project['solution_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </a>
                                            </td>
                                            <td><?= htmlspecialchars((string) ($project['vendor'] !== '' ? $project['vendor'] : '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <em class="project-template is-<?= htmlspecialchars($templateStatus['key'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($templateStatus['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($templateStatus['label'], ENT_QUOTES, 'UTF-8') ?>
                                                </em>
                                            </td>
                                            <td class="project-table-owner" title="<?= htmlspecialchars($ownerStatus['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($ownerStatus['label'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td>
                                                <em class="project-status is-<?= htmlspecialchars($goliveStatus['key'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($goliveStatus['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($goliveStatus['label'], ENT_QUOTES, 'UTF-8') ?>
                                                </em>
                                            </td>
                                            <td class="project-table-date"><?= htmlspecialchars($assessedLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="project-table-date"><?= htmlspecialchars((string) $project['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="project-table-actions"><?php $renderProjectDelete($project); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>
                    <?php if ($searchTotal > 0): ?>
                    <nav class="pagination" aria-label="Project list pages">
                        <div class="pagination-controls">
                            <?php if ($searchTotalPages > 1): ?>
                                <?php if ($searchPage > 1): ?>
                                    <a class="button ghost" href="<?= htmlspecialchars($projectListUrl(['page' => $searchPage - 1]), ENT_QUOTES, 'UTF-8') ?>">← Previous</a>
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
                                            <a class="pagination-page" href="<?= htmlspecialchars($projectListUrl(['page' => $pageNum]), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNum ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </span>
                                <?php if ($searchPage < $searchTotalPages): ?>
                                    <a class="button ghost" href="<?= htmlspecialchars($projectListUrl(['page' => $searchPage + 1]), ENT_QUOTES, 'UTF-8') ?>">Next →</a>
                                <?php else: ?>
                                    <span class="button ghost is-disabled" aria-disabled="true">Next →</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <form method="get" class="pagination-per-page" action="index.php#find-projects">
                            <?php if ($searchQuery !== ''): ?>
                                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endif; ?>
                            <?php if ($searchSort !== 'uploaded' || $searchDir !== 'desc'): ?>
                                <input type="hidden" name="sort" value="<?= htmlspecialchars($searchSort, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="dir" value="<?= htmlspecialchars($searchDir, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endif; ?>
                            <?php foreach ($searchFilters as $filterKey => $filterValue): ?>
                                <?php if ($filterValue !== ''): ?>
                                    <input type="hidden" name="f_<?= htmlspecialchars($filterKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($filterValue, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <label>
                                <span>Rows per page</span>
                                <select name="per" onchange="this.form.submit()">
                                    <?php foreach ($allowedPerPage as $size): ?>
                                        <option value="<?= (int) $size ?>"<?= $searchPerPage === $size ? ' selected' : '' ?>><?= (int) $size ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </form>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="upload-card" id="upload">
                <h2>Upload assessment</h2>
                <p>Drop one or more Architecture Risk Assessment workbooks (.xlsx). Supports the classic table-based Risk Register format and the Adaptive Architecture template (classify → route → material findings). Include Due Diligence, Governance/Exception, and Scoring tabs as needed.</p>
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
    <script src="assets/js/project-list.js?v=<?= filemtime(__DIR__ . '/assets/js/project-list.js') ?>"></script>
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
