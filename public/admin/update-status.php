<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use RiskAssessment\GitHubUpdater;

$currentUser = $auth->requireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$updater = new GitHubUpdater(
    $settings,
    $crypto,
    dirname(__DIR__, 2),
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'updater.lock'
);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $force = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
        $state = $updater->notificationState($force);
        $state['ok'] = !empty($state['ok']);
        $state['csrf_token'] = (string) ($_SESSION['csrf_token'] ?? '');
        echo json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $raw = file_get_contents('php://input');
    $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($json)) {
        $json = $_POST;
    }
    if (!is_array($json)) {
        $json = [];
    }
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($json['csrf_token'] ?? ''))) {
        throw new RuntimeException('Invalid form submission. Please refresh and try again.');
    }

    $action = trim((string) ($json['action'] ?? ''));
    if ($action !== 'mark_installed') {
        throw new RuntimeException('Unknown action.');
    }

    $ref = trim((string) ($json['target_ref'] ?? ''));
    $marked = $updater->markAlreadyInstalled($ref);
    try {
        $auth->users()->logAudit(
            'updater.mark_installed',
            (int) $currentUser['id'],
            (string) $currentUser['username'],
            null,
            null,
            ['ref' => $ref !== '' ? $ref : (string) ($marked['short'] ?? ''), 'mode' => 'local']
        );
    } catch (Throwable) {
        // Audit is best-effort.
    }

    $state = $updater->notificationState(true);
    $state['ok'] = true;
    $state['message'] = (string) $marked['message'];
    $state['csrf_token'] = (string) ($_SESSION['csrf_token'] ?? '');
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
        'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
