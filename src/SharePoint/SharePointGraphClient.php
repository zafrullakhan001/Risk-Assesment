<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use RiskAssessment\Crypto;
use RiskAssessment\Repositories\SettingsRepository;
use RiskAssessment\Repositories\SharePointCatalogRepository;
use RuntimeException;

final class SharePointGraphClient
{
    public const SOURCE_KEY = SharePointCatalogRepository::SOURCE_DEFAULT;
    public const MAX_ITEMS = 25000;
    public const MAX_DEPTH = 30;

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const TOKEN_SCOPE = 'https://graph.microsoft.com/.default';

    private ?string $accessToken = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Crypto $crypto,
        private readonly SharePointCatalogRepository $catalog,
    ) {
    }

    /** @return array{tenant_id: string, client_id: string, has_secret: bool, enable_one_click_sync: bool, enable_console_sync: bool, site_host: string, site_path: string, folder_path: string, folder_url: string, last_synced_at: string, last_sync_status: string, last_sync_error: string, last_item_count: int} */
    public function status(): array
    {
        $siteHost = trim($this->settings->get('sharepoint_site_host', 'ahsonline.sharepoint.com'))
            ?: 'ahsonline.sharepoint.com';
        $sitePath = $this->normalizeSitePath(
            $this->settings->get('sharepoint_site_path', '/teams/AITTechnologyEngagement')
        );
        $folderPath = trim($this->settings->get(
            'sharepoint_folder_path',
            'Architectural Projects [Public]'
        )) ?: 'Architectural Projects [Public]';
        $folderUrl = SharePointFolderUrl::resolveFromSettings(
            $this->settings->get('sharepoint_folder_url', ''),
            $siteHost,
            $sitePath,
            $folderPath
        );

        return [
            'tenant_id' => trim($this->settings->get('sharepoint_tenant_id', '')),
            'client_id' => trim($this->settings->get('sharepoint_client_id', '')),
            'has_secret' => $this->clientSecret() !== '',
            'enable_one_click_sync' => $this->settingEnabled('sharepoint_enable_one_click_sync', true),
            'enable_console_sync' => $this->settingEnabled('sharepoint_enable_console_sync', true),
            'site_host' => $siteHost,
            'site_path' => $sitePath,
            'folder_path' => $folderPath,
            'folder_url' => $folderUrl,
            'last_synced_at' => trim($this->settings->get('sharepoint_last_synced_at', '')),
            'last_sync_status' => trim($this->settings->get('sharepoint_last_sync_status', '')),
            'last_sync_error' => trim($this->settings->get('sharepoint_last_sync_error', '')),
            'last_item_count' => (int) $this->settings->get('sharepoint_last_item_count', '0'),
        ];
    }

    /**
     * @param array{
     *   sharepoint_tenant_id?: string,
     *   sharepoint_client_id?: string,
     *   sharepoint_client_secret?: string|null,
     *   sharepoint_enable_one_click_sync?: bool|string|int,
     *   sharepoint_enable_console_sync?: bool|string|int,
     *   sharepoint_site_host?: string,
     *   sharepoint_site_path?: string,
     *   sharepoint_folder_path?: string,
     *   sharepoint_folder_url?: string
     * } $input
     */
    public function saveSettings(array $input): void
    {
        $tenant = trim((string) ($input['sharepoint_tenant_id'] ?? ''));
        $clientId = trim((string) ($input['sharepoint_client_id'] ?? ''));
        $folderUrlInput = trim((string) ($input['sharepoint_folder_url'] ?? ''));

        $siteHost = trim((string) ($input['sharepoint_site_host'] ?? ''));
        $sitePath = $this->normalizeSitePath((string) ($input['sharepoint_site_path'] ?? ''));
        $folderPath = trim((string) ($input['sharepoint_folder_path'] ?? ''));

        if ($folderUrlInput !== '') {
            $parsed = SharePointFolderUrl::parse($folderUrlInput);
            $siteHost = $parsed['site_host'];
            $sitePath = $parsed['site_path'];
            $folderPath = $parsed['folder_path'];
            $this->settings->set('sharepoint_folder_url', $parsed['folder_url']);
        }

        if ($siteHost === '') {
            throw new RuntimeException('SharePoint site host is required.');
        }
        if ($sitePath === '' || $sitePath === '/') {
            throw new RuntimeException('SharePoint site path is required (e.g. /teams/AITTechnologyEngagement).');
        }
        if ($folderPath === '') {
            throw new RuntimeException('SharePoint folder path is required.');
        }
        if ($tenant !== '' && !$this->looksLikeGuid($tenant)) {
            throw new RuntimeException(
                'Tenant ID must be a GUID from Entra Overview (example: 6ac36678-7785-476f-be03-b68b403734c2).'
            );
        }
        if ($clientId !== '' && !$this->looksLikeGuid($clientId)) {
            throw new RuntimeException(
                'Client ID must be the Application (client) ID GUID from your Entra app registration — not a username or short code.'
            );
        }

        $this->settings->set('sharepoint_tenant_id', $tenant);
        $this->settings->set('sharepoint_client_id', $clientId);
        $this->settings->set('sharepoint_site_host', $siteHost);
        $this->settings->set('sharepoint_site_path', $sitePath);
        $this->settings->set('sharepoint_folder_path', $folderPath);
        if ($folderUrlInput === '') {
            $this->settings->set(
                'sharepoint_folder_url',
                SharePointFolderUrl::buildBrowseUrl($siteHost, $sitePath, $folderPath)
            );
        }

        if (array_key_exists('sharepoint_enable_one_click_sync', $input)) {
            $this->settings->set(
                'sharepoint_enable_one_click_sync',
                $this->toBool($input['sharepoint_enable_one_click_sync']) ? '1' : '0'
            );
        }
        if (array_key_exists('sharepoint_enable_console_sync', $input)) {
            $this->settings->set(
                'sharepoint_enable_console_sync',
                $this->toBool($input['sharepoint_enable_console_sync']) ? '1' : '0'
            );
        }

        if (!array_key_exists('sharepoint_client_secret', $input)) {
            return;
        }

        $secret = is_string($input['sharepoint_client_secret'])
            ? trim($input['sharepoint_client_secret'])
            : '';
        if ($secret === '') {
            return;
        }
        $this->settings->set('sharepoint_client_secret', $this->crypto->encrypt($secret));
        $this->accessToken = null;
    }

    /** Persist posted admin fields, then verify Graph can resolve the site. */
    public function testConnectionFromPost(array $post): array
    {
        $this->applyPostedCredentials($post);
        return $this->testConnection();
    }

    /** Persist posted admin fields, then sync the catalog. */
    public function syncFromPost(array $post): array
    {
        $this->applyPostedCredentials($post);
        $sourceKey = trim((string) ($post['source'] ?? $post['source_key'] ?? self::SOURCE_KEY));

        return $this->sync([
            'source_key' => $sourceKey !== '' ? $sourceKey : self::SOURCE_KEY,
            'site_host' => (string) ($post['sharepoint_site_host'] ?? ''),
            'site_path' => (string) ($post['sharepoint_site_path'] ?? ''),
            'folder_path' => (string) ($post['sharepoint_folder_path'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $post */
    private function applyPostedCredentials(array $post): void
    {
        $payload = [
            'sharepoint_tenant_id' => (string) ($post['sharepoint_tenant_id'] ?? ''),
            'sharepoint_client_id' => (string) ($post['sharepoint_client_id'] ?? ''),
            'sharepoint_site_host' => (string) ($post['sharepoint_site_host'] ?? ''),
            'sharepoint_site_path' => (string) ($post['sharepoint_site_path'] ?? ''),
            'sharepoint_folder_path' => (string) ($post['sharepoint_folder_path'] ?? ''),
            'sharepoint_folder_url' => (string) ($post['sharepoint_folder_url'] ?? ''),
        ];
        $secret = trim((string) ($post['sharepoint_client_secret'] ?? ''));
        if ($secret !== '') {
            $payload['sharepoint_client_secret'] = $secret;
        }
        $this->saveSettings($payload);

        $status = $this->status();
        if ($status['tenant_id'] === '' || $status['client_id'] === '' || !$status['has_secret']) {
            throw new RuntimeException(
                'Enter Tenant ID, Client ID (app GUID), and Client secret, then click Test connection again. '
                . 'Save is optional — Test now saves the values from this form first.'
            );
        }
    }

    private function looksLikeGuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        $normalized = strtolower(trim((string) $value));

        return !in_array($normalized, ['', '0', 'false', 'no', 'off'], true);
    }

    private function settingEnabled(string $key, bool $default = true): bool
    {
        $raw = trim($this->settings->get($key, $default ? '1' : '0'));
        if ($raw === '') {
            return $default;
        }

        return $this->toBool($raw);
    }

    public function clearSecret(): void
    {
        $this->settings->delete('sharepoint_client_secret');
        $this->accessToken = null;
    }

    /** @return array{ok: bool, message: string, site_id?: string, site_name?: string, folder_ok?: bool} */
    public function testConnection(): array
    {
        $token = $this->acquireToken();
        $status = $this->status();
        $site = $this->resolveSite($status['site_host'], $status['site_path'], $token);
        $siteId = (string) ($site['id'] ?? '');
        if ($siteId === '') {
            throw new RuntimeException('SharePoint site id was empty.');
        }

        // Also prove the configured folder is reachable under the site drive.
        $folderPath = $this->encodeDrivePath($status['folder_path']);
        $children = $this->listChildren($siteId, $folderPath, $token);
        $childCount = count($children);

        return [
            'ok' => true,
            'message' => 'Connected. Site and folder are reachable (' . $childCount
                . ' item' . ($childCount === 1 ? '' : 's') . ' in the root of the folder).',
            'site_id' => $siteId,
            'site_name' => (string) ($site['displayName'] ?? $site['name'] ?? ''),
            'folder_ok' => true,
        ];
    }

    /**
     * Sync a SharePoint folder tree into the local catalog.
     *
     * @param array{
     *   source_key?: string,
     *   site_host?: string,
     *   site_path?: string,
     *   folder_path?: string,
     *   access_token?: string
     * }|null $options
     * @return array{ok: bool, count: int, projects: int, message: string, source_key: string}
     */
    public function sync(?array $options = null): array
    {
        @set_time_limit(600);
        ignore_user_abort(true);

        $options = $options ?? [];
        $sourceKey = trim((string) ($options['source_key'] ?? self::SOURCE_KEY));
        if ($sourceKey === '') {
            $sourceKey = self::SOURCE_KEY;
        }
        $userToken = trim((string) ($options['access_token'] ?? ''));

        try {
            $token = $userToken !== '' ? $userToken : $this->acquireToken();
            $status = $this->status();
            $siteHost = trim((string) ($options['site_host'] ?? $status['site_host'])) ?: $status['site_host'];
            $sitePath = $this->normalizeSitePath((string) ($options['site_path'] ?? $status['site_path']));
            $folderPath = trim((string) ($options['folder_path'] ?? $status['folder_path'])) ?: $status['folder_path'];

            $site = $this->resolveSite($siteHost, $sitePath, $token);
            $siteId = (string) ($site['id'] ?? '');
            if ($siteId === '') {
                throw new RuntimeException('SharePoint site id was empty.');
            }

            $encodedFolder = $this->encodeDrivePath($folderPath);
            $rootChildren = $this->listChildren($siteId, $encodedFolder, $token);
            $items = [];
            $this->walkChildren(
                $siteId,
                $rootChildren,
                '',
                '',
                1,
                $token,
                $items,
                $folderPath
            );

            $count = $this->catalog->replaceForSource($sourceKey, $items);
            $projects = $this->catalog->countProjects($sourceKey);

            $this->settings->set('sharepoint_last_synced_at', date('Y-m-d H:i:s'));
            $this->settings->set('sharepoint_last_sync_status', 'ok');
            $this->settings->set('sharepoint_last_sync_error', '');
            $this->settings->set('sharepoint_last_item_count', (string) $count);

            $via = $userToken !== '' ? 'Microsoft login' : 'Graph app';

            return [
                'ok' => true,
                'count' => $count,
                'projects' => $projects,
                'source_key' => $sourceKey,
                'message' => 'Synced ' . $count . ' item' . ($count === 1 ? '' : 's')
                    . ' across ' . $projects . ' project folder' . ($projects === 1 ? '' : 's')
                    . ' via ' . $via . '.',
            ];
        } catch (\Throwable $exception) {
            $this->settings->set('sharepoint_last_synced_at', date('Y-m-d H:i:s'));
            $this->settings->set('sharepoint_last_sync_status', 'error');
            $this->settings->set('sharepoint_last_sync_error', mb_substr($exception->getMessage(), 0, 1000));
            throw $exception;
        }
    }

    /**
     * @param list<array<string, mixed>> $children
     * @param list<array<string, mixed>> $items
     */
    private function walkChildren(
        string $siteId,
        array $children,
        string $projectName,
        string $relativePrefix,
        int $depth,
        string $token,
        array &$items,
        string $rootFolderPath
    ): void {
        foreach ($children as $child) {
            if (count($items) >= self::MAX_ITEMS) {
                throw new RuntimeException(
                    'SharePoint sync stopped after ' . self::MAX_ITEMS . ' items. Narrow the folder path.'
                );
            }

            $name = trim((string) ($child['name'] ?? ''));
            $webUrl = trim((string) ($child['webUrl'] ?? ''));
            $itemKey = trim((string) ($child['id'] ?? ''));
            if ($name === '' || $webUrl === '' || $itemKey === '') {
                continue;
            }

            $isFolder = isset($child['folder']) && is_array($child['folder']);
            $itemType = $isFolder ? 'folder' : 'file';
            $parentKey = trim((string) (($child['parentReference']['id'] ?? '') ?: ''));

            $thisProject = $projectName;
            $relativePath = $relativePrefix === '' ? $name : ($relativePrefix . '/' . $name);
            if ($depth === 1) {
                $thisProject = $name;
                $relativePath = $name;
            }

            $items[] = [
                'item_key' => $itemKey,
                'parent_item_key' => $parentKey,
                'project_name' => $thisProject,
                'name' => $name,
                'item_type' => $itemType,
                'web_url' => $webUrl,
                'relative_path' => $relativePath,
                'mime_type' => (string) ($child['file']['mimeType'] ?? ''),
                'size_bytes' => (int) ($child['size'] ?? 0),
                'last_modified' => (string) ($child['lastModifiedDateTime'] ?? ''),
            ];

            if ($isFolder && $depth < self::MAX_DEPTH) {
                $childPath = $relativePrefix === '' ? $name : ($relativePrefix . '/' . $name);
                $fullPath = rtrim($rootFolderPath, '/') . '/' . $childPath;
                $grandChildren = $this->listChildren($siteId, $this->encodeDrivePath($fullPath), $token);
                $this->walkChildren(
                    $siteId,
                    $grandChildren,
                    $thisProject,
                    $relativePath,
                    $depth + 1,
                    $token,
                    $items,
                    $rootFolderPath
                );
            }
        }
    }

    private function acquireToken(): string
    {
        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        $tenant = trim($this->settings->get('sharepoint_tenant_id', ''));
        $clientId = trim($this->settings->get('sharepoint_client_id', ''));
        $secret = $this->clientSecret();
        if ($tenant === '' || $clientId === '' || $secret === '') {
            throw new RuntimeException(
                'SharePoint Graph credentials are incomplete. Set tenant ID, client ID, and client secret.'
            );
        }

        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
        $body = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $secret,
            'scope' => self::TOKEN_SCOPE,
            'grant_type' => 'client_credentials',
        ]);

        try {
            $response = $this->httpRequest('POST', $url, [
                'Content-Type: application/x-www-form-urlencoded',
            ], $body);
        } catch (RuntimeException $exception) {
            $msg = $exception->getMessage();
            if (stripos($msg, 'AADSTS700016') !== false || stripos($msg, 'not found') !== false) {
                throw new RuntimeException(
                    'Microsoft rejected the Client ID. Register an Entra app and paste its Application (client) ID GUID.'
                );
            }
            if (stripos($msg, 'AADSTS7000215') !== false || stripos($msg, 'Invalid client secret') !== false) {
                throw new RuntimeException(
                    'Microsoft rejected the client secret. Create a new secret under Certificates & secrets and paste the Value.'
                );
            }
            if (stripos($msg, 'AADSTS70011') !== false || stripos($msg, 'scope') !== false) {
                throw new RuntimeException(
                    'Token scope was rejected. Ensure the app uses Microsoft Graph application permissions.'
                );
            }
            throw $exception;
        }

        $token = (string) ($response['access_token'] ?? '');
        if ($token === '') {
            $desc = (string) ($response['error_description'] ?? $response['error'] ?? '');
            throw new RuntimeException(
                'Microsoft identity platform did not return an access token'
                . ($desc !== '' ? ': ' . $desc : '.')
            );
        }

        $this->accessToken = $token;

        return $token;
    }

    /** @return array<string, mixed> */
    private function resolveSite(string $host, string $sitePath, string $token): array
    {
        $host = trim($host);
        $sitePath = $this->normalizeSitePath($sitePath);
        $path = ltrim($sitePath, '/');
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        // Encode the colon so cURL on Windows/XAMPP does not mangle the Graph site path.
        $url = self::GRAPH_BASE . '/sites/' . rawurlencode($host) . '%3A/' . $encodedPath;

        return $this->httpJson('GET', $url, $token);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listChildren(string $siteId, string $encodedFolderPath, string $token): array
    {
        $url = self::GRAPH_BASE . '/sites/' . rawurlencode($siteId)
            . '/drive/root%3A/' . $encodedFolderPath . '%3A/children'
            . '?$select=id,name,webUrl,size,lastModifiedDateTime,folder,file,parentReference'
            . '&$top=200';

        $items = [];
        while ($url !== '') {
            $payload = $this->httpJson('GET', $url, $token);
            $page = $payload['value'] ?? [];
            if (is_array($page)) {
                foreach ($page as $row) {
                    if (is_array($row)) {
                        $items[] = $row;
                    }
                }
            }
            $next = (string) ($payload['@odata.nextLink'] ?? '');
            $url = $next;
        }

        return $items;
    }

    private function encodeDrivePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        $parts = explode('/', $path);
        $encoded = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $encoded[] = rawurlencode($part);
        }

        return implode('/', $encoded);
    }

    private function normalizeSitePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '/teams/AITTechnologyEngagement';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return rtrim($path, '/') ?: '/';
    }

    private function clientSecret(): string
    {
        $stored = $this->settings->get('sharepoint_client_secret', '');
        if ($stored === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($stored);
        } catch (\Throwable $exception) {
            error_log('sharepoint: failed to decrypt client secret: ' . $exception->getMessage());

            return '';
        }
    }

    /** @return array<string, mixed> */
    private function httpJson(string $method, string $url, string $token): array
    {
        return $this->httpRequest($method, $url, [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ]);
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function httpRequest(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required to talk to Microsoft Graph.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to start a Microsoft Graph request.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            // Match GitHubUpdater: XAMPP often lacks a CA bundle for peer verification.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new RuntimeException('Microsoft Graph request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        $text = is_string($raw) ? $raw : '';
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error_description'] ?? '');
            if ($message === '') {
                $graphErr = $decoded['error'] ?? null;
                if (is_array($graphErr)) {
                    $message = (string) ($graphErr['message'] ?? $graphErr['code'] ?? '');
                } elseif (is_string($graphErr)) {
                    $message = $graphErr;
                }
            }
            if ($message === '') {
                $message = 'HTTP ' . $status;
            }
            throw new RuntimeException('Microsoft Graph error: ' . $message);
        }

        return $decoded;
    }
}
