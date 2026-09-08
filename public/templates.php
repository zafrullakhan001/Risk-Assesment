<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\ProjectImageConverter;
use RiskAssessment\Repositories\TemplateImagesRepository;
use RiskAssessment\Repositories\TemplateWorkbookRepository;

$currentUser = $auth->requireAuth();
\RiskAssessment\AppModules::instance()->require(\RiskAssessment\AppModules::RISK, $currentUser);
$templates = new TemplateWorkbookRepository($pdo);
$templateImages = new TemplateImagesRepository($pdo);
$imageConverter = new ProjectImageConverter();

$error = '';
$flash = '';
$maxTemplates = max(1, (int) ($config['max_template_workbooks'] ?? TemplateWorkbookRepository::MAX_TEMPLATES));
$maxGuideImages = TemplateImagesRepository::MAX_IMAGES;
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

/**
 * Apply ELK layout and flowchart direction to Mermaid source.
 */
$applyMermaidOptions = static function (string $source, string $layout, string $direction): string {
    $source = trim($source);
    if ($source === '') {
        return '';
    }

    $layout = strtolower(trim($layout)) === 'elk' ? 'elk' : 'default';
    $direction = strtoupper(trim($direction));
    if (!in_array($direction, ['TB', 'TD', 'BT', 'LR', 'RL'], true)) {
        $direction = 'TB';
    }

    // Strip prior ELK YAML front-matter / init directives we manage.
    $source = preg_replace('/\A---\s*\nconfig:\s*\n(?:[ \t]+.+\n)*---\s*\n?/u', '', $source) ?? $source;
    $source = preg_replace('/\A%%\{init:[\s\S]*?\}%%\s*/u', '', $source) ?? $source;
    $source = trim($source);

    $keyword = $layout === 'elk' ? 'flowchart-elk' : 'flowchart';
    if (preg_match('/^(flowchart(?:-elk)?|graph)\s+(TB|TD|BT|LR|RL)\b/im', $source) === 1) {
        $source = preg_replace(
            '/^(flowchart(?:-elk)?|graph)\s+(TB|TD|BT|LR|RL)\b/im',
            $keyword . ' ' . $direction,
            $source,
            1
        ) ?? $source;
    } elseif (preg_match('/^(flowchart(?:-elk)?|graph)\b/im', $source) === 1) {
        $source = preg_replace(
            '/^(flowchart(?:-elk)?|graph)\b/im',
            $keyword . ' ' . $direction,
            $source,
            1
        ) ?? $source;
    } else {
        $source = $keyword . ' ' . $direction . "\n" . $source;
    }

    if ($layout === 'elk') {
        $source = "---\nconfig:\n  layout: elk\n---\n" . $source;
    }

    return $source;
};

/**
 * Normalize multi-file upload field into a list of file arrays.
 *
 * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
 */
