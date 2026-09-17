<?php
declare(strict_types=1);

final class ProjectImporter
{
    /**
     * Auto-import sample files once on a fresh install.
     * Never re-seeds after the user deletes all projects (that looked like delete failing).
     */
    public static function seedSampleIfEmpty(?array $currentUser = null): void
    {
        $flagPath = TD_DATABASE_DIR . '/.td_sample_seeded';
        if (is_file($flagPath)) {
            return;
        }

        if (ProjectRepository::count() > 0) {
            @file_put_contents($flagPath, gmdate('c') . "\n");
            return;
        }

        $root = is_dir(TD_SAMPLE_DIR) ? TD_SAMPLE_DIR : TD_ROOT;
        $candidates = [
            'ddr' => glob($root . '/DDR_*.json') ?: [],
            'demand' => array_values(array_filter([
                $root . '/dmn_demand.pdf',
                ... (glob($root . '/*demand*.pdf') ?: []),
            ], 'is_file')),
            'story' => array_values(array_filter([
                $root . '/rm_story.pdf',
                ... (glob($root . '/*story*.pdf') ?: []),
            ], 'is_file')),
            'task' => array_values(array_filter([
                $root . '/sc_task.pdf',
                ... (glob($root . '/*task*.pdf') ?: []),
            ], 'is_file')),
        ];

        $files = [];
        foreach ($candidates as $kind => $paths) {
            foreach ($paths as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $files[] = [
                    'tmp_name' => $path,
                    'name' => basename($path),
                    'size' => (int) filesize($path),
                    'error' => UPLOAD_ERR_OK,
                    'is_local' => true,
                    'forced_kind' => $kind,
                ];
                break;
            }
        }

        try {
            if ($files !== []) {
                self::import($files, null, $currentUser);
            }
        } catch (Throwable $e) {
            // Seeding must never break the app.
            error_log('TicketDetails seed failed: ' . $e->getMessage());
        } finally {
            // Always mark so an empty DB after delete stays empty.
            @file_put_contents($flagPath, gmdate('c') . "\n");
        }
    }

