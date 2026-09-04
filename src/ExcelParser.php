<?php

declare(strict_types=1);

namespace RiskAssessment;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RiskAssessment\Models\Assessment;

final class ExcelParser
{
    private const METADATA_ROW_START = 2;
    private const METADATA_ROW_END = 7;
    private const ARCHITECTURE_HEADER_ROW = 8;
    private const ARCHITECTURE_DATA_START = 9;

    private const TABLE_RISK_REGISTER = 'RiskRegisterTable';
    private const TABLE_DUE_DILIGENCE = 'DueDiligenceExtensionTable';
    private const TABLE_STATUS_LEGEND = 'StatusLegendTable';
    private const TABLE_RISK_LEGEND = 'RiskLevelLegendTable';
    private const TABLE_EVIDENCE_CHECKLIST = 'EvidenceChecklistTable';
    private const TABLE_DD_SUMMARY = 'DueDiligenceSummaryTable';
    private const TABLE_EXCEPTION_REGISTER = 'ExceptionRegisterTable';

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
        $items = $this->parseArchitectureItems($architectureSheet);

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
        $tableSheet = $this->findSheetWithTable($spreadsheet, self::TABLE_RISK_REGISTER);
        if ($tableSheet !== null) {
            return $tableSheet;
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = strtolower($sheet->getTitle());
            if (
                str_contains($title, 'risk register')
                || str_contains($title, 'sheet1')
                || str_contains($title, 'architecture')
                || str_contains($title, 'data sheet')
            ) {
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
        $headerRow = self::ARCHITECTURE_HEADER_ROW;
        $table = $this->findTableOnSheet($sheet, self::TABLE_RISK_REGISTER);
        if ($table !== null) {
            $headerRow = $this->tableBounds($table)['headerRow'];
        }

        $header = strtolower($this->cellValue($sheet, 'A', $headerRow));
        $check = strtolower($this->cellValue($sheet, 'B', $headerRow));

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

    /** @return list<array<string, string>> */
    private function parseArchitectureItems(Worksheet $sheet): array
    {
        $table = $this->findTableOnSheet($sheet, self::TABLE_RISK_REGISTER);
        if ($table !== null) {
            $bounds = $this->tableBounds($table);
            $columnMap = $this->mapArchitectureColumns($sheet, $bounds['headerRow'], $bounds['startCol'], $bounds['endCol']);
            return $this->parseArchitectureDataRows(
                $sheet,
                $columnMap,
                $bounds['dataStart'],
                $bounds['dataEnd'],
                false
            );
        }

        $columnMap = $this->mapArchitectureColumns(
            $sheet,
            self::ARCHITECTURE_HEADER_ROW,
            1,
            Coordinate::columnIndexFromString($sheet->getHighestColumn(self::ARCHITECTURE_HEADER_ROW))
        );

        return $this->parseArchitectureDataRows(
            $sheet,
            $columnMap,
            self::ARCHITECTURE_DATA_START,
            $sheet->getHighestRow(),
            true
        );
    }

    /**
     * @return array<string, string> column letter => field
     */
    private function mapArchitectureColumns(Worksheet $sheet, int $headerRow, int $startColIndex, int $endColIndex): array
    {
        $columnMap = [];
        $foundFields = [];

        for ($colIndex = $startColIndex; $colIndex <= $endColIndex; $colIndex++) {
            $column = Coordinate::stringFromColumnIndex($colIndex);
            $header = $this->cellValue($sheet, $column, $headerRow);
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
    private function parseArchitectureDataRows(
        Worksheet $sheet,
        array $columnMap,
        int $dataStart,
        int $dataEnd,
        bool $stopOnEmptyRow
    ): array {
        $items = [];
        $currentSection = '';
        $sortOrder = 0;

        for ($row = $dataStart; $row <= $dataEnd; $row++) {
            $rowValues = [];
            foreach ($columnMap as $column => $field) {
                $rowValues[$field] = $this->cellValue($sheet, $column, $row);
            }

            if ($this->isEmptyRow($rowValues)) {
                if ($stopOnEmptyRow) {
                    break;
                }
                continue;
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
        $context = $this->findDueDiligenceContext($sheet);
        $table = $this->findTableOnSheet($sheet, self::TABLE_DUE_DILIGENCE);

        if ($table !== null) {
            $bounds = $this->tableBounds($table);
            $columnMap = $this->mapDueDiligenceColumns($sheet, $bounds['headerRow'], $bounds['startCol'], $bounds['endCol']);
            if (!in_array('check', $columnMap, true)) {
                return [[], $context];
            }

            return [
                $this->parseDueDiligenceDataRows($sheet, $columnMap, $bounds['dataStart'], $bounds['dataEnd']),
                $context,
            ];
        }

        $headerRow = $this->findHeaderRow($sheet, ['category', 'assessmentitem', 'status'], 1, 12);
        if ($headerRow === null) {
            return [[], $context];
        }

        $endColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn($headerRow));
        $columnMap = $this->mapDueDiligenceColumns($sheet, $headerRow, 1, $endColIndex);
        if (!in_array('check', $columnMap, true)) {
            return [[], $context];
        }

        return [
            $this->parseDueDiligenceDataRows($sheet, $columnMap, $headerRow + 1, $sheet->getHighestRow()),
            $context,
        ];
    }

    private function findDueDiligenceContext(Worksheet $sheet): string
    {
        $scanEnd = min(12, $sheet->getHighestRow());
        for ($row = 1; $row <= $scanEnd; $row++) {
            $value = $this->cellValue($sheet, 'A', $row);
            if (stripos($value, 'Current assessment context') !== false) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<string, string> column letter => field
     */
    private function mapDueDiligenceColumns(Worksheet $sheet, int $headerRow, int $startColIndex, int $endColIndex): array
    {
        $columnMap = [];

        for ($colIndex = $startColIndex; $colIndex <= $endColIndex; $colIndex++) {
            $column = Coordinate::stringFromColumnIndex($colIndex);
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

        return $columnMap;
    }

    /**
     * @param array<string, string> $columnMap
     * @return list<array<string, string>>
     */
    private function parseDueDiligenceDataRows(
        Worksheet $sheet,
        array $columnMap,
        int $dataStart,
        int $dataEnd
    ): array {
        $items = [];
        $sortOrder = 0;

        for ($row = $dataStart; $row <= $dataEnd; $row++) {
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

        return $items;
    }

    /**
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>, 2: string}
     */
    private function parseJsonDueDiligenceSummary(Worksheet $sheet): array
    {
        $summaryTable = $this->findTableOnSheet($sheet, self::TABLE_DD_SUMMARY);
        $exceptionTable = $this->findTableOnSheet($sheet, self::TABLE_EXCEPTION_REGISTER);

        if ($summaryTable !== null || $exceptionTable !== null) {
            $fields = $summaryTable !== null
                ? $this->parseSummaryFieldsFromTable($sheet, $summaryTable)
                : [];
            $findings = $exceptionTable !== null
                ? $this->parseFindingsFromTable($sheet, $exceptionTable)
                : [];
            $note = $this->parseSummaryNote($sheet);

            return [$fields, $findings, $note];
        }

        return $this->parseJsonDueDiligenceSummaryLegacy($sheet);
    }

    /** @return list<array<string, string>> */
    private function parseSummaryFieldsFromTable(Worksheet $sheet, Table $table): array
    {
        $bounds = $this->tableBounds($table);
        $columnMap = [];

        for ($colIndex = $bounds['startCol']; $colIndex <= $bounds['endCol']; $colIndex++) {
            $column = Coordinate::stringFromColumnIndex($colIndex);
            $normalized = $this->normalizeKey($this->cellValue($sheet, $column, $bounds['headerRow']));
            $field = match (true) {
                str_contains($normalized, 'jsonfield') || $normalized === 'field' || $normalized === 'label' => 'label',
                $normalized === 'value' => 'value',
                str_contains($normalized, 'assessmentuse') || $normalized === 'use' => 'use',
                default => null,
            };
            if ($field !== null) {
                $columnMap[$column] = $field;
            }
        }

        if (!in_array('label', $columnMap, true)) {
            return [];
        }

        $fields = [];
        for ($row = $bounds['dataStart']; $row <= $bounds['dataEnd']; $row++) {
            $label = '';
            $value = '';
            $use = '';
            foreach ($columnMap as $column => $field) {
                $cell = $this->cellValue($sheet, $column, $row);
                if ($field === 'label') {
                    $label = $cell;
                } elseif ($field === 'value') {
                    $value = $cell;
                } elseif ($field === 'use') {
                    $use = $cell;
                }
            }

            if ($label === '') {
                continue;
            }

            // Skip blank Value+Use pairs (blank template placeholders).
            if ($value === '' && $use === '') {
                continue;
            }

            $fields[] = [
                'label' => $label,
                'value' => $value,
                'use' => $use,
            ];
        }

        return $fields;
    }

    /** @return list<array<string, string>> */
    private function parseFindingsFromTable(Worksheet $sheet, Table $table): array
    {
        $bounds = $this->tableBounds($table);
        $columnMap = [];

        for ($colIndex = $bounds['startCol']; $colIndex <= $bounds['endCol']; $colIndex++) {
            $column = Coordinate::stringFromColumnIndex($colIndex);
            $normalized = $this->normalizeKey($this->cellValue($sheet, $column, $bounds['headerRow']));
            $field = match (true) {
                str_contains($normalized, 'finding') || str_contains($normalized, 'control') => 'finding',
                str_contains($normalized, 'policy') || str_contains($normalized, 'reference') => 'policy_reference',
                $normalized === 'impact' => 'impact',
                str_contains($normalized, 'exception') || str_contains($normalized, 'mitigation') => 'mitigation',
                $normalized === 'owner' => 'owner',
                $normalized === 'timeline' => 'timeline',
                default => null,
            };
            if ($field !== null && !in_array($field, $columnMap, true)) {
                $columnMap[$column] = $field;
            }
        }

        if (!in_array('finding', $columnMap, true)) {
            return [];
        }

        $findings = [];
        for ($row = $bounds['dataStart']; $row <= $bounds['dataEnd']; $row++) {
            $rowData = [
                'finding' => '',
                'policy_reference' => '',
                'impact' => '',
                'mitigation' => '',
                'owner' => '',
                'timeline' => '',
            ];
            foreach ($columnMap as $column => $field) {
                $rowData[$field] = $this->cellValue($sheet, $column, $row);
            }

            if (trim($rowData['finding']) === '') {
                continue;
            }

            $findings[] = $rowData;
        }

        return $findings;
    }

    private function parseSummaryNote(Worksheet $sheet): string
    {
        $note = '';
        $highestRow = $sheet->getHighestRow();
        $inNote = false;

        for ($row = 1; $row <= $highestRow; $row++) {
            $a = $this->cellValue($sheet, 'A', $row);
            if (str_contains(strtolower($a), 'risk interpretation note')) {
                $inNote = true;
                continue;
            }
            if ($inNote && $a !== '') {
                $note = trim($note === '' ? $a : $note . ' ' . $a);
            }
        }

        return $note;
    }

    /**
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>, 2: string}
     */
    private function parseJsonDueDiligenceSummaryLegacy(Worksheet $sheet): array
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
        $statusTable = $this->findTableOnSheet($sheet, self::TABLE_STATUS_LEGEND);
        $riskTable = $this->findTableOnSheet($sheet, self::TABLE_RISK_LEGEND);
        $checklistTable = $this->findTableOnSheet($sheet, self::TABLE_EVIDENCE_CHECKLIST);

        if ($statusTable !== null || $riskTable !== null || $checklistTable !== null) {
            return [
                'statuses' => $statusTable !== null ? $this->parseStatusLegendTable($sheet, $statusTable) : [],
                'risk_levels' => $riskTable !== null ? $this->parseRiskLegendTable($sheet, $riskTable) : [],
                'checklist' => $checklistTable !== null ? $this->parseEvidenceChecklistTable($sheet, $checklistTable) : [],
            ];
        }

        return $this->parseScoringLegendLegacy($sheet);
    }

    /** @return list<array<string, string>> */
    private function parseStatusLegendTable(Worksheet $sheet, Table $table): array
    {
        $bounds = $this->tableBounds($table);
        $statuses = [];

        for ($row = $bounds['dataStart']; $row <= $bounds['dataEnd']; $row++) {
            $statusRaw = $this->cellValue($sheet, Coordinate::stringFromColumnIndex($bounds['startCol']), $row);
            $status = Assessment::normalizeStatus($statusRaw);
            if (!in_array($status, ['Pass', 'Gap', 'Risk', 'TBD', 'N/A'], true)) {
                continue;
            }

            $meaningCol = Coordinate::stringFromColumnIndex($bounds['startCol'] + 1);
            $actionCol = Coordinate::stringFromColumnIndex(min($bounds['startCol'] + 2, $bounds['endCol']));
            $statuses[] = [
                'status' => $status,
                'meaning' => $this->cellValue($sheet, $meaningCol, $row),
                'action' => $this->cellValue($sheet, $actionCol, $row),
            ];
        }

        return $statuses;
    }

    /** @return list<array<string, string>> */
    private function parseRiskLegendTable(Worksheet $sheet, Table $table): array
    {
        $bounds = $this->tableBounds($table);
        $riskLevels = [];

        for ($row = $bounds['dataStart']; $row <= $bounds['dataEnd']; $row++) {
            $riskRaw = $this->cellValue($sheet, Coordinate::stringFromColumnIndex($bounds['startCol']), $row);
            $risk = Assessment::normalizeRiskLevel($riskRaw);
            if (!in_array($risk, ['Low', 'Med', 'High'], true)) {
                continue;
            }

            $useCol = Coordinate::stringFromColumnIndex(min($bounds['startCol'] + 1, $bounds['endCol']));
            $riskLevels[] = [
                'risk_level' => $risk,
                'use_when' => $this->cellValue($sheet, $useCol, $row),
            ];
        }

        return $riskLevels;
    }

    /** @return list<string> */
    private function parseEvidenceChecklistTable(Worksheet $sheet, Table $table): array
    {
        $bounds = $this->tableBounds($table);
        $requirementCol = null;

        for ($colIndex = $bounds['startCol']; $colIndex <= $bounds['endCol']; $colIndex++) {
            $column = Coordinate::stringFromColumnIndex($colIndex);
            $normalized = $this->normalizeKey($this->cellValue($sheet, $column, $bounds['headerRow']));
            if (
                str_contains($normalized, 'evidencerequirement')
                || str_contains($normalized, 'requirement')
                || $normalized === 'evidenceitem' && $requirementCol === null
            ) {
                if (str_contains($normalized, 'requirement') || str_contains($normalized, 'evidencerequirement')) {
                    $requirementCol = $column;
                    break;
                }
            }
        }

        // Prefer the second column when headers are Evidence item | Evidence requirement.
        if ($requirementCol === null && $bounds['endCol'] > $bounds['startCol']) {
            $requirementCol = Coordinate::stringFromColumnIndex($bounds['startCol'] + 1);
        } elseif ($requirementCol === null) {
            $requirementCol = Coordinate::stringFromColumnIndex($bounds['startCol']);
        }

        $checklist = [];
        for ($row = $bounds['dataStart']; $row <= $bounds['dataEnd']; $row++) {
            $value = $this->cellValue($sheet, $requirementCol, $row);
            if ($value === '') {
                continue;
            }
            $normalized = $this->normalizeKey($value);
            if ($normalized === 'evidencerequirement' || $normalized === 'evidenceitem') {
                continue;
            }
            $checklist[] = $value;
        }

        return $checklist;
    }

    /** @return array{statuses: list<array<string, string>>, risk_levels: list<array<string, string>>, checklist: list<string>} */
    private function parseScoringLegendLegacy(Worksheet $sheet): array
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
                $normalizedB = $this->normalizeKey($b);
                if ($normalizedB === 'evidencerequirement' || $normalizedB === 'evidenceitem') {
                    continue;
                }
                $checklist[] = $b;
            }
        }

        return [
            'statuses' => $statuses,
            'risk_levels' => $riskLevels,
            'checklist' => $checklist,
        ];
    }

    private function findSheetWithTable(Spreadsheet $spreadsheet, string $tableName): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if ($this->findTableOnSheet($sheet, $tableName) !== null) {
                return $sheet;
            }
        }

        return null;
    }

    private function findTableOnSheet(Worksheet $sheet, string $tableName): ?Table
    {
        foreach ($sheet->getTableCollection() as $table) {
            if (strcasecmp($table->getName(), $tableName) === 0) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array{headerRow: int, dataStart: int, dataEnd: int, startCol: int, endCol: int}
     */
    private function tableBounds(Table $table): array
    {
        $boundaries = Coordinate::rangeBoundaries($table->getRange());
        $startCol = (int) $boundaries[0][0];
        $headerRow = (int) $boundaries[0][1];
        $endCol = (int) $boundaries[1][0];
        $endRow = (int) $boundaries[1][1];

        return [
            'headerRow' => $headerRow,
            'dataStart' => $headerRow + 1,
            'dataEnd' => $endRow,
            'startCol' => $startCol,
            'endCol' => $endCol,
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
