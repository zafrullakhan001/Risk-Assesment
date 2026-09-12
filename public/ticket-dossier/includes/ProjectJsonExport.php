<?php

declare(strict_types=1);

/**
 * Builds the same Ticket Dossier JSON export used for download / AI analysis.
 * Never reads PDF or attachment binaries — only structured parsed_json + metadata.
 */
final class ProjectJsonExport
{
    public const FORMAT = 'architecture-risk.ticket-dossier.v1';

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed>|null $parsed
     * @param array<string, mixed>|null $sources
     * @param list<array<string, mixed>>|null $files
     * @return array{
     *   format: string,
     *   exported_at: string,
     *   project: array<string, mixed>,
     *   sources: array<string, bool>,
     *   dossier: array<string, mixed>,
     *   files: list<array<string, mixed>>
     * }
     */
    public static function buildPayload(
        array $project,
        ?array $parsed = null,
        ?array $sources = null,
        ?array $files = null
    ): array {
        $projectId = (int) ($project['id'] ?? 0);

        if ($parsed === null) {
            $decoded = json_decode((string) ($project['parsed_json'] ?? ''), true);
            $parsed = is_array($decoded) ? $decoded : [];
        }

        if ($sources === null) {
            $decodedSources = json_decode((string) ($project['sources_json'] ?? ''), true);
            $sources = is_array($decodedSources) ? $decodedSources : [];
        }

        if ($files === null && $projectId > 0) {
            $files = ProjectRepository::filesFor($projectId);
        }
        if (!is_array($files)) {
            $files = [];
        }

        $fileManifest = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $fileManifest[] = [
                'id' => (int) ($file['id'] ?? 0),
                'kind' => (string) ($file['kind'] ?? ''),
                'original_name' => (string) ($file['original_name'] ?? ''),
                'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                'created_at' => (string) ($file['created_at'] ?? ''),
            ];
        }

