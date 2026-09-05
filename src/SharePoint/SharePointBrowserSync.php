<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use RiskAssessment\Repositories\SharePointSourceRepository;
use RuntimeException;

/**
 * Short-lived token so a SharePoint browser tab (MFA session) can POST a catalog listing.
 */
final class SharePointBrowserSync
{
    private const TOKEN_TTL_SECONDS = 1800;
    private const MAX_ROWS = 25000;

    public function __construct(
        private readonly SharePointSourceRepository $sources,
    ) {
    }

    /**
     * @return array{token: string, expires_at: int, source_key: string, title: string, folder_url: string, site_host: string, site_path: string, folder_path: string, server_relative_folder: string, import_url: string}
     */
    public function prepare(string $sourceKey, string $importUrl): array
    {
        $source = $this->sources->requireByKey($sourceKey);
        $parsed = SharePointFolderUrl::parse((string) $source['folder_url']);
        $issued = $this->sources->issueSyncToken((string) $source['source_key'], self::TOKEN_TTL_SECONDS);

        return [
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'source_key' => (string) $source['source_key'],
            'title' => (string) $source['title'],
            'folder_url' => $parsed['folder_url'],
            'site_host' => $parsed['site_host'],
            'site_path' => $parsed['site_path'],
            'folder_path' => $parsed['folder_path'],
            'server_relative_folder' => $parsed['server_relative_folder'],
            'import_url' => $importUrl,
        ];
    }

    public function assertValidToken(string $sourceKey, string $token): void
    {
        $this->sources->assertSyncToken($sourceKey, $token);
    }

    public function clearToken(string $sourceKey): void
    {
        $this->sources->clearSyncToken($sourceKey);
    }

    public static function maxRows(): int
    {
        return self::MAX_ROWS;
    }

    /**
     * Allow CORS only from the source SharePoint host.
     */
    public function applyCorsHeaders(string $origin, string $siteHost): void
    {
        $origin = trim($origin);
        $host = strtolower(trim($siteHost)) ?: 'ahsonline.sharepoint.com';
        $allowed = [
            'https://' . $host,
            'https://' . preg_replace('/\.sharepoint\.com$/i', '-my.sharepoint.com', $host),
        ];
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Sync-Token, X-Source-Key');
            header('Access-Control-Max-Age: 600');
            header('Vary: Origin');
        }
    }
}
