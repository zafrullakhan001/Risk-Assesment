<?php

declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\DashboardRenderer;
use RiskAssessment\ExcelParser;
use RiskAssessment\Models\Assessment;

$config = require dirname(__DIR__) . '/config/config.php';

$error = '';
$dashboardHtml = '';

if (isset($_SESSION['dashboard_html']) && ($_GET['view'] ?? '') === '1') {
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

        $_SESSION['assessment'] = [
            'metadata' => $assessment->metadata,
            'items' => $assessment->items,
            'summary' => $assessment->summary,
            'source_filename' => $originalName,
            'stored_filename' => $storedName,
        ];

        $renderer = new DashboardRenderer();
        $dashboardHtml = $renderer->render($assessment, $originalName);
        $_SESSION['dashboard_html'] = $dashboardHtml;

        header('Location: index.php?view=1');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($dashboardHtml === '' && isset($_SESSION['assessment']) && ($_GET['view'] ?? '') === '1') {
    $stored = $_SESSION['assessment'];
    $assessment = Assessment::fromParsedData(
        $stored['metadata'] ?? [],
        $stored['items'] ?? []
    );
    $renderer = new DashboardRenderer();
    $dashboardHtml = $renderer->render($assessment, (string) ($stored['source_filename'] ?? ''));
    $_SESSION['dashboard_html'] = $dashboardHtml;
}

if ($dashboardHtml !== '') {
    echo $dashboardHtml;
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config['app_name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <div class="shell upload-page">
        <header class="topbar">
            <div class="brand">
                <span class="brand-mark">🛡️</span>
                <div>
                    <div class="eyebrow">Architecture risk operations</div>
                    <h1>Risk assessment control room</h1>
                </div>
            </div>
            <div class="updated">
                <span class="live-dot"></span>
                <span>📤 Upload a standardized assessment workbook</span>
            </div>
        </header>

        <main>
            <section class="hero">
                <div>
                    <div class="eyebrow">Executive view / architecture risk</div>
                    <h2>Risk<br><em>Dashboard.</em> 📊</h2>
                    <p>📁 Upload a standardized Architecture Risk Assessment Data Sheet (.xlsx) to generate a live executive dashboard.</p>
                </div>
                <div class="hero-art">
                    <div class="orbit orbit-a"></div>
                    <div class="orbit orbit-b"></div>
                    <div class="hero-stat">
                        <strong>📈</strong>
                        <span>workbook upload</span>
                    </div>
                </div>
            </section>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <section class="upload-card">
                <h2>📤 Upload assessment</h2>
                <p>Use the same spreadsheet format as the Architecture Risk Assessment Data Sheet:</p>
                <ul class="format-list">
                    <li>Rows 2–7: solution metadata (Solution Name, Vendor, Scope, Architecture Model, Reviewer, Date)</li>
                    <li>Row 8: column headers (Section, Check, Status, Risk Level, Notes, Mitigation, Owner, Remediation Timeline)</li>
                    <li>Row 9 onward: risk checks grouped by section</li>
                </ul>

                <form method="post" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <label class="file-input">
                        <span>📎 Excel workbook (.xlsx)</span>
                        <input type="file" name="assessment_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </label>
                    <button type="submit" class="button button-primary">🚀 Generate dashboard</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>
