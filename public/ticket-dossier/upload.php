<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    flashSet('error', 'Invalid security token. Please try again.');
    redirect('index.php');
}

$optionalTitle = trim((string) ($_POST['title'] ?? ''));
if (strlen($optionalTitle) > 200) {
    $optionalTitle = substr($optionalTitle, 0, 200);
}

$uploads = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
    $names = $_FILES['files']['name'];
    $tmps = $_FILES['files']['tmp_name'];
    $sizes = $_FILES['files']['size'];
    $errors = $_FILES['files']['error'];
    $count = count($names);

    for ($i = 0; $i < $count; $i++) {
        if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $uploads[] = [
            'name' => (string) $names[$i],
            'tmp_name' => (string) $tmps[$i],
            'size' => (int) $sizes[$i],
            'error' => (int) $errors[$i],
            'is_local' => false,
        ];
    }
}

try {
    $result = ProjectImporter::import($uploads, $optionalTitle !== '' ? $optionalTitle : null);
    $msg = 'Project created successfully.';
    if ($result['warnings'] !== []) {
        $msg .= ' ' . implode(' ', $result['warnings']);
    }
    flashSet('success', $msg);
    redirect('project.php?id=' . $result['project_id']);
} catch (Throwable $e) {
    flashSet('error', $e->getMessage());
    redirect('index.php');
}
