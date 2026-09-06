<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Build absolute public URLs from the current HTTP request.
 * Never hardcodes a deploy hostname; works under any host/domain and folder path.
 */
final class AppUrl
{
    public static function scheme(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

        return $https ? 'https' : 'http';
    }

    public static function host(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return $host !== '' ? $host : 'localhost';
    }

    /**
     * Directory of the public front controller (no trailing slash).
     * Examples: /RiskRegister/public, /RiskRegister (rewritten), or '' at vhost root.
     */
    public static function publicBasePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '') {
            return '';
        }

        $dir = str_replace('\\', '/', dirname($script));
        // Admin scripts live under public/admin/; absolute public URLs use the parent.
        if (str_ends_with($dir, '/admin')) {
            $dir = dirname($dir);
        }

        $dir = rtrim($dir, '/');
        if ($dir === '' || $dir === '.' || $dir === '\\') {
            return '';
        }

        return $dir;
    }

    /**
     * Absolute URL to a file under the public web root, e.g. "share.php?t=abc".
     */
    public static function absolute(string $publicFileAndQuery): string
    {
        $path = ltrim(str_replace('\\', '/', $publicFileAndQuery), '/');
        $base = self::publicBasePath();

        return self::scheme() . '://' . self::host() . $base . '/' . $path;
    }

    /**
     * Prefer the browser-visible path when REQUEST_URI already names this public file
     * (e.g. /RiskRegister/sharepoint.php via rewrite). Falls back to SCRIPT_NAME base.
     */
    public static function absoluteMatchingRequest(string $publicFile): string
    {
        $publicFile = ltrim(str_replace('\\', '/', $publicFile), '/');
        $fileOnly = explode('?', $publicFile, 2)[0];
        $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (is_string($requestPath) && $requestPath !== '') {
            $requestPath = str_replace('\\', '/', $requestPath);
            if (strcasecmp(basename($requestPath), $fileOnly) === 0) {
                return self::scheme() . '://' . self::host() . $requestPath;
            }
        }

        return self::absolute($publicFile);
    }
}
