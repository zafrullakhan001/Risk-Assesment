<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\CatalogShareRepository;

/**
 * Per-user limits on SharePoint catalogs and menu destinations.
 * Limits apply only to non-admin users; an empty stored list means "no limit".
 */
final class UserAccess
{
    /** Menu destinations in display order; keys match data-nav-dest on nav links. */
    public const MENU_DESTS = [
        'find' => ['label' => 'Find projects', 'app' => AppModules::RISK, 'url' => 'index.php'],
        'upload' => ['label' => 'Upload', 'app' => AppModules::RISK, 'url' => 'index.php#upload'],
        'templates' => ['label' => 'Templates', 'app' => AppModules::RISK, 'url' => 'templates.php'],
        'sharepoint' => ['label' => 'SharePoint', 'app' => AppModules::SHAREPOINT, 'url' => 'sharepoint.php'],
        'catalogs' => ['label' => 'catalogs', 'app' => AppModules::SHAREPOINT, 'url' => 'sharepoint.php?view=catalog&mode=or&per=100'],
        'owners' => ['label' => 'Owners', 'app' => AppModules::SHAREPOINT, 'url' => 'sharepoint.php?view=owners'],
        'storage' => ['label' => 'Storage', 'app' => AppModules::STORAGE, 'url' => 'sharepoint.php?view=heatmap'],
        'ticket' => ['label' => 'Ticket Dossier', 'app' => AppModules::TICKET, 'url' => 'ticket-dossier/'],
    ];

    /** @param array<string, mixed>|null $user */
    public static function isRestrictable(?array $user): bool
    {
        return $user !== null && empty($user['is_admin']) && empty($user['is_superadmin']);
    }

    /**
     * @param array<string, mixed>|null $user
     * @return list<string>|null Null when the user may see every catalog.
     */
    public static function catalogKeysForUser(?array $user): ?array
    {
        if (!self::isRestrictable($user)) {
            return null;
        }
        $keys = $user['allowed_catalog_source_keys'] ?? [];

        return is_array($keys) && $keys !== [] ? array_values($keys) : null;
    }

    /**
     * @param list<array<string, mixed>> $allSources
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public static function filterSources(array $allSources, ?array $user): array
    {
        $keys = self::catalogKeysForUser($user);
        if ($keys === null) {
            return array_values($allSources);
        }

        return CatalogShareRepository::filterSources($allSources, $keys);
    }

    /** @param array<string, mixed>|null $user */
    public static function canUseCatalog(?array $user, string $sourceKey): bool
    {
        $keys = self::catalogKeysForUser($user);

        return $keys === null || in_array($sourceKey, $keys, true);
    }

    /** @param array<string, mixed>|null $user */
    public static function assertCatalogSource(?array $user, string $sourceKey): void
    {
        if (!self::canUseCatalog($user, $sourceKey)) {
            AppModules::instance()->deny('You do not have access to this catalog.', $user);
        }
    }

    /**
     * Whether the nav link / page for $dest is available: module enabled and allowed for this user.
     *
     * @param array<string, mixed>|null $user
     */
    public static function canShowMenuDest(?array $user, string $dest): bool
    {
        if (!isset(self::MENU_DESTS[$dest])) {
            return false;
        }
        if (!AppModules::instance()->canAccess($user, self::MENU_DESTS[$dest]['app'])) {
            return false;
        }
        if (!self::isRestrictable($user)) {
            return true;
        }
        $allowed = $user['allowed_menu_dests'] ?? [];

        return !is_array($allowed) || $allowed === [] || in_array($dest, $allowed, true);
    }

    /**
     * Block the request unless at least one of $dests is allowed.
     *
     * @param array<string, mixed>|null $user
     */
    public static function requireDest(?array $user, string ...$dests): void
    {
        foreach ($dests as $dest) {
            if (self::canShowMenuDest($user, $dest)) {
                return;
            }
        }
        AppModules::instance()->deny('This page is not available for your account.', $user);
    }

    /**
     * Menu destinations that grant access to a relative app path (any one is enough).
     *
     * @return list<string>
     */
    public static function destsForPath(string $path): array
    {
        $query = (string) (parse_url($path, PHP_URL_QUERY) ?? '');
        parse_str($query, $params);
        $file = ltrim((string) (strtok($path, '?#') ?: $path), '/');

        if ($file === '' || $file === 'index.php') {
            return ['find', 'upload'];
        }
        if ($file === 'templates.php') {
            return ['templates'];
        }
        if ($file === 'transfer-ownership.php') {
            return ['find'];
        }
        if (str_starts_with($file, 'ticket-dossier')) {
            return ['ticket'];
        }
        if ($file === 'sharepoint.php') {
            return match ((string) ($params['view'] ?? '')) {
                'catalog' => ['catalogs'],
                'owners' => ['owners'],
                'heatmap' => ['storage'],
                default => ['sharepoint'],
            };
        }

        return [];
    }

    /**
     * @param list<string> $dests
     * @return list<string>
     */
    public static function normalizeMenuDests(array $dests): array
    {
        $out = [];
        foreach ($dests as $dest) {
            $dest = (string) $dest;
            if (isset(self::MENU_DESTS[$dest]) && !in_array($dest, $out, true)) {
                $out[] = $dest;
            }
        }

        return $out;
    }
}
