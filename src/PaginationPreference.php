<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Persist rows-per-page choices in cookies so list views keep the user's size.
 */
final class PaginationPreference
{
    public const KEY_USERS = 'ra_per_users';
    public const KEY_AUDIT = 'ra_per_audit';
    public const KEY_PROJECTS = 'ra_per_projects';
    public const KEY_SHAREPOINT = 'ra_per_sharepoint';

    private const LIFETIME_SECONDS = 365 * 24 * 60 * 60;

    /**
     * Resolve page size from an explicit request value, else the saved cookie, else default.
     * When the request supplies a valid size, it is saved for later visits.
     *
     * @param list<int> $allowed
     */
    public static function resolve(string $key, ?int $requested, int $default, array $allowed): int
    {
        if ($requested !== null && in_array($requested, $allowed, true)) {
            self::save($key, $requested);

            return $requested;
        }

        $saved = self::read($key);
        if ($saved !== null && in_array($saved, $allowed, true)) {
            return $saved;
        }

        return $default;
    }

    public static function save(string $key, int $perPage): void
    {
        if (headers_sent()) {
            $_COOKIE[$key] = (string) $perPage;

            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

        setcookie($key, (string) $perPage, [
            'expires' => time() + self::LIFETIME_SECONDS,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$key] = (string) $perPage;
    }

    public static function read(string $key): ?int
    {
        if (!isset($_COOKIE[$key])) {
            return null;
        }

        $value = filter_var($_COOKIE[$key], FILTER_VALIDATE_INT);
        if ($value === false) {
            return null;
        }

        return (int) $value;
    }
}
