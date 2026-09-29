<?php

declare(strict_types=1);

/**
 * ZIP backup of one Ticket Dossier project, or every project, including original files.
 */
final class ProjectPackager
{
    public const FORMAT = 'architecture-risk.ticket-dossier.zip.v1';
    private const ARCHIVE_FILE_EXTENSIONS = [
        'pdf', 'json', 'txt', 'log', 'csv',
        'docx', 'xlsx', 'pptx',
        'png', 'jpg', 'jpeg',
    ];
    private const MAX_ARCHIVE_FILE_BYTES = 25 * 1024 * 1024;
    private const ARCHIVE_FILE_KINDS = ['ddr', 'demand', 'story', 'task', 'packet', 'attachment'];
    /** Zip entries keep the dossier file names, so bound the readable parts. */
    private const MAX_ARCHIVE_NAME_LENGTH = 160;
    private const MAX_ARCHIVE_SEGMENT_LENGTH = 120;
    private const MAX_ARCHIVE_PATH_SEGMENTS = 4;
    private const MAX_ARCHIVE_ENTRY_LENGTH = 200;

    /**
     * Stream a ZIP of one project (id > 0) or every project (id = 0).
     */
    public static function download(int $projectId = 0): void
    {
        $built = self::buildZip($projectId);
        self::streamDownload($built['path'], $built['filename']);
    }

