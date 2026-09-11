<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\SettingsRepository;

/**
 * Install-wide enable/disable for signed-in apps.
 * Superadmin always retains access so they can re-enable modules.
 */
final class AppModules
{
    public const RISK = 'risk';
    public const SHAREPOINT = 'sharepoint';
    public const STORAGE = 'storage';
    public const TICKET = 'ticket';

    private const SETTING_KEYS = [
        self::RISK => 'app_risk_register_enabled',
        self::SHAREPOINT => 'app_sharepoint_enabled',
        self::STORAGE => 'app_storage_heatmap_enabled',
        self::TICKET => 'app_ticket_dossier_enabled',
    ];

    private const LABELS = [
        self::RISK => 'Risk Register',
        self::SHAREPOINT => 'SharePoint',
        self::STORAGE => 'Storage Heatmap',
        self::TICKET => 'Ticket Dossier',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {
    }

    public static function instance(): self
    {
        return new self(Auth::instance()->settings());
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::RISK, self::SHAREPOINT, self::STORAGE, self::TICKET];
    }

    public static function settingKey(string $app): string
    {
        if (!isset(self::SETTING_KEYS[$app])) {
            throw new \InvalidArgumentException('Unknown app module: ' . $app);
        }

        return self::SETTING_KEYS[$app];
    }

    public static function label(string $app): string
    {
        return self::LABELS[$app] ?? $app;
    }

    public function isEnabled(string $app): bool
    {
        return $this->settings->get(self::settingKey($app), '1') === '1';
    }

    /** @param array<string, mixed>|null $user */
    public function canAccess(?array $user, string $app): bool
    {
        if ($user !== null && Auth::isSuperAdmin($user)) {
            return true;
        }

        return $this->isEnabled($app);
    }

    /**
     * Block access when the module is disabled for this user.
     * Call after requireAuth().
     *
     * @param array<string, mixed>|null $user
     */
    public function require(string $app, ?array $user = null): void
    {
        $user ??= Auth::instance()->currentUser();
        if ($this->canAccess($user, $app)) {
            return;
        }

        $auth = Auth::instance();
        $label = self::label($app);
        $home = $this->homeUrl($user);
        $message = $label . ' is disabled by the administrator.';

        if ($this->wantsJson()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(403);
        $brandTitle = Branding::current()->brandTitle();
        $homeHref = htmlspecialchars($auth->publicUrl($home), ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeBrand = htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Unavailable · ' . $safeBrand . '</title></head><body>'
            . '<main style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:1.5rem;">'
            . '<h1>App unavailable</h1>'
            . '<p>' . $safeMessage . '</p>'
            . '<p><a href="' . $homeHref . '">Continue</a></p>'
            . '</main></body></html>';
        exit;
    }

    /**
     * First accessible signed-in destination for this user.
     *
     * @param array<string, mixed>|null $user
     */
    public function homeUrl(?array $user = null): string
    {
        $user ??= Auth::instance()->currentUser();
        if ($this->canAccess($user, self::RISK)) {
            return 'index.php';
        }
        if ($this->canAccess($user, self::SHAREPOINT)) {
            return 'sharepoint.php';
        }
        if ($this->canAccess($user, self::STORAGE)) {
            return 'sharepoint.php?view=heatmap';
        }
        if ($this->canAccess($user, self::TICKET)) {
            return 'ticket-dossier/';
        }

        return 'help.php';
    }

    /**
     * Map a relative next URL to its app module, or null if not an app page.
     */
    public function appForPath(string $path): ?string
    {
        $path = trim($path);
        $query = (string) (parse_url($path, PHP_URL_QUERY) ?? '');
        parse_str($query, $queryParams);
        $path = strtok($path, '?#') ?: $path;
        $path = ltrim($path, '/');

        if ($path === '' || $path === 'index.php' || $path === 'templates.php' || $path === 'transfer-ownership.php') {
            return self::RISK;
        }
        if ($path === 'sharepoint.php' || str_starts_with($path, 'sharepoint.php')) {
            $view = (string) ($queryParams['view'] ?? '');
            $action = (string) ($queryParams['action'] ?? '');
            if ($view === 'heatmap' || $action === 'size_stats') {
                return self::STORAGE;
            }
            return self::SHAREPOINT;
        }
        if ($path === 'ticket-dossier' || $path === 'ticket-dossier/' || str_starts_with($path, 'ticket-dossier/')) {
            return self::TICKET;
        }

        return null;
    }

    /**
     * Resolve a post-login next URL, falling back when the target app is disabled.
     *
     * @param array<string, mixed>|null $user
     */
    public function resolveNext(string $next, ?array $user = null): string
    {
        $user ??= Auth::instance()->currentUser();
        $safe = Auth::instance()->safeNext($next, $user);
        $app = $this->appForPath($safe);
        if ($app !== null && !$this->canAccess($user, $app)) {
            return $this->homeUrl($user);
        }

        return $safe;
    }

    private function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $requested = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $action = (string) ($_POST['action'] ?? '');

        return str_contains($accept, 'application/json')
            || strcasecmp($requested, 'XMLHttpRequest') === 0
            || str_starts_with($action, 'save_');
    }
}
