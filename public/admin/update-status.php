<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use RiskAssessment\GitHubUpdater;

$auth->requireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$updater = new GitHubUpdater(
    $settings,
    $crypto,
    dirname(__DIR__, 2),
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'updater.lock'
);

$force = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';

try {
    $state = $updater->notificationState($force);
    $state['ok'] = !empty($state['ok']);
    echo json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'available' => false,
        'aheadBy' => 0,
        'items' => [],
        'fingerprint' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