    /**
     * @param list<array{tmp_name: string, name: string, size?: int, error?: int, is_local?: bool, forced_kind?: string}> $uploads
     * @param array<string, mixed>|null $ownerUser Signed-in user who created the dossier
     * @return array{project_id: int, warnings: list<string>}
     */
    public static function import(array $uploads, ?string $optionalTitle, ?array $ownerUser = null): array
    {
        if ($uploads === []) {
            throw new InvalidArgumentException('Please upload at least one ServiceNow file (DDR JSON, demand, story, task, or project PDF).');
        }

        if (count($uploads) > TD_MAX_FILES_PER_UPLOAD) {
            throw new InvalidArgumentException('Too many files. Upload up to ' . TD_MAX_FILES_PER_UPLOAD . ' at a time.');
        }

        $classified = [];
        $warnings = [];

        foreach ($uploads as $upload) {
            $error = (int) ($upload['error'] ?? UPLOAD_ERR_OK);
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload failed for ' . safeBasename((string) $upload['name']) . '.');
            }

            $tmp = (string) $upload['tmp_name'];
            $name = safeBasename((string) $upload['name']);
            $isLocal = !empty($upload['is_local']);

            if (!$isLocal && !is_uploaded_file($tmp)) {
                throw new InvalidArgumentException('Invalid upload for ' . $name . '.');
            }
            if (!is_readable($tmp)) {
                throw new InvalidArgumentException('Cannot read file ' . $name . '.');
            }

            $size = (int) ($upload['size'] ?? filesize($tmp) ?: 0);
            if ($size <= 0 || $size > TD_MAX_UPLOAD_BYTES) {
                throw new InvalidArgumentException($name . ' exceeds the allowed size.');
            }

            $ext = extensionOf($name);
            if (!in_array($ext, TD_ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException($name . ' is not an allowed type (pdf/json only).');
            }

            if (!$isLocal && !isAllowedUpload($name, $tmp)) {
                throw new InvalidArgumentException($name . ' failed security checks.');
            }

            $kind = isset($upload['forced_kind'])
                && in_array($upload['forced_kind'], array_merge(TD_SOURCE_KINDS, ['packet', 'project']), true)
                ? (string) $upload['forced_kind']
                : FileClassifier::classify($tmp, $name);

            if ($kind === null) {
                throw new InvalidArgumentException(
                    $name . ' was not recognized. Use DDR JSON, a ServiceNow task packet JSON, or demand/story/task/project PDF exports.'
                );
            }

            if (isset($classified[$kind])) {
                $warnings[] = 'Multiple ' . kindLabel($kind) . ' files provided; using ' . $name . '.';
            }

            $classified[$kind] = [
                'tmp_name' => $tmp,
                'name' => $name,
                'size' => $size,
                'is_local' => $isLocal,
            ];
        }

        if ($classified === []) {
            throw new InvalidArgumentException('No recognized ServiceNow files found.');
        }

        // Console / manual task packet JSON — dedicated import path.
        if (isset($classified['packet'])) {
            $packetFile = $classified['packet'];
            $packetData = ServicenowTaskPacketParser::parse($packetFile['tmp_name']);
            $owner = projectOwnerFromUser($ownerUser);
            $result = self::importServiceNowPacket($packetData, $owner, $optionalTitle, [
                'tmp_name' => $packetFile['tmp_name'],
                'name' => $packetFile['name'],
                'size' => $packetFile['size'],
                'is_local' => !empty($packetFile['is_local']),
            ]);
            foreach ($classified as $kind => $file) {
                if ($kind === 'packet') {
                    continue;
                }
                $result['warnings'][] = 'Extra ' . kindLabel($kind) . ' file ignored when importing a task packet (' . $file['name'] . ').';
            }

            return $result;
        }

        $parsed = [
            'demand' => null,
            'story' => null,
            'task' => null,
            'ddr' => null,
            'vendor' => null,
            'assessments' => ['external' => [], 'internal' => []],
            'overview' => [
                'description' => '',
                'business_case' => '',
                'title' => '',
                'vendor' => '',
            ],
        ];

        $sources = [
            'ddr' => false,
            'demand' => false,
            'story' => false,
            'task' => false,
        ];

        // Parse first (before moving files) so failures leave no orphan storage.
        $parsedFilesMeta = [];
        foreach ($classified as $kind => $file) {
            try {
                if ($kind === 'ddr') {
                    $ddr = DdrJsonParser::parse($file['tmp_name']);
                    $parsed['ddr'] = [
                        'number' => $ddr['number'],
                        'state' => $ddr['state'],
                        'title' => $ddr['title'],
                        'description' => $ddr['description'],
                        'fields' => $ddr['fields'],
                        'metadata' => $ddr['metadata'],
                        'export_meta' => $ddr['export_meta'],
                    ];
                    $parsed['vendor'] = $ddr['vendor'];
                    $parsed['assessments'] = $ddr['assessments'];
                    $sources['ddr'] = true;
                } else {
                    $section = ServicenowPdfParser::parse($file['tmp_name'], $kind);
                    $parsed[$kind] = $section;
                    $sources[$kind] = true;
                }
                $parsedFilesMeta[$kind] = $file;
            } catch (Throwable $e) {
                throw new RuntimeException('Failed to parse ' . $file['name'] . ': ' . $e->getMessage(), 0, $e);
            }
        }

        $meta = self::deriveProjectMeta($parsed, $optionalTitle);
        $parsed['overview'] = [
            'title' => $meta['title'],
            'vendor' => $meta['vendor'],
            'description' => $meta['_overview_description'],
            'business_case' => $meta['_overview_business_case'],
        ];

        $owner = projectOwnerFromUser($ownerUser);
        $projectId = ProjectRepository::create([
            'title' => $meta['title'],
            'vendor' => $meta['vendor'],
            'demand_number' => $meta['demand_number'],
            'story_number' => $meta['story_number'],
            'task_number' => $meta['task_number'],
            'ddr_number' => $meta['ddr_number'],
            'demand_state' => $meta['demand_state'],
            'story_state' => $meta['story_state'],
            'task_state' => $meta['task_state'],
            'ddr_state' => $meta['ddr_state'],
            'sources' => $sources,
            'parsed' => $parsed,
            'owner_user_id' => $owner['owner_user_id'],
            'owner_username' => $owner['owner_username'],
            'owner_display_name' => $owner['owner_display_name'],
            'owner_auth_source' => $owner['owner_auth_source'],
        ], []);

        $storageDir = TD_STORAGE_DIR . '/' . $projectId;
        if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
            ProjectRepository::delete($projectId);
            throw new RuntimeException('Could not create storage directory.');
        }

        $storedRows = [];
        try {
            foreach ($parsedFilesMeta as $kind => $file) {
                $ext = extensionOf($file['name']);
                $storedName = $kind . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest = $storageDir . '/' . $storedName;

                if (!empty($file['is_local'])) {
                    if (!copy($file['tmp_name'], $dest)) {
                        throw new RuntimeException('Could not copy ' . $file['name']);
                    }
                } else {
                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        throw new RuntimeException('Could not store ' . $file['name']);
                    }
                }

                $storedRows[] = [
                    'kind' => $kind,
                    'original_name' => $file['name'],
                    'stored_name' => $storedName,
                    'size_bytes' => (int) $file['size'],
                ];
            }

