<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use RuntimeException;

/**
 * Parse / build SharePoint folder browse URLs used by MFA browser sync.
 */
final class SharePointFolderUrl
{
    /**
     * @return array{
     *   folder_url: string,
     *   site_host: string,
     *   site_path: string,
     *   folder_path: string,
     *   server_relative_folder: string
     * }
     */
    public static function parse(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('SharePoint folder URL is required.');
        }
        if (!preg_match('#^https://#i', $url)) {
            throw new RuntimeException('SharePoint folder URL is not a valid URL.');
        }

        $parts = parse_url(str_replace(' ', '%20', $url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !str_ends_with($host, 'sharepoint.com')) {
            throw new RuntimeException('URL host must be a *.sharepoint.com site.');
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $idPath = self::folderPathFromQuery($query);
        if ($idPath === '') {
            $idPath = self::folderPathFromUrlPath((string) ($parts['path'] ?? ''));
        }

        if ($idPath === '') {
            throw new RuntimeException(
                'Could not find the folder path. Use a Forms/AllItems.aspx library or folder link '
                . '(viewid is fine), or paste a link like …/AllItems.aspx?id=/teams/…/Shared Documents/Your Folder.'
            );
        }

        $idPath = str_replace('\\', '/', $idPath);
        $idPath = '/' . trim($idPath, '/');

        if (!preg_match('#^(/(?:teams|sites)/[^/]+)/Shared Documents(?:/(.*))?$#i', $idPath, $m)) {
            throw new RuntimeException(
                'Folder id path must look like /teams/SiteName/Shared Documents or /teams/SiteName/Shared Documents/Your Folder.'
            );
        }

        $sitePath = $m[1];
        $folderPath = trim(str_replace('\\', '/', (string) ($m[2] ?? '')), '/');
        $serverRelative = $sitePath . '/Shared Documents' . ($folderPath !== '' ? '/' . $folderPath : '');
        $folderUrl = self::buildBrowseUrl($host, $sitePath, $folderPath);

        return [
            'folder_url' => $folderUrl,
            'site_host' => $host,
            'site_path' => $sitePath,
            'folder_path' => $folderPath,
            'server_relative_folder' => $serverRelative,
        ];
    }

    public static function buildBrowseUrl(string $host, string $sitePath, string $folderPath): string
    {
        $host = strtolower(trim($host));
        $sitePath = '/' . trim($sitePath, '/');
        $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
        $id = $sitePath . '/Shared Documents' . ($folderPath !== '' ? '/' . $folderPath : '');

        return 'https://' . $host . $sitePath . '/Shared%20Documents/Forms/AllItems.aspx?id='
            . rawurlencode($id) . '&p=true';
    }

    /**
     * Prefer saved folder_url; otherwise build from host/path/folder settings.
     */
    public static function resolveFromSettings(
        string $folderUrl,
        string $siteHost,
        string $sitePath,
        string $folderPath
    ): string {
        $folderUrl = trim($folderUrl);
        if ($folderUrl !== '') {
            try {
                return self::parse($folderUrl)['folder_url'];
            } catch (RuntimeException) {
                // fall through to rebuild
            }
        }

        $siteHost = trim($siteHost) !== '' ? trim($siteHost) : 'ahsonline.sharepoint.com';
        $sitePath = trim($sitePath) !== '' ? trim($sitePath) : '/teams/AITTechnologyEngagement';
        $folderPath = trim($folderPath) !== '' ? trim($folderPath) : 'Architectural Projects [Public]';

        return self::buildBrowseUrl($siteHost, $sitePath, $folderPath);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function folderPathFromQuery(array $query): string
    {
        foreach (['id', 'RootFolder', 'rootfolder'] as $key) {
            $raw = trim((string) ($query[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $decoded = trim(rawurldecode(str_replace('+', ' ', $raw)));
            if ($decoded !== '') {
                return $decoded;
            }
        }

        return '';
    }

    /**
     * Library-home AllItems links often have only viewid=… (no id=).
     * Also accepts modern sharing paths such as /:f:/r/teams/…/Shared Documents/…
     */
    private static function folderPathFromUrlPath(string $path): string
    {
        $decoded = rawurldecode(str_replace('\\', '/', $path));
        $decoded = preg_replace('#/+#', '/', $decoded) ?? $decoded;

        // /:f:/r/…  /:f:/s/…  /:u:/r/…
        if (preg_match('#^/:[a-z0-9]+:/[a-z0-9]+(/.*)$#i', $decoded, $m)) {
            $decoded = $m[1];
        }

        $decoded = preg_replace('#/Forms/AllItems\.aspx$#i', '', $decoded) ?? $decoded;
        $decoded = rtrim($decoded, '/');

        if (preg_match('#^/(?:teams|sites)/[^/]+/Shared Documents(?:/.*)?$#i', $decoded)) {
            return $decoded;
        }

        return '';
    }
}