    /**
     * @return array{path: string, filename: string}
     */
    public static function buildZip(int $projectId = 0): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP support is not available on this server (PHP ZipArchive).');
        }

        $projects = [];
        if ($projectId > 0) {
            $project = ProjectRepository::find($projectId);
            if ($project === null) {
                throw new InvalidArgumentException('Project not found.');
            }
            $projects[] = $project;
        } else {
            $projects = ProjectRepository::all();
            if ($projects === []) {
                throw new InvalidArgumentException('There are no projects to export.');
            }
            if (count($projects) > TD_MAX_ZIP_PROJECTS) {
                throw new InvalidArgumentException('Too many projects to export in one ZIP (max ' . TD_MAX_ZIP_PROJECTS . ').');
            }
        }

        $tmp = self::tempPath('td-export-', '.zip');
        $zip = new ZipArchive();
        $opened = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }

        try {
            $kind = count($projects) === 1 ? 'project' : 'bundle';
            $manifest = [
                'format' => self::FORMAT,
                'kind' => $kind,
                'exported_at' => gmdate('c'),
                'project_count' => count($projects),
            ];
            $zip->addFromString(
                'manifest.json',
                json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}'
            );

            foreach ($projects as $index => $project) {
                $folder = 'projects/' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
                self::addProjectToZip($zip, $project, $folder);
            }

            $zip->close();
        } catch (Throwable $e) {
            $zip->close();
            @unlink($tmp);
            throw $e;
        }

        if (!is_file($tmp)) {
            throw new RuntimeException('ZIP file was not written.');
        }

        if ($projectId > 0) {
            $slug = dossierFilenameSlug((string) ($projects[0]['title'] ?? 'dossier'));
            $filename = sprintf('ticket-dossier-%d-%s.zip', $projectId, $slug);
        } else {
            $filename = 'ticket-dossier-all-' . gmdate('Ymd-His') . '.zip';
        }

        return ['path' => $tmp, 'filename' => $filename];
    }

    /**
     * Import a ZIP created by download(). Creates new projects (does not overwrite IDs).
     *
     * @param array<string, mixed>|null $currentUser
     * @return array{imported: int, last_id: int, warnings: list<string>}
     */
    public static function import(string $zipPath, ?array $currentUser): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP support is not available on this server (PHP ZipArchive).');
        }
        if (!is_readable($zipPath)) {
            throw new InvalidArgumentException('Cannot read the ZIP file.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new InvalidArgumentException('That file is not a readable ZIP archive.');
        }

        $extractDir = self::tempPath('td-import-', '');
        if (!mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            $zip->close();
            throw new RuntimeException('Could not create a temporary folder for import.');
        }

        $imported = 0;
        $warnings = [];
        $createdIds = [];

        try {
            self::extractSafe($zip, $extractDir);
            $zip->close();
            $zip = null;

            $manifestPath = $extractDir . '/manifest.json';
            if (!is_file($manifestPath)) {
                throw new InvalidArgumentException('This ZIP is not a Ticket Dossier backup (missing manifest.json).');
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
                throw new InvalidArgumentException('This ZIP is not a Ticket Dossier backup (unknown format).');
            }

            $projectFiles = glob($extractDir . '/projects/*/project.json') ?: [];
            sort($projectFiles);
            if ($projectFiles === []) {
                throw new InvalidArgumentException('This ZIP does not contain any dossier projects.');
            }
            if (count($projectFiles) > TD_MAX_ZIP_PROJECTS) {
                throw new InvalidArgumentException('Too many projects in this ZIP (max ' . TD_MAX_ZIP_PROJECTS . ').');
            }

            $owner = projectOwnerFromUser($currentUser);

            foreach ($projectFiles as $jsonPath) {
                $payload = json_decode((string) file_get_contents($jsonPath), true);
                if (!is_array($payload)) {
                    $warnings[] = 'Skipped a project with invalid JSON.';
                    continue;
                }
                $folder = dirname($jsonPath);
                $newId = self::importOne($payload, $folder . '/files', $owner, $warnings);
                $createdIds[] = $newId;
                $imported++;
            }
        } catch (Throwable $e) {
            foreach ($createdIds as $id) {
                try {
                    ProjectRepository::delete((int) $id);
                } catch (Throwable) {
                    // Keep the original error.
                }
            }
            self::removeDirectory($extractDir);
            if ($zip instanceof ZipArchive) {
                @$zip->close();
            }
            throw $e;
        }

        self::removeDirectory($extractDir);

        return [
            'imported' => $imported,
            'last_id' => $createdIds !== [] ? (int) $createdIds[count($createdIds) - 1] : 0,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $project
     */
    private static function addProjectToZip(ZipArchive $zip, array $project, string $folder): void
    {
        $id = (int) ($project['id'] ?? 0);
        $files = $id > 0 ? ProjectRepository::filesFor($id) : [];
        $archiveNames = self::archiveNames($files);
        $payload = self::payload($project, $files, $archiveNames);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode project ' . $id . ' for ZIP export.');
        }
        $zip->addFromString($folder . '/project.json', $json);

        $dir = TD_STORAGE_DIR . '/' . $id;
        foreach ($files as $file) {
            $fileId = (int) ($file['id'] ?? 0);
            $stored = safeBasename((string) ($file['stored_name'] ?? ''));
            if ($stored === '' || $stored === 'file' || !isset($archiveNames[$fileId])) {
                continue;
            }
            $path = $dir . '/' . $stored;
            if (!is_file($path)) {
                continue;
            }
            // Store the file under the name shown in the dossier file list
            // instead of its internal stored_* name.
            $zip->addFile($path, $folder . '/files/' . $archiveNames[$fileId]);
        }
    }

    /**
     * Zip entry path (relative to the project's files folder) for each file,
     * keyed by file id. Uses the dossier file name, kept verbatim except where
     * a character is unsafe for ZIP extraction, and de-duplicates repeats as
     * "name (2).ext", "name (3).ext", ...
     *
     * @param list<array<string, mixed>> $files
     * @return array<int, string>
     */
    private static function archiveNames(array $files): array
    {
        $names = [];
        $used = [];
        foreach ($files as $file) {
            $fileId = (int) ($file['id'] ?? 0);
            if ($fileId <= 0) {
                continue;
            }
            $stored = safeBasename((string) ($file['stored_name'] ?? ''));
            if ($stored === '' || $stored === 'file') {
                continue;
            }
            $name = self::safeArchiveName((string) ($file['original_name'] ?? ''), $stored);
            $name = self::uniqueArchiveName($name, $used);
            $used[strtolower($name)] = true;
            $names[$fileId] = $name;
        }

        return $names;
    }

    /**
     * Keep the dossier file name readable in the ZIP while removing path
     * traversal, control characters, and characters that are invalid on
     * Windows. Embedded "/" stays as a folder separator, which mirrors the
     * ticket-prefixed names shown in the dossier file list.
     */
    private static function safeArchiveName(string $original, string $fallback): string
    {
        $name = str_replace('\\', '/', $original);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $segments = [];
        foreach (explode('/', $name) as $segment) {
            $segment = trim(str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $segment));
            $segment = trim($segment, ' .');
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            if (strlen($segment) > self::MAX_ARCHIVE_SEGMENT_LENGTH) {
                $segment = substr($segment, 0, self::MAX_ARCHIVE_SEGMENT_LENGTH);
            }
            $segments[] = $segment;
        }
        if ($segments === []) {
            return $fallback;
        }
        if (count($segments) > self::MAX_ARCHIVE_PATH_SEGMENTS) {
            $segments = array_slice($segments, -self::MAX_ARCHIVE_PATH_SEGMENTS);
        }

        return self::shortArchiveName(implode('/', $segments), $fallback);
    }

    private static function shortArchiveName(string $name, string $fallback): string
    {
        if (strlen($name) <= self::MAX_ARCHIVE_NAME_LENGTH) {
            return $name;
        }

        $ext = extensionOf($name);
        $suffix = $ext !== '' ? '.' . $ext : '';
        $base = $suffix !== '' ? substr($name, 0, -strlen($suffix)) : $name;
        $room = self::MAX_ARCHIVE_NAME_LENGTH - strlen($suffix);
        $base = rtrim(substr($base, 0, max(1, $room)), " ./");
        if ($base === '') {
            return $fallback;
        }

        return $base . $suffix;
    }

    /**
     * @param array<string, bool> $used lower-cased entry names already taken
     */
    private static function uniqueArchiveName(string $name, array $used): string
    {
        if (!isset($used[strtolower($name)])) {
            return $name;
        }

        $ext = extensionOf($name);
        $suffix = $ext !== '' ? '.' . $ext : '';
        $base = $suffix !== '' ? substr($name, 0, -strlen($suffix)) : $name;
        $copy = 2;
        while (true) {
            $candidate = $base . ' (' . $copy . ')' . $suffix;
            if (!isset($used[strtolower($candidate)])) {
                return $candidate;
            }
            $copy++;
        }
    }


    /**
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>> $files
     * @param array<int, string> $archiveNames zip entry name per file id
     * @return array<string, mixed>
     */
    private static function payload(array $project, array $files, array $archiveNames = []): array
    {
        $parsed = json_decode((string) ($project['parsed_json'] ?? ''), true);
        if (!is_array($parsed)) {
            $parsed = [];
        }
        $sources = json_decode((string) ($project['sources_json'] ?? ''), true);
        if (!is_array($sources)) {
            $sources = [];
        }

        $manifest = [];
        foreach ($files as $file) {
            $stored = safeBasename((string) ($file['stored_name'] ?? ''));
            $archive = (string) (
                $archiveNames[(int) ($file['id'] ?? 0)]
                ?? ($stored !== '' ? $stored : '')
            );
            $manifest[] = [
                'kind' => (string) ($file['kind'] ?? ''),
                'original_name' => (string) ($file['original_name'] ?? ''),
                'archive_name' => $archive,
                'stored_name' => $stored,
                'size_bytes' => (int) ($file['size_bytes'] ?? 0),
            ];
        }

        return [
            'title' => (string) ($project['title'] ?? 'Untitled Project'),
            'vendor' => (string) ($project['vendor'] ?? ''),
            'demand_number' => (string) ($project['demand_number'] ?? ''),
            'story_number' => (string) ($project['story_number'] ?? ''),
            'task_number' => (string) ($project['task_number'] ?? ''),
            'ddr_number' => (string) ($project['ddr_number'] ?? ''),
            'demand_state' => (string) ($project['demand_state'] ?? ''),
            'story_state' => (string) ($project['story_state'] ?? ''),
            'task_state' => (string) ($project['task_state'] ?? ''),
            'ddr_state' => (string) ($project['ddr_state'] ?? ''),
            'owner_username' => (string) ($project['owner_username'] ?? ''),
            'owner_display_name' => (string) ($project['owner_display_name'] ?? ''),
            'sources' => [
                'demand' => !empty($sources['demand']),
                'story' => !empty($sources['story']),
                'task' => !empty($sources['task']),
                'ddr' => !empty($sources['ddr']),
            ],
            'parsed' => $parsed,
            'files' => $manifest,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{owner_user_id: int|null, owner_username: string, owner_display_name: string, owner_auth_source: string} $owner
     * @param list<string> $warnings
     */
    private static function importOne(array $payload, string $filesDir, array $owner, array &$warnings): int
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $title = 'Imported project';
        }
        if (strlen($title) > 200) {
            $title = substr($title, 0, 200);
        }
        $vendor = trim((string) ($payload['vendor'] ?? ''));
        if (strlen($vendor) > 200) {
            $vendor = substr($vendor, 0, 200);
        }

        $parsed = is_array($payload['parsed'] ?? null) ? $payload['parsed'] : [];
        $sourcesIn = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
        $sources = [];
        foreach (TD_SOURCE_KINDS as $kind) {
            $sources[$kind] = !empty($sourcesIn[$kind]);
        }

        $projectId = ProjectRepository::create([
            'title' => $title,
            'vendor' => $vendor,
            'demand_number' => substr(trim((string) ($payload['demand_number'] ?? '')), 0, 80),
            'story_number' => substr(trim((string) ($payload['story_number'] ?? '')), 0, 80),
            'task_number' => substr(trim((string) ($payload['task_number'] ?? '')), 0, 80),
            'ddr_number' => substr(trim((string) ($payload['ddr_number'] ?? '')), 0, 80),
            'demand_state' => substr(trim((string) ($payload['demand_state'] ?? '')), 0, 80),
            'story_state' => substr(trim((string) ($payload['story_state'] ?? '')), 0, 80),
            'task_state' => substr(trim((string) ($payload['task_state'] ?? '')), 0, 80),
            'ddr_state' => substr(trim((string) ($payload['ddr_state'] ?? '')), 0, 80),
            'sources' => $sources,
            'parsed' => $parsed,
            'owner_user_id' => $owner['owner_user_id'],
            'owner_username' => $owner['owner_username'],
            'owner_display_name' => $owner['owner_display_name'] !== ''
                ? $owner['owner_display_name']
                : (string) ($payload['owner_display_name'] ?? ''),
            'owner_auth_source' => $owner['owner_auth_source'],
        ], []);

        $storageDir = TD_STORAGE_DIR . '/' . $projectId;
        if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
            ProjectRepository::delete($projectId);
            throw new RuntimeException('Could not create storage for imported project.');
        }

        $listed = is_array($payload['files'] ?? null) ? $payload['files'] : [];
        $db = getDb();
        $now = nowUtc();
        $stmt = $db->prepare(
            'INSERT INTO project_files (project_id, kind, original_name, stored_name, size_bytes, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $importedFileCount = 0;
        foreach ($listed as $file) {
            if (!is_array($file)) {
                continue;
            }
            $kind = (string) ($file['kind'] ?? '');
            if (!in_array($kind, self::ARCHIVE_FILE_KINDS, true)) {
                $warnings[] = 'Skipped a file with an unknown type in ' . $title . '.';
                continue;
            }
            $stored = safeBasename((string) ($file['stored_name'] ?? ''));
            // Keep the dossier name (including a ticket prefix such as
            // TASK123/name.pdf) so an export/import round trip is lossless.
            $original = self::safeArchiveName((string) ($file['original_name'] ?? ''), $stored);
            $archived = self::safeArchiveName(
                (string) ($file['archive_name'] ?? ''),
                $stored
            );
            $resolved = self::resolveImportSource($filesDir, [$archived, $stored]);
            if ($resolved === null) {
                $warnings[] = 'Missing file for ' . $title . ' (' . $kind . ').';
                continue;
            }
            $src = $resolved['path'];
            $archiveExt = extensionOf($resolved['name']);
            $originalExt = extensionOf($original);
            $storedExt = extensionOf($stored);
            $ext = '';
            foreach ([$archiveExt, $originalExt, $storedExt] as $candidateExt) {
                if (in_array($candidateExt, self::ARCHIVE_FILE_EXTENSIONS, true)) {
                    $ext = $candidateExt;
                    break;
                }
            }
            if ($ext === '') {
                $warnings[] = 'Skipped disallowed file type in ' . $title . '.';
                continue;
            }
            $size = (int) filesize($src);
            if ($size <= 0 || $size > self::MAX_ARCHIVE_FILE_BYTES) {
                $warnings[] = 'Skipped oversized file in ' . $title . '.';
                continue;
            }

            $storedName = $kind . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $storageDir . '/' . $storedName;
            if (!@copy($src, $dest)) {
                ProjectRepository::delete($projectId);
                throw new RuntimeException('Could not store imported file for ' . $title . '.');
            }

            $stmt->execute([$projectId, $kind, $original, $storedName, $size, $now]);
            $importedFileCount++;
            if (in_array($kind, TD_SOURCE_KINDS, true)) {
                $sources[$kind] = true;
            }
        }

        if ($importedFileCount > 0) {
            ProjectRepository::updateParsed($projectId, [
                'title' => $title,
                'vendor' => $vendor,
                'demand_number' => substr(trim((string) ($payload['demand_number'] ?? '')), 0, 80),
                'story_number' => substr(trim((string) ($payload['story_number'] ?? '')), 0, 80),
                'task_number' => substr(trim((string) ($payload['task_number'] ?? '')), 0, 80),
                'ddr_number' => substr(trim((string) ($payload['ddr_number'] ?? '')), 0, 80),
                'demand_state' => substr(trim((string) ($payload['demand_state'] ?? '')), 0, 80),
                'story_state' => substr(trim((string) ($payload['story_state'] ?? '')), 0, 80),
                'task_state' => substr(trim((string) ($payload['task_state'] ?? '')), 0, 80),
                'ddr_state' => substr(trim((string) ($payload['ddr_state'] ?? '')), 0, 80),
                'sources' => $sources,
                'parsed' => $parsed,
            ]);
        }

        return $projectId;
    }

    private static function extractSafe(ZipArchive $zip, string $extractDir): void
    {
        $count = $zip->numFiles;
        if ($count <= 0) {
            throw new InvalidArgumentException('The ZIP archive is empty.');
        }
        if ($count > 2000) {
            throw new InvalidArgumentException('The ZIP archive has too many entries.');
        }

        $extractReal = realpath($extractDir);
        if ($extractReal === false) {
            throw new RuntimeException('Invalid import folder.');
        }

        $uncompressed = 0;
        $maxUncompressed = 200 * 1024 * 1024;

        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') {
                continue;
            }
            $name = str_replace('\\', '/', $name);
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (!self::isSafeZipPath($name)) {
                throw new InvalidArgumentException('The ZIP contains an unsafe path and was rejected.');
            }
            $stat = $zip->statIndex($i);
            $size = (int) ($stat['size'] ?? 0);
            $uncompressed += $size;
            if ($uncompressed > $maxUncompressed) {
                throw new InvalidArgumentException('The ZIP is too large when unpacked.');
            }
            $dest = $extractReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new RuntimeException('Could not extract ZIP folder.');
            }
            $destRealDir = realpath($destDir);
            if ($destRealDir === false || !str_starts_with($destRealDir, $extractReal)) {
                throw new InvalidArgumentException('The ZIP contains an unsafe path and was rejected.');
            }
            $stream = $zip->getStream($name);
            if (!is_resource($stream)) {
                continue;
            }
            $out = fopen($dest, 'wb');
            if ($out === false) {
                fclose($stream);
                throw new RuntimeException('Could not extract a file from the ZIP.');
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
        }
    }

    /**
     * Entry names keep the dossier file names, so characters such as spaces,
     * parentheses, and commas are allowed. Path traversal, control characters,
     * Windows-invalid characters, and unknown file types are rejected.
     */
    private static function isSafeZipPath(string $name): bool
    {
        if ($name === '' || strlen($name) > self::MAX_ARCHIVE_ENTRY_LENGTH) {
            return false;
        }
        if (str_contains($name, "\0") || str_starts_with($name, '/')) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return false;
        }

        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
            if (strpbrk($segment, '<>:"|?*\\') !== false) {
                return false;
            }
            // Windows drops a trailing dot or space, which would silently
            // rename the extracted file.
            if (rtrim($segment, ' .') !== $segment) {
                return false;
            }
        }

        $count = count($segments);
        if ($count === 1) {
            return $segments[0] === 'manifest.json';
        }
        if ($segments[0] !== 'projects' || !self::isSafeArchiveFolder($segments[1])) {
            return false;
        }
        if ($count === 3 && $segments[2] === 'project.json') {
            return true;
        }
        if ($count < 4 || $segments[2] !== 'files') {
            return false;
        }

        $fileSegments = array_slice($segments, 3);
        if (count($fileSegments) > self::MAX_ARCHIVE_PATH_SEGMENTS) {
            return false;
        }
        foreach ($fileSegments as $segment) {
            if (strlen($segment) > self::MAX_ARCHIVE_SEGMENT_LENGTH) {
                return false;
            }
        }

        return in_array(extensionOf($segments[$count - 1]), self::ARCHIVE_FILE_EXTENSIONS, true);
    }

    private static function isSafeArchiveFolder(string $folder): bool
    {
        if ($folder === '' || $folder === '.' || $folder === '..') {
            return false;
        }

        return preg_match('/^[0-9A-Za-z._-]{1,80}$/', $folder) === 1;
    }

    /**
     * Find the extracted file for one manifest entry, preferring the archive
     * name stored in project.json and falling back to the legacy stored_* name.
     * The resolved path must stay inside the project's files folder.
     *
     * @param list<string> $candidates
     * @return array{path: string, name: string}|null
     */
    private static function resolveImportSource(string $filesDir, array $candidates): ?array
    {
        $base = realpath($filesDir);
        if ($base === false) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $candidate = str_replace('\\', '/', trim($candidate));
            if ($candidate === '' || $candidate === 'file' || str_starts_with($candidate, '/')) {
                continue;
            }
            if (!in_array(extensionOf($candidate), self::ARCHIVE_FILE_EXTENSIONS, true)) {
                continue;
            }

            $segments = explode('/', $candidate);
            $safe = true;
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    $safe = false;
                    break;
                }
            }
            if (!$safe) {
                continue;
            }

            $path = $filesDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
            $real = realpath($path);
            if ($real === false || !is_file($real) || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                continue;
            }

            return ['path' => $real, 'name' => $candidate];
        }

        return null;
    }

    private static function streamDownload(string $path, string $filename): never
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        readfile($path);
        @unlink($path);
        exit;
    }

    private static function tempPath(string $prefix, string $suffix): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8)) . $suffix;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::removeDirectory($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }
}
