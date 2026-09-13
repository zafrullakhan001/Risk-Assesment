<?php

declare(strict_types=1);

/**
 * Delete or reparse files already stored in a Ticket Dossier project.
 */
final class DossierFileManager
{
    /**
     * @param list<int> $fileIds
     * @return array{deleted: int, names: list<string>}
     */
    public static function deleteFiles(int $projectId, array $fileIds): array
    {
        $project = ProjectRepository::find($projectId);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        $ids = self::normalizeIds($fileIds);
        if ($ids === []) {
            throw new InvalidArgumentException('Select at least one file.');
        }

        $db = getDb();
        $deleted = 0;
        $names = [];
        foreach ($ids as $fileId) {
            $file = ProjectRepository::findFile($fileId, $projectId);
            if ($file === null) {
                continue;
            }

            $stmt = $db->prepare('DELETE FROM project_files WHERE id = ? AND project_id = ?');
            $stmt->execute([$fileId, $projectId]);
            if ($stmt->rowCount() < 1) {
                continue;
            }

            $path = TD_STORAGE_DIR . '/' . $projectId . '/' . (string) $file['stored_name'];
            if (is_file($path)) {
                @unlink($path);
            }
            $deleted++;
            $names[] = (string) $file['original_name'];
        }

        if ($deleted > 0) {
            $stmt = $db->prepare('UPDATE projects SET updated_at = ? WHERE id = ?');
            $stmt->execute([nowUtc(), $projectId]);
        }

        return ['deleted' => $deleted, 'names' => $names];
    }

    /**
     * @param list<int> $fileIds
     * @return array{parsed: int, skipped: int, messages: list<string>}
     */
    public static function reparseFiles(int $projectId, array $fileIds): array
    {
        $project = ProjectRepository::find($projectId);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        $ids = self::normalizeIds($fileIds);
        if ($ids === []) {
            throw new InvalidArgumentException('Select at least one file.');
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
            'overview' => [],
            'related_tickets' => [],
        ], $parsed);

        $sources = json_decode((string) ($project['sources_json'] ?? ''), true);
        if (!is_array($sources)) {
            $sources = [];
        }

        $parsedCount = 0;
        $skipped = 0;
        $messages = [];

        foreach ($ids as $fileId) {
            $file = ProjectRepository::findFile($fileId, $projectId);
            if ($file === null) {
                $skipped++;
                continue;
            }

            $path = TD_STORAGE_DIR . '/' . $projectId . '/' . (string) $file['stored_name'];
            if (!is_file($path) || !is_readable($path)) {
                $skipped++;
                $messages[] = (string) $file['original_name'] . ': file is missing on disk.';
                continue;
            }

            try {
                $result = self::parseAndMerge($project, $file, $path, $parsed, $sources);
                if ($result === '') {
                    $skipped++;
                    $messages[] = (string) $file['original_name'] . ': not a reparsable ticket PDF, packet, or DDR JSON.';
                    continue;
                }
                $parsedCount++;
                $messages[] = (string) $file['original_name'] . ': ' . $result;
            } catch (Throwable $e) {
                $skipped++;
                $messages[] = (string) $file['original_name'] . ': ' . $e->getMessage();
            }
        }

        if ($parsedCount > 0) {
            self::saveParsed($project, $parsed, $sources);
        }