        return [
            'format' => self::FORMAT,
            'exported_at' => gmdate('c'),
            'project' => [
                'id' => $projectId,
                'title' => (string) ($project['title'] ?? ''),
                'vendor' => (string) ($project['vendor'] ?? ''),
                'demand_number' => (string) ($project['demand_number'] ?? ''),
                'story_number' => (string) ($project['story_number'] ?? ''),
                'task_number' => (string) ($project['task_number'] ?? ''),
                'ddr_number' => (string) ($project['ddr_number'] ?? ''),
                'demand_state' => (string) ($project['demand_state'] ?? ''),
                'story_state' => (string) ($project['story_state'] ?? ''),
                'task_state' => (string) ($project['task_state'] ?? ''),
                'ddr_state' => (string) ($project['ddr_state'] ?? ''),
                'owner' => function_exists('projectOwnerName') ? projectOwnerName($project) : '',
                'owner_username' => (string) ($project['owner_username'] ?? ''),
                'owner_display_name' => (string) ($project['owner_display_name'] ?? ''),
                'created_at' => (string) ($project['created_at'] ?? ''),
                'updated_at' => (string) ($project['updated_at'] ?? ''),
            ],
            'sources' => [
                'demand' => !empty($sources['demand']),
                'story' => !empty($sources['story']),
                'task' => !empty($sources['task']),
                'ddr' => !empty($sources['ddr']),
            ],
            'dossier' => $parsed,
            'files' => $fileManifest,
        ];
    }

    /**
     * Compact JSON text of the complete export (no attachment bytes).
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed>|null $parsed
     */
    public static function toJsonText(array $project, ?array $parsed = null, bool $pretty = false): string
    {
        $payload = self::buildPayload($project, $parsed);
        $payload['dossier'] = self::pruneEmpty($payload['dossier']);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($payload, $flags);
    }

    /**
     * Export JSON text sized for local Gemma reasoning.
     * Same schema as export.php; never includes PDF/attachment binaries.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed>|null $parsed
     */
    public static function toReasoningText(array $project, ?array $parsed = null, int $maxChars = TD_GEMMA_CONTEXT_CHARS): string
    {
        $payload = self::buildPayload($project, $parsed);
        $payload['dossier'] = self::pruneEmpty($payload['dossier']);
        $payload['dossier'] = self::compactDossierForReasoning($payload['dossier']);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '{}';
        }

        if (strlen($json) <= $maxChars) {
            return $json;
        }

        // Drop assessment Q&A next — usually the largest section — then retry.
        if (isset($payload['dossier']['assessments'])) {
            $payload['dossier']['assessments'] = [
                'note' => 'Assessment Q&A omitted to fit local model context; present in full export.php download.',
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json) && strlen($json) <= $maxChars) {
                return $json;
            }
        }

        // Last resort: keep meta + sources + files + truncated dossier JSON object.
        $header = [
            'format' => $payload['format'],
            'exported_at' => $payload['exported_at'],
            'project' => $payload['project'],
            'sources' => $payload['sources'],
            'files' => $payload['files'],
            'note' => 'dossier truncated for local Gemma context; download export.php for the full JSON file',
        ];
        $headerJson = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($headerJson)) {
            $headerJson = '{}';
        }
        $dossierJson = json_encode($payload['dossier'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($dossierJson)) {
            $dossierJson = '{}';
        }
        $budget = max(1500, $maxChars - strlen($headerJson) - 48);
        if (strlen($dossierJson) > $budget) {
            $dossierJson = substr($dossierJson, 0, $budget) . '…[truncated]';
        }

        return $headerJson . "\n\"dossier\":" . $dossierJson;
    }

    /**
     * Keep export completeness while shrinking verbose assessment trees for faster inference.
     *
     * @param array<string, mixed> $dossier
     * @return array<string, mixed>
     */
    private static function compactDossierForReasoning(array $dossier): array
    {
        foreach (['demand', 'story', 'task', 'ddr', 'vendor', 'overview'] as $section) {
            if (!isset($dossier[$section]) || !is_array($dossier[$section])) {
                continue;
            }
            $dossier[$section] = self::limitSection($dossier[$section], 60, 600);
        }

        if (isset($dossier['assessments']) && is_array($dossier['assessments'])) {
            $dossier['assessments'] = self::sampleAssessments($dossier['assessments'], 35);
        }

        return $dossier;
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private static function limitSection(array $section, int $maxFields, int $maxValueChars): array
    {
        $out = [];
        $count = 0;
        foreach ($section as $key => $value) {
            if ($key === 'fields' && is_array($value)) {
                $fields = [];
                $fieldCount = 0;
                foreach ($value as $label => $fieldValue) {
                    if ($fieldCount >= $maxFields) {
                        $fields['…'] = 'additional fields omitted for model context';
                        break;
                    }
                    $text = is_scalar($fieldValue) || $fieldValue === null
                        ? trim((string) $fieldValue)
                        : trim(json_encode($fieldValue, JSON_UNESCAPED_UNICODE) ?: '');
                    if ($text === '') {
                        continue;
                    }
                    $fields[is_string($label) ? $label : (string) $label] = self::clip($text, $maxValueChars);
                    $fieldCount++;
                }
                $out['fields'] = $fields;
                continue;
            }

            if ($count >= $maxFields) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $text = trim((string) $value);
                if ($text === '') {
                    continue;
                }
                $out[(string) $key] = self::clip($text, $maxValueChars);
                $count++;
            } elseif (is_array($value)) {
                $pruned = self::pruneEmpty($value);
                if ($pruned === [] || $pruned === null) {
                    continue;
                }
                $out[(string) $key] = $pruned;
                $count++;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $assessments
     * @return array{qa_sample: list<array{group: string, question: string, answer: string}>}
     */
    private static function sampleAssessments(array $assessments, int $max): array
    {
        $samples = [];
        foreach (['external', 'internal'] as $groupKey) {
            $group = $assessments[$groupKey] ?? [];
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $assessment) {
                if (!is_array($assessment)) {
                    continue;
                }
                foreach (($assessment['questionnaires'] ?? []) as $questionnaire) {
                    if (!is_array($questionnaire)) {
                        continue;
                    }
                    foreach (($questionnaire['instances'] ?? []) as $instance) {
                        if (!is_array($instance)) {
                            continue;
                        }
                        foreach (($instance['qa'] ?? []) as $qa) {
                            if (!is_array($qa) || count($samples) >= $max) {
                                break 4;
                            }
                            $q = trim((string) ($qa['question'] ?? ''));
                            $a = trim((string) (function_exists('normalizeDisplayValue')
                                ? normalizeDisplayValue($qa['answer'] ?? '')
                                : ($qa['answer'] ?? '')));
                            if ($q === '' || $a === '') {
                                continue;
                            }
                            if (function_exists('isEmptyish') && isEmptyish($a)) {
                                continue;
                            }
                            $samples[] = [
                                'group' => $groupKey,
                                'question' => self::clip($q, 220),
                                'answer' => self::clip($a, 360),
                            ];
                        }
                    }
                }
            }
        }

        return ['qa_sample' => $samples];
    }

    /**
     * Remove empty / emptyish leaves so the export text is smaller and faster to reason over.
     */
    private static function pruneEmpty(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            $isList = array_is_list($value);
            foreach ($value as $key => $child) {
                $pruned = self::pruneEmpty($child);
                if ($pruned === null || $pruned === '' || $pruned === []) {
                    continue;
                }
                if (is_string($pruned) && function_exists('isEmptyish') && isEmptyish($pruned)) {
                    continue;
                }
                if ($isList) {
                    $out[] = $pruned;
                } else {
                    $out[$key] = $pruned;
                }
            }

            return $out;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    private static function clip(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, max(0, $max - 1)) . '…';
    }
}
