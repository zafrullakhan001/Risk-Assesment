<?php
declare(strict_types=1);

final class DdrJsonParser
{
    /**
     * @return array{
     *   number: string,
     *   state: string,
     *   title: string,
     *   vendor_name: string,
     *   description: string,
     *   fields: array<string, string>,
     *   metadata: array<string, mixed>,
     *   vendor: array{fields: array<string, string>, metadata: array<string, mixed>},
     *   assessments: array{external: list<array<string, mixed>>, internal: list<array<string, mixed>>},
     *   export_meta: array<string, mixed>
     * }
     */
    public static function parse(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Could not read DDR JSON file.');
        }

        if (strlen($raw) > TD_MAX_UPLOAD_BYTES) {
            throw new RuntimeException('DDR JSON file is too large.');
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('DDR JSON root must be an object.');
        }

        $ddrFields = fieldsToMap(is_array($data['ddr']['fields'] ?? null) ? $data['ddr']['fields'] : []);
        $vendorFields = fieldsToMap(is_array($data['vendor']['fields'] ?? null) ? $data['vendor']['fields'] : []);

        $number = fieldValue($ddrFields, 'Unique Identifier', 'Number', 'Effective number', 'Top task');
        $state = fieldValue($ddrFields, 'State');
        $title = firstNonEmpty(
            fieldValue($ddrFields, 'Engagement', 'Engagement name', 'Demand', 'Short Description'),
            fieldValue($vendorFields, 'Name')
        );
        $vendorName = firstNonEmpty(
            fieldValue($ddrFields, 'Third party', 'Third-party name'),
            fieldValue($vendorFields, 'Name')
        );
        $description = fieldValue($ddrFields, 'Description', 'Solution Description', 'Short Description');

        return [
            'number' => $number,
            'state' => $state,
            'title' => $title,
            'vendor_name' => $vendorName,
            'description' => $description,
            'fields' => $ddrFields,
            'metadata' => is_array($data['ddr']['metadata'] ?? null) ? $data['ddr']['metadata'] : [],
            'vendor' => [
                'fields' => $vendorFields,
                'metadata' => is_array($data['vendor']['metadata'] ?? null) ? $data['vendor']['metadata'] : [],
            ],
            'assessments' => [
                'external' => self::normalizeAssessments($data['external_assessments'] ?? [], 'external'),
                'internal' => self::normalizeAssessments($data['internal_assessments'] ?? [], 'internal'),
            ],
            'export_meta' => [
                'export_version' => $data['export_version'] ?? null,
                'exported_on' => $data['exported_on'] ?? null,
                'root_sys_id' => $data['root_sys_id'] ?? null,
                'root_table' => $data['root_table'] ?? null,
            ],
        ];
    }

    /**
     * @param mixed $assessments
     * @return list<array<string, mixed>>
     */
    private static function normalizeAssessments(mixed $assessments, string $type): array
    {
        if (!is_array($assessments)) {
            return [];
        }

        $out = [];
        foreach ($assessments as $assessment) {
            if (!is_array($assessment)) {
                continue;
            }

            $wrapperKey = $type === 'external' ? 'external_assessment' : 'internal_assessment';
            $core = is_array($assessment[$wrapperKey] ?? null) ? $assessment[$wrapperKey] : [];
            $fields = fieldsToMap(is_array($core['fields'] ?? null) ? $core['fields'] : []);

            $questionnaires = [];
            $rawQuestionnaires = $assessment['questionnaires'] ?? [];
            if (is_array($rawQuestionnaires)) {
                foreach ($rawQuestionnaires as $questionnaire) {
                    if (!is_array($questionnaire)) {
                        continue;
                    }
                    $questionnaires[] = self::normalizeQuestionnaire($questionnaire);
                }
            }

            // Some exports nest QA without questionnaires key.
            if ($questionnaires === [] && isset($assessment['qa']) && is_array($assessment['qa'])) {
                $questionnaires[] = [
                    'name' => fieldValue($fields, 'Name', 'Short Description', 'Number') ?: 'Questionnaire',
                    'instances' => [[
                        'fields' => [],
                        'qa' => self::normalizeQaList($assessment['qa']),
                    ]],
                ];
            }

            $out[] = [
                'type' => $type,
                'number' => fieldValue($fields, 'Number', 'Effective number', 'Top task'),
                'name' => fieldValue($fields, 'Name', 'Short Description'),
                'state' => fieldValue($fields, 'State'),
                'fields' => $fields,
                'metadata' => is_array($core['metadata'] ?? null) ? $core['metadata'] : [],
                'questionnaires' => $questionnaires,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $questionnaire
     * @return array{name: string, instances: list<array{fields: array<string, string>, qa: list<array<string, string>>}>}
     */
    private static function normalizeQuestionnaire(array $questionnaire): array
    {
        $qFields = fieldsToMap(is_array($questionnaire['fields'] ?? null) ? $questionnaire['fields'] : []);
        $name = firstNonEmpty(
            fieldValue($qFields, 'Name', 'Short Description', 'Number'),
            'Questionnaire'
        );

        $instances = [];
        $rawInstances = $questionnaire['assessment_instances'] ?? [];
        if (!is_array($rawInstances)) {
            $rawInstances = [];
        }

        foreach ($rawInstances as $instance) {
            if (!is_array($instance)) {
                continue;
            }
            $instFields = [];
            if (isset($instance['instance']['fields']) && is_array($instance['instance']['fields'])) {
                $instFields = fieldsToMap($instance['instance']['fields']);
            } elseif (isset($instance['fields']) && is_array($instance['fields'])) {
                $instFields = fieldsToMap($instance['fields']);
            }

            $qa = [];
            if (isset($instance['qa']) && is_array($instance['qa'])) {
                $qa = self::normalizeQaList($instance['qa']);
            }

            $instances[] = [
                'fields' => $instFields,
                'qa' => $qa,
            ];
        }

        if ($instances === [] && isset($questionnaire['qa']) && is_array($questionnaire['qa'])) {
            $instances[] = [
                'fields' => [],
                'qa' => self::normalizeQaList($questionnaire['qa']),
            ];
        }

        return [
            'name' => $name,
            'instances' => $instances,
        ];
    }

    /**
     * @param list<mixed> $qa
     * @return list<array{question: string, answer: string}>
     */
    private static function normalizeQaList(array $qa): array
    {
        $out = [];
        foreach ($qa as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? ''));
            $answer = firstNonEmpty(
                normalizeDisplayValue($item['answer'] ?? ''),
                normalizeDisplayValue($item['string_answer'] ?? ''),
                normalizeDisplayValue($item['answer_value'] ?? '')
            );
            if ($question === '' && $answer === '') {
                continue;
            }
            $out[] = [
                'question' => $question !== '' ? $question : '(Untitled question)',
                'answer' => $answer,
            ];
        }

        return $out;
    }
}
