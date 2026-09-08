<?php

declare(strict_types=1);

/**
 * Session is owned by RiskRegister (public/bootstrap.php). CSRF shares $_SESSION['csrf_token'].
 */
function startAppSession(): void
{
    // No-op when RiskRegister already started the session.
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
}

function csrfToken(): string
{
    startAppSession();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool
{
    startAppSession();
    $expected = $_SESSION['csrf_token'] ?? '';

    return is_string($token)
        && is_string($expected)
        && $expected !== ''
        && hash_equals($expected, $token);
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function flashSet(string $type, string $message): void
{
    startAppSession();
    $_SESSION['td_flash'] = ['type' => $type, 'message' => $message];
}

/**
 * @return array{type: string, message: string}|null
 */
function flashTake(): ?array
{
    startAppSession();
    if (empty($_SESSION['td_flash']) || !is_array($_SESSION['td_flash'])) {
        return null;
    }

    $flash = $_SESSION['td_flash'];
    unset($_SESSION['td_flash']);

    return [
        'type' => (string) ($flash['type'] ?? 'info'),
        'message' => (string) ($flash['message'] ?? ''),
    ];
}

function safeBasename(string $name): string
{
    $name = str_replace(["\0", '/', '\\'], '', $name);
    $name = basename($name);

    return $name !== '' ? $name : 'file';
}

function extensionOf(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function isAllowedUpload(string $originalName, string $tmpPath): bool
{
    $ext = extensionOf($originalName);
    if (!in_array($ext, TD_ALLOWED_EXTENSIONS, true)) {
        return false;
    }

    if (!is_uploaded_file($tmpPath) && !is_readable($tmpPath)) {
        return false;
    }

    $size = filesize($tmpPath);
    if ($size === false || $size <= 0 || $size > TD_MAX_UPLOAD_BYTES) {
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath) ?: '';

    if ($ext === 'json') {
        return in_array($mime, ['application/json', 'text/json', 'text/plain', 'application/octet-stream'], true);
    }

    return in_array($mime, ['application/pdf', 'application/octet-stream'], true);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
