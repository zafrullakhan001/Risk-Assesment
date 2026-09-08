<?php
declare(strict_types=1);

final class ProjectImporter
{
    /**
     * Auto-import the sample files sitting in the project root when DB is empty.
     */
    public static function seedSampleIfEmpty(): void
    {
        if (ProjectRepository::count() > 0) {
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

        if ($files === []) {
            return;
        }

        try {
            self::import($files, null);
        } catch (Throwable $e) {
            // Seeding must never break the app.
            error_log('TicketDetails seed failed: ' . $e->getMessage());
        }
    }

    /**
     * @param list<array{tmp_name: string, name: string, size?: int, error?: int, is_local?: bool, forced_kind?: string}> $uploads
     * @return array{project_id: int, warnings: list<string>}
     */
    public static function import(array $uploads, ?string $optionalTitle): array
    {
        if ($uploads === []) {
            throw new InvalidArgumentException('Please upload at least one ServiceNow file (DDR JSON, demand, story, or task PDF).');
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

            $kind = isset($upload['forced_kind']) && in_array($upload['forced_kind'], TD_SOURCE_KINDS, true)
                ? (string) $upload['forced_kind']
                : FileClassifier::classify($tmp, $name);

            if ($kind === null) {
                throw new InvalidArgumentException(
                    $name . ' was not recognized. Use DDR JSON or demand/story/task ServiceNow PDF exports.'
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
     * @param array<string, mixed> $parsed
     * @return array{
     *   title: string, vendor: string,
     *   demand_number: string, story_number: string, task_number: string, ddr_number: string,
     *   demand_state: string, story_state: string, task_state: string, ddr_state: string
     * }
     */
    public static function deriveProjectMeta(array $parsed, ?string $optionalTitle): array
    {
        $demand = is_array($parsed['demand'] ?? null) ? $parsed['demand'] : [];
        $story = is_array($parsed['story'] ?? null) ? $parsed['story'] : [];
        $task = is_array($parsed['task'] ?? null) ? $parsed['task'] : [];
        $ddr = is_array($parsed['ddr'] ?? null) ? $parsed['ddr'] : [];
        $vendor = is_array($parsed['vendor']['fields'] ?? null) ? $parsed['vendor']['fields'] : [];

        $title = firstNonEmpty(
            trim((string) ($optionalTitle ?? '')),
            (string) ($demand['title'] ?? ''),
            (string) ($story['title'] ?? ''),
            (string) ($ddr['title'] ?? ''),
            (string) ($task['title'] ?? ''),
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

        // Fill overview helpers into parsed structure for the UI.
        $description = firstNonEmpty(
            (string) ($demand['description'] ?? ''),
            (string) ($story['description'] ?? ''),
            (string) ($ddr['description'] ?? ''),
            (string) ($task['description'] ?? '')
        );
        $businessCase = (string) ($demand['business_case'] ?? '');

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
