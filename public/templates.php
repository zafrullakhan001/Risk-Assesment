<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\Repositories\TemplateWorkbookRepository;

$currentUser = $auth->requireAuth();
$templates = new TemplateWorkbookRepository($pdo);

$error = '';
$flash = '';
$maxTemplates = max(1, (int) ($config['max_template_workbooks'] ?? TemplateWorkbookRepository::MAX_TEMPLATES));
$templateDir = (string) ($config['template_upload_dir'] ?? ($config['upload_dir'] . DIRECTORY_SEPARATOR . 'templates'));
$maxWorkbookBytes = (int) ($config['max_upload_bytes'] ?? 5 * 1024 * 1024);
$maxPromptBytes = (int) ($config['max_template_prompt_bytes'] ?? 512 * 1024);
$allowedPromptExtensions = $config['allowed_prompt_extensions'] ?? ['txt', 'md', 'prompt'];

$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes / (1024 * 1024), 1) . ' MB';
};

$ensureTemplateDir = static function () use ($templateDir): void {
    if (!is_dir($templateDir) && !mkdir($templateDir, 0755, true) && !is_dir($templateDir)) {
        throw new RuntimeException('Unable to prepare the template upload directory.');
    }
};

$safeBasename = static function (string $name, string $fallback): string {
    $base = basename(str_replace(["\0", '\\'], '', $name));
    $base = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $base) ?? $fallback;
    $base = trim($base, ' ._');

    return $base !== '' ? $base : $fallback;
};

$storeUpload = static function (
    array $file,
    string $extension,
    int $maxBytes,
    string $label
) use ($templateDir, $ensureTemplateDir, $safeBasename): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $message = match ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "The {$label} exceeds the allowed size.",
            UPLOAD_ERR_NO_FILE => "Please choose a {$label} to upload.",
            default => "The {$label} upload failed. Please try again.",
        };
        throw new RuntimeException($message);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException("The {$label} is empty.");
    }
    if ($size > $maxBytes) {
        throw new RuntimeException("The {$label} exceeds the size limit.");
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException("Invalid {$label} upload.");
    }

    $original = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext !== strtolower($extension)) {
        throw new RuntimeException("The {$label} must be a .{$extension} file.");
    }

    $ensureTemplateDir();
    $storedName = bin2hex(random_bytes(16)) . '.' . strtolower($extension);
    $destination = $templateDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException("Unable to store the {$label}.");
    }

    return [
        'path' => $destination,
        'filename' => $safeBasename($original, 'template.' . strtolower($extension)),
        'size' => $size,
    ];
};

$unlinkQuiet = static function (string $path): void {
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
};

