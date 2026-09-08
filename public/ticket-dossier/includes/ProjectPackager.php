<?php

declare(strict_types=1);

/**
 * ZIP backup of one Ticket Dossier project, or every project, including original files.
 */
final class ProjectPackager
{
    public const FORMAT = 'architecture-risk.ticket-dossier.zip.v1';

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
        $payload = self::payload($project, $files);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode project ' . $id . ' for ZIP export.');
        }
        $zip->addFromString($folder . '/project.json', $json);

        $dir = TD_STORAGE_DIR . '/' . $id;
        foreach ($files as $file) {
            $stored = safeBasename((string) ($file['stored_name'] ?? ''));
            if ($stored === '' || $stored === 'file') {
                continue;
            }
            $path = $dir . '/' . $stored;
            if (!is_file($path)) {
                continue;
            }
            $zip->addFile($path, $folder . '/files/' . $stored);
        }
    }

    /**
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private static function payload(array $project, array $files): array
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
            $manifest[] = [
                'kind' => (string) ($file['kind'] ?? ''),
                'original_name' => (string) ($file['original_name'] ?? ''),
                'stored_name' => safeBasename((string) ($file['stored_name'] ?? '')),
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
        $seenKinds = [];

        foreach ($listed as $file) {
            if (!is_array($file)) {
                continue;
            }
            $kind = (string) ($file['kind'] ?? '');
            if (!in_array($kind, TD_SOURCE_KINDS, true) || isset($seenKinds[$kind])) {
                continue;
            }
            $fromName = safeBasename((string) ($file['stored_name'] ?? ''));
            $original = safeBasename((string) ($file['original_name'] ?? $fromName));
            $src = $filesDir . '/' . $fromName;
            if ($fromName === '' || $fromName === 'file' || !is_file($src)) {
                $warnings[] = 'Missing file for ' . $title . ' (' . $kind . ').';
                continue;
            }
            $ext = extensionOf($original !== 'file' ? $original : $fromName);
            if (!in_array($ext, TD_ALLOWED_EXTENSIONS, true)) {
                $warnings[] = 'Skipped disallowed file type in ' . $title . '.';
                continue;
            }
            $size = (int) filesize($src);
            if ($size <= 0 || $size > TD_MAX_UPLOAD_BYTES) {
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
            $seenKinds[$kind] = true;
            $sources[$kind] = true;
        }

        if ($seenKinds !== []) {
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

    private static function isSafeZipPath(string $name): bool
    {
        if (str_contains($name, "\0") || str_starts_with($name, '/') || str_contains($name, '..')) {
            return false;
        }
        if ($name === 'manifest.json') {
            return true;
        }
        if (preg_match('#^projects/[0-9A-Za-z._-]{1,80}/project\.json$#', $name) === 1) {
            return true;
        }
        if (preg_match('#^projects/[0-9A-Za-z._-]{1,80}/files/[0-9A-Za-z._-]{1,160}$#', $name) === 1) {
            $ext = extensionOf($name);

            return in_array($ext, TD_ALLOWED_EXTENSIONS, true);
        }

        return false;
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
