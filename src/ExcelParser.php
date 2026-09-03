<?php

declare(strict_types=1);

namespace RiskAssessment;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RiskAssessment\Models\Assessment;

final class ExcelParser
{
    private const METADATA_ROW_START = 2;
    private const METADATA_ROW_END = 7;
    private const ARCHITECTURE_HEADER_ROW = 8;
    private const ARCHITECTURE_DATA_START = 9;

    private const METADATA_MAP = [
        'solutionname' => 'solution_name',
        'vendor' => 'vendor',
        'scope' => 'scope',
        'scopesiteregionalenterprise' => 'scope',
        'architecturemodel' => 'architecture_model',
        'architecturemodelcentralizeddistributed' => 'architecture_model',
        'reviewer' => 'reviewer',
        'date' => 'date',
    ];

    public function parse(string $filePath): Assessment
    {
        if (!is_readable($filePath)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $spreadsheet = IOFactory::load($filePath);
        $architectureSheet = $this->findArchitectureSheet($spreadsheet);

        $metadata = $this->parseMetadata($architectureSheet);
        $columnMap = $this->parseArchitectureHeaderRow($architectureSheet);
        $items = $this->parseArchitectureDataRows($architectureSheet, $columnMap);

        if ($items === []) {
            throw new \InvalidArgumentException('No risk assessment rows were found in the spreadsheet.');
        }

        $dueDiligenceItems = [];
        $dueDiligenceContext = '';
        $dueDiligenceSheet = $this->findSheetByTitleContains($spreadsheet, ['due diligence extension']);
        if ($dueDiligenceSheet !== null) {
            [$dueDiligenceItems, $dueDiligenceContext] = $this->parseDueDiligenceExtension($dueDiligenceSheet);
        }

        $summaryFields = [];
        $findings = [];
        $summaryNote = '';
        $jsonSummarySheet = $this->findSheetByTitleContains($spreadsheet, ['json due diligence', 'due diligence summary']);
        if ($jsonSummarySheet !== null) {
            [$summaryFields, $findings, $summaryNote] = $this->parseJsonDueDiligenceSummary($jsonSummarySheet);
            $metadata = $this->enrichMetadataFromSummary($metadata, $summaryFields);
        }

        $scoringLegend = [
            'statuses' => [],
            'risk_levels' => [],
            'checklist' => [],
        ];
        $legendSheet = $this->findSheetByTitleContains($spreadsheet, ['scoring legend']);
        if ($legendSheet !== null) {
            $scoringLegend = $this->parseScoringLegend($legendSheet);
        }

        return Assessment::fromParsedData(
            $metadata,
            $items,
            $dueDiligenceItems,
            [
                'context' => $dueDiligenceContext,
                'fields' => $summaryFields,
                'findings' => $findings,
                'note' => $summaryNote,
                'legend' => $scoringLegend,
            ]
        );
    }

    private function findArchitectureSheet(Spreadsheet $spreadsheet): Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = strtolower($sheet->getTitle());
            if (str_contains($title, 'sheet1') || str_contains($title, 'architecture') || str_contains($title, 'data sheet')) {
                if ($this->looksLikeArchitectureSheet($sheet)) {
                    return $sheet;
                }
            }
        }

        $first = $spreadsheet->getSheet(0);
        if ($this->looksLikeArchitectureSheet($first)) {
            return $first;
        }

