<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
    } catch (Throwable) {
        // Still sign out so a stale CSRF token cannot trap the user.
    }
}

$auth->logout();
header('Location: login.php');
exit;