            // Attach file rows (project already inserted with empty files list).
            $db = getDb();
            $now = nowUtc();
            $stmt = $db->prepare(
                'INSERT INTO project_files (project_id, kind, original_name, stored_name, size_bytes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($storedRows as $row) {
                $stmt->execute([
                    $projectId,
                    $row['kind'],
                    $row['original_name'],
                    $row['stored_name'],
                    $row['size_bytes'],
                    $now,
                ]);
            }
        } catch (Throwable $e) {
            ProjectRepository::delete($projectId);
            throw $e;
        }

        return [
            'project_id' => $projectId,
            'warnings' => $warnings,
        ];
    }

    /**
     * Merge newly uploaded ServiceNow files into an existing dossier (fill gaps / replace kinds).
     *
     * @param list<array{tmp_name: string, name: string, size?: int, error?: int, is_local?: bool, forced_kind?: string}> $uploads
     * @return array{project_id: int, warnings: list<string>, added: list<string>}
     */
    public static function mergeIntoProject(int $projectId, array $uploads): array
    {
        if ($projectId <= 0) {
            throw new InvalidArgumentException('Invalid project.');
        }

        $project = ProjectRepository::find($projectId);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        if ($uploads === []) {
            throw new InvalidArgumentException('Please upload at least one ServiceNow file to add.');
        }

        if (count($uploads) > TD_MAX_FILES_PER_UPLOAD) {
            throw new InvalidArgumentException('Too many files. Upload up to ' . TD_MAX_FILES_PER_UPLOAD . ' at a time.');
        }

        $classified = self::classifyUploads($uploads);
        $warnings = $classified['warnings'];
        $filesByKind = $classified['files'];

        if ($filesByKind === []) {
            throw new InvalidArgumentException('No recognized ServiceNow files found.');
        }

        $parsed = json_decode((string) ($project['parsed_json'] ?? ''), true);
        if (!is_array($parsed)) {
            $parsed = [];
        }
        $parsed = array_merge([
            'demand' => null,
            'story' => null,
            'task' => null,
            'ddr' => null,
            'vendor' => null,
            'assessments' => ['external' => [], 'internal' => []],
            'overview' => [
                'description' => '',
                'business_case' => '',
                'title' => '',
                'vendor' => '',
            ],
        ], $parsed);

        $sources = json_decode((string) ($project['sources_json'] ?? ''), true);
        if (!is_array($sources)) {
            $sources = [];
        }
        foreach (TD_SOURCE_KINDS as $kind) {
            $sources[$kind] = !empty($sources[$kind]);
        }

        $added = [];
        $parsedFilesMeta = [];

        foreach ($filesByKind as $kind => $file) {
            try {
                if ($kind === 'ddr') {
                    $ddr = DdrJsonParser::parse($file['tmp_name']);
                    $parsed['ddr'] = [
                        'number' => $ddr['number'],
                        'state' => $ddr['state'],
                        'title' => $ddr['title'],
                        'description' => $ddr['description'],
                        'fields' => $ddr['fields'],
                        'metadata' => $ddr['metadata'],
                        'export_meta' => $ddr['export_meta'],
                    ];
                    $parsed['vendor'] = $ddr['vendor'];
                    $parsed['assessments'] = $ddr['assessments'];
                    $sources['ddr'] = true;
                } else {
                    $section = ServicenowPdfParser::parse($file['tmp_name'], $kind);
                    $parsed[$kind] = $section;
                    $sources[$kind] = true;
                }
                $parsedFilesMeta[$kind] = $file;
                $added[] = kindLabel($kind);
            } catch (Throwable $e) {
                throw new RuntimeException('Failed to parse ' . $file['name'] . ': ' . $e->getMessage(), 0, $e);
            }
        }

        $meta = self::deriveProjectMeta($parsed, (string) $project['title']);
        // Keep an explicit user title if they set one that isn't the generic fallback.
        $keepTitle = trim((string) $project['title']);
        if ($keepTitle !== '' && $keepTitle !== 'Untitled Project') {
            $meta['title'] = $keepTitle;
        }
        $parsed['overview'] = [
            'title' => $meta['title'],
            'vendor' => $meta['vendor'] !== '' ? $meta['vendor'] : (string) ($parsed['overview']['vendor'] ?? ''),
            'description' => $meta['_overview_description'] !== ''
                ? $meta['_overview_description']
                : (string) ($parsed['overview']['description'] ?? ''),
            'business_case' => $meta['_overview_business_case'] !== ''
                ? $meta['_overview_business_case']
                : (string) ($parsed['overview']['business_case'] ?? ''),
        ];
        if ($meta['vendor'] === '' && !empty($project['vendor'])) {
            $meta['vendor'] = (string) $project['vendor'];
            $parsed['overview']['vendor'] = $meta['vendor'];
        }

        $storageDir = TD_STORAGE_DIR . '/' . $projectId;
        if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Could not create storage directory.');
        }

        foreach ($parsedFilesMeta as $kind => $file) {
            $ext = extensionOf($file['name']);
            $storedName = $kind . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $storageDir . '/' . $storedName;

            if (!empty($file['is_local'])) {
                if (!copy($file['tmp_name'], $dest)) {
                    throw new RuntimeException('Could not copy ' . $file['name']);
                }
            } else {
                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    throw new RuntimeException('Could not store ' . $file['name']);
                }
            }

            ProjectRepository::replaceFileOfKind($projectId, [
                'kind' => $kind,
                'original_name' => $file['name'],
                'stored_name' => $storedName,
                'size_bytes' => (int) $file['size'],
            ]);
        }

        ProjectRepository::updateParsed($projectId, [
            'title' => $meta['title'],
            'vendor' => $meta['vendor'],
            'demand_number' => $meta['demand_number'],
            'story_number' => $meta['story_number'],
            'task_number' => $meta['task_number'],
            'ddr_number' => $meta['ddr_number'],
            'demand_state' => $meta['demand_state'],
            'story_state' => $meta['story_state'],
            'task_state' => $meta['task_state'],
            'ddr_state' => $meta['ddr_state'],
            'sources' => $sources,
            'parsed' => $parsed,
        ]);

        return [
            'project_id' => $projectId,
            'warnings' => $warnings,
            'added' => $added,
        ];
    }

    /**
     * @param list<array{tmp_name: string, name: string, size?: int, error?: int, is_local?: bool, forced_kind?: string}> $uploads
     * @return array{files: array<string, array{tmp_name: string, name: string, size: int, is_local: bool}>, warnings: list<string>}
     */
    private static function classifyUploads(array $uploads): array
    {
        $classified = [];
        $warnings = [];

        foreach ($uploads as $upload) {
            $error = (int) ($upload['error'] ?? UPLOAD_ERR_OK);
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload failed for ' . safeBasename((string) $upload['name']) . '.');
            }

            $tmp = (string) $upload['tmp_name'];
            $name = safeBasename((string) $upload['name']);
            $isLocal = !empty($upload['is_local']);

            if (!$isLocal && !is_uploaded_file($tmp)) {
                throw new InvalidArgumentException('Invalid upload for ' . $name . '.');
            }
            if (!is_readable($tmp)) {
                throw new InvalidArgumentException('Cannot read file ' . $name . '.');
            }

            $size = (int) ($upload['size'] ?? filesize($tmp) ?: 0);
            if ($size <= 0 || $size > TD_MAX_UPLOAD_BYTES) {
                throw new InvalidArgumentException($name . ' exceeds the allowed size.');
            }

            $ext = extensionOf($name);
            if (!in_array($ext, TD_ALLOWED_EXTENSIONS, true)) {
                throw new InvalidArgumentException($name . ' is not an allowed type (pdf/json only).');
            }

            if (!$isLocal && !isAllowedUpload($name, $tmp)) {
                throw new InvalidArgumentException($name . ' failed security checks.');
            }

            $kind = isset($upload['forced_kind'])
                && in_array($upload['forced_kind'], array_merge(TD_SOURCE_KINDS, ['packet', 'project']), true)
                ? (string) $upload['forced_kind']
                : FileClassifier::classify($tmp, $name);

            if ($kind === null) {
                throw new InvalidArgumentException(
                    $name . ' was not recognized. Use DDR JSON, a ServiceNow task packet JSON, or demand/story/task/project PDF exports.'
                );
            }

            if (isset($classified[$kind])) {
                $warnings[] = 'Multiple ' . kindLabel($kind) . ' files provided; using ' . $name . '.';
            }

            $classified[$kind] = [
                'tmp_name' => $tmp,
                'name' => $name,
                'size' => $size,
                'is_local' => $isLocal,
            ];
        }

        return ['files' => $classified, 'warnings' => $warnings];
    }

    /**
     * Import a ServiceNow console task packet (parsed array) into a new dossier.
     *
     * @param array<string, mixed> $packet Parsed packet or raw packet array
     * @param array{
     *   owner_user_id?: int|null,
     *   owner_username?: string,
     *   owner_display_name?: string,
     *   owner_auth_source?: string
     * } $owner
     * @param array{tmp_name: string, name: string, size?: int, is_local?: bool}|null $packetFile Optional source JSON to store
     * @return array{project_id: int, warnings: list<string>}
     */
    public static function importServiceNowPacket(
        array $packet,
        array $owner = [],
        ?string $optionalTitle = null,
        ?array $packetFile = null
    ): array {
        // Accept either already-mapped parser output or a raw packet body.
        if (isset($packet['task']) && is_array($packet['task']) && ($packet['kind'] ?? '') === 'packet') {
            $mapped = $packet;
        } else {
            $mapped = ServicenowTaskPacketParser::parseArray($packet);
        }

        $parsed = [
            'demand' => $mapped['demand'] ?? null,
            'story' => $mapped['story'] ?? null,
            'task' => $mapped['task'] ?? null,
            'ddr' => $mapped['ddr'] ?? null,
            'vendor' => is_array($mapped['vendor'] ?? null) ? $mapped['vendor'] : null,
            'assessments' => is_array($mapped['assessments'] ?? null)
                ? $mapped['assessments']
                : ['external' => [], 'internal' => []],
            'overview' => is_array($mapped['overview'] ?? null) ? $mapped['overview'] : [
                'title' => '',
                'vendor' => '',
                'description' => '',
                'business_case' => '',
            ],
            'related_tickets' => is_array($mapped['related_tickets'] ?? null) ? $mapped['related_tickets'] : [],
            'packet_meta' => is_array($mapped['packet_meta'] ?? null) ? $mapped['packet_meta'] : [],
            'relationships' => is_array($mapped['relationships'] ?? null) ? $mapped['relationships'] : [],
        ];

        $sources = [
            'ddr' => !empty($parsed['ddr']),
            'demand' => !empty($parsed['demand']),
            'story' => !empty($parsed['story']),
            'task' => !empty($parsed['task']),
            'packet' => true,
        ];

        $meta = self::deriveProjectMeta($parsed, $optionalTitle);
        if (trim((string) ($parsed['overview']['title'] ?? '')) === '') {
            $parsed['overview']['title'] = $meta['title'];
        }
        if (trim((string) ($parsed['overview']['description'] ?? '')) === '') {
            $parsed['overview']['description'] = $meta['_overview_description'];
        }
        $parsed['overview']['vendor'] = $meta['vendor'];

        $projectId = ProjectRepository::create([
            'title' => $meta['title'],
            'vendor' => $meta['vendor'],
            'demand_number' => $meta['demand_number'],
            'story_number' => $meta['story_number'],
            'task_number' => $meta['task_number'],
            'ddr_number' => $meta['ddr_number'],
            'demand_state' => $meta['demand_state'],
            'story_state' => $meta['story_state'],
            'task_state' => $meta['task_state'],
            'ddr_state' => $meta['ddr_state'],
            'sources' => $sources,
            'parsed' => $parsed,
            'owner_user_id' => $owner['owner_user_id'] ?? null,
            'owner_username' => (string) ($owner['owner_username'] ?? ''),
            'owner_display_name' => (string) ($owner['owner_display_name'] ?? ''),
            'owner_auth_source' => (string) ($owner['owner_auth_source'] ?? ''),
        ], []);

        $storageDir = TD_STORAGE_DIR . '/' . $projectId;
        if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
            ProjectRepository::delete($projectId);
            throw new RuntimeException('Could not create storage directory.');
        }

        $warnings = [];
        try {
            $jsonBody = json_encode(
                [
                    'format' => ServicenowTaskPacketParser::FORMAT,
                    'instance' => (string) ($mapped['instance'] ?? ''),
                    'exported_at' => (string) ($mapped['exported_at'] ?? gmdate('c')),
                    'root_number' => (string) ($mapped['root_number'] ?? $meta['task_number']),
                    'root_sys_id' => (string) ($mapped['root_sys_id'] ?? ''),
                    'relationships' => $parsed['relationships'],
                    'tickets' => is_array($mapped['tickets'] ?? null) ? $mapped['tickets'] : [],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            $packetName = (string) ($meta['task_number'] !== '' ? $meta['task_number'] : 'TASK') . '.json';
            if ($packetFile !== null && is_readable((string) $packetFile['tmp_name'])) {
                $ext = extensionOf((string) $packetFile['name']);
                if ($ext !== 'json') {
                    $ext = 'json';
                }
                $storedName = 'packet_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest = $storageDir . '/' . $storedName;
                if (!empty($packetFile['is_local'])) {
                    if (!copy((string) $packetFile['tmp_name'], $dest)) {
                        throw new RuntimeException('Could not copy packet JSON.');
                    }
                } else {
                    if (!@copy((string) $packetFile['tmp_name'], $dest) && !move_uploaded_file((string) $packetFile['tmp_name'], $dest)) {
                        // Console import posts JSON in body — write encoded packet instead.
                        if (@file_put_contents($dest, $jsonBody) === false) {
                            throw new RuntimeException('Could not store packet JSON.');
                        }
                    }
                }
                $size = (int) ($packetFile['size'] ?? filesize($dest) ?: strlen($jsonBody));
                $originalName = safeBasename((string) ($packetFile['name'] ?? $packetName));
            } else {
                $storedName = 'packet_' . bin2hex(random_bytes(8)) . '.json';
                $dest = $storageDir . '/' . $storedName;
                if (@file_put_contents($dest, $jsonBody) === false) {
                    throw new RuntimeException('Could not store packet JSON.');
                }
                $size = strlen($jsonBody);
                $originalName = $packetName;
            }

            $db = getDb();
            $stmt = $db->prepare(
                'INSERT INTO project_files (project_id, kind, original_name, stored_name, size_bytes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $projectId,
                'packet',
                $originalName,
                $storedName,
                $size,
                nowUtc(),
            ]);
        } catch (Throwable $e) {
            ProjectRepository::delete($projectId);
            throw $e;
        }

        $relCount = count($parsed['related_tickets'] ?? []);
        if ($relCount > 0) {
            $warnings[] = 'Imported ' . $relCount . ' related ticket(s) alongside the root task.';
        }

        return [
            'project_id' => $projectId,
            'warnings' => $warnings,
        ];
    }

    /**
     * Merge a ServiceNow console packet into an existing dossier.
     * Matching Demand/Story/Task/DDR numbers are refreshed; new tickets (e.g. Project) are added to Related.
     *
     * @param array<string, mixed> $packet
     * @param array{tmp_name: string, name: string, size?: int, is_local?: bool}|null $packetFile
     * @return array{project_id: int, warnings: list<string>, updated: list<string>, added: list<string>}
     */
    public static function mergeServiceNowPacket(
        int $projectId,
        array $packet,
        ?array $packetFile = null
    ): array {
        if ($projectId <= 0) {
            throw new InvalidArgumentException('Invalid project.');
        }

        $project = ProjectRepository::find($projectId);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        if (isset($packet['task']) && is_array($packet['task']) && ($packet['kind'] ?? '') === 'packet') {
            $mapped = $packet;
        } else {
            $mapped = ServicenowTaskPacketParser::parseArray($packet);
        }

        $parsed = json_decode((string) ($project['parsed_json'] ?? ''), true);
        if (!is_array($parsed)) {
            $parsed = [];
        }
        $parsed = array_merge([
            'demand' => null,
            'story' => null,
            'task' => null,
            'ddr' => null,
            'vendor' => null,
            'assessments' => ['external' => [], 'internal' => []],
            'overview' => [
                'description' => '',
                'business_case' => '',
                'title' => '',
                'vendor' => '',
            ],
            'related_tickets' => [],
            'relationships' => [],
            'packet_meta' => [],
        ], $parsed);

        $sources = json_decode((string) ($project['sources_json'] ?? ''), true);
        if (!is_array($sources)) {
            $sources = [];
        }
        foreach (TD_SOURCE_KINDS as $kind) {
            $sources[$kind] = !empty($sources[$kind]);
        }

        $updated = [];
        $added = [];
        $warnings = [];

        foreach (['demand', 'story', 'task', 'ddr'] as $kind) {
            $incoming = $mapped[$kind] ?? null;
            if (!is_array($incoming) || $incoming === []) {
                continue;
            }

            $incomingNumber = strtoupper(trim((string) ($incoming['number'] ?? '')));
            $existing = is_array($parsed[$kind] ?? null) ? $parsed[$kind] : null;
            $existingNumber = strtoupper(trim((string) (
                ($existing['number'] ?? '') !== ''
                    ? ($existing['number'] ?? '')
                    : ($project[$kind . '_number'] ?? '')
            )));

            if ($existingNumber === '' || $incomingNumber === '' || $incomingNumber === $existingNumber) {
                $wasEmpty = $existingNumber === '' || $existing === null;
                $parsed[$kind] = self::mergeParsedSection(
                    is_array($existing) ? $existing : [],
                    $incoming
                );
                $sources[$kind] = true;
                $label = kindLabel($kind) . ($incomingNumber !== '' ? ' (' . $incomingNumber . ')' : '');
                if ($wasEmpty) {
                    $added[] = $label;
                } else {
                    $updated[] = $label;
                }
            } else {
                $result = self::upsertRelatedTicket($parsed, $incoming);
                $label = kindLabel($kind) . ($incomingNumber !== '' ? ' (' . $incomingNumber . ')' : '');
                if ($result === 'updated') {
                    $updated[] = $label . ' in Related';
                } else {
                    $added[] = $label . ' to Related';
                }
                $warnings[] = 'Primary ' . kindLabel($kind) . ' is already '
                    . $existingNumber . '; imported ' . ($incomingNumber !== '' ? $incomingNumber : 'ticket')
                    . ' into Related tickets instead.';
            }
        }

        $relatedIn = is_array($mapped['related_tickets'] ?? null) ? $mapped['related_tickets'] : [];
        foreach ($relatedIn as $relatedTicket) {
            if (!is_array($relatedTicket)) {
                continue;
            }
            $number = strtoupper(trim((string) ($relatedTicket['number'] ?? '')));
            // Skip if this ticket already became a primary section above.
            $skip = false;
            foreach (['demand', 'story', 'task', 'ddr'] as $kind) {
                $primaryNumber = strtoupper(trim((string) ($parsed[$kind]['number'] ?? '')));
                if ($number !== '' && $primaryNumber !== '' && $number === $primaryNumber) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $result = self::upsertRelatedTicket($parsed, $relatedTicket);
            $label = $number !== '' ? $number : 'related ticket';
            if ($result === 'updated') {
                $updated[] = $label;
            } else {
                $added[] = $label;
            }
        }

        $incomingRels = is_array($mapped['relationships'] ?? null) ? $mapped['relationships'] : [];
        if ($incomingRels !== []) {
            $parsed['relationships'] = self::mergeRelationships(
                is_array($parsed['relationships'] ?? null) ? $parsed['relationships'] : [],
                $incomingRels
            );
        }

        if (!empty($mapped['packet_meta']) && is_array($mapped['packet_meta'])) {
            $existingMeta = is_array($parsed['packet_meta'] ?? null) ? $parsed['packet_meta'] : [];
            $parsed['packet_meta'] = array_merge($existingMeta, $mapped['packet_meta']);
        }
        if (trim((string) ($mapped['instance'] ?? '')) !== '') {
            $parsed['instance'] = (string) $mapped['instance'];
            $meta = is_array($parsed['packet_meta'] ?? null) ? $parsed['packet_meta'] : [];
            $meta['instance'] = (string) $mapped['instance'];
            $parsed['packet_meta'] = $meta;
        }
        $sources['packet'] = true;

        $meta = self::deriveProjectMeta($parsed, (string) $project['title']);
        $keepTitle = trim((string) $project['title']);
        if ($keepTitle !== '' && $keepTitle !== 'Untitled Project') {
            $meta['title'] = $keepTitle;
        }
        $existingOverview = is_array($parsed['overview'] ?? null) ? $parsed['overview'] : [];
        $parsed['overview'] = [
            'title' => $meta['title'],
            'vendor' => $meta['vendor'] !== ''
                ? $meta['vendor']
                : (string) ($existingOverview['vendor'] ?? $project['vendor'] ?? ''),
            'description' => $meta['_overview_description'] !== ''
                ? $meta['_overview_description']
                : (string) ($existingOverview['description'] ?? ''),
            'business_case' => $meta['_overview_business_case'] !== ''
                ? $meta['_overview_business_case']
                : (string) ($existingOverview['business_case'] ?? ''),
        ];
        if ($meta['vendor'] === '' && !empty($project['vendor'])) {
            $meta['vendor'] = (string) $project['vendor'];
            $parsed['overview']['vendor'] = $meta['vendor'];
        }

        $storageDir = TD_STORAGE_DIR . '/' . $projectId;
        if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Could not create storage directory.');
        }

        $jsonBody = json_encode(
            [
                'format' => ServicenowTaskPacketParser::FORMAT,
                'instance' => (string) ($mapped['instance'] ?? ''),
                'exported_at' => (string) ($mapped['exported_at'] ?? gmdate('c')),
                'root_number' => (string) ($mapped['root_number'] ?? ''),
                'root_sys_id' => (string) ($mapped['root_sys_id'] ?? ''),
                'relationships' => is_array($mapped['relationships'] ?? null) ? $mapped['relationships'] : [],
                'tickets' => is_array($mapped['tickets'] ?? null) ? $mapped['tickets'] : [],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $packetName = (string) (
            ($mapped['root_number'] ?? '') !== ''
                ? $mapped['root_number']
                : 'packet'
        ) . '.json';
        $storedName = 'packet_' . bin2hex(random_bytes(8)) . '.json';
        $dest = $storageDir . '/' . $storedName;
        $size = strlen($jsonBody);
        $originalName = $packetName;

        if ($packetFile !== null && is_readable((string) $packetFile['tmp_name'])) {
            if (!empty($packetFile['is_local'])) {
                if (!copy((string) $packetFile['tmp_name'], $dest)) {
                    throw new RuntimeException('Could not copy packet JSON.');
                }
            } elseif (!@copy((string) $packetFile['tmp_name'], $dest)
                && !move_uploaded_file((string) $packetFile['tmp_name'], $dest)
            ) {
                if (@file_put_contents($dest, $jsonBody) === false) {
                    throw new RuntimeException('Could not store packet JSON.');
                }
            }
            $size = (int) ($packetFile['size'] ?? filesize($dest) ?: strlen($jsonBody));
            $originalName = safeBasename((string) ($packetFile['name'] ?? $packetName));
        } elseif (@file_put_contents($dest, $jsonBody) === false) {
            throw new RuntimeException('Could not store packet JSON.');
        }

        ProjectRepository::replaceFileOfKind($projectId, [
            'kind' => 'packet',
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'size_bytes' => $size,
        ]);

        ProjectRepository::updateParsed($projectId, [
            'title' => $meta['title'],
            'vendor' => $meta['vendor'],
            'demand_number' => $meta['demand_number'],
            'story_number' => $meta['story_number'],
            'task_number' => $meta['task_number'],
            'ddr_number' => $meta['ddr_number'],
            'demand_state' => $meta['demand_state'],
            'story_state' => $meta['story_state'],
            'task_state' => $meta['task_state'],
            'ddr_state' => $meta['ddr_state'],
            'sources' => $sources,
            'parsed' => $parsed,
        ]);

        if ($updated === [] && $added === []) {
            $warnings[] = 'Packet imported but no ticket sections changed.';
        }

        return [
            'project_id' => $projectId,
            'warnings' => $warnings,
            'updated' => array_values(array_unique($updated)),
            'added' => array_values(array_unique($added)),
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $fresh
     * @return array<string, mixed>
     */
    private static function mergeParsedSection(array $existing, array $fresh): array
    {
        $merged = $existing;
        foreach ($fresh as $key => $value) {
            if ($key === 'fields' && is_array($value)) {
                $oldFields = is_array($merged['fields'] ?? null) ? $merged['fields'] : [];
                $merged['fields'] = array_merge($oldFields, array_filter(
                    $value,
                    static fn (mixed $fieldValue): bool => trim(normalizeDisplayValue($fieldValue)) !== ''
                ));
                continue;
            }
            if (is_array($value)) {
                if ($value !== []) {
                    $merged[$key] = $value;
                }
                continue;
            }
            if (trim((string) $value) !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $section
     * @return 'updated'|'added'
     */
    private static function upsertRelatedTicket(array &$parsed, array $section): string
    {
        $number = strtoupper(trim((string) ($section['number'] ?? '')));
        $related = is_array($parsed['related_tickets'] ?? null) ? $parsed['related_tickets'] : [];

        if ($number !== '') {
            foreach ($related as $index => $existing) {
                if (!is_array($existing)) {
                    continue;
                }
                if (strcasecmp((string) ($existing['number'] ?? ''), $number) === 0) {
                    $related[$index] = self::mergeParsedSection($existing, $section);
                    $parsed['related_tickets'] = $related;

                    return 'updated';
                }
            }
        }

        $related[] = $section;
        $parsed['related_tickets'] = $related;

        return 'added';
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    private static function mergeRelationships(array $existing, array $incoming): array
    {
        return array_values(uniqueRelatedRecords(array_merge($existing, $incoming)));
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array{
     *   title: string, vendor: string,
     *   demand_number: string, story_number: string, task_number: string, ddr_number: string,
     *   demand_state: string, story_state: string, task_state: string, ddr_state: string,
     *   _overview_description: string, _overview_business_case: string
     * }
     */
    public static function deriveProjectMeta(array $parsed, ?string $optionalTitle): array
    {
        $demand = is_array($parsed['demand'] ?? null) ? $parsed['demand'] : [];
        $story = is_array($parsed['story'] ?? null) ? $parsed['story'] : [];
        $task = is_array($parsed['task'] ?? null) ? $parsed['task'] : [];
        $ddr = is_array($parsed['ddr'] ?? null) ? $parsed['ddr'] : [];
        $vendor = is_array($parsed['vendor']['fields'] ?? null) ? $parsed['vendor']['fields'] : [];
        $overview = is_array($parsed['overview'] ?? null) ? $parsed['overview'] : [];
        $packetMeta = is_array($parsed['packet_meta'] ?? null) ? $parsed['packet_meta'] : [];
        $rootKind = strtolower(trim((string) ($packetMeta['root_kind'] ?? '')));
        $rootNumber = strtoupper(trim((string) ($parsed['root_number'] ?? '')));
        if ($rootKind === '' && $rootNumber !== '') {
            $rootKind = servicenowKindFromNumber($rootNumber);
        }

        $rootProjectTitle = '';
        $projectSection = is_array($parsed['project'] ?? null) ? $parsed['project'] : [];
        if ($projectSection !== []) {
            $projectFields = is_array($projectSection['fields'] ?? null) ? $projectSection['fields'] : [];
            $rootProjectTitle = firstNonEmpty(
                (string) ($projectSection['title'] ?? ''),
                (string) ($projectSection['short_description'] ?? ''),
                (string) ($projectFields['Project Name'] ?? ''),
                (string) ($projectFields['Name'] ?? ''),
                (string) ($projectFields['Project name'] ?? '')
            );
            if ($rootKind === '') {
                $rootKind = 'project';
            }
            if ($rootNumber === '') {
                $rootNumber = strtoupper(trim((string) ($projectSection['number'] ?? '')));
            }
        }
        if ($rootKind === 'project') {
            $rootProjectTitle = firstNonEmpty(
                $rootProjectTitle,
                trim((string) ($overview['title'] ?? ''))
            );
            $relatedTickets = is_array($parsed['related_tickets'] ?? null)
                ? $parsed['related_tickets']
                : [];
            foreach ($relatedTickets as $relatedTicket) {
                if (!is_array($relatedTicket)) {
                    continue;
                }
                $number = strtoupper(trim((string) ($relatedTicket['number'] ?? '')));
                if ($rootNumber !== '' && $number !== $rootNumber) {
                    continue;
                }
                $fields = is_array($relatedTicket['fields'] ?? null)
                    ? $relatedTicket['fields']
                    : [];
                $rootProjectTitle = firstNonEmpty(
                    $rootProjectTitle,
                    (string) ($relatedTicket['title'] ?? ''),
                    (string) ($relatedTicket['short_description'] ?? ''),
                    (string) ($fields['Name'] ?? ''),
                    (string) ($fields['Project Name'] ?? ''),
                    (string) ($fields['Project name'] ?? '')
                );
                break;
            }
        }

        $explicitTitle = trim((string) ($optionalTitle ?? ''));
        if (strcasecmp($explicitTitle, 'Untitled Project') === 0) {
            $explicitTitle = '';
        }

        $title = firstNonEmpty(
            $explicitTitle,
            $rootProjectTitle,
            (string) ($demand['title'] ?? ''),
            (string) ($story['title'] ?? ''),
            (string) ($ddr['title'] ?? ''),
            (string) ($task['title'] ?? ''),
            (string) ($overview['title'] ?? ''),
            'Untitled Project'
        );

        $vendorName = firstNonEmpty(
            (string) ($ddr['vendor_name'] ?? ''),
            fieldValue($vendor, 'Name'),
            (string) (($demand['fields']['Third-Party Vendor'] ?? '')),
            (string) (($task['fields']['Vendor'] ?? ''))
        );

        $numbers = [
            'demand' => (string) ($demand['number'] ?? ''),
            'story' => (string) ($story['number'] ?? ''),
            'task' => (string) ($task['number'] ?? ''),
            'ddr' => (string) ($ddr['number'] ?? ''),
        ];

        foreach ([$demand, $story, $task] as $section) {
            $related = $section['related_numbers'] ?? [];
            if (!is_array($related)) {
                continue;
            }
            foreach (['demand', 'story', 'task', 'ddr'] as $key) {
                if ($numbers[$key] === '' && !empty($related[$key])) {
                    $numbers[$key] = (string) $related[$key];
                }
            }
        }

        // Also pull numbers from related_tickets in a console packet.
        $relatedTickets = is_array($parsed['related_tickets'] ?? null) ? $parsed['related_tickets'] : [];
        foreach ($relatedTickets as $relTicket) {
            if (!is_array($relTicket)) {
                continue;
            }
            $num = strtoupper(trim((string) ($relTicket['number'] ?? '')));
            if ($num === '') {
                continue;
            }
            if ($numbers['demand'] === '' && str_starts_with($num, 'DMND')) {
                $numbers['demand'] = $num;
            }
            if ($numbers['story'] === '' && str_starts_with($num, 'STRY')) {
                $numbers['story'] = $num;
            }
            if ($numbers['task'] === '' && str_starts_with($num, 'TASK')) {
                $numbers['task'] = $num;
            }
            if ($numbers['ddr'] === '' && str_starts_with($num, 'DDR')) {
                $numbers['ddr'] = $num;
            }
        }

        // Fill overview helpers into parsed structure for the UI.
        $description = firstNonEmpty(
            (string) ($projectSection['description'] ?? ''),
            (string) ($demand['description'] ?? ''),
            (string) ($story['description'] ?? ''),
            (string) ($ddr['description'] ?? ''),
            (string) ($task['description'] ?? '')
        );
        $businessCase = firstNonEmpty(
            (string) ($projectSection['business_case'] ?? ''),
            (string) ($demand['business_case'] ?? '')
        );

        return [
            'title' => $title,
            'vendor' => $vendorName,
            'demand_number' => $numbers['demand'],
            'story_number' => $numbers['story'],
            'task_number' => $numbers['task'],
            'ddr_number' => $numbers['ddr'],
            'demand_state' => (string) ($demand['state'] ?? ''),
            'story_state' => (string) ($story['state'] ?? ''),
            'task_state' => (string) ($task['state'] ?? ''),
            'ddr_state' => (string) ($ddr['state'] ?? ''),
            // Side-channel used by caller after merge — kept for clarity.
            '_overview_description' => $description,
            '_overview_business_case' => $businessCase,
        ];
    }
}
