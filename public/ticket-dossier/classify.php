<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token']);
    exit;
}

$results = [];
$uploads = $_FILES['files'] ?? null;

if (!is_array($uploads) || !isset($uploads['name']) || !is_array($uploads['name'])) {
    echo json_encode(['ok' => true, 'files' => []]);
    exit;
}

$count = count($uploads['name']);
for ($i = 0; $i < $count; $i++) {
    $name = safeBasename((string) ($uploads['name'][$i] ?? 'file'));
    $tmp = (string) ($uploads['tmp_name'][$i] ?? '');
    $error = (int) ($uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    $size = (int) ($uploads['size'][$i] ?? 0);

    if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    $item = [
        'name' => $name,
        'size' => $size,
        'kind' => null,
        'label' => null,
        'emoji' => '📄',
        'ok' => false,
        'message' => '',
        'method' => null,
    ];

    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        $item['message'] = 'Upload failed';
        $results[] = $item;
        continue;
    }

    if ($size <= 0 || $size > TD_MAX_UPLOAD_BYTES) {
        $item['message'] = 'File too large or empty';
        $results[] = $item;
        continue;
    }

    $ext = extensionOf($name);
    if (!in_array($ext, TD_ALLOWED_EXTENSIONS, true)) {
        $item['message'] = 'Only PDF and JSON allowed';
        $results[] = $item;
        continue;
    }

    $byContent = FileClassifier::classifyByContent($tmp, $name);
    $byName = FileClassifier::classifyByFilename($name);
    $kind = $byContent ?? $byName;

    if ($kind === null) {
        $item['message'] = 'Unrecognized — not a Demand, Story, Task, or DDR export';
        $results[] = $item;
        continue;
    }

    $item['ok'] = true;
    $item['kind'] = $kind;
    $item['label'] = kindLabel($kind);
    $item['emoji'] = kindEmoji($kind);
    $item['method'] = $byContent !== null ? 'content' : 'filename';
    $item['message'] = $byContent !== null
        ? 'Detected from file contents'
        : 'Detected from filename';
    $results[] = $item;
}

echo json_encode(['ok' => true, 'files' => $results], JSON_UNESCAPED_UNICODE);
exit;
