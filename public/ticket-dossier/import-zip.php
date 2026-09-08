<?php

declare(strict_types=1);

/**
 * Import a Ticket Dossier ZIP backup (one project or a full set).
 */
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php#import-zip');
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    flashSet('error', 'Invalid security token. Please try again.');
    redirect('index.php#import-zip');
}

if (empty($_FILES['zip']) || !is_array($_FILES['zip'])) {
    flashSet('error', 'Please choose a Ticket Dossier ZIP file to import.');
    redirect('index.php#import-zip');
}

$error = (int) ($_FILES['zip']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error === UPLOAD_ERR_NO_FILE) {
    flashSet('error', 'Please choose a Ticket Dossier ZIP file to import.');
    redirect('index.php#import-zip');
}
if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
    flashSet('error', 'The ZIP is larger than this server allows. Try a smaller backup or raise PHP upload limits.');
    redirect('index.php#import-zip');
}
if ($error !== UPLOAD_ERR_OK) {
    flashSet('error', 'Upload failed. Please try again.');
    redirect('index.php#import-zip');
}

$tmp = (string) ($_FILES['zip']['tmp_name'] ?? '');
$name = safeBasename((string) ($_FILES['zip']['name'] ?? 'backup.zip'));
$size = (int) ($_FILES['zip']['size'] ?? 0);

if (!is_uploaded_file($tmp) || !is_readable($tmp)) {
    flashSet('error', 'Invalid ZIP upload.');
    redirect('index.php#import-zip');
}
if (extensionOf($name) !== 'zip') {
    flashSet('error', 'Please upload a .zip file exported from Ticket Dossier.');
    redirect('index.php#import-zip');
}
if ($size <= 0 || $size > TD_MAX_ZIP_UPLOAD_BYTES) {
    flashSet('error', 'The ZIP exceeds the allowed size (' . (int) round(TD_MAX_ZIP_UPLOAD_BYTES / 1024 / 1024) . ' MB).');
    redirect('index.php#import-zip');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmp) ?: '';
$allowedMime = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
if (!in_array($mime, $allowedMime, true)) {
    flashSet('error', 'That file does not look like a ZIP archive.');
    redirect('index.php#import-zip');
}

try {
    $result = ProjectPackager::import($tmp, is_array($currentUser) ? $currentUser : null);
    $count = (int) $result['imported'];
    $msg = $count === 1
        ? 'Imported 1 project from ZIP.'
        : 'Imported ' . $count . ' projects from ZIP.';
    if ($result['warnings'] !== []) {
        $msg .= ' ' . implode(' ', $result['warnings']);
    }
    flashSet('success', $msg);
    if ($count === 1 && (int) $result['last_id'] > 0) {
        redirect('project.php?id=' . (int) $result['last_id']);
    }
    redirect('index.php#find-projects');
} catch (Throwable $e) {
    flashSet('error', $e->getMessage());
    redirect('index.php#import-zip');
}
