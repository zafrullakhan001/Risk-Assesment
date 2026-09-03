<?php

declare(strict_types=1);

namespace RiskAssessment;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $installRoot = dirname(__DIR__);
        session_name('RASESS' . substr(hash('sha256', $installRoot), 0, 8));
        session_set_cookie_params(self::cookieOptions(0));
        session_start();
    }

    public static function writeCookie(int $lifetimeSeconds): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        $expires = $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0;
        setcookie((string) session_name(), (string) session_id(), self::cookieOptions($expires));
    }

    public static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        $options = self::cookieOptions(time() - 3600);
        setcookie((string) session_name(), '', $options);
    }

    /** @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: string} */
    private static function cookieOptions(int $expires): array
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

        return [
            'expires' => $expires,
            'path' => self::cookiePath(),
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function cookiePath(): string
    {
        // Path "/" avoids cookie drops on XAMPP folders that contain spaces.
        // Isolation comes from the per-install session name instead.
        return '/';
    }
}
