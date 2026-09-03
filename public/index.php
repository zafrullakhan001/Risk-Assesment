<?php

declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\AssessmentComparer;
use RiskAssessment\DashboardRenderer;
use RiskAssessment\Database\Database;
use RiskAssessment\ExcelParser;
use RiskAssessment\Models\Assessment;
use RiskAssessment\Repositories\AssessmentRepository;
use RiskAssessment\Repositories\FinalEvaluationRepository;
use RiskAssessment\Repositories\ItemResponseRepository;
use RiskAssessment\Repositories\ProjectLinksRepository;
use RiskAssessment\Repositories\ProjectMermaidRepository;

$config = require dirname(__DIR__) . '/config/config.php';
$dbConfig = require dirname(__DIR__) . '/config/database.php';
$repository = new AssessmentRepository(Database::connection($dbConfig));
$responseRepository = new ItemResponseRepository(Database::connection($dbConfig));
$evaluationRepository = new FinalEvaluationRepository(Database::connection($dbConfig));
$projectLinksRepository = new ProjectLinksRepository(Database::connection($dbConfig));
$projectMermaidRepository = new ProjectMermaidRepository(Database::connection($dbConfig));

$error = '';
$flash = '';
$dashboardHtml = '';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchResults = $searchQuery !== '' || isset($_GET['q'])
    ? $repository->searchByProjectName($searchQuery)
    : $repository->listRecent(50);

$assessmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

                $saved = $responseRepository->upsertMany($targetId, $itemKeys, $responseAction, $comment);
                echo json_encode([
                    'ok' => true,
                    'saved' => $saved,
                    'action' => ItemResponseRepository::normalizeAction($responseAction),
                    'label' => ItemResponseRepository::label($responseAction),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $itemKey = trim((string) ($_POST['item_key'] ?? ''));
            if ($itemKey === '') {
                throw new RuntimeException('Invalid response payload.');
            }

            if (!$responseRepository->upsert($targetId, $itemKey, $responseAction, $comment)) {
                throw new RuntimeException('Unable to save response.');
            }

            echo json_encode([
                'ok' => true,
                'action' => ItemResponseRepository::normalizeAction($responseAction),
                'label' => ItemResponseRepository::label($responseAction),
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

            if (!$evaluationRepository->upsert($targetId, $evaluatorName, $evaluatorEmail, $notes, $readyToGolive)) {
                throw new RuntimeException('Unable to save final evaluation.');
            }

            $saved = $evaluationRepository->findByAssessmentId($targetId);
            echo json_encode([
                'ok' => true,
                'evaluation' => $saved,
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

        $file = $_FILES['assessment_file'];
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

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $config['allowed_extensions'], true)) {
            throw new RuntimeException('Only .xlsx files are supported.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name'] ?? '') ?: '';
        if (!in_array($mimeType, $config['allowed_mime_types'], true)) {
            throw new RuntimeException('The uploaded file is not a valid Excel workbook.');
        }

        if (!is_dir($config['upload_dir']) && !mkdir($config['upload_dir'], 0755, true) && !is_dir($config['upload_dir'])) {
            throw new RuntimeException('Unable to prepare the upload directory.');
        }

        $storedName = bin2hex(random_bytes(16)) . '.xlsx';
        $destination = $config['upload_dir'] . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to store the uploaded file.');
        }

        $parser = new ExcelParser();
        $assessment = $parser->parse($destination);
        $savedId = $repository->save($assessment, $destination, $originalName);

        $_SESSION['assessment'] = [
            'id' => $savedId,
            'metadata' => $assessment->metadata,
            'items' => $assessment->items,
            'due_diligence_items' => $assessment->dueDiligenceItems,
            'workbook' => $assessment->workbook,
            'summary' => $assessment->summary,
            'source_filename' => $originalName,
            'stored_filename' => $storedName,
        ];

        header('Location: index.php?view=1&id=' . $savedId);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if (isset($_GET['deleted'])) {
    $flash = 'Assessment version deleted.';
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
                $projectDiagrams
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
            $projectDiagrams
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
    <title><?= htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8') ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
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
                    <div class="brand-title">Architecture Risk</div>
                    <h1>Assessment register</h1>
                </div>
            </a>
            <div class="topbar-actions">
                <a class="button ghost home-link" href="#find-projects">Find by name</a>
                <?php require __DIR__ . '/includes/theme-controls.php'; ?>
                <div class="updated"><?= (int) $totalProjects ?> saved project<?= $totalProjects === 1 ? '' : 's' ?></div>
            </div>
        </header>

        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">Architecture risk assessment</div>
                            <h2>Find any project by <em>name</em></h2>
                            <p>Upload a multi-tab workbook or open a saved assessment.</p>
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
                <h2>Find any project by name</h2>
                <p>Type part of the project name, vendor, or scope. Leave blank to browse recent uploads. Delete drops that saved version only.</p>
                <form method="get" class="search-form" action="index.php#find-projects">
                    <div class="search-wrap search-wrap-wide">
                        <span>Find</span>
                        <input
                            type="search"
                            name="q"
                            value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Project name, e.g. FibroScan..."
                            autofocus
                        >
                    </div>
                    <button type="submit" class="button button-primary">Find project</button>
                </form>

                <?php if ($searchResults === []): ?>
                    <p class="empty-results">No saved projects found<?= $searchQuery !== '' ? ' for that search.' : ' yet.' ?></p>
                <?php else: ?>
                    <div class="project-list">
                        <?php foreach ($searchResults as $project): ?>
                            <div class="project-item project-item-row">
                                <a href="index.php?view=1&amp;id=<?= (int) $project['id'] ?>">
                                    <div>
                                        <strong><?= htmlspecialchars((string) $project['solution_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars((string) $project['vendor'], ENT_QUOTES, 'UTF-8') ?> · #<?= (int) $project['id'] ?></span>
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
                <?php endif; ?>
            </section>

            <section class="upload-card" id="upload">
                <h2>Upload assessment</h2>
                <p>Supports the Architecture Risk Assessment workbook, including Due Diligence Extension, Governance Summary, and Scoring Legend tabs.</p>
                <ul class="format-list">
                    <li>Architecture sheet: metadata in rows 2–7, headers in row 8, checks from row 9</li>
                    <li>Due Diligence Extension: category items with status, risk, actions, and sources</li>
                    <li>JSON Due Diligence Summary: ratings, recommendations, and exception findings</li>
                </ul>

                <form method="post" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <label class="file-input">
                        <span>Excel workbook (.xlsx)</span>
                        <input type="file" name="assessment_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </label>
                    <button type="submit" class="button button-primary">Generate dashboard</button>
                </form>
            </section>
        </main>
    </div>
    <script src="assets/js/theme.js"></script>
</body>
</html>
