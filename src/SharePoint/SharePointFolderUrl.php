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
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('SharePoint folder URL is not a valid URL.');
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !str_ends_with($host, 'sharepoint.com')) {
            throw new RuntimeException('URL host must be a *.sharepoint.com site.');
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $idPath = trim(rawurldecode((string) ($query['id'] ?? '')));
        $path = (string) ($parts['path'] ?? '');

        // Modern sharing links: /:f:/r/teams/.../Shared Documents/Forms/AllItems.aspx
        if ($idPath === '' && preg_match('#/(?:teams|sites)/[^/]+/#i', $path)) {
            $decodedPath = rawurldecode($path);
            if (preg_match('#(/(?:teams|sites)/[^/]+)/Shared Documents(?:/Forms/AllItems\.aspx)?$#i', $decodedPath, $m)) {
                // Not enough — need folder under Shared Documents from id= preferably.
            }
            if (preg_match('#/(?:teams|sites)/([^/]+)/Shared%20Documents/#i', $path)
                || preg_match('#/(?:teams|sites)/([^/]+)/Shared Documents/#i', $decodedPath)) {
                // fall through; id may still be empty
            }
        }

        if ($idPath === '') {
            throw new RuntimeException(
                'Could not find the folder path. Use a Forms/AllItems.aspx link that includes an id=… query, '
                . 'or paste a link like …/AllItems.aspx?id=/teams/…/Shared Documents/Architectural Projects [Public].'
            );
        }

        $idPath = str_replace('\\', '/', $idPath);
        $idPath = '/' . trim($idPath, '/');

        if (!preg_match('#^(/(?:teams|sites)/[^/]+)/Shared Documents/(.+)$#i', $idPath, $m)) {
            throw new RuntimeException(
                'Folder id path must look like /teams/SiteName/Shared Documents/Your Folder.'
            );
        }

        $sitePath = $m[1];
        $folderPath = trim(str_replace('\\', '/', $m[2]), '/');
        if ($folderPath === '') {
            throw new RuntimeException('Folder path under Shared Documents is empty.');
        }

        $serverRelative = $sitePath . '/Shared Documents/' . $folderPath;
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
        $id = $sitePath . '/Shared Documents/' . $folderPath;

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
}