        throw new \InvalidArgumentException('Could not find the Architecture Risk Analysis data sheet.');
    }

    private function looksLikeArchitectureSheet(Worksheet $sheet): bool
    {
        $header = strtolower($this->cellValue($sheet, 'A', self::ARCHITECTURE_HEADER_ROW));
        $check = strtolower($this->cellValue($sheet, 'B', self::ARCHITECTURE_HEADER_ROW));

        return $header === 'section' && str_starts_with($check, 'check');
    }

    /** @param list<string> $needles */
    private function findSheetByTitleContains(Spreadsheet $spreadsheet, array $needles): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = strtolower($sheet->getTitle());
            foreach ($needles as $needle) {
                if (str_contains($title, strtolower($needle))) {
                    return $sheet;
                }
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function parseMetadata(Worksheet $sheet): array
    {
        $metadata = [
            'solution_name' => '',
            'vendor' => '',
            'scope' => '',
            'architecture_model' => '',
            'reviewer' => '',
            'date' => '',
        ];

        for ($row = self::METADATA_ROW_START; $row <= self::METADATA_ROW_END; $row++) {
            $label = $this->cellValue($sheet, 'B', $row);
            $value = $this->cellValue($sheet, 'C', $row);

            if ($label === '' && $value === '') {
                continue;
            }

            $normalizedLabel = $this->normalizeKey($label);
            if (isset(self::METADATA_MAP[$normalizedLabel])) {
                $metadata[self::METADATA_MAP[$normalizedLabel]] = $value;
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, string> $metadata
     * @param list<array<string, string>> $summaryFields
     * @return array<string, string>
     */
    private function enrichMetadataFromSummary(array $metadata, array $summaryFields): array
    {
        $map = [];
        foreach ($summaryFields as $field) {
            $map[$this->normalizeKey($field['label'] ?? '')] = trim($field['value'] ?? '');
        }

        if (($metadata['vendor'] ?? '') === '' && ($map['vendorproduct'] ?? '') !== '') {
            $metadata['vendor'] = $map['vendorproduct'];
        }

        foreach ([
            'duediligencerequest' => 'ddr_id',
            'technologyriskassessment' => 'vra_id',
            'businessunit' => 'business_unit',
            'assessmenttypetier' => 'assessment_tier',
            'overallriskrating' => 'overall_risk_rating',
            'tprmrecommendation' => 'tprm_recommendation',
            'technologyrecommendation' => 'technology_recommendation',
            'facilityregion' => 'facility_region',
            'datahosting' => 'data_hosting',
            'vendoraccessai' => 'vendor_access_ai',
            'requiredgovernanceaction' => 'governance_action',
        ] as $source => $target) {
            if (($map[$source] ?? '') !== '') {
                $metadata[$target] = $map[$source];
            }
        }

        return $metadata;
    }

    /** @return array<string, string> */
    private function parseArchitectureHeaderRow(Worksheet $sheet): array
    {
        $highestColumn = $sheet->getHighestColumn(self::ARCHITECTURE_HEADER_ROW);
        $columnMap = [];
        $foundFields = [];

        foreach ($this->columnRange('A', $highestColumn) as $column) {
            $header = $this->cellValue($sheet, $column, self::ARCHITECTURE_HEADER_ROW);
            if ($header === '') {
                continue;
            }

            $normalized = $this->normalizeKey($header);
            $field = match (true) {
                $normalized === 'section' => 'section',
                $normalized === 'check' => 'check',
                str_starts_with($normalized, 'status') => 'status',
                str_starts_with($normalized, 'risklevel') => 'risk_level',
                $normalized === 'notes' => 'notes',
                str_starts_with($normalized, 'mitigation') => 'mitigation',
                $normalized === 'owner' => 'owner',
                str_starts_with($normalized, 'remediationtimeline') => 'remediation_timeline',
                default => null,
            };

            if ($field !== null) {
                $columnMap[$column] = $field;
                $foundFields[$field] = true;
            }
        }

        $requiredFields = ['section', 'check', 'status', 'risk_level'];
        $missingFields = array_diff($requiredFields, array_keys($foundFields));

        if ($missingFields !== []) {
            throw new \InvalidArgumentException('The spreadsheet header row is missing required columns. Expected the Architecture Risk Assessment Data Sheet format.');
        }

        return $columnMap;
    }

    /**
     * @param array<string, string> $columnMap
     * @return list<array<string, string>>
     */
    private function parseArchitectureDataRows(Worksheet $sheet, array $columnMap): array
    {
        $items = [];
        $currentSection = '';
        $highestRow = $sheet->getHighestRow();
        $sortOrder = 0;

        for ($row = self::ARCHITECTURE_DATA_START; $row <= $highestRow; $row++) {
            $rowValues = [];
            foreach ($columnMap as $column => $field) {
                $rowValues[$field] = $this->cellValue($sheet, $column, $row);
            }

            if ($this->isEmptyRow($rowValues)) {
                break;
            }

            $section = trim($rowValues['section'] ?? '');
            if ($section !== '') {
                $currentSection = $section;
            }

            $check = trim($rowValues['check'] ?? '');
            if ($check === '') {
                continue;
            }

            $items[] = [
                'item_type' => 'architecture',
                'section' => $currentSection,
                'check' => $check,
                'status' => Assessment::normalizeStatus($rowValues['status'] ?? ''),
                'risk_level' => Assessment::normalizeRiskLevel($rowValues['risk_level'] ?? ''),
                'notes' => trim($rowValues['notes'] ?? ''),
                'mitigation' => trim($rowValues['mitigation'] ?? ''),
                'owner' => trim($rowValues['owner'] ?? ''),
                'remediation_timeline' => trim($rowValues['remediation_timeline'] ?? ''),
                'review_question' => '',
                'source_reference' => '',
                'sort_order' => (string) $sortOrder++,
            ];
        }

        return $items;
    }

    /**
     * @return array{0: list<array<string, string>>, 1: string}
     */
    private function parseDueDiligenceExtension(Worksheet $sheet): array
    {
        $headerRow = $this->findHeaderRow($sheet, ['category', 'assessmentitem', 'status'], 1, 12);
        if ($headerRow === null) {
            return [[], ''];
        }

        $context = '';
        for ($row = 1; $row < $headerRow; $row++) {
            $value = $this->cellValue($sheet, 'A', $row);
            if (stripos($value, 'Current assessment context') !== false) {
                $context = $value;
                break;
            }
        }

        $columnMap = [];
        $highestColumn = $sheet->getHighestColumn($headerRow);
        foreach ($this->columnRange('A', $highestColumn) as $column) {
            $header = $this->cellValue($sheet, $column, $headerRow);
            if ($header === '') {
                continue;
            }

            $normalized = $this->normalizeKey($header);
            $field = match (true) {
                $normalized === 'category' => 'section',
                $normalized === 'assessmentitem' => 'check',
                str_contains($normalized, 'finding') || str_contains($normalized, 'evidence') => 'notes',
                $normalized === 'status' => 'status',
                str_starts_with($normalized, 'risklevel') => 'risk_level',
                str_contains($normalized, 'action') || str_contains($normalized, 'mitigation') => 'mitigation',
                $normalized === 'owner' => 'owner',
                $normalized === 'timeline' => 'remediation_timeline',
                str_contains($normalized, 'question') => 'review_question',
                str_contains($normalized, 'source') || str_contains($normalized, 'reference') => 'source_reference',
                default => null,
            };

            if ($field !== null) {
                $columnMap[$column] = $field;
            }
        }

        if (!in_array('check', $columnMap, true)) {
            return [[], $context];
        }

        $items = [];
        $sortOrder = 0;
        $highestRow = $sheet->getHighestRow();

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $rowValues = [
                'section' => '',
                'check' => '',
                'notes' => '',
                'status' => '',
                'risk_level' => '',
                'mitigation' => '',
                'owner' => '',
                'remediation_timeline' => '',
                'review_question' => '',
                'source_reference' => '',
            ];

            foreach ($columnMap as $column => $field) {
                $rowValues[$field] = $this->cellValue($sheet, $column, $row);
            }

            if ($this->isEmptyRow($rowValues)) {
                continue;
            }

            $check = trim($rowValues['check']);
            if ($check === '') {
                continue;
            }

            $items[] = [
                'item_type' => 'due_diligence',
                'section' => trim($rowValues['section']),
                'check' => $check,
                'status' => Assessment::normalizeStatus($rowValues['status']),
                'risk_level' => Assessment::normalizeRiskLevel($rowValues['risk_level']),
                'notes' => trim($rowValues['notes']),
                'mitigation' => trim($rowValues['mitigation']),
                'owner' => trim($rowValues['owner']),
                'remediation_timeline' => trim($rowValues['remediation_timeline']),
                'review_question' => trim($rowValues['review_question']),
                'source_reference' => trim($rowValues['source_reference']),
                'sort_order' => (string) $sortOrder++,
            ];
        }

        return [$items, $context];
    }

    /**
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>, 2: string}
     */
    private function parseJsonDueDiligenceSummary(Worksheet $sheet): array
    {
        $fields = [];
        $findings = [];
        $note = '';
        $highestRow = $sheet->getHighestRow();
        $mode = 'fields';

        for ($row = 1; $row <= $highestRow; $row++) {
            $a = $this->cellValue($sheet, 'A', $row);
            $b = $this->cellValue($sheet, 'B', $row);
            $c = $this->cellValue($sheet, 'C', $row);
            $d = $this->cellValue($sheet, 'D', $row);
            $e = $this->cellValue($sheet, 'E', $row);
            $f = $this->cellValue($sheet, 'F', $row);

            $normalizedA = $this->normalizeKey($a);

            if ($normalizedA === 'jsonfield') {
                $mode = 'fields';
                continue;
            }

            if (str_contains(strtolower($a), 'documented findings') || $normalizedA === 'findingcontrol') {
                $mode = str_contains(strtolower($a), 'documented findings') ? 'findings_header' : 'findings';
                if ($normalizedA === 'findingcontrol') {
                    $mode = 'findings';
                }
                continue;
            }

            if (str_contains(strtolower($a), 'risk interpretation note')) {
                $mode = 'note';
                continue;
            }

            if ($mode === 'note') {
                if ($a !== '') {
                    $note = trim($note === '' ? $a : $note . ' ' . $a);
                }
                continue;
            }

            if ($mode === 'findings_header') {
                if ($normalizedA === 'findingcontrol') {
                    $mode = 'findings';
                }
                continue;
            }

            if ($mode === 'findings') {
                if ($a === '') {
                    continue;
                }
                $findings[] = [
                    'finding' => $a,
                    'policy_reference' => $b,
                    'impact' => $c,
                    'mitigation' => $d,
                    'owner' => $e,
                    'timeline' => $f,
                ];
                continue;
            }

            if ($a === '' || $normalizedA === 'jsonfield') {
                continue;
            }

            // Skip title/intro rows before the field table.
            if ($b === '' && $c === '') {
                continue;
            }

            if (stripos($a, 'Due Diligence JSON Summary') === 0 || stripos($a, 'This tab makes') === 0) {
                continue;
            }

            $fields[] = [
                'label' => $a,
                'value' => $b,
                'use' => $c,
            ];
        }

        return [$fields, $findings, $note];
    }

    /** @return array{statuses: list<array<string, string>>, risk_levels: list<array<string, string>>, checklist: list<string>} */
    private function parseScoringLegend(Worksheet $sheet): array
    {
        $statuses = [];
        $riskLevels = [];
        $checklist = [];
        $highestRow = $sheet->getHighestRow();
        $mode = '';

        for ($row = 1; $row <= $highestRow; $row++) {
            $a = $this->cellValue($sheet, 'A', $row);
            $b = $this->cellValue($sheet, 'B', $row);
            $c = $this->cellValue($sheet, 'C', $row);
            $e = $this->cellValue($sheet, 'E', $row);
            $f = $this->cellValue($sheet, 'F', $row);
            $normalizedA = $this->normalizeKey($a);

            if ($normalizedA === 'status') {
                $mode = 'status';
                continue;
            }

            if (str_contains(strtolower($a), 'minimum evidence checklist')) {
                $mode = 'checklist';
                continue;
            }

            if ($mode === 'status') {
                $status = Assessment::normalizeStatus($a);
                if (in_array($status, ['Pass', 'Gap', 'Risk', 'TBD', 'N/A'], true)) {
                    $statuses[] = [
                        'status' => $status,
                        'meaning' => $b,
                        'action' => $c,
                    ];
                }
                if ($e !== '') {
                    $risk = Assessment::normalizeRiskLevel($e);
                    if (in_array($risk, ['Low', 'Med', 'High'], true)) {
                        $riskLevels[] = [
                            'risk_level' => $risk,
                            'use_when' => $f,
                        ];
                    }
                }
                continue;
            }

            if ($mode === 'checklist' && $b !== '') {
                $checklist[] = $b;
            }
        }

        return [
            'statuses' => $statuses,
            'risk_levels' => $riskLevels,
            'checklist' => $checklist,
        ];
    }

    /** @param list<string> $requiredNormalizedHeaders */
    private function findHeaderRow(Worksheet $sheet, array $requiredNormalizedHeaders, int $startRow, int $endRow): ?int
    {
        for ($row = $startRow; $row <= $endRow; $row++) {
            $headers = [];
            foreach ($this->columnRange('A', $sheet->getHighestColumn($row)) as $column) {
                $value = $this->normalizeKey($this->cellValue($sheet, $column, $row));
                if ($value !== '') {
                    $headers[] = $value;
                }
            }

            $matched = 0;
            foreach ($requiredNormalizedHeaders as $required) {
                foreach ($headers as $header) {
                    if ($header === $required || str_starts_with($header, $required)) {
                        $matched++;
                        break;
                    }
                }
            }

            if ($matched === count($requiredNormalizedHeaders)) {
                return $row;
            }
        }

        return null;
    }

    private function cellValue(Worksheet $sheet, string $column, int $row): string
    {
        $value = $sheet->getCell($column . $row)->getCalculatedValue();

        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    /** @param array<string, string> $rowValues */
    private function isEmptyRow(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value;
    }

    /** @return list<string> */
    private function columnRange(string $start, string $end): array
    {
        $columns = [];
        $current = $start;

        while (true) {
            $columns[] = $current;
            if ($current === $end) {
                break;
            }
            $current = $this->nextColumn($current);
        }

        return $columns;
    }

    private function nextColumn(string $column): string
    {
        $length = strlen($column);
        $chars = str_split($column);
        $index = $length - 1;

        while ($index >= 0) {
            if ($chars[$index] !== 'Z') {
                $chars[$index] = chr(ord($chars[$index]) + 1);
                return implode('', $chars);
            }

            $chars[$index] = 'A';
            $index--;
        }

        return 'A' . implode('', $chars);
    }
}
