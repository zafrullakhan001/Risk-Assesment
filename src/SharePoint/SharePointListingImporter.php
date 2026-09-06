<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\Repositories\SettingsRepository;
use RuntimeException;

final class SharePointListingImporter
{
    public function __construct(
        private readonly SharePointCatalogRepository $catalog,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Import an Excel or CSV listing into the catalog (replaces existing items for the given source).
     *
     * Expected flexible headers: Name, Path/Folder, Type, URL/Link.
     *
     * @param array{source_key?: string, site_host?: string, site_path?: string, folder_path?: string}|null $source
     * @return array{ok: bool, count: int, projects: int, message: string, source_key: string}
     */
    public function importFile(string $absolutePath, string $originalFilename = '', ?array $source = null): array
    {
        if ($absolutePath === '' || !is_readable($absolutePath)) {
            throw new RuntimeException('Import file is missing or unreadable.');
        }

        $ext = strtolower(pathinfo($originalFilename !== '' ? $originalFilename : $absolutePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException('Import must be an .xlsx, .xls, or .csv file.');
        }

        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        if ($rows === []) {
            throw new RuntimeException('The import file is empty.');
        }

        $headerRow = array_shift($rows);
        if (!is_array($headerRow)) {
            throw new RuntimeException('The import file has no header row.');
        }

        $map = $this->mapHeaders($headerRow);
        if (!isset($map['name']) && !isset($map['path'])) {
            throw new RuntimeException(
                'Could not find a Name or Path column. Use headers like Name, Path, Type, URL.'
            );
        }

        $assocRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assocRows[] = [
                'name' => trim((string) ($row[$map['name'] ?? -1] ?? '')),
                'path' => trim((string) ($row[$map['path'] ?? -1] ?? '')),
                'type' => trim((string) ($row[$map['type'] ?? -1] ?? '')),
                'url' => trim((string) ($row[$map['url'] ?? -1] ?? '')),
                'modified' => trim((string) ($row[$map['modified'] ?? -1] ?? '')),
                'modified_by' => trim((string) ($row[$map['modified_by'] ?? -1] ?? '')),
                'person' => trim((string) ($row[$map['person'] ?? -1] ?? '')),
                'created' => trim((string) ($row[$map['created'] ?? -1] ?? '')),
                'size' => trim((string) ($row[$map['size'] ?? -1] ?? '')),
            ];
        }

        return $this->importAssocRows($assocRows, 'imported', $source);
    }

    /**
     * Import associative listing rows (CSV columns or MFA browser sync JSON).
     *
     * @param list<array{
     *   name?: string,
     *   path?: string,
     *   type?: string,
     *   url?: string,
     *   modified?: string,
     *   modified_by?: string,
     *   person?: string,
     *   created?: string,
     *   date_created?: string,
     *   size?: int|string,
     *   size_bytes?: int|string
     * }> $rows
     * @param array{source_key?: string, site_host?: string, site_path?: string, folder_path?: string}|null $source
     * @return array{ok: bool, count: int, projects: int, message: string, source_key: string}
     */
    public function importAssocRows(array $rows, string $statusLabel = 'imported', ?array $source = null): array
    {
        $sourceKey = trim((string) ($source['source_key'] ?? SharePointCatalogRepository::SOURCE_DEFAULT));
        if ($sourceKey === '') {
            $sourceKey = SharePointCatalogRepository::SOURCE_DEFAULT;
        }
        $host = trim((string) ($source['site_host'] ?? ''))
            ?: (trim($this->settings->get('sharepoint_site_host', 'ahsonline.sharepoint.com')) ?: 'ahsonline.sharepoint.com');
        $sitePath = trim((string) ($source['site_path'] ?? ''))
            ?: trim($this->settings->get('sharepoint_site_path', '/teams/AITTechnologyEngagement'));
        if ($sitePath === '') {
            $sitePath = '/teams/AITTechnologyEngagement';
        }
        if (!str_starts_with($sitePath, '/')) {
            $sitePath = '/' . $sitePath;
        }
        $folderPath = trim((string) ($source['folder_path'] ?? ''))
            ?: (trim($this->settings->get('sharepoint_folder_path', 'Architectural Projects [Public]')) ?: 'Architectural Projects [Public]');

        $items = [];
        $seenKeys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $path = trim((string) ($row['path'] ?? ''));
            $typeRaw = trim((string) ($row['type'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            $modified = trim((string) ($row['modified'] ?? ''));
            $modifiedBy = trim((string) ($row['modified_by'] ?? ''));
            $person = trim((string) ($row['person'] ?? ''));
            $created = trim((string) ($row['created'] ?? $row['date_created'] ?? ''));
            $sizeBytes = $this->parseSizeBytes($row['size'] ?? $row['size_bytes'] ?? 0);

            if ($name === '' && $path === '') {
                continue;
            }

            $relative = $this->normalizeRelativePath($path !== '' ? $path : $name, $folderPath);
            if ($relative === '') {
                continue;
            }

            $parts = explode('/', $relative);
            $projectName = (string) ($parts[0] ?? '');
            if ($projectName === '') {
                continue;
            }
            if ($name === '') {
                $name = (string) end($parts);
            }

            $itemType = $this->detectType($typeRaw, $name, $url);
            if ($url === '') {
                $url = $this->buildSharePointUrl($host, $sitePath, $folderPath, $relative, $itemType === 'folder');
            }
            if (!$this->isAllowedUrl($url)) {
                continue;
            }

            $itemKey = 'import:' . hash('sha256', strtolower($relative) . '|' . $itemType);
            if (isset($seenKeys[$itemKey])) {
                continue;
            }

            $partsCount = count($parts);
            $parentPath = $partsCount > 1
                ? implode('/', array_slice($parts, 0, -1))
                : '';
            $parentKey = $parentPath !== ''
                ? 'import:' . hash('sha256', strtolower($parentPath) . '|folder')
                : '';

            // Ensure project root folder exists as an item (before marking this row seen).
            $rootKey = 'import:' . hash('sha256', strtolower($projectName) . '|folder');
            if ($itemKey === $rootKey) {
                // This row IS the project folder — store it with the provided/built URL.
                $seenKeys[$itemKey] = true;
                $items[] = [
                    'item_key' => $itemKey,
                    'parent_item_key' => '',
                    'project_name' => $projectName,
                    'name' => $projectName,
                    'item_type' => 'folder',
                    'web_url' => $url,
                    'relative_path' => $projectName,
                    'mime_type' => '',
                    'size_bytes' => $sizeBytes,
                    'last_modified' => $modified,
                    'date_created' => $created,
                    'modified_by' => $modifiedBy,
                    'person' => $person,
                ];
                continue;
            }

            if (!isset($seenKeys[$rootKey])) {
                $seenKeys[$rootKey] = true;
                $rootUrl = $this->buildSharePointUrl($host, $sitePath, $folderPath, $projectName, true);
                if ($this->isAllowedUrl($rootUrl)) {
                    $items[] = [
                        'item_key' => $rootKey,
                        'parent_item_key' => '',
                        'project_name' => $projectName,
                        'name' => $projectName,
                        'item_type' => 'folder',
                        'web_url' => $rootUrl,
                        'relative_path' => $projectName,
                        'mime_type' => '',
                        'size_bytes' => 0,
                        'last_modified' => '',
                        'date_created' => '',
                        'modified_by' => '',
                        'person' => '',
                    ];
                }
            }

            $seenKeys[$itemKey] = true;
            $items[] = [
                'item_key' => $itemKey,
                'parent_item_key' => $parentKey !== '' ? $parentKey : $rootKey,
                'project_name' => $projectName,
                'name' => $name,
                'item_type' => $itemType,
                'web_url' => $url,
                'relative_path' => $relative,
                'mime_type' => '',
                'size_bytes' => $sizeBytes,
                'last_modified' => $modified,
                'date_created' => $created,
                'modified_by' => $modifiedBy,
                'person' => $person,
            ];
        }

        if ($items === []) {
            throw new RuntimeException('No valid rows were found in the import.');
        }

        $count = $this->catalog->replaceForSource($sourceKey, $items);
        $projects = $this->catalog->countProjects($sourceKey);

        $status = preg_replace('/[^a-z0-9_]+/i', '', $statusLabel) ?: 'imported';
        $this->settings->set('sharepoint_last_synced_at', date('Y-m-d H:i:s'));
        $this->settings->set('sharepoint_last_sync_status', $status);
        $this->settings->set('sharepoint_last_sync_error', '');
        $this->settings->set('sharepoint_last_item_count', (string) $count);

        $verb = $status === 'mfa_sync' ? 'Synced' : 'Imported';

        return [
            'ok' => true,
            'count' => $count,
            'projects' => $projects,
            'source_key' => $sourceKey,
            'message' => $verb . ' ' . $count . ' item' . ($count === 1 ? '' : 's')
                . ' across ' . $projects . ' project folder' . ($projects === 1 ? '' : 's') . '.',
        ];
    }

    /**
     * @param array<int, mixed> $headerRow
     * @return array{name?: int, path?: int, type?: int, url?: int, modified?: int, modified_by?: int, person?: int, created?: int, size?: int}
     */
    private function mapHeaders(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $index => $label) {
            $key = $this->normalizeHeader((string) $label);
            if ($key === '') {
                continue;
            }
            if (in_array($key, ['name', 'filename', 'title', 'item', 'itemname'], true) && !isset($map['name'])) {
                $map['name'] = (int) $index;
            } elseif (in_array($key, ['path', 'folder', 'folderpath', 'relativepath', 'filepath', 'fullpath', 'location'], true)
                && !isset($map['path'])) {
                $map['path'] = (int) $index;
            } elseif (in_array($key, ['type', 'itemtype', 'kind'], true) && !isset($map['type'])) {
                $map['type'] = (int) $index;
            } elseif (in_array($key, ['url', 'link', 'weburl', 'href', 'uri'], true) && !isset($map['url'])) {
                $map['url'] = (int) $index;
            } elseif (in_array($key, ['modified', 'modifieddate', 'lastmodified', 'datemodified'], true)
                && !isset($map['modified'])) {
                $map['modified'] = (int) $index;
            } elseif (in_array($key, ['modifiedby', 'editor', 'lastmodifiedby'], true)
                && !isset($map['modified_by'])) {
                $map['modified_by'] = (int) $index;
            } elseif (in_array($key, ['person', 'owner', 'assignedto', 'createdby', 'author', 'createdbyuser'], true)
                && !isset($map['person'])) {
                $map['person'] = (int) $index;
            } elseif (in_array($key, ['created', 'datecreated', 'createddate', 'timecreated'], true)
                && !isset($map['created'])) {
                $map['created'] = (int) $index;
            } elseif (in_array($key, ['size', 'filesize', 'sizebytes', 'bytes'], true) && !isset($map['size'])) {
                $map['size'] = (int) $index;
            }
        }

        return $map;
    }

    private function normalizeHeader(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/[^a-z0-9]+/', '', $label) ?? $label;

        return $label;
    }

    private function normalizeRelativePath(string $path, string $folderPath): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        // Strip leading "Shared Documents/Architectural Projects [Public]/" etc.
        $folderNorm = trim(str_replace('\\', '/', $folderPath), '/');
        $lower = mb_strtolower($path);
        $prefixCandidates = [
            'shared documents/' . mb_strtolower($folderNorm) . '/',
            mb_strtolower($folderNorm) . '/',
            'documents/' . mb_strtolower($folderNorm) . '/',
        ];
        foreach ($prefixCandidates as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        return trim(str_replace('\\', '/', $path), '/');
    }

    private function detectType(string $typeRaw, string $name, string $url): string
    {
        $type = mb_strtolower(trim($typeRaw));
        if (str_contains($type, 'folder') || $type === 'dir' || $type === 'directory') {
            return 'folder';
        }
        if (str_contains($type, 'file') || str_contains($type, 'document')) {
            return 'file';
        }
        // Heuristic: no extension ⇒ folder.
        $base = basename(str_replace('\\', '/', $name !== '' ? $name : $url));
        if ($base !== '' && !str_contains($base, '.')) {
            return 'folder';
        }

        return 'file';
    }

    private function buildSharePointUrl(
        string $host,
        string $sitePath,
        string $folderPath,
        string $relative,
        bool $isFolder
    ): string {
        $sitePath = '/' . trim($sitePath, '/');
        $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $full = $folderPath . ($relative !== '' ? '/' . $relative : '');

        // Encode each path segment for a Forms AllItems / Doc.aspx style deep link.
        $encodedSegments = [];
        foreach (explode('/', $full) as $segment) {
            if ($segment === '') {
                continue;
            }
            $encodedSegments[] = rawurlencode($segment);
        }
        $idPath = $sitePath . '/Shared Documents/' . implode('/', $encodedSegments);
        // Prefer a direct folder view when it is a folder; otherwise a document path.
        if ($isFolder) {
            return 'https://' . $host . $sitePath . '/Shared%20Documents/Forms/AllItems.aspx?id='
                . rawurlencode($sitePath . '/Shared Documents/' . $full);
        }

        return 'https://' . $host . $sitePath . '/Shared%20Documents/' . implode('/', $encodedSegments);
    }

    private function parseSizeBytes(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) $value);
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $raw) === 1) {
            return max(0, (int) $raw);
        }
        if (preg_match('/^([\d,.]+)\s*([kmgt]i?b)?$/i', $raw, $match) === 1) {
            $number = (float) str_replace(',', '', $match[1]);
            $unit = strtolower($match[2] ?? '');
            $multiplier = match ($unit) {
                'kb', 'kib' => 1024,
                'mb', 'mib' => 1024 ** 2,
                'gb', 'gib' => 1024 ** 3,
                'tb', 'tib' => 1024 ** 4,
                default => 1,
            };

            return max(0, (int) round($number * $multiplier));
        }

        return 0;
    }

    private function isAllowedUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
