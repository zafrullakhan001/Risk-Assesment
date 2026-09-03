<?php

declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\DashboardRenderer;
use RiskAssessment\Database\Database;
use RiskAssessment\ExcelParser;
use RiskAssessment\Models\Assessment;
use RiskAssessment\Repositories\AssessmentRepository;

$config = require dirname(__DIR__) . '/config/config.php';
$dbConfig = require dirname(__DIR__) . '/config/database.php';
$repository = new AssessmentRepository(Database::connection($dbConfig));

$error = '';
$dashboardHtml = '';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchResults = $searchQuery !== '' || isset($_GET['q'])
    ? $repository->searchByProjectName($searchQuery)
    : $repository->listRecent(10);

$assessmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if (isset($_SESSION['dashboard_html']) && ($_GET['view'] ?? '') === '1' && $assessmentId <= 0) {
    $dashboardHtml = (string) $_SESSION['dashboard_html'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid form submission. Please refresh and try again.');
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

if ($dashboardHtml === '' && ($_GET['view'] ?? '') === '1') {
    if ($assessmentId > 0) {
        $record = $repository->findById($assessmentId);
        if ($record === null) {
            $error = 'Assessment not found.';
        } else {
            $renderer = new DashboardRenderer();
            $dashboardHtml = $renderer->render($record['assessment'], $record['source_filename']);
        }
    } elseif (isset($_SESSION['assessment'])) {
        $stored = $_SESSION['assessment'];
        $assessment = Assessment::fromParsedData(
            $stored['metadata'] ?? [],
            $stored['items'] ?? [],
            $stored['due_diligence_items'] ?? [],
            $stored['workbook'] ?? []
        );
        $renderer = new DashboardRenderer();
        $dashboardHtml = $renderer->render($assessment, (string) ($stored['source_filename'] ?? ''));
    }
}

if ($dashboardHtml !== '') {
    echo $dashboardHtml;
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
            <div class="brand">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div>
                    <div class="brand-title">Architecture Risk</div>
                    <h1>Assessment register</h1>
                </div>
            </div>
            <div class="topbar-actions">
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

            <section class="upload-card search-card">
                <h2>Search saved projects</h2>
                <p>Search by project name, vendor, or scope.</p>
                <form method="get" class="search-form">
                    <div class="search-wrap search-wrap-wide">
                        <span>Search</span>
                        <input
                            type="search"
                            name="q"
                            value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Project name, e.g. FibroScan..."
                        >
                    </div>
                    <button type="submit" class="button button-primary">Find project</button>
                </form>

                <?php if ($searchResults === []): ?>
                    <p class="empty-results">No saved projects found<?= $searchQuery !== '' ? ' for that search.' : ' yet.' ?></p>
                <?php else: ?>
                    <div class="project-list">
                        <?php foreach ($searchResults as $project): ?>
                            <a class="project-item" href="index.php?view=1&amp;id=<?= (int) $project['id'] ?>">
                                <div>
                                    <strong><?= htmlspecialchars((string) $project['solution_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= htmlspecialchars((string) $project['vendor'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="project-meta">
                                    <span><?= htmlspecialchars((string) ($project['assessment_date'] ?: 'No date'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span><?= htmlspecialchars((string) $project['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="upload-card">
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
