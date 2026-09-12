<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$wantsStream = isset($_GET['stream']) || isset($_POST['stream'])
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'text/event-stream'));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$wantsStream) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $service = new DossierReasoningService();
    $status = $service->status();
    $id = (int) ($_GET['id'] ?? 0);
    $cached = $id > 0 ? ProjectRepository::getAiReasoning($id) : null;
    $summary = null;
    if ($id > 0) {
        $project = ProjectRepository::find($id);
        if ($project !== null) {
            $parsed = json_decode((string) $project['parsed_json'], true);
            if (!is_array($parsed)) {
                $parsed = [];
            }
            $mapped = ProjectSummaryMapper::map($project, $parsed);
            $aiTemplate = is_array($cached['template'] ?? null) ? $cached['template'] : null;
            $summary = ProjectSummaryMapper::mergeWithAi($mapped, $aiTemplate);
        }
    }

    echo json_encode([
        'ok' => true,
        'status' => $status,
        'reasoning' => $cached,
        'summary' => $summary,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'POST or GET required']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? null;
if ($csrf === null) {
    $raw = file_get_contents('php://input');
    $jsonBody = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($jsonBody)) {
        $csrf = $jsonBody['csrf_token'] ?? null;
        $_POST = array_merge($_POST, $jsonBody);
    }
}

if (!verifyCsrf(is_string($csrf) ? $csrf : null)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid security token']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$project = $id > 0 ? ProjectRepository::find($id) : null;
if ($project === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Project not found']);
    exit;
}

$parsed = json_decode((string) $project['parsed_json'], true);
if (!is_array($parsed)) {
    $parsed = [];
}

$stream = $wantsStream || !empty($_POST['stream']);

$sendJson = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
};

$sseSend = static function (string $event, array $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }
    flush();
};

try {
    @set_time_limit(TD_GEMMA_TIMEOUT_SECONDS + 30);
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'off');

    $service = new DossierReasoningService();
    $mapped = ProjectSummaryMapper::map($project, $parsed);

    if ($stream) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('X-Content-Type-Options: nosniff');

        $sseSend('status', $service->status());

        $reasoning = $service->reason(
            $project,
            $parsed,
            $mapped,
            static function (array $progress) use ($sseSend): void {
                $sseSend('progress', $progress);
            }
        );
        ProjectRepository::saveAiReasoning($id, $reasoning);
        $summary = ProjectSummaryMapper::mergeWithAi($mapped, is_array($reasoning['template'] ?? null) ? $reasoning['template'] : null);

        $sseSend('result', [
            'ok' => true,
            'reasoning' => $reasoning,
            'summary' => $summary,
            'status' => $service->status(),
        ]);
        $sseSend('done', ['ok' => true]);
        exit;
    }

    $reasoning = $service->reason($project, $parsed, $mapped);
    ProjectRepository::saveAiReasoning($id, $reasoning);
    $summary = ProjectSummaryMapper::mergeWithAi($mapped, is_array($reasoning['template'] ?? null) ? $reasoning['template'] : null);

    $sendJson([
        'ok' => true,
        'reasoning' => $reasoning,
        'summary' => $summary,
        'status' => $service->status(),
    ]);
} catch (Throwable $e) {
    if ($stream) {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store');
        }
        $sseSend('error', [
            'ok' => false,
            'error' => $e->getMessage(),
            'status' => (new DossierReasoningService())->status(),
        ]);
        exit;
    }

    $sendJson([
        'ok' => false,
        'error' => $e->getMessage(),
        'status' => (new DossierReasoningService())->status(),
    ], 503);
}