$collectUploadedFiles = static function (string $field): array {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }

    $bucket = $_FILES[$field];
    $files = [];

    if (is_array($bucket['name'] ?? null)) {
        $count = count($bucket['name']);
        for ($i = 0; $i < $count; $i++) {
            $error = (int) ($bucket['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => (string) ($bucket['name'][$i] ?? ''),
                'type' => (string) ($bucket['type'][$i] ?? ''),
                'tmp_name' => (string) ($bucket['tmp_name'][$i] ?? ''),
                'error' => $error,
                'size' => (int) ($bucket['size'][$i] ?? 0),
            ];
        }
    } else {
        $error = (int) ($bucket['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_NO_FILE) {
            $files[] = [
                'name' => (string) ($bucket['name'] ?? ''),
                'type' => (string) ($bucket['type'] ?? ''),
                'tmp_name' => (string) ($bucket['tmp_name'] ?? ''),
                'error' => $error,
                'size' => (int) ($bucket['size'] ?? 0),
            ];
        }
    }

    return $files;
};

$viewAction = strtolower(trim((string) ($_GET['view'] ?? '')));
if ($viewAction === 'image') {
    $imageId = (int) ($_GET['id'] ?? 0);
    $picture = $templateImages->findForView($imageId);
    if ($picture === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Guide image not found.';
        exit;
    }

    header('Content-Type: ' . $picture['mime_type']);
    header('Content-Length: ' . (string) strlen($picture['bytes']));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $picture['bytes'];
    exit;
}

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
            $newTemplateId = 0;

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

                $newTemplateId = $templates->create([
                    'name' => $name,
                    'workbook_path' => $workbook['path'],
                    'workbook_filename' => $workbook['filename'],
                    'workbook_size' => $workbook['size'],
                    'prompt_path' => $promptStored['path'],
                    'prompt_filename' => $promptStored['filename'],
                    'prompt_size' => $promptStored['size'],
                    'mermaid_title' => trim((string) ($_POST['mermaid_title'] ?? '')),
                    'mermaid_source' => $applyMermaidOptions(
                        trim((string) ($_POST['mermaid_source'] ?? '')),
                        (string) ($_POST['mermaid_layout'] ?? 'default'),
                        (string) ($_POST['mermaid_direction'] ?? 'TB')
                    ),
                    'uploaded_by_user_id' => (int) ($currentUser['id'] ?? 0),
                    'uploaded_by_username' => (string) ($currentUser['username'] ?? ''),
                    'uploaded_by_display_name' => (string) (($currentUser['display_name'] ?? '') !== ''
                        ? $currentUser['display_name']
                        : ($currentUser['username'] ?? '')),
                ]);

                $imageFiles = $collectUploadedFiles('template_images');
                if (count($imageFiles) > $maxGuideImages) {
                    throw new RuntimeException('You can upload up to ' . $maxGuideImages . ' guide images per template.');
                }
                foreach ($imageFiles as $imageFile) {
                    $converted = $imageConverter->fromUploadedFile($imageFile);
                    $templateImages->addForTemplate($newTemplateId, [
                        'title' => pathinfo($converted['original_filename'], PATHINFO_FILENAME) ?: 'Guide image',
                        'mime_type' => $converted['mime_type'],
                        'base64' => $converted['base64'],
                        'original_filename' => $converted['original_filename'],
                    ]);
                }
            } catch (Throwable $exception) {
                if ($newTemplateId > 0) {
                    $templateImages->deleteAllForTemplate($newTemplateId);
                    $templates->delete($newTemplateId);
                }
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

                $clearMermaid = !empty($_POST['clear_mermaid']);
                $mermaidTitle = trim((string) ($_POST['mermaid_title'] ?? ''));
                $mermaidSource = trim((string) ($_POST['mermaid_source'] ?? ''));
                if ($clearMermaid) {
                    $updatePayload['clear_mermaid'] = true;
                } elseif (array_key_exists('mermaid_title', $_POST) || array_key_exists('mermaid_source', $_POST)) {
                    $updatePayload['mermaid_title'] = $mermaidTitle;
                    $updatePayload['mermaid_source'] = $applyMermaidOptions(
                        $mermaidSource,
                        (string) ($_POST['mermaid_layout'] ?? 'default'),
                        (string) ($_POST['mermaid_direction'] ?? 'TB')
                    );
                }

                $templates->update($id, $updatePayload);

                $deleteImageIds = $_POST['delete_image_ids'] ?? [];
                if (is_array($deleteImageIds)) {
                    foreach ($deleteImageIds as $deleteImageId) {
                        $templateImages->deleteOne((int) $deleteImageId, $id);
                    }
                }

                $imageFiles = $collectUploadedFiles('template_images');
                if ($imageFiles !== []) {
                    $remainingSlots = $maxGuideImages - $templateImages->countForTemplate($id);
                    if (count($imageFiles) > $remainingSlots) {
                        throw new RuntimeException(
                            'This template can hold ' . $maxGuideImages . ' guide images. '
                            . 'You can add ' . max(0, $remainingSlots) . ' more after removing some.'
                        );
                    }
                    foreach ($imageFiles as $imageFile) {
                        $converted = $imageConverter->fromUploadedFile($imageFile);
                        $templateImages->addForTemplate($id, [
                            'title' => pathinfo($converted['original_filename'], PATHINFO_FILENAME) ?: 'Guide image',
                            'mime_type' => $converted['mime_type'],
                            'base64' => $converted['base64'],
                            'original_filename' => $converted['original_filename'],
                        ]);
                    }
                }

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
            $existing = $templates->find($id);
            if ($existing === null) {
                throw new RuntimeException('Template not found.');
            }
            $templateImages->deleteAllForTemplate($id);
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
$imagesByTemplate = [];
foreach ($templateRows as $templateRow) {
    $imagesByTemplate[(int) $templateRow['id']] = $templateImages->listForTemplate((int) $templateRow['id']);
}

$parseMermaidOptions = static function (string $source): array {
    $layout = 'default';
    $direction = 'TB';
    if (
        preg_match('/^\s*flowchart-elk\b/im', $source) === 1
        || preg_match('/^\s*layout:\s*elk\b/im', $source) === 1
        || preg_match('/defaultRenderer[\'"]?\s*:\s*[\'"]elk[\'"]/i', $source) === 1
    ) {
        $layout = 'elk';
    }
    if (preg_match('/^(?:flowchart(?:-elk)?|graph)\s+(TB|TD|BT|LR|RL)\b/im', $source, $matches) === 1) {
        $direction = strtoupper((string) $matches[1]);
    }

    return [
        'layout' => $layout,
        'direction' => $direction,
    ];
};

$renderMermaidOptionControls = static function (
    string $layout,
    string $direction,
    string $layoutName = 'mermaid_layout',
    string $directionName = 'mermaid_direction',
    string $prefix = '',
    bool $disabled = false
): string {
    $disabledAttr = $disabled ? ' disabled' : '';
    $idLayout = $prefix !== '' ? $prefix . '-layout' : '';
    $idDirection = $prefix !== '' ? $prefix . '-direction' : '';
    $layouts = [
        'default' => 'Default',
        'elk' => 'ELK',
    ];
    $directions = [
        'TB' => 'TB',
        'TD' => 'TD',
        'BT' => 'BT',
        'LR' => 'LR',
        'RL' => 'RL',
    ];
    ob_start();
    ?>
    <div class="template-mermaid-options" data-mermaid-options>
        <div class="template-mermaid-option-block">
            <span class="template-mermaid-option-label"><span class="settings-emoji" aria-hidden="true">✨</span> Layout style</span>
            <input
                type="hidden"
                name="<?= e($layoutName) ?>"
                value="<?= e($layout) ?>"
                data-mermaid-layout
                <?= $idLayout !== '' ? 'id="' . e($idLayout) . '"' : '' ?>
                <?= $disabledAttr ?>
            >
            <div class="template-mermaid-btn-group" role="group" aria-label="Layout style">
                <?php foreach ($layouts as $value => $label): ?>
                    <button
                        type="button"
                        class="template-mermaid-opt-btn<?= $layout === $value ? ' is-active' : '' ?>"
                        data-mermaid-layout-btn="<?= e($value) ?>"
                        <?= $disabledAttr ?>
                    ><?= e($label) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="template-mermaid-option-block">
            <span class="template-mermaid-option-label"><span class="settings-emoji" aria-hidden="true">🧭</span> Flow direction</span>
            <input
                type="hidden"
                name="<?= e($directionName) ?>"
                value="<?= e($direction) ?>"
                data-mermaid-direction
                <?= $idDirection !== '' ? 'id="' . e($idDirection) . '"' : '' ?>
                <?= $disabledAttr ?>
            >
            <div class="template-mermaid-btn-group" role="group" aria-label="Flow direction">
                <?php foreach ($directions as $value => $label): ?>
                    <button
                        type="button"
                        class="template-mermaid-opt-btn<?= $direction === $value ? ' is-active' : '' ?>"
                        data-mermaid-direction-btn="<?= e($value) ?>"
                        title="<?= e($value) ?>"
                        <?= $disabledAttr ?>
                    ><?= e($label) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
};
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
                <?php require __DIR__ . '/includes/topbar-menu-start.php'; ?>
                <?php
                $menuApps = \RiskAssessment\AppModules::instance();
                $menuCanRisk = $menuApps->canAccess($currentUser, \RiskAssessment\AppModules::RISK);
                $menuCanSharePoint = $menuApps->canAccess($currentUser, \RiskAssessment\AppModules::SHAREPOINT);
                ?>
                <?php if ($menuCanRisk): ?>
                <a class="button ghost home-link" data-menu-group="risk" data-menu-tone="sky" href="index.php#find-projects" title="Search and open saved risk assessments by name, vendor, owner, and more"><span class="topbar-menu-emoji" aria-hidden="true">🔎</span>Find projects</a>
                <a class="button ghost home-link" data-menu-group="risk" data-menu-tone="mint" href="index.php#upload" title="Upload an Architecture Risk Assessment workbook (.xlsx) to generate a dashboard"><span class="topbar-menu-emoji" aria-hidden="true">📤</span>Upload assessment</a>
                <a class="button ghost home-link is-active" data-menu-group="risk" data-menu-tone="lavender" href="templates.php" aria-current="page" title="Browse and manage assessment workbook templates"><span class="topbar-menu-emoji" aria-hidden="true">📚</span>Templates</a>
                <?php endif; ?>
                <?php if ($menuCanSharePoint): ?>
                <a class="button ghost home-link" data-menu-group="sharepoint" data-menu-tone="peach" href="sharepoint.php" title="Browse SharePoint folders, sync projects, and search architecture work"><span class="topbar-menu-emoji" aria-hidden="true">📁</span>SharePoint</a>
                <?php require __DIR__ . '/includes/catalog-nav-link.php'; ?>
                <?php require __DIR__ . '/includes/owners-nav-link.php'; ?>
                <?php endif; ?>
                <?php require __DIR__ . '/includes/ticket-dossier-nav-link.php'; ?>

                <?php require __DIR__ . '/includes/updates-nav.php'; ?>
                <?php require __DIR__ . '/includes/topbar-menu-end.php'; ?>
                <div class="updated template-count-chip"><?= (int) $templateCount ?> / <?= (int) $maxTemplates ?> templates</div>
            </div>
        </header>

        <main>
            <section class="hero hero-compact template-hero">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">📦 Blank workbooks · 🤖 AI prompts · 🖼️ Guides</div>
                            <h2>Keep up to <?= (int) $maxTemplates ?> <em>downloadable</em> templates here</h2>
                            <p>Drop an Excel workbook and its AI prompt, plus optional guide images and a Mermaid diagram so anyone signed in can understand how to use it.</p>
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

            <?php $homeTab = 'templates'; require __DIR__ . '/includes/home-section-tabs.php'; ?>

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
                    Optional: up to <?= (int) $maxGuideImages ?> guide images (JPG/PNG, 2&nbsp;MB each) and one Mermaid diagram.
                    Dropping a workbook auto-saves it after a moment (add the AI prompt, images, and Mermaid first if you have them).
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

                    <fieldset class="settings-fieldset settings-tone-mint template-fieldset">
                        <legend><span class="settings-emoji" aria-hidden="true">🖼️</span> Guide images</legend>
                        <p class="settings-hint">Optional screenshots or diagrams that explain how to use this template (up to <?= (int) $maxGuideImages ?>, JPG/PNG, 2&nbsp;MB each).</p>
                        <label class="file-input template-guide-images">
                            <span><span class="settings-emoji" aria-hidden="true">📤</span> Upload images</span>
                            <input
                                type="file"
                                name="template_images[]"
                                id="template-images"
                                accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                                multiple
                                <?= $slotsLeft === 0 ? 'disabled' : '' ?>
                            >
                        </label>
                        <p class="file-drop-status" id="template-images-status" hidden></p>
                        <ul class="file-drop-list" id="template-images-list" hidden></ul>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-sky template-fieldset">
                        <legend><span class="settings-emoji" aria-hidden="true">🗺️</span> Mermaid diagram</legend>
                        <p class="settings-hint">Optional flowchart or architecture view shown in the template Guide popup. Choose ELK layout and flow direction, then paste Mermaid source.</p>
                        <label class="file-input template-mermaid-title">
                            <span><span class="settings-emoji" aria-hidden="true">🏷️</span> Diagram title</span>
                            <input
                                type="text"
                                name="mermaid_title"
                                id="mermaid-title"
                                maxlength="200"
                                placeholder="e.g. How to fill this workbook"
                                <?= $slotsLeft === 0 ? 'disabled' : '' ?>
                            >
                        </label>
                        <?= $renderMermaidOptionControls('default', 'TB', 'mermaid_layout', 'mermaid_direction', 'mermaid', $slotsLeft === 0) ?>
                        <label class="file-input template-mermaid-source">
                            <span><span class="settings-emoji" aria-hidden="true">📐</span> Mermaid source</span>
                            <textarea
                                name="mermaid_source"
                                id="mermaid-source"
                                rows="6"
                                data-mermaid-source-field
                                placeholder="flowchart TB&#10;  A[Download template] --> B[Fill workbook]&#10;  B --> C[Upload assessment]"
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
                <p>Download the blank workbook or AI prompt for any saved entry. Use <strong>Open guide</strong> for a popup with explanatory images and Mermaid diagrams. Files stay in the app upload folder (not publicly browsable).</p>

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
                                    <th scope="col" class="col-row-num">#</th>
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
                                    $templateNumber = $index + 1;
                                    $templateId = (int) $row['id'];
                                    $uploader = $row['uploaded_by_display_name'] !== ''
                                        ? $row['uploaded_by_display_name']
                                        : ($row['uploaded_by_username'] !== '' ? $row['uploaded_by_username'] : '—');
                                    $hasPrompt = $row['prompt_path'] !== '' && is_file($row['prompt_path']);
                                    $guideImages = $imagesByTemplate[$templateId] ?? [];
                                    $hasMermaid = trim((string) $row['mermaid_source']) !== '';
                                    $hasGuide = $guideImages !== [] || $hasMermaid;
                                    $toneClass = ['is-tone-teal', 'is-tone-sky', 'is-tone-violet', 'is-tone-mint', 'is-tone-amber'][$index % 5];
                                    ?>
                                    <tr class="template-row <?= $toneClass ?>" data-template-row="<?= $templateId ?>" data-row-number="<?= $templateNumber ?>">
                                        <td class="col-row-num">
                                            <span class="row-number" title="Template <?= $templateNumber ?>">#<?= $templateNumber ?></span>
                                        </td>
                                        <td class="project-table-name">
                                            <div class="template-name-display">
                                                <strong><?= e($row['name']) ?></strong>
                                                <button
                                                    type="button"
                                                    class="template-rename-btn"
                                                    data-template-edit-open="<?= $templateId ?>"
                                                    title="Edit template"
                                                    aria-label="Edit template"
                                                    aria-expanded="false"
                                                    aria-controls="template-edit-<?= $templateId ?>"
                                                >
                                                    <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
                                                        <path d="M12 20h9"></path>
                                                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                            <?php if ($hasGuide): ?>
                                                <button
                                                    type="button"
                                                    class="template-guide-toggle"
                                                    data-template-guide-open="<?= $templateId ?>"
                                                    aria-haspopup="dialog"
                                                    aria-expanded="false"
                                                    aria-controls="template-guide-<?= $templateId ?>"
                                                >
                                                    🗺️ Open guide
                                                    <?php if ($guideImages !== []): ?>
                                                        <span class="template-guide-count"><?= count($guideImages) ?> img</span>
                                                    <?php endif; ?>
                                                    <?php if ($hasMermaid): ?>
                                                        <span class="template-guide-count">diagram</span>
                                                    <?php endif; ?>
                                                </button>
                                            <?php else: ?>
                                                <span class="template-missing template-guide-missing">No guide yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="template-file-meta">
                                                <span>📗 <?= e($row['workbook_filename']) ?></span>
                                                <small><?= e($formatBytes($row['workbook_size'])) ?></small>
                                            </div>
                                            <a class="template-dl-link template-dl-workbook" href="templates.php?download=workbook&amp;id=<?= $templateId ?>">Download workbook</a>
                                        </td>
                                        <td>
                                            <?php if ($hasPrompt): ?>
                                                <div class="template-file-meta">
                                                    <span>🤖 <?= e($row['prompt_filename'] !== '' ? $row['prompt_filename'] : 'ai-prompt.txt') ?></span>
                                                    <small><?= e($formatBytes($row['prompt_size'])) ?></small>
                                                </div>
                                                <a class="template-dl-link template-dl-prompt" href="templates.php?download=prompt&amp;id=<?= $templateId ?>">Download prompt</a>
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
                                                <input type="hidden" name="template_id" value="<?= $templateId ?>">
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
                                    <tr class="template-edit-row" id="template-edit-<?= $templateId ?>" hidden>
                                        <td colspan="7">
                                            <form
                                                method="post"
                                                enctype="multipart/form-data"
                                                class="template-edit-form"
                                                data-template-edit-form="<?= $templateId ?>"
                                            >
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="update_template">
                                                <input type="hidden" name="template_id" value="<?= $templateId ?>">

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

                                                    <fieldset class="settings-fieldset settings-tone-mint template-edit-panel template-edit-panel-images">
                                                        <legend><span class="settings-emoji" aria-hidden="true">🖼️</span> Guide images</legend>
                                                        <p class="settings-hint">
                                                            <?= count($guideImages) ?>/<?= (int) $maxGuideImages ?> stored.
                                                            Check images to remove, or add more (JPG/PNG, 2&nbsp;MB each).
                                                        </p>
                                                        <?php if ($guideImages !== []): ?>
                                                            <ul class="template-edit-image-list">
                                                                <?php foreach ($guideImages as $image): ?>
                                                                    <li class="template-edit-image-item">
                                                                        <img
                                                                            src="<?= e($templateImages->viewUrl((int) $image['id'])) ?>"
                                                                            alt="<?= e($image['title'] !== '' ? $image['title'] : 'Guide image') ?>"
                                                                            loading="lazy"
                                                                        >
                                                                        <div>
                                                                            <strong><?= e($image['title'] !== '' ? $image['title'] : $image['original_filename']) ?></strong>
                                                                            <label class="template-edit-clear">
                                                                                <input type="checkbox" name="delete_image_ids[]" value="<?= (int) $image['id'] ?>">
                                                                                <span>Remove</span>
                                                                            </label>
                                                                        </div>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                        <?php if (count($guideImages) < $maxGuideImages): ?>
                                                            <label class="file-input">
                                                                <span><span class="settings-emoji" aria-hidden="true">📤</span> Add images</span>
                                                                <input
                                                                    type="file"
                                                                    name="template_images[]"
                                                                    accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                                                                    multiple
                                                                >
                                                            </label>
                                                        <?php endif; ?>
                                                    </fieldset>

                                                    <fieldset class="settings-fieldset settings-tone-sky template-edit-panel template-edit-panel-mermaid">
                                                        <legend><span class="settings-emoji" aria-hidden="true">🗺️</span> Mermaid diagram</legend>
                                                        <p class="settings-hint">Update the diagram shown in the Guide popup, or clear it. ELK + direction are applied when you save.</p>
                                                        <?php $mermaidOpts = $parseMermaidOptions((string) $row['mermaid_source']); ?>
                                                        <label class="file-input">
                                                            <span><span class="settings-emoji" aria-hidden="true">🏷️</span> Diagram title</span>
                                                            <input
                                                                type="text"
                                                                name="mermaid_title"
                                                                maxlength="200"
                                                                value="<?= e($row['mermaid_title']) ?>"
                                                                placeholder="e.g. How to fill this workbook"
                                                            >
                                                        </label>
                                                        <?= $renderMermaidOptionControls(
                                                            $mermaidOpts['layout'],
                                                            $mermaidOpts['direction'],
                                                            'mermaid_layout',
                                                            'mermaid_direction'
                                                        ) ?>
                                                        <label class="file-input">
                                                            <span><span class="settings-emoji" aria-hidden="true">📐</span> Mermaid source</span>
                                                            <textarea name="mermaid_source" rows="6" data-mermaid-source-field placeholder="flowchart TB&#10;  A --> B"><?= e($row['mermaid_source']) ?></textarea>
                                                        </label>
                                                        <?php if ($hasMermaid): ?>
                                                            <label class="template-edit-clear">
                                                                <input type="checkbox" name="clear_mermaid" value="1">
                                                                <span><span class="settings-emoji" aria-hidden="true">🗑️</span> Remove the Mermaid diagram</span>
                                                            </label>
                                                        <?php endif; ?>
                                                    </fieldset>
                                                </div>

                                                <div class="template-edit-actions">
                                                    <button type="submit" class="button button-primary template-edit-save">
                                                        <span class="settings-emoji" aria-hidden="true">💾</span> Save changes
                                                    </button>
                                                    <button type="button" class="button ghost template-edit-cancel-btn" data-template-edit-cancel="<?= $templateId ?>">
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

                    <?php foreach ($templateRows as $row): ?>
                        <?php
                        $templateId = (int) $row['id'];
                        $guideImages = $imagesByTemplate[$templateId] ?? [];
                        $hasMermaid = trim((string) $row['mermaid_source']) !== '';
                        if ($guideImages === [] && !$hasMermaid) {
                            continue;
                        }
                        ?>
                        <dialog
                            class="template-guide-dialog"
                            id="template-guide-<?= $templateId ?>"
                            aria-labelledby="template-guide-title-<?= $templateId ?>"
                        >
                            <div class="template-guide-dialog-shell">
                                <header class="template-guide-dialog-head">
                                    <div>
                                        <p class="eyebrow">🗺️ Template guide</p>
                                        <h3 id="template-guide-title-<?= $templateId ?>"><?= e($row['name']) ?></h3>
                                        <p class="template-guide-dialog-sub">Screenshots and Mermaid diagram for this workbook</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="button ghost template-guide-dialog-close"
                                        data-template-guide-close="<?= $templateId ?>"
                                        aria-label="Close guide"
                                    >✕</button>
                                </header>

                                <div class="template-guide-dialog-body">
                                    <?php if ($guideImages !== []): ?>
                                        <section class="template-guide-section" aria-label="Guide images">
                                            <div class="template-guide-section-head">
                                                <strong>🖼️ Guide images</strong>
                                                <span><?= count($guideImages) ?> / <?= (int) $maxGuideImages ?></span>
                                            </div>
                                            <div class="template-guide-gallery">
                                                <?php foreach ($guideImages as $image): ?>
                                                    <a
                                                        class="template-guide-thumb"
                                                        href="<?= e($templateImages->viewUrl((int) $image['id'])) ?>"
                                                        target="_blank"
                                                        rel="noopener"
                                                        title="<?= e($image['title'] !== '' ? $image['title'] : $image['original_filename']) ?>"
                                                    >
                                                        <img
                                                            src="<?= e($templateImages->viewUrl((int) $image['id'])) ?>"
                                                            alt="<?= e($image['title'] !== '' ? $image['title'] : 'Guide image') ?>"
                                                            loading="lazy"
                                                        >
                                                        <span><?= e($image['title'] !== '' ? $image['title'] : $image['original_filename']) ?></span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        </section>
                                    <?php endif; ?>

                                    <?php if ($hasMermaid): ?>
                                        <?php $guideMermaidOpts = $parseMermaidOptions((string) $row['mermaid_source']); ?>
                                        <section class="template-guide-section template-guide-section-mermaid" aria-label="Mermaid diagram">
                                            <div class="template-guide-section-head">
                                                <strong>📐 <?= e($row['mermaid_title'] !== '' ? $row['mermaid_title'] : 'Mermaid diagram') ?></strong>
                                                <div class="template-guide-mermaid-actions">
                                                    <button
                                                        type="button"
                                                        class="button ghost-light template-guide-mermaid-fullscreen"
                                                        data-template-mermaid-fullscreen
                                                    >⛶ Full screen</button>
                                                    <button
                                                        type="button"
                                                        class="button ghost-light template-guide-mermaid-view-code"
                                                        data-template-mermaid-view-code
                                                        aria-expanded="false"
                                                    >📄 View code</button>
                                                    <button
                                                        type="button"
                                                        class="button ghost-light template-guide-mermaid-copy-code"
                                                        data-template-mermaid-copy-code
                                                    >📋 Copy code</button>
                                                    <button
                                                        type="button"
                                                        class="button ghost-light template-guide-mermaid-live"
                                                        data-template-mermaid-live
                                                    >✨ Mermaid Live</button>
                                                </div>
                                            </div>
                                            <div class="template-mermaid-options template-guide-mermaid-controls" data-mermaid-options>
                                                <div class="template-mermaid-option-block">
                                                    <span class="template-mermaid-option-label">✨ Layout</span>
                                                    <input type="hidden" data-mermaid-layout value="<?= e($guideMermaidOpts['layout']) ?>">
                                                    <div class="template-mermaid-btn-group" role="group" aria-label="Layout style">
                                                        <button type="button" class="template-mermaid-opt-btn<?= $guideMermaidOpts['layout'] === 'default' ? ' is-active' : '' ?>" data-mermaid-layout-btn="default">Default</button>
                                                        <button type="button" class="template-mermaid-opt-btn<?= $guideMermaidOpts['layout'] === 'elk' ? ' is-active' : '' ?>" data-mermaid-layout-btn="elk">ELK</button>
                                                    </div>
                                                </div>
                                                <div class="template-mermaid-option-block">
                                                    <span class="template-mermaid-option-label">🧭 Direction</span>
                                                    <input type="hidden" data-mermaid-direction value="<?= e($guideMermaidOpts['direction']) ?>">
                                                    <div class="template-mermaid-btn-group" role="group" aria-label="Flow direction">
                                                        <?php foreach (['TB', 'TD', 'BT', 'LR', 'RL'] as $dir): ?>
                                                            <button type="button" class="template-mermaid-opt-btn<?= $guideMermaidOpts['direction'] === $dir ? ' is-active' : '' ?>" data-mermaid-direction-btn="<?= $dir ?>"><?= $dir ?></button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="template-mermaid-code-panel" data-mermaid-code-panel hidden>
                                                <div class="template-mermaid-code-panel-head">
                                                    <strong>Mermaid source</strong>
                                                    <button type="button" class="button ghost-light template-guide-mermaid-copy-code" data-template-mermaid-copy-code>📋 Copy</button>
                                                </div>
                                                <pre class="template-mermaid-code-pre" data-mermaid-code-pre></pre>
                                            </div>
                                            <div
                                                class="template-guide-mermaid-host"
                                                data-mermaid-source="<?= e($row['mermaid_source']) ?>"
                                                data-mermaid-base-source="<?= e($row['mermaid_source']) ?>"
                                            >
                                                <pre class="mermaid template-guide-mermaid-diagram"></pre>
                                            </div>
                                        </section>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </dialog>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="assets/js/template-mermaid-viewer.js?v=<?= filemtime(__DIR__ . '/assets/js/template-mermaid-viewer.js') ?>"></script>
    <script src="assets/js/template-upload.js?v=<?= filemtime(__DIR__ . '/assets/js/template-upload.js') ?>"></script>
</body>
</html>