        return [
            'parsed' => $parsedCount,
            'skipped' => $skipped,
            'messages' => $messages,
        ];
    }

    /**
     * Reparse one file after browser sync stores it.
     *
     * @return string Empty when not reparsable.
     */
    public static function reparseFile(int $projectId, int $fileId): string
    {
        $result = self::reparseFiles($projectId, [$fileId]);

        return $result['parsed'] > 0 ? ($result['messages'][0] ?? 'Reparsed.') : '';
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $file
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $sources
     */
    private static function parseAndMerge(
        array $project,
        array $file,
        string $path,
        array &$parsed,
        array &$sources
    ): string {
        $kind = strtolower(trim((string) ($file['kind'] ?? '')));
        $original = (string) ($file['original_name'] ?? '');
        $lowerName = strtolower($original);

        if ($kind === 'packet' || ServicenowTaskPacketParser::looksLikePacket($path)) {
            $packet = ServicenowTaskPacketParser::parse($path);
            foreach (['demand', 'story', 'task', 'ddr'] as $sectionKind) {
                if (!empty($packet[$sectionKind]) && is_array($packet[$sectionKind])) {
                    $parsed[$sectionKind] = self::mergeSection(
                        is_array($parsed[$sectionKind] ?? null) ? $parsed[$sectionKind] : [],
                        $packet[$sectionKind]
                    );
                    $sources[$sectionKind] = true;
                }
            }
            foreach (['related_tickets', 'relationships', 'packet_meta'] as $key) {
                if (!empty($packet[$key]) && is_array($packet[$key])) {
                    $parsed[$key] = $packet[$key];
                }
            }

            return 'task packet parsed and dossier sections refreshed.';
        }

        if (self::looksLikeDdrJson($path, $lowerName)) {
            $ddr = DdrJsonParser::parse($path);
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

            return 'DDR JSON parsed, including vendor and questionnaire data.';
        }

        if (extensionOf($original) !== 'pdf' && extensionOf((string) $file['stored_name']) !== 'pdf') {
            return '';
        }

        $pdfKind = self::inferPdfKind($kind, $original);
        if ($pdfKind === null) {
            return '';
        }

        $section = ServicenowPdfParser::parse($path, $pdfKind);
        $number = strtoupper(trim((string) ($section['number'] ?? '')));
        $primaryNumber = strtoupper(trim((string) ($project[$pdfKind . '_number'] ?? '')));

        if ($primaryNumber === '' || $number === '' || $number === $primaryNumber) {
            $parsed[$pdfKind] = self::mergeSection(
                is_array($parsed[$pdfKind] ?? null) ? $parsed[$pdfKind] : [],
                $section
            );
            $sources[$pdfKind] = true;

            return ucfirst($pdfKind) . ' ticket PDF parsed into the primary dossier section.';
        }

        $related = is_array($parsed['related_tickets'] ?? null) ? $parsed['related_tickets'] : [];
        $replaced = false;
        foreach ($related as $index => $existing) {
            if (!is_array($existing)) {
                continue;
            }
            if (strcasecmp((string) ($existing['number'] ?? ''), $number) === 0) {
                $related[$index] = self::mergeSection($existing, $section);
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $related[] = $section;
        }
        $parsed['related_tickets'] = $related;

        return 'ticket PDF parsed into Related tickets (' . ($number !== '' ? $number : $pdfKind) . ').';
    }

    /**
     * Prefer newly parsed non-empty values while retaining API packet fields the PDF lacks.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $fresh
     * @return array<string, mixed>
     */
    private static function mergeSection(array $existing, array $fresh): array
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
     * @param array<string, mixed> $project
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $sources
     */
    private static function saveParsed(array $project, array $parsed, array $sources): void
    {
        $meta = ProjectImporter::deriveProjectMeta($parsed, (string) ($project['title'] ?? ''));
        $keepTitle = trim((string) ($project['title'] ?? ''));
        if ($keepTitle !== '') {
            $meta['title'] = $keepTitle;
        }
        if ($meta['vendor'] === '') {
            $meta['vendor'] = (string) ($project['vendor'] ?? '');
        }

        $oldOverview = is_array($parsed['overview'] ?? null) ? $parsed['overview'] : [];
        $parsed['overview'] = [
            'title' => $meta['title'],
            'vendor' => $meta['vendor'],
            'description' => $meta['_overview_description'] !== ''
                ? $meta['_overview_description']
                : (string) ($oldOverview['description'] ?? ''),
            'business_case' => $meta['_overview_business_case'] !== ''
                ? $meta['_overview_business_case']
                : (string) ($oldOverview['business_case'] ?? ''),
        ];

        ProjectRepository::updateParsed((int) $project['id'], [
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
    }

    private static function looksLikeDdrJson(string $path, string $lowerName): bool
    {
        if (!str_contains($lowerName, 'ddr') && !str_ends_with($lowerName, '.json')) {
            return false;
        }

        return FileClassifier::looksLikeDdrJson($path);
    }

    private static function inferPdfKind(string $kind, string $name): ?string
    {
        if (in_array($kind, ['demand', 'story', 'task'], true)) {
            return $kind;
        }
        if (preg_match('/(?:^|\/)(DMND\d+)/i', $name)) {
            return 'demand';
        }
        if (preg_match('/(?:^|\/)(STRY\d+)/i', $name)) {
            return 'story';
        }
        if (preg_match('/(?:^|\/)(TASK\d+)/i', $name)) {
            return 'task';
        }

        return null;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function normalizeIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }
}
