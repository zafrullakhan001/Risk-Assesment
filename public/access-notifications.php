<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\Repositories\UserNotificationRepository;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$currentUser = $auth->requireAuth();
$userId = (int) ($currentUser['id'] ?? 0);
$repo = new UserNotificationRepository($pdo);

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = '';

    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? 'list'));
    } elseif ($method === 'POST') {
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
    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'list' || $action === '') {
        $unread = $repo->listUnread($userId, 20);
        $recent = $repo->listRecent($userId, 25);
        $toastTypes = [
            UserNotificationRepository::TYPE_EDITOR_GRANTED,
            UserNotificationRepository::TYPE_OWNERSHIP_RECEIVED,
        ];
        $toast = [];
        foreach ($unread as $row) {
            if (in_array((string) ($row['type'] ?? ''), $toastTypes, true)) {
                $toast[] = $row;
            }
        }

        echo json_encode([
            'ok' => true,
            'unread_count' => $repo->countUnread($userId),
            'unread' => $unread,
            'recent' => $recent,
            'toast' => array_slice($toast, 0, 3),
            'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
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
            if ($nid > 0 && $repo->markRead($userId, $nid)) {
                $marked++;
            }
        }
        echo json_encode([
            'ok' => true,
            'marked' => $marked,
            'unread_count' => $repo->countUnread($userId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'mark_all_read') {
        $marked = $repo->markAllRead($userId);
        echo json_encode([
            'ok' => true,
            'marked' => $marked,
            'unread_count' => 0,
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
