<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\Repositories\FindingStatusRepository;
use RiskAssessment\Repositories\UserNotificationRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$currentUser = $auth->requireAuth();
$userId = (int) ($currentUser['id'] ?? 0);
$findingStatuses = new FindingStatusRepository($pdo);
$notifications = new UserNotificationRepository($pdo);

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = '';
    $json = [];

    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? 'list'));
    } elseif ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = $_POST;
        }
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $json = $decoded;
        if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($json['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid form submission. Please refresh and try again.');
        }
        $action = trim((string) ($json['action'] ?? ''));
    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'list' || $action === 'count' || $action === '') {
        $due = $findingStatuses->listDueForUser($currentUser, 50);
        $unreadException = 0;
        foreach ($notifications->listUnread($userId, 50) as $row) {
            if ((string) ($row['type'] ?? '') === UserNotificationRepository::TYPE_EXCEPTION_DUE) {
                $unreadException++;
            }
        }

        $payload = [
            'ok' => true,
            'due_count' => count($due),
            'unread_count' => $unreadException,
            'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
        ];
        if ($action !== 'count') {
            $payload['due'] = $due;
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'mark_read') {
        $ids = $json['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $marked = 0;
        foreach ($ids as $id) {
            $nid = (int) $id;
            if ($nid > 0 && $notifications->markRead($userId, $nid)) {
                $marked++;
            }
        }
        echo json_encode([
            'ok' => true,
            'marked' => $marked,
            'due_count' => $findingStatuses->countDueForUser($currentUser),
            'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'mark_all_read') {
        $marked = $notifications->markUnreadTypesRead($userId, [
            UserNotificationRepository::TYPE_EXCEPTION_DUE,
        ]);
        echo json_encode([
            'ok' => true,
            'marked' => $marked,
            'due_count' => $findingStatuses->countDueForUser($currentUser),
            'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
