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
        // session_set_cookie_params() uses "lifetime", not "expires" (that key is for setcookie()).
        session_set_cookie_params(self::sessionCookieParams(0));
        session_start();
    }

    public static function writeCookie(int $lifetimeSeconds): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        $expires = $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0;
        setcookie((string) session_name(), (string) session_id(), self::setCookieOptions($expires));
    }

    public static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        $options = self::setCookieOptions(time() - 3600);
        setcookie((string) session_name(), '', $options);
    }

    /**
     * Options for session_set_cookie_params() (PHP 7.3+ array form).
     *
     * @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string}
     */
    private static function sessionCookieParams(int $lifetimeSeconds): array
    {
        return [
            'lifetime' => $lifetimeSeconds,
            'path' => self::cookiePath(),
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * Options for setcookie() (uses "expires", not "lifetime").
     *
     * @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: string}
     */
    private static function setCookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => self::cookiePath(),
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function cookiePath(): string
    {
        // Path "/" avoids cookie drops on XAMPP folders that contain spaces.
        // Isolation comes from the per-install session name instead.
        return '/';
    }
}
