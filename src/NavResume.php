<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Last signed-in page, stored in a JS-writable cookie so PHP can restore it
 * when the browser reopens the install folder URL (for example /riskregister/).
 */
final class NavResume
{
    public const COOKIE = 'riskregister_resume';

    /**
     * True when the request is the install folder / public directory, not an
     * explicit .php bookmark such as index.php#find-projects.
     */
    public static function isLaunchRequest(): bool
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        if ((string) ($_SERVER['QUERY_STRING'] ?? '') !== '') {
            return false;
        }
        if ($_GET !== []) {
            return false;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? $uri);
        $path = rawurldecode(str_replace('\\', '/', $path));
        $path = rtrim($path, '/');
        $base = strtolower((string) basename($path));

        if ($base === 'index.php' || str_ends_with(strtolower($path), '.php')) {
            return false;
        }

        return true;
    }

    /**
     * Public-relative resume URL, or empty when the cookie is missing/invalid.
     *
     * @param array<string, mixed>|null $user
     */
    public static function safeUrl(?array $user = null): string
    {
        $raw = trim((string) ($_COOKIE[self::COOKIE] ?? ''));
        if ($raw === '' || strlen($raw) > 1800) {
            return '';
        }

        $relative = self::toRelative($raw);
        if ($relative === '') {
            return '';
        }

        $safe = Auth::instance()->safeNext($relative, $user);
        $requestedFile = strtolower((string) (strtok($relative, '?#') ?: $relative));
        $safeFile = strtolower((string) (strtok($safe, '?#') ?: $safe));
        if ($requestedFile !== $safeFile) {
            return '';
        }

        return $safe;
    }

    public static function clearCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE]);
    }

    /**
     * Turn an origin path or public-relative URL into sharepoint.php?view=catalog…
     */
    public static function toRelative(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || str_contains($raw, "\n") || str_contains($raw, "\r") || str_contains($raw, '\\')) {
            return '';
        }

        $path = $raw;
        $query = '';
        $fragment = '';

        if (preg_match('~^https?://~i', $raw) === 1 || str_starts_with($raw, '//')) {
            $parts = parse_url($raw);
            if (!is_array($parts) || empty($parts['path'])) {
                return '';
            }
            $path = (string) $parts['path'];
            $query = (string) ($parts['query'] ?? '');
            $fragment = (string) ($parts['fragment'] ?? '');
        } else {
            $hashPos = strpos($raw, '#');
            if ($hashPos !== false) {
                $fragment = substr($raw, $hashPos + 1);
                $raw = substr($raw, 0, $hashPos);
            }
            $qPos = strpos($raw, '?');
            if ($qPos !== false) {
                $query = substr($raw, $qPos + 1);
                $path = substr($raw, 0, $qPos);
            } else {
                $path = $raw;
            }
        }

        $path = str_replace('\\', '/', $path);
        $lower = strtolower($path);
        $relative = $path;

        if (str_starts_with($path, '/')) {
            $pub = strrpos($lower, '/public/');
            if ($pub !== false) {
                $relative = substr($path, $pub + strlen('/public/'));
            } elseif (str_contains($lower, '/ticket-dossier')) {
                $relative = ltrim(substr($path, (int) strpos($lower, '/ticket-dossier')), '/');
            } elseif (str_contains($lower, '/admin/')) {
                $relative = 'admin/' . substr($path, (int) strpos($lower, '/admin/') + strlen('/admin/'));
            } else {
                $relative = (string) basename($path);
            }
        }

        $relative = ltrim($relative, '/');
        if ($relative === '' || strcasecmp($relative, 'public') === 0) {
            return '';
        }
        if (strcasecmp($relative, 'ticket-dossier') === 0) {
            $relative = 'ticket-dossier/';
        }

        if ($query !== '') {
            $relative .= '?' . $query;
        }
        if ($fragment !== '') {
            $relative .= '#' . $fragment;
        }

        return $relative;
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