$downloadAction = strtolower(trim((string) ($_GET['download'] ?? '')));
if ($downloadAction === 'workbook' || $downloadAction === 'prompt') {
    $id = (int) ($_GET['id'] ?? 0);
    $row = $templates->find($id);
    if ($row === null) {
        http_response_code(404);
        echo 'Template not found.';
        exit;
    }

    if ($downloadAction === 'workbook') {
        $path = $row['workbook_path'];
        $filename = $row['workbook_filename'] !== '' ? $row['workbook_filename'] : 'template.xlsx';
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } else {
        if ($row['prompt_path'] === '' || !is_file($row['prompt_path'])) {
            http_response_code(404);
            echo 'AI prompt not found for this template.';
            exit;
        }
        $path = $row['prompt_path'];
        $filename = $row['prompt_filename'] !== '' ? $row['prompt_filename'] : 'ai-prompt.txt';
        $mime = 'text/plain; charset=UTF-8';
    }

    if (!is_file($path)) {
        http_response_code(404);
        echo 'File missing from storage.';
        exit;
    }

    $safeName = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $filename) ?: 'download';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'upload_template') {
            if ($templates->count() >= $maxTemplates) {
                throw new RuntimeException('You can store up to ' . $maxTemplates . ' template workbooks. Delete one before uploading another.');
            }

            $name = trim((string) ($_POST['template_name'] ?? ''));
            if ($name === '') {
                $fallbackName = (string) ($_FILES['workbook_file']['name'] ?? '');
                $name = pathinfo($fallbackName, PATHINFO_FILENAME);
            }
            if ($name === '') {
                throw new RuntimeException('Enter a template name.');
            }

            if (!isset($_FILES['workbook_file']) || !is_array($_FILES['workbook_file'])) {
                throw new RuntimeException('Choose an Excel workbook (.xlsx) to upload.');
            }

            $workbook = $storeUpload($_FILES['workbook_file'], 'xlsx', $maxWorkbookBytes, 'workbook');

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($workbook['path']);
            $allowedMimes = $config['allowed_mime_types'] ?? [];
            if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
                $unlinkQuiet($workbook['path']);
                throw new RuntimeException('The uploaded file is not a valid Excel workbook.');
            }

            $promptStored = [
                'path' => '',
                'filename' => '',
                'size' => 0,
            ];
            $promptText = trim((string) ($_POST['ai_prompt_text'] ?? ''));
            $hasPromptFile = isset($_FILES['ai_prompt_file'])
                && is_array($_FILES['ai_prompt_file'])
                && (int) ($_FILES['ai_prompt_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

            try {
                if ($hasPromptFile) {
                    $promptFile = $_FILES['ai_prompt_file'];
                    $promptExt = strtolower(pathinfo((string) ($promptFile['name'] ?? ''), PATHINFO_EXTENSION));
                    if (!in_array($promptExt, $allowedPromptExtensions, true)) {
                        throw new RuntimeException('AI prompt files must be .txt, .md, or .prompt.');
                    }
                    $promptStored = $storeUpload($promptFile, $promptExt, $maxPromptBytes, 'AI prompt');
                } elseif ($promptText !== '') {
                    if (strlen($promptText) > $maxPromptBytes) {
                        throw new RuntimeException('The AI prompt text exceeds the size limit.');
                    }
                    $ensureTemplateDir();
                    $storedName = bin2hex(random_bytes(16)) . '.txt';
                    $destination = $templateDir . DIRECTORY_SEPARATOR . $storedName;
                    if (file_put_contents($destination, $promptText) === false) {
                        throw new RuntimeException('Unable to store the AI prompt.');
                    }
                    $promptStored = [
                        'path' => $destination,
                        'filename' => 'ai-prompt.txt',
                        'size' => strlen($promptText),
                    ];
                }

                $templates->create([
                    'name' => $name,
                    'workbook_path' => $workbook['path'],
                    'workbook_filename' => $workbook['filename'],
                    'workbook_size' => $workbook['size'],
                    'prompt_path' => $promptStored['path'],
                    'prompt_filename' => $promptStored['filename'],
                    'prompt_size' => $promptStored['size'],
                    'uploaded_by_user_id' => (int) ($currentUser['id'] ?? 0),
                    'uploaded_by_username' => (string) ($currentUser['username'] ?? ''),
                    'uploaded_by_display_name' => (string) (($currentUser['display_name'] ?? '') !== ''
                        ? $currentUser['display_name']
                        : ($currentUser['username'] ?? '')),
                ]);
            } catch (Throwable $exception) {
                $unlinkQuiet($workbook['path']);
                $unlinkQuiet($promptStored['path']);
                throw $exception;
            }

            $flash = 'Template workbook saved. You can download it anytime from this list.';
        } elseif ($action === 'rename_template' || $action === 'update_template') {
            $id = (int) ($_POST['template_id'] ?? 0);
            $existing = $templates->find($id);
            if ($existing === null) {
                throw new RuntimeException('Template not found.');
            }

            $name = trim((string) ($_POST['template_name'] ?? ''));
            if ($name === '') {
                $name = $existing['name'];
            }

            $updatePayload = ['name' => $name];
            $newWorkbookPath = '';
            $newPromptPath = '';
            $oldWorkbookPath = '';
            $oldPromptPath = '';
            $clearPrompt = !empty($_POST['clear_prompt']);

            try {
                $hasWorkbookFile = isset($_FILES['workbook_file'])
                    && is_array($_FILES['workbook_file'])
                    && (int) ($_FILES['workbook_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

                if ($hasWorkbookFile) {
                    $workbook = $storeUpload($_FILES['workbook_file'], 'xlsx', $maxWorkbookBytes, 'workbook');
                    $newWorkbookPath = $workbook['path'];
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = (string) $finfo->file($workbook['path']);
                    $allowedMimes = $config['allowed_mime_types'] ?? [];
                    if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
                        throw new RuntimeException('The uploaded file is not a valid Excel workbook.');
                    }
                    $updatePayload['workbook_path'] = $workbook['path'];
                    $updatePayload['workbook_filename'] = $workbook['filename'];
                    $updatePayload['workbook_size'] = $workbook['size'];
                    $oldWorkbookPath = $existing['workbook_path'];
                }

                $hasPromptFile = isset($_FILES['ai_prompt_file'])
                    && is_array($_FILES['ai_prompt_file'])
                    && (int) ($_FILES['ai_prompt_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                $promptText = trim((string) ($_POST['ai_prompt_text'] ?? ''));

                if ($clearPrompt && !$hasPromptFile && $promptText === '') {
                    $updatePayload['clear_prompt'] = true;
                    $oldPromptPath = $existing['prompt_path'];
                } elseif ($hasPromptFile) {
                    $promptFile = $_FILES['ai_prompt_file'];
                    $promptExt = strtolower(pathinfo((string) ($promptFile['name'] ?? ''), PATHINFO_EXTENSION));
                    if (!in_array($promptExt, $allowedPromptExtensions, true)) {
                        throw new RuntimeException('AI prompt files must be .txt, .md, or .prompt.');
                    }
                    $promptStored = $storeUpload($promptFile, $promptExt, $maxPromptBytes, 'AI prompt');
                    $newPromptPath = $promptStored['path'];
                    $updatePayload['prompt_path'] = $promptStored['path'];
                    $updatePayload['prompt_filename'] = $promptStored['filename'];
                    $updatePayload['prompt_size'] = $promptStored['size'];
                    $oldPromptPath = $existing['prompt_path'];
                } elseif ($promptText !== '') {
                    if (strlen($promptText) > $maxPromptBytes) {
                        throw new RuntimeException('The AI prompt text exceeds the size limit.');
                    }
                    $ensureTemplateDir();
                    $storedName = bin2hex(random_bytes(16)) . '.txt';
                    $destination = $templateDir . DIRECTORY_SEPARATOR . $storedName;
                    if (file_put_contents($destination, $promptText) === false) {
                        throw new RuntimeException('Unable to store the AI prompt.');
                    }
                    $newPromptPath = $destination;
                    $updatePayload['prompt_path'] = $destination;
                    $updatePayload['prompt_filename'] = 'ai-prompt.txt';
                    $updatePayload['prompt_size'] = strlen($promptText);
                    $oldPromptPath = $existing['prompt_path'];
                }

                $templates->update($id, $updatePayload);

                if ($oldWorkbookPath !== '' && isset($updatePayload['workbook_path']) && $oldWorkbookPath !== $updatePayload['workbook_path']) {
                    $unlinkQuiet($oldWorkbookPath);
                }
                if ($oldPromptPath !== '') {
                    $replacedPrompt = isset($updatePayload['prompt_path']) && $oldPromptPath !== $updatePayload['prompt_path'];
                    $clearedPrompt = !empty($updatePayload['clear_prompt']);
                    if ($replacedPrompt || $clearedPrompt) {
                        $unlinkQuiet($oldPromptPath);
                    }
                }
            } catch (Throwable $exception) {
                $unlinkQuiet($newWorkbookPath);
                $unlinkQuiet($newPromptPath);
                throw $exception;
            }

            $flash = 'Template updated.';
        } elseif ($action === 'delete_template') {
            $id = (int) ($_POST['template_id'] ?? 0);
            $deleted = $templates->delete($id);
            if ($deleted === null) {
                throw new RuntimeException('Template not found.');
            }
            $unlinkQuiet($deleted['workbook_path']);
            $unlinkQuiet($deleted['prompt_path']);
            $flash = 'Template removed.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$templateRows = $templates->listAll();
$templateCount = count($templateRows);
$slotsLeft = max(0, $maxTemplates - $templateCount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Template library · <?= e($branding->documentTitle()) ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="shell upload-page template-library-page">
        <header class="topbar topbar-uplift">
            <a class="brand brand-link" href="index.php#find-projects" title="Find projects by name">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1>📚 Template library</h1>
                </div>
            </a>
            <div class="topbar-actions">
                <a class="button ghost home-link" href="index.php#find-projects">🔎 Find projects</a>
                <a class="button ghost home-link" href="index.php#upload">📤 Upload assessment</a>
                <a class="button ghost home-link is-active" href="templates.php" aria-current="page">📚 Templates</a>
                <?php require __DIR__ . '/includes/updates-nav.php'; ?>
                <?php require __DIR__ . '/includes/theme-controls.php'; ?>
                <div class="updated template-count-chip"><?= (int) $templateCount ?> / <?= (int) $maxTemplates ?> templates</div>
            </div>
        </header>

        <main>
            <section class="hero hero-compact template-hero">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">📦 Blank workbooks &amp; 🤖 AI prompts</div>
                            <h2>Keep up to <?= (int) $maxTemplates ?> <em>downloadable</em> templates here</h2>
                            <p>Drop an Excel workbook and its AI prompt so anyone signed in can download them later — no hunting shared drives.</p>
                        </div>
                        <?php require __DIR__ . '/includes/hero-medallion.php'; renderHeroMedallion((int) $templateCount, 'templates'); ?>
                    </div>
                </div>
            </section>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error">⚠️ <?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($flash !== ''): ?>
                <div class="alert alert-success">✅ <?= e($flash) ?></div>
            <?php endif; ?>

            <nav class="home-section-tabs template-section-tabs" aria-label="Home sections">
                <a href="index.php#find-projects"><span class="settings-emoji" aria-hidden="true">🔎</span> Find projects</a>
                <a href="index.php#upload"><span class="settings-emoji" aria-hidden="true">📤</span> Upload assessment</a>
                <a class="is-active" href="templates.php" aria-current="page"><span class="settings-emoji" aria-hidden="true">📚</span> Template library</a>
            </nav>

            <div class="template-stats" aria-label="Library capacity">
                <div class="template-stat template-stat-stored">
                    <span class="template-stat-emoji" aria-hidden="true">📚</span>
                    <span class="template-stat-label">Stored</span>
                    <strong><?= (int) $templateCount ?></strong>
                </div>
                <div class="template-stat template-stat-slots<?= $slotsLeft === 0 ? ' is-full' : '' ?>">
                    <span class="template-stat-emoji" aria-hidden="true"><?= $slotsLeft === 0 ? '🚫' : '✨' ?></span>
                    <span class="template-stat-label">Slots left</span>
                    <strong><?= (int) $slotsLeft ?></strong>
                </div>
                <div class="template-stat template-stat-max">
                    <span class="template-stat-emoji" aria-hidden="true">🔟</span>
                    <span class="template-stat-label">Max</span>
                    <strong><?= (int) $maxTemplates ?></strong>
                </div>
            </div>

            <section class="upload-card template-card template-card-upload" id="template-upload">
                <div class="template-card-head">
                    <h2><span class="settings-emoji" aria-hidden="true">🚀</span> Upload template</h2>
                    <span class="template-pill template-pill-upload"><?= $slotsLeft > 0 ? 'Open for uploads' : 'Library full' ?></span>
                </div>
                <p>
                    <?= $slotsLeft > 0
                        ? 'You can add <strong>' . (int) $slotsLeft . '</strong> more template' . ($slotsLeft === 1 ? '' : 's') . ' (max ' . (int) $maxTemplates . ').'
                        : 'Library is full (' . (int) $maxTemplates . '). Delete a template below before uploading another.' ?>
                    Workbooks up to <?= e($formatBytes($maxWorkbookBytes)) ?>; AI prompts (.txt / .md / .prompt) up to <?= e($formatBytes($maxPromptBytes)) ?>.
                    Dropping a workbook auto-saves it after a moment (drop the AI prompt first or right after if you have one).
                </p>

                <form
                    method="post"
                    enctype="multipart/form-data"
                    class="upload-form template-upload-form"
                    id="template-upload-form"
                    <?= $slotsLeft === 0 ? 'data-disabled="1"' : '' ?>
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_template">

                    <fieldset class="settings-fieldset settings-tone-teal template-fieldset">
                        <legend><span class="settings-emoji" aria-hidden="true">🏷️</span> Name</legend>
                        <label class="file-input template-name-field">
                            <span><span class="settings-emoji" aria-hidden="true">✨</span> Template name</span>
                            <input
                                type="text"
                                name="template_name"
                                id="template-name"
                                maxlength="160"
                                placeholder="e.g. Adaptive Architecture blank + AI prompt"
                                <?= $slotsLeft === 0 ? 'disabled' : '' ?>
                            >
                        </label>
                    </fieldset>

                    <div class="template-drop-grid">
                        <div
                            class="file-drop template-file-drop template-drop-workbook"
                            id="workbook-drop"
                            data-accept=".xlsx"
                            data-label="workbook"
                        >
                            <span class="file-drop-caption"><span class="settings-emoji" aria-hidden="true">📗</span> Excel workbook (.xlsx)</span>
                            <div class="file-drop-zone" id="workbook-drop-zone">
                                <input
                                    type="file"
                                    name="workbook_file"
                                    id="workbook-file"
                                    class="file-drop-input"
                                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    aria-label="Excel workbook"
                                    <?= $slotsLeft === 0 ? 'disabled' : 'required' ?>
                                >
                                <div class="file-drop-copy">
                                    <span class="template-drop-icon" aria-hidden="true">📊</span>
                                    <strong>Drop workbook here</strong>
                                    <em>or click to browse — .xlsx only</em>
                                </div>
                            </div>
                            <p class="file-drop-status" id="workbook-drop-status" hidden></p>
                            <ul class="file-drop-list" id="workbook-drop-list" hidden></ul>
                        </div>

                        <div
                            class="file-drop template-file-drop template-drop-prompt"
                            id="prompt-drop"
                            data-accept=".txt,.md,.prompt"
                            data-label="prompt"
                        >
                            <span class="file-drop-caption"><span class="settings-emoji" aria-hidden="true">🤖</span> AI prompt (.txt / .md / .prompt)</span>
                            <div class="file-drop-zone" id="prompt-drop-zone">
                                <input
                                    type="file"
                                    name="ai_prompt_file"
                                    id="ai-prompt-file"
                                    class="file-drop-input"
                                    accept=".txt,.md,.prompt,text/plain,text/markdown"
                                    aria-label="AI prompt file"
                                    <?= $slotsLeft === 0 ? 'disabled' : '' ?>
                                >
                                <div class="file-drop-copy">
                                    <span class="template-drop-icon" aria-hidden="true">💬</span>
                                    <strong>Drop AI prompt here</strong>
                                    <em>optional — or paste text below</em>
                                </div>
                            </div>
                            <p class="file-drop-status" id="prompt-drop-status" hidden></p>
                            <ul class="file-drop-list" id="prompt-drop-list" hidden></ul>
                        </div>
                    </div>

                    <fieldset class="settings-fieldset settings-tone-violet template-fieldset">
                        <legend><span class="settings-emoji" aria-hidden="true">📝</span> Paste prompt</legend>
                        <label class="file-input template-prompt-text">
                            <span><span class="settings-emoji" aria-hidden="true">💡</span> Or paste AI prompt text</span>
                            <textarea
                                name="ai_prompt_text"
                                id="ai-prompt-text"
                                rows="5"
                                placeholder="Paste the AI prompt used with this workbook…"
                                <?= $slotsLeft === 0 ? 'disabled' : '' ?>
                            ></textarea>
                        </label>
                    </fieldset>

                    <button type="submit" class="button button-primary template-save-btn" <?= $slotsLeft === 0 ? 'disabled' : '' ?>>
                        <span class="settings-emoji" aria-hidden="true">💾</span> Save to template library
                    </button>
                </form>
            </section>

            <section class="upload-card table-card template-card template-card-list" id="template-list">
                <div class="template-card-head">
                    <h2><span class="settings-emoji" aria-hidden="true">🗂️</span> Stored templates</h2>
                    <span class="template-pill template-pill-list"><?= (int) $templateCount ?> saved</span>
                </div>
                <p>Download the blank workbook or AI prompt for any saved entry. Files stay in the app upload folder (not publicly browsable).</p>

                <?php if ($templateRows === []): ?>
                    <div class="template-empty">
                        <span class="template-empty-emoji" aria-hidden="true">📭</span>
                        <p class="empty-results">No templates uploaded yet. Drop a workbook above to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="project-table template-table">
                            <thead>
                                <tr>
                                    <th scope="col">🏷️ Name</th>
                                    <th scope="col">📗 Workbook</th>
                                    <th scope="col">🤖 AI prompt</th>
                                    <th scope="col">📅 Uploaded</th>
                                    <th scope="col">👤 By</th>
                                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($templateRows as $index => $row): ?>
                                    <?php
                                    $uploader = $row['uploaded_by_display_name'] !== ''
                                        ? $row['uploaded_by_display_name']
                                        : ($row['uploaded_by_username'] !== '' ? $row['uploaded_by_username'] : '—');
                                    $hasPrompt = $row['prompt_path'] !== '' && is_file($row['prompt_path']);
                                    $toneClass = ['is-tone-teal', 'is-tone-sky', 'is-tone-violet', 'is-tone-mint', 'is-tone-amber'][$index % 5];
                                    ?>
                                    <tr class="template-row <?= $toneClass ?>" data-template-row="<?= (int) $row['id'] ?>">
                                        <td class="project-table-name">
                                            <div class="template-name-display">
                                                <strong><?= e($row['name']) ?></strong>
                                                <button
                                                    type="button"
                                                    class="template-rename-btn"
                                                    data-template-edit-open="<?= (int) $row['id'] ?>"
                                                    title="Edit template"
                                                    aria-label="Edit template"
                                                    aria-expanded="false"
                                                    aria-controls="template-edit-<?= (int) $row['id'] ?>"
                                                >
                                                    <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
                                                        <path d="M12 20h9"></path>
                                                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="template-file-meta">
                                                <span>📗 <?= e($row['workbook_filename']) ?></span>
                                                <small><?= e($formatBytes($row['workbook_size'])) ?></small>
                                            </div>
                                            <a class="template-dl-link template-dl-workbook" href="templates.php?download=workbook&amp;id=<?= (int) $row['id'] ?>">Download workbook</a>
                                        </td>
                                        <td>
                                            <?php if ($hasPrompt): ?>
                                                <div class="template-file-meta">
                                                    <span>🤖 <?= e($row['prompt_filename'] !== '' ? $row['prompt_filename'] : 'ai-prompt.txt') ?></span>
                                                    <small><?= e($formatBytes($row['prompt_size'])) ?></small>
                                                </div>
                                                <a class="template-dl-link template-dl-prompt" href="templates.php?download=prompt&amp;id=<?= (int) $row['id'] ?>">Download prompt</a>
                                            <?php else: ?>
                                                <span class="template-missing">➖ No AI prompt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="project-table-date"><?= e($row['uploaded_at']) ?></td>
                                        <td><?= e($uploader) ?></td>
                                        <td class="project-table-actions">
                                            <form method="post" class="inline-form template-delete-form" onsubmit="return confirm('Remove this template from the library?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_template">
                                                <input type="hidden" name="template_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="project-delete-btn template-delete-icon" title="Delete template" aria-label="Delete template">
                                                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                        <polyline points="3 6 5 6 21 6"></polyline>
                                                        <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"></path>
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr class="template-edit-row" id="template-edit-<?= (int) $row['id'] ?>" hidden>
                                        <td colspan="6">
                                            <form
                                                method="post"
                                                enctype="multipart/form-data"
                                                class="template-edit-form"
                                                data-template-edit-form="<?= (int) $row['id'] ?>"
                                            >
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_template">
                                                <input type="hidden" name="template_id" value="<?= (int) $row['id'] ?>">

                                                <div class="template-edit-head">
                                                    <div class="template-edit-title">
                                                        <span class="template-edit-badge" aria-hidden="true">✏️</span>
                                                        <div>
                                                            <strong>Edit template</strong>
                                                            <span class="template-edit-hint">Leave a file blank to keep the current one · only changed fields are saved</span>
                                                        </div>
                                                    </div>
                                                    <span class="template-pill template-pill-edit">Updating</span>
                                                </div>

                                                <div class="template-edit-layout">
                                                    <fieldset class="settings-fieldset settings-tone-sky template-edit-panel template-edit-panel-name">
                                                        <legend><span class="settings-emoji" aria-hidden="true">🏷️</span> Name</legend>
                                                        <p class="settings-hint">Shown in the library list and download labels.</p>
                                                        <label class="file-input template-edit-name">
                                                            <span><span class="settings-emoji" aria-hidden="true">✨</span> Template name</span>
                                                            <input
                                                                type="text"
                                                                name="template_name"
                                                                value="<?= e($row['name']) ?>"
                                                                maxlength="160"
                                                                required
                                                            >
                                                        </label>
                                                    </fieldset>

                                                    <fieldset class="settings-fieldset settings-tone-mint template-edit-panel template-edit-panel-workbook">
                                                        <legend><span class="settings-emoji" aria-hidden="true">📗</span> Workbook</legend>
                                                        <p class="settings-hint">Upload a new .xlsx only if you want to replace the blank template.</p>
                                                        <div class="template-edit-current-chip template-edit-current-chip-workbook">
                                                            <span class="settings-emoji" aria-hidden="true">📎</span>
                                                            <div>
                                                                <em>Current workbook</em>
                                                                <strong><?= e($row['workbook_filename']) ?></strong>
                                                                <small><?= e($formatBytes($row['workbook_size'])) ?></small>
                                                            </div>
                                                        </div>
                                                        <label class="file-input template-edit-workbook">
                                                            <span><span class="settings-emoji" aria-hidden="true">📤</span> Replace with new .xlsx</span>
                                                            <input
                                                                type="file"
                                                                name="workbook_file"
                                                                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                                            >
                                                        </label>
                                                    </fieldset>

                                                    <fieldset class="settings-fieldset settings-tone-violet template-edit-panel template-edit-panel-prompt">
                                                        <legend><span class="settings-emoji" aria-hidden="true">🤖</span> AI prompt</legend>
                                                        <p class="settings-hint">Replace with a file, paste new text, or clear the existing prompt.</p>
                                                        <div class="template-edit-current-chip template-edit-current-chip-prompt">
                                                            <span class="settings-emoji" aria-hidden="true"><?= $hasPrompt ? '💬' : '💤' ?></span>
                                                            <div>
                                                                <em>Current prompt</em>
                                                                <?php if ($hasPrompt): ?>
                                                                    <strong><?= e($row['prompt_filename'] !== '' ? $row['prompt_filename'] : 'ai-prompt.txt') ?></strong>
                                                                    <small><?= e($formatBytes($row['prompt_size'])) ?></small>
                                                                <?php else: ?>
                                                                    <strong>None uploaded yet</strong>
                                                                    <small>Optional — add one below</small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="template-edit-prompt-split">
                                                            <label class="file-input template-edit-prompt-file">
                                                                <span><span class="settings-emoji" aria-hidden="true">📄</span> Upload .txt / .md / .prompt</span>
                                                                <input
                                                                    type="file"
                                                                    name="ai_prompt_file"
                                                                    accept=".txt,.md,.prompt,text/plain,text/markdown"
                                                                >
                                                            </label>
                                                            <label class="file-input template-edit-prompt-text">
                                                                <span><span class="settings-emoji" aria-hidden="true">📝</span> Or paste new text</span>
                                                                <textarea name="ai_prompt_text" rows="4" placeholder="Paste replacement prompt text…"></textarea>
                                                            </label>
                                                        </div>
                                                        <?php if ($hasPrompt): ?>
                                                            <label class="template-edit-clear">
                                                                <input type="checkbox" name="clear_prompt" value="1">
                                                                <span><span class="settings-emoji" aria-hidden="true">🗑️</span> Remove the current AI prompt (without uploading a new one)</span>
                                                            </label>
                                                        <?php endif; ?>
                                                    </fieldset>
                                                </div>

                                                <div class="template-edit-actions">
                                                    <button type="submit" class="button button-primary template-edit-save">
                                                        <span class="settings-emoji" aria-hidden="true">💾</span> Save changes
                                                    </button>
                                                    <button type="button" class="button ghost template-edit-cancel-btn" data-template-edit-cancel="<?= (int) $row['id'] ?>">
                                                        <span class="settings-emoji" aria-hidden="true">✕</span> Cancel
                                                    </button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="assets/js/template-upload.js?v=<?= filemtime(__DIR__ . '/assets/js/template-upload.js') ?>"></script>
</body>
</html>
