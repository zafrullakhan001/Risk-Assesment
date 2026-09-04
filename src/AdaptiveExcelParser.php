<?php

declare(strict_types=1);

namespace RiskAssessment;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RiskAssessment\Models\Assessment;

/**
 * Parser for the Adaptive Architecture Risk Assessment workbook
 * (classify → route → materialize material findings only).
 */
final class AdaptiveExcelParser
{
    private const TABLE_ROUTER = 'AdaptiveQuestionRouter';
    private const TABLE_RISKS = 'MaterialArchitectureRisks';
    private const TABLE_DD = 'AdaptiveDueDiligenceEvidence';
    private const TABLE_DECISIONS = 'AdaptiveArchitectureDecisions';
    private const TABLE_LIFECYCLE = 'AdaptiveTechnologyLifecycle';
    private const TABLE_EXCEPTIONS = 'AdaptivePolicyExceptions';

    /** @var list<string> */
    private const ADAPTIVE_TABLES = [
        self::TABLE_ROUTER,
        self::TABLE_RISKS,
        self::TABLE_DD,
        self::TABLE_DECISIONS,
        self::TABLE_LIFECYCLE,
        self::TABLE_EXCEPTIONS,
    ];

    public static function isAdaptiveWorkbook(Spreadsheet $spreadsheet): bool
    {
        foreach (self::ADAPTIVE_TABLES as $tableName) {
            if (self::findSheetWithTableStatic($spreadsheet, $tableName) !== null) {
                return true;
            }
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = strtolower($sheet->getTitle());
            if (str_contains($title, 'assessment summary')) {
                $a1 = strtolower(trim((string) $sheet->getCell('A1')->getCalculatedValue()));
                if (str_contains($a1, 'adaptive architecture')) {
                    return true;
                }
            }
            if (str_contains($title, 'question router') || str_contains($title, 'solution classification')) {
                return true;
            }
        }

        return false;
    }

    public function parse(string $filePath): Assessment
    {
        if (!is_readable($filePath)) {
            throw new \InvalidArgumentException('Unable to read the uploaded file.');
        }

        $spreadsheet = IOFactory::load($filePath);

        return $this->parseSpreadsheet($spreadsheet);
    }

    public function parseSpreadsheet(Spreadsheet $spreadsheet): Assessment
    {
        $summarySheet = $this->findSheetByTitleContains($spreadsheet, ['assessment summary']);
        $classificationSheet = $this->findSheetByTitleContains($spreadsheet, ['solution classification']);
        $routerSheet = $this->findSheetWithTable($spreadsheet, self::TABLE_ROUTER)
            ?? $this->findSheetByTitleContains($spreadsheet, ['question router']);
        $riskSheet = $this->findSheetWithTable($spreadsheet, self::TABLE_RISKS)
            ?? $this->findSheetByTitleContains($spreadsheet, ['architecture risk register']);
        $ddSheet = $this->findSheetWithTable($spreadsheet, self::TABLE_DD)
            ?? $this->findSheetByTitleContains($spreadsheet, ['due diligence evidence']);
        $decisionsSheet = $this->findSheetWithTable($spreadsheet, self::TABLE_DECISIONS)
            ?? $this->findSheetByTitleContains($spreadsheet, ['architecture decisions']);
        $lifecycleSheet = $this->findSheetWithTable($spreadsheet, self::TABLE_LIFECYCLE)
            ?? $this->findSheetByTitleContains($spreadsheet, ['technology lifecycle']);
        $exceptionsSheet = $this->findSheetWithTable($spreadsheet, self::TABLE_EXCEPTIONS)
            ?? $this->findSheetByTitleContains($spreadsheet, ['exception register']);
        $scoringSheet = $this->findSheetByTitleContains($spreadsheet, ['scoring & guidance', 'scoring and guidance']);

        $metadata = $this->parseMetadata($summarySheet, $riskSheet, $classificationSheet);
        $classification = $this->parseClassification($classificationSheet);
        $router = $this->parseRouter($routerSheet);
        $items = $this->parseMaterialRisks($riskSheet);
        $dueDiligenceItems = $this->parseDueDiligence($ddSheet);
        $decisions = $this->parseDecisions($decisionsSheet);
        $lifecycle = $this->parseLifecycle($lifecycleSheet);
        $exceptions = $this->parseExceptions($exceptionsSheet);
        $legend = $this->parseScoringLegend($scoringSheet);
        $fields = $this->buildSummaryFields($summarySheet, $riskSheet, $classificationSheet, $metadata, $classification);
        $findings = $this->exceptionsToFindings($exceptions);
        $kpis = $this->computeKpis($router, $items);

        if (($metadata['architecture_model'] ?? '') === '' && ($classification['primary_type'] ?? '') !== '') {
            $metadata['architecture_model'] = $classification['primary_type'];
        }

        $contextParts = [];
        if (($classification['primary_type'] ?? '') !== '') {
            $contextParts[] = 'Primary type: ' . $classification['primary_type'];
        }
        if (($classification['secondary_types'] ?? '') !== '') {
            $contextParts[] = 'Secondary: ' . $classification['secondary_types'];
        }
        if (($kpis['routed'] ?? 0) > 0) {
            $contextParts[] = 'Routed scenarios: ' . $kpis['routed'];
        }

        return Assessment::fromParsedData(
            $metadata,
            $items,
            $dueDiligenceItems,
            [
                'format' => 'adaptive',
                'context' => implode(' · ', $contextParts),
                'fields' => $fields,
                'findings' => $findings,
                'note' => '',
                'legend' => $legend,
                'classification' => $classification,
                'router' => $router,
                'decisions' => $decisions,
                'lifecycle' => $lifecycle,
                'exceptions' => $exceptions,
                'material_findings' => array_map(static function (array $item): array {
                    return [
                        'risk_id' => $item['risk_id'] ?? '',
                        'check' => $item['check'] ?? '',
                        'source_scenario_id' => $item['source_scenario_id'] ?? '',
                        'lens' => $item['lens'] ?? '',
                        'quality_attribute' => $item['quality_attribute'] ?? '',
                        'likelihood' => $item['likelihood'] ?? '',
                        'impact' => $item['impact'] ?? '',
                        'inherent_score' => $item['inherent_score'] ?? '',
                        'inherent_level' => $item['inherent_level'] ?? '',
                        'residual_likelihood' => $item['residual_likelihood'] ?? '',
                        'residual_impact' => $item['residual_impact'] ?? '',
                        'residual_score' => $item['residual_score'] ?? '',
                        'residual_level' => $item['residual_level'] ?? '',
                        'closure_evidence' => $item['closure_evidence'] ?? '',
                    ];
                }, $items),
                'kpis' => $kpis,
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseMetadata(?Worksheet $summary, ?Worksheet $risk, ?Worksheet $classification): array
    {
        $metadata = [
            'solution_name' => '',
            'vendor' => '',
            'scope' => '',
            'architecture_model' => '',
            'reviewer' => '',
            'date' => '',
        ];

        $labelMap = [
            'solutionname' => 'solution_name',
            'vendor' => 'vendor',
            'vendorproduct' => 'vendor',
            'reviewer' => 'reviewer',
            'reviewdate' => 'date',
            'date' => 'date',
            'facilitiesregion' => 'scope',
            'scopefacilities' => 'scope',
            'scope' => 'scope',
            'primaryarchitecturetype' => 'architecture_model',
            'architectureowner' => 'architecture_owner',
            'businessowner' => 'business_owner',
            'duediligenceid' => 'ddr_id',
            'technologyriskid' => 'vra_id',
            'businessunit' => 'business_unit',
            'assessmenttypetier' => 'assessment_tier',
            'targetgolive' => 'target_go_live',
            'hostingdeployment' => 'data_hosting',
            'dataclassification' => 'data_classification',
            'clinicalbusinesscriticality' => 'clinical_criticality',
            'overallriskrating' => 'overall_risk_rating',
            'decisiongate' => 'decision_gate',
            'overallrecommendation' => 'technology_recommendation',
            'assessmentstatus' => 'assessment_status',
            'classificationconfidence' => 'classification_confidence',
            'secondarytypes' => 'secondary_types',
        ];

        foreach ([$summary, $risk, $classification] as $sheet) {
            if ($sheet === null) {
                continue;
            }
            $scanned = $this->scanLabelValuePairs($sheet, 1, min(20, $sheet->getHighestRow()), 16);
            foreach ($scanned as $label => $value) {
                if ($value === '' || !isset($labelMap[$label])) {
                    continue;
                }
                $key = $labelMap[$label];
                if (($metadata[$key] ?? '') === '') {
                    $metadata[$key] = $value;
                }
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseClassification(?Worksheet $sheet): array
    {
        $result = [
            'primary_type' => '',
            'secondary_types' => '',
            'confidence' => '',
            'composite_pattern' => '',
            'in_scope_components' => '',
            'hosting_boundary' => '',
            'integration_patterns' => '',
            'data_types' => '',
            'clinical_impact' => '',
            'classification_rationale' => '',
            'selected_modules' => '',
            'excluded_modules' => '',
            'key_assumptions' => '',
            'required_sme_validation' => '',
            'signals' => [],
        ];

        if ($sheet === null) {
            return $result;
        }

        $pairs = $this->scanLabelValuePairs($sheet, 4, 12, 16);
        $fieldMap = [
            'primaryarchitecturetype' => 'primary_type',
            'secondaryarchitecturetypes' => 'secondary_types',
            'classificationconfidence' => 'confidence',
            'compositepatternwhymultiplelensesapply' => 'composite_pattern',
            'inscopecomponents' => 'in_scope_components',
            'hostingoperationalcontrolboundary' => 'hosting_boundary',
            'integrationpatterns' => 'integration_patterns',
            'datatypesclassification' => 'data_types',
            'clinicalpatientsafetyimpact' => 'clinical_impact',
            'classificationrationalewithsourcereferences' => 'classification_rationale',
            'selectedquestionmodules' => 'selected_modules',
            'excludedmodulesandrationale' => 'excluded_modules',
            'keyassumptions' => 'key_assumptions',
            'requiredsmevalidation' => 'required_sme_validation',
        ];
        foreach ($pairs as $label => $value) {
            if (isset($fieldMap[$label]) && $value !== '') {
                $result[$fieldMap[$label]] = $value;
            }
        }

        // Classification layout: labels in A/I, values in B/J on the same row.
        $grid = [
            5 => ['primary_type' => 'B', 'secondary_types' => 'J'],
            6 => ['confidence' => 'B', 'composite_pattern' => 'J'],
            7 => ['in_scope_components' => 'B', 'hosting_boundary' => 'J'],
            8 => ['integration_patterns' => 'B', 'data_types' => 'J'],
            9 => ['clinical_impact' => 'B', 'classification_rationale' => 'J'],
            10 => ['selected_modules' => 'B', 'excluded_modules' => 'J'],
            11 => ['key_assumptions' => 'B', 'required_sme_validation' => 'J'],
        ];
        foreach ($grid as $row => $fields) {
            foreach ($fields as $field => $column) {
                $value = $this->cellValue($sheet, $column, $row);
                if ($value !== '' && !$this->looksLikeFieldLabel($value)) {
                    $result[$field] = $value;
                }
            }
        }

        $signals = [];
        $headerRow = $this->findHeaderRow($sheet, ['signalid', 'detectionquestion'], 13, 20);
        if ($headerRow !== null) {
            $endCol = Coordinate::columnIndexFromString($sheet->getHighestColumn($headerRow));
            $map = $this->mapColumns($sheet, $headerRow, 1, $endCol, [
                'signalid' => 'signal_id',
                'detectionquestion' => 'question',
                'evidencesource' => 'evidence',
                'answer' => 'answer',
                'pointsto' => 'points_to',
                'whyitchangesthequestionset' => 'why',
                'confidence' => 'confidence',
                'notesassumptions' => 'notes',
            ]);
            for ($row = $headerRow + 1; $row <= $sheet->getHighestRow(); $row++) {
                $signalId = trim($this->mappedCell($sheet, $map, 'signal_id', $row));
                if ($signalId === '' || !str_starts_with(strtoupper($signalId), 'SIG-')) {
                    continue;
                }
                $signals[] = [
                    'signal_id' => $signalId,
                    'question' => $this->mappedCell($sheet, $map, 'question', $row),
                    'evidence' => $this->mappedCell($sheet, $map, 'evidence', $row),
                    'answer' => $this->mappedCell($sheet, $map, 'answer', $row),
                    'points_to' => $this->mappedCell($sheet, $map, 'points_to', $row),
                    'why' => $this->mappedCell($sheet, $map, 'why', $row),
                    'confidence' => $this->mappedCell($sheet, $map, 'confidence', $row),
                    'notes' => $this->mappedCell($sheet, $map, 'notes', $row),
                ];
            }
        }
        $result['signals'] = $signals;

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseRouter(?Worksheet $sheet): array
    {
        if ($sheet === null) {
            return [];
        }

        $table = $this->findTableOnSheet($sheet, self::TABLE_ROUTER);
        $bounds = $table !== null
            ? $this->tableBounds($table)
            : null;

        $headerRow = $bounds['headerRow'] ?? $this->findHeaderRow($sheet, ['scenarioid', 'module'], 1, 15);
        if ($headerRow === null) {
            return [];
        }

        $startCol = $bounds['startCol'] ?? 1;
        $endCol = $bounds['endCol'] ?? Coordinate::columnIndexFromString($sheet->getHighestColumn($headerRow));
        $dataStart = $bounds['dataStart'] ?? ($headerRow + 1);
        $dataEnd = $bounds['dataEnd'] ?? $sheet->getHighestRow();

        $map = $this->mapColumns($sheet, $headerRow, $startCol, $endCol, [
            'scenarioid' => 'scenario_id',
            'module' => 'module',
            'domain' => 'domain',
            'qualityattribute' => 'quality_attribute',
            'architecturescenariodecisiontest' => 'scenario',
            'crediblefailuremodeconsequence' => 'failure_mode',
            'inclusiontrigger' => 'inclusion_trigger',
            'excludewhen' => 'exclude_when',
            'expectedarchitectureevidence' => 'expected_evidence',
            'airoutingdecision' => 'routing_decision',
            'routingrationalesource' => 'routing_rationale',
            'assessmentdisposition' => 'disposition',
            'linkedoutputid' => 'linked_output_id',
        ]);

        $rows = [];
        for ($row = $dataStart; $row <= $dataEnd; $row++) {
            $id = trim($this->mappedCell($sheet, $map, 'scenario_id', $row));
            if ($id === '') {
                continue;
            }
            $rows[] = [
                'scenario_id' => $id,
                'module' => $this->mappedCell($sheet, $map, 'module', $row),
                'domain' => $this->mappedCell($sheet, $map, 'domain', $row),
                'quality_attribute' => $this->mappedCell($sheet, $map, 'quality_attribute', $row),
                'scenario' => $this->mappedCell($sheet, $map, 'scenario', $row),
                'failure_mode' => $this->mappedCell($sheet, $map, 'failure_mode', $row),
                'inclusion_trigger' => $this->mappedCell($sheet, $map, 'inclusion_trigger', $row),
                'exclude_when' => $this->mappedCell($sheet, $map, 'exclude_when', $row),
                'expected_evidence' => $this->mappedCell($sheet, $map, 'expected_evidence', $row),
                'routing_decision' => $this->mappedCell($sheet, $map, 'routing_decision', $row),
                'routing_rationale' => $this->mappedCell($sheet, $map, 'routing_rationale', $row),
                'disposition' => $this->mappedCell($sheet, $map, 'disposition', $row),
                'linked_output_id' => $this->mappedCell($sheet, $map, 'linked_output_id', $row),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseMaterialRisks(?Worksheet $sheet): array
    {
        if ($sheet === null) {
            return [];
        }

        $table = $this->findTableOnSheet($sheet, self::TABLE_RISKS);
        $bounds = $table !== null ? $this->tableBounds($table) : null;
        $headerRow = $bounds['headerRow'] ?? $this->findHeaderRow($sheet, ['riskid', 'sourcescenarioid'], 1, 20);
        if ($headerRow === null) {
            return [];
        }

        $startCol = $bounds['startCol'] ?? 1;
        $endCol = $bounds['endCol'] ?? Coordinate::columnIndexFromString($sheet->getHighestColumn($headerRow));
        $dataStart = $bounds['dataStart'] ?? ($headerRow + 1);
        $dataEnd = $bounds['dataEnd'] ?? $sheet->getHighestRow();

        $map = $this->mapColumns($sheet, $headerRow, $startCol, $endCol, [
            'riskid' => 'risk_id',
            'sourcescenarioid' => 'source_scenario_id',
            'selectedarchitecturelens' => 'lens',
            'architecturedomain' => 'domain',
            'qualityattribute' => 'quality_attribute',
            'whyapplicabletothisdesign' => 'why_applicable',
            'currentdesignevidence' => 'evidence',
            'assessment' => 'assessment',
            'riskdecisionstatement' => 'statement',
            'likelihood15' => 'likelihood',
            'impact15' => 'impact',
            'inherentscore' => 'inherent_score',
            'inherentlevel' => 'inherent_level',
            'treatmentdecisionneeded' => 'treatment',
            'accountableowner' => 'owner',
            'targetdate' => 'target_date',
            'residuallikelihood' => 'residual_likelihood',
            'residualimpact' => 'residual_impact',
            'residualscore' => 'residual_score',
            'residuallevel' => 'residual_level',
            'closureevidenceacceptancecriteria' => 'closure_evidence',
            'relatedddadrcmpexids' => 'related_ids',
        ]);

        $items = [];
        $sortOrder = 0;
        for ($row = $dataStart; $row <= $dataEnd; $row++) {
            $riskId = trim($this->mappedCell($sheet, $map, 'risk_id', $row));
            if ($riskId === '') {
                continue;
            }

            $domain = trim($this->mappedCell($sheet, $map, 'domain', $row));
            $lens = trim($this->mappedCell($sheet, $map, 'lens', $row));
            $section = $domain !== '' ? $domain : ($lens !== '' ? $lens : 'Material findings');
            $statement = trim($this->mappedCell($sheet, $map, 'statement', $row));
            $quality = trim($this->mappedCell($sheet, $map, 'quality_attribute', $row));
            $check = $riskId . ($statement !== '' ? ' — ' . $statement : ($quality !== '' ? ' — ' . $quality : ''));
            $sourceScenario = trim($this->mappedCell($sheet, $map, 'source_scenario_id', $row));
            $related = trim($this->mappedCell($sheet, $map, 'related_ids', $row));
            $sourceRef = $sourceScenario;
            if ($related !== '') {
                $sourceRef = trim($sourceRef . ($sourceRef !== '' ? ' · ' : '') . $related);
            }

            $why = trim($this->mappedCell($sheet, $map, 'why_applicable', $row));
            $reviewQuestion = $why !== '' ? $why : $quality;

            $items[] = [
                'item_type' => 'architecture',
                'section' => $section,
                'check' => $check,
                'status' => Assessment::normalizeStatus($this->mappedCell($sheet, $map, 'assessment', $row)),
                'risk_level' => Assessment::normalizeRiskLevel($this->mappedCell($sheet, $map, 'inherent_level', $row)),
                'notes' => $this->mappedCell($sheet, $map, 'evidence', $row),
                'mitigation' => $this->mappedCell($sheet, $map, 'treatment', $row),
                'owner' => $this->mappedCell($sheet, $map, 'owner', $row),
                'remediation_timeline' => $this->mappedCell($sheet, $map, 'target_date', $row),
                'review_question' => $reviewQuestion,
                'source_reference' => $sourceRef,
                'sort_order' => (string) $sortOrder++,
                'risk_id' => $riskId,
                'source_scenario_id' => $sourceScenario,
                'lens' => $lens,
                'quality_attribute' => $quality,
                'likelihood' => $this->mappedCell($sheet, $map, 'likelihood', $row),
                'impact' => $this->mappedCell($sheet, $map, 'impact', $row),
                'inherent_score' => $this->mappedCell($sheet, $map, 'inherent_score', $row),
                'inherent_level' => Assessment::normalizeRiskLevel($this->mappedCell($sheet, $map, 'inherent_level', $row)),
                'residual_likelihood' => $this->mappedCell($sheet, $map, 'residual_likelihood', $row),
                'residual_impact' => $this->mappedCell($sheet, $map, 'residual_impact', $row),
                'residual_score' => $this->mappedCell($sheet, $map, 'residual_score', $row),
                'residual_level' => Assessment::normalizeRiskLevel($this->mappedCell($sheet, $map, 'residual_level', $row)),
                'closure_evidence' => $this->mappedCell($sheet, $map, 'closure_evidence', $row),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseDueDiligence(?Worksheet $sheet): array
    {
        if ($sheet === null) {
            return [];
        }

        $table = $this->findTableOnSheet($sheet, self::TABLE_DD);
        $bounds = $table !== null ? $this->tableBounds($table) : null;
        $headerRow = $bounds['headerRow'] ?? $this->findHeaderRow($sheet, ['ddid', 'category'], 1, 15);
        if ($headerRow === null) {
            return [];
        }

        $startCol = $bounds['startCol'] ?? 1;
        $endCol = $bounds['endCol'] ?? Coordinate::columnIndexFromString($sheet->getHighestColumn($headerRow));
        $dataStart = $bounds['dataStart'] ?? ($headerRow + 1);
        $dataEnd = $bounds['dataEnd'] ?? $sheet->getHighestRow();

        $map = $this->mapColumns($sheet, $headerRow, $startCol, $endCol, [
            'ddid' => 'dd_id',
            'category' => 'category',
            'evidencerequestquestion' => 'question',
            'expectedevidence' => 'expected_evidence',
            'applicability' => 'applicability',
            'applicabilityrationale' => 'applicability_rationale',
            'responsefinding' => 'response',
            'evidencestatus' => 'evidence_status',
            'sourcereference' => 'source',
            'evidenceconfidence' => 'confidence',
            'policycontractimpact' => 'policy_impact',
            'relatedarchitectureriskids' => 'related_risks',
            'reviewer' => 'reviewer',
            'reviewdate' => 'review_date',
        ]);

        $items = [];
        $sortOrder = 0;
        for ($row = $dataStart; $row <= $dataEnd; $row++) {
            $ddId = trim($this->mappedCell($sheet, $map, 'dd_id', $row));
            $question = trim($this->mappedCell($sheet, $map, 'question', $row));
            if ($ddId === '' && $question === '') {
                continue;
            }
            if ($ddId === '') {
                continue;
            }

            $check = $ddId . ($question !== '' ? ' — ' . $question : '');
            $items[] = [
                'item_type' => 'due_diligence',
                'section' => $this->mappedCell($sheet, $map, 'category', $row) ?: 'Due diligence',
                'check' => $check,
                'status' => Assessment::normalizeStatus($this->mappedCell($sheet, $map, 'evidence_status', $row)),
                'risk_level' => '',
                'notes' => $this->mappedCell($sheet, $map, 'response', $row),
                'mitigation' => $this->mappedCell($sheet, $map, 'policy_impact', $row),
                'owner' => $this->mappedCell($sheet, $map, 'reviewer', $row),
                'remediation_timeline' => $this->mappedCell($sheet, $map, 'review_date', $row),
                'review_question' => $this->mappedCell($sheet, $map, 'expected_evidence', $row),
                'source_reference' => $this->mappedCell($sheet, $map, 'source', $row),
                'sort_order' => (string) $sortOrder++,
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseDecisions(?Worksheet $sheet): array
    {
        if ($sheet === null) {
            return [];
        }

        $table = $this->findTableOnSheet($sheet, self::TABLE_DECISIONS);
        $bounds = $table !== null ? $this->tableBounds($table) : null;
        $headerRow = $bounds['headerRow'] ?? $this->findHeaderRow($sheet, ['adrid'], 1, 10);
        if ($headerRow === null) {
            return [];
        }

        $startCol = $bounds['startCol'] ?? 1;
        $endCol = $bounds['endCol'] ?? Coordinate::columnIndexFromString($sheet->getHighestColumn($headerRow));
        $dataStart = $bounds['dataStart'] ?? ($headerRow + 1);
        $dataEnd = $bounds['dataEnd'] ?? $sheet->getHighestRow();

        $map = $this->mapColumns($sheet, $headerRow, $startCol, $endCol, [
            'adrid' => 'adr_id',
            'sourcescenarioid' => 'source_scenario_id',
            'architecturelens' => 'lens',
            'decisionquestion' => 'decision',
            'status' => 'status',
            'contextdrivers' => 'context',
            'optionsconsidered' => 'options',
            'chosendirection' => 'chosen',
            'rationale' => 'rationale',
            'consequencestradeoffs' => 'consequences',
            'decisionowner' => 'owner',
            'approver' => 'approver',
            'decisiondate' => 'decision_date',
            'relatedriskddcmpexids' => 'related_ids',
        ]);

        $rows = [];
        for ($row = $dataStart; $row <= $dataEnd; $row++) {
            $id = trim($this->mappedCell($sheet, $map, 'adr_id', $row));
            if ($id === '') {
                continue;
            }
            $status = trim($this->mappedCell($sheet, $map, 'status', $row));
            $decision = trim($this->mappedCell($sheet, $map, 'decision', $row));
            $chosen = trim($this->mappedCell($sheet, $map, 'chosen', $row));
            // Skip blank-template placeholders (ID + default "Not Started" only).
            if ($decision === '' && $chosen === '' && ($status === '' || strcasecmp($status, 'Not Started') === 0)) {
                continue;
            }
            $rows[] = [
                'adr_id' => $id,
                'source_scenario_id' => $this->mappedCell($sheet, $map, 'source_scenario_id', $row),
                'lens' => $this->mappedCell($sheet, $map, 'lens', $row),
                'decision' => $decision,
                'status' => $status,
                'context' => $this->mappedCell($sheet, $map, 'context', $row),
                'options' => $this->mappedCell($sheet, $map, 'options', $row),
                'chosen' => $chosen,
                'rationale' => $this->mappedCell($sheet, $map, 'rationale', $row),
                'consequences' => $this->mappedCell($sheet, $map, 'consequences', $row),
                'owner' => $this->mappedCell($sheet, $map, 'owner', $row),
                'approver' => $this->mappedCell($sheet, $map, 'approver', $row),
                'decision_date' => $this->mappedCell($sheet, $map, 'decision_date', $row),
                'related_ids' => $this->mappedCell($sheet, $map, 'related_ids', $row),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseLifecycle(?Worksheet $sheet): array
    {
        if ($sheet === null) {
            return [];
        }

        $table = $this->findTableOnSheet($sheet, self::TABLE_LIFECYCLE);
        $bounds = $table !== null ? $this->tableBounds($table) : null;
        $headerRow = $bounds['headerRow'] ?? $this->findHeaderRow($sheet, ['componentid'], 1, 10);
        if ($headerRow === null) {
            return [];
        }

        $startCol = $bounds['startCol'] ?? 1;
        $endCol = $bounds['endCol'] ?? Coordinate::columnIndexFromString($sheet->getHighestColumn($headerRow));
        $dataStart = $bounds['dataStart'] ?? ($headerRow + 1);
        $dataEnd = $bounds['dataEnd'] ?? $sheet->getHighestRow();

        $map = $this->mapColumns($sheet, $headerRow, $startCol, $endCol, [
            'componentid' => 'component_id',
            'architecturelens' => 'lens',
            'componentdependency' => 'component',
            'architecturalrole' => 'role',
            'hostinglocation' => 'hosting',
            'technicalowner' => 'owner',
            'currentversion' => 'current_version',
            'vendorsupportedrange' => 'vendor_supported',
            'enterprisebaseline' => 'baseline',
            'endofsupport' => 'end_of_support',
            'patchowner' => 'patch_owner',
            'patchcadence' => 'patch_cadence',
            'upgradevalidationmethod' => 'upgrade_method',
            'compatibilitycouplingconstraint' => 'compatibility',
            'currencyassessment' => 'currency',
            'relatedriskids' => 'related_risks',
            'sourceevidence' => 'evidence',
        ]);

        $rows = [];
        for ($row = $dataStart; $row <= $dataEnd; $row++) {
            $id = trim($this->mappedCell($sheet, $map, 'component_id', $row));
            if ($id === '') {
                continue;
            }
            $component = trim($this->mappedCell($sheet, $map, 'component', $row));
            $currency = trim($this->mappedCell($sheet, $map, 'currency', $row));
            // Skip placeholders that only have an ID and default Unknown currency.
            if ($component === '' && ($currency === '' || strcasecmp($currency, 'Unknown') === 0)) {
                $otherFilled = false;
                foreach (['lens', 'role', 'hosting', 'owner', 'current_version'] as $field) {
                    if (trim($this->mappedCell($sheet, $map, $field, $row)) !== '') {
                        $otherFilled = true;
                        break;
                    }
                }
                if (!$otherFilled) {
                    continue;
                }
            }
            $rows[] = [
                'component_id' => $id,
                'lens' => $this->mappedCell($sheet, $map, 'lens', $row),
                'component' => $component,
                'role' => $this->mappedCell($sheet, $map, 'role', $row),
                'hosting' => $this->mappedCell($sheet, $map, 'hosting', $row),
                'owner' => $this->mappedCell($sheet, $map, 'owner', $row),
                'current_version' => $this->mappedCell($sheet, $map, 'current_version', $row),
                'vendor_supported' => $this->mappedCell($sheet, $map, 'vendor_supported', $row),
                'baseline' => $this->mappedCell($sheet, $map, 'baseline', $row),
                'end_of_support' => $this->mappedCell($sheet, $map, 'end_of_support', $row),
                'currency' => $currency,
                'related_risks' => $this->mappedCell($sheet, $map, 'related_risks', $row),
                'evidence' => $this->mappedCell($sheet, $map, 'evidence', $row),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseExceptions(?Worksheet $sheet): array
    {
        if ($sheet === null) {
            return [];
        }

        $table = $this->findTableOnSheet($sheet, self::TABLE_EXCEPTIONS);
        $bounds = $table !== null ? $this->tableBounds($table) : null;
        $headerRow = $bounds['headerRow'] ?? $this->findHeaderRow($sheet, ['exceptionid'], 1, 10);
        if ($headerRow === null) {
            return [];
        }

        $startCol = $bounds['startCol'] ?? 1;
        $endCol = $bounds['endCol'] ?? Coordinate::columnIndexFromString($sheet->getHighestColumn($headerRow));
        $dataStart = $bounds['dataStart'] ?? ($headerRow + 1);
        $dataEnd = $bounds['dataEnd'] ?? $sheet->getHighestRow();

        $map = $this->mapColumns($sheet, $headerRow, $startCol, $endCol, [
            'exceptionid' => 'exception_id',
            'sourcescenarioid' => 'source_scenario_id',
            'architecturelens' => 'lens',
            'policycontrolid' => 'policy_id',
            'unmetrequirementdeviation' => 'deviation',
            'impact' => 'impact',
            'compensatingcontrols' => 'compensating',
            'exceptionowner' => 'owner',
            'businessowner' => 'business_owner',
            'approvalauthority' => 'approval_authority',
            'approvalstatus' => 'approval_status',
            'requesteddate' => 'requested_date',
            'approvaldate' => 'approval_date',
            'reviewexpirationdate' => 'expiration_date',
            'residualrisk' => 'residual_risk',
            'dependencies' => 'dependencies',
            'closurecriteria' => 'closure_criteria',
            'relatedriskddids' => 'related_ids',
            'reviewhealth' => 'review_health',
        ]);

        $rows = [];
        for ($row = $dataStart; $row <= $dataEnd; $row++) {
            $id = trim($this->mappedCell($sheet, $map, 'exception_id', $row));
            if ($id === '') {
                continue;
            }
            $deviation = trim($this->mappedCell($sheet, $map, 'deviation', $row));
            $policy = trim($this->mappedCell($sheet, $map, 'policy_id', $row));
            $status = trim($this->mappedCell($sheet, $map, 'approval_status', $row));
            // Skip blank placeholders (ID only).
            if ($deviation === '' && $policy === '' && $status === '') {
                $otherFilled = false;
                foreach (['lens', 'impact', 'owner', 'compensating'] as $field) {
                    if (trim($this->mappedCell($sheet, $map, $field, $row)) !== '') {
                        $otherFilled = true;
                        break;
                    }
                }
                if (!$otherFilled) {
                    continue;
                }
            }
            $rows[] = [
                'exception_id' => $id,
                'source_scenario_id' => $this->mappedCell($sheet, $map, 'source_scenario_id', $row),
                'lens' => $this->mappedCell($sheet, $map, 'lens', $row),
                'policy_id' => $policy,
                'deviation' => $deviation,
                'impact' => $this->mappedCell($sheet, $map, 'impact', $row),
                'compensating' => $this->mappedCell($sheet, $map, 'compensating', $row),
                'owner' => $this->mappedCell($sheet, $map, 'owner', $row),
                'business_owner' => $this->mappedCell($sheet, $map, 'business_owner', $row),
                'approval_status' => $status,
                'expiration_date' => $this->mappedCell($sheet, $map, 'expiration_date', $row),
                'residual_risk' => $this->mappedCell($sheet, $map, 'residual_risk', $row),
                'related_ids' => $this->mappedCell($sheet, $map, 'related_ids', $row),
                'review_health' => $this->mappedCell($sheet, $map, 'review_health', $row),
            ];
        }

        return $rows;
    }

    /**
     * @return array{statuses: list<array<string, string>>, risk_levels: list<array<string, string>>, checklist: list<string>, routing?: list<array<string, string>>, finding_types?: list<string>}
     */
    private function parseScoringLegend(?Worksheet $sheet): array
    {
        $legend = [
            'statuses' => [],
            'risk_levels' => [],
            'checklist' => [],
            'routing' => [],
            'finding_types' => [],
        ];

        if ($sheet === null) {
            return $legend;
        }

        // Risk bands: H4:J7 Risk Level | Minimum Score | Interpretation
        for ($row = 4; $row <= 7; $row++) {
            $level = $this->cellValue($sheet, 'H', $row);
            $score = $this->cellValue($sheet, 'I', $row);
            $meaning = $this->cellValue($sheet, 'J', $row);
            if ($level === '' || strcasecmp($level, 'Risk Level') === 0) {
                continue;
            }
            $normalized = Assessment::normalizeRiskLevel($level);
            $legend['risk_levels'][] = [
                'risk_level' => $normalized !== '' ? $normalized : $level,
                'use_when' => trim(($score !== '' ? 'Min score ' . $score . '. ' : '') . $meaning),
            ];
        }

        // Routing decisions A4:F9
        for ($row = 4; $row <= 9; $row++) {
            $decision = $this->cellValue($sheet, 'A', $row);
            $useWhen = $this->cellValue($sheet, 'B', $row);
            $meaning = $this->cellValue($sheet, 'C', $row);
            if ($decision === '' || strcasecmp($decision, 'Decision') === 0) {
                continue;
            }
            $legend['routing'][] = [
                'decision' => $decision,
                'use_when' => $useWhen,
                'meaning' => $meaning,
                'risk_register_row' => $this->cellValue($sheet, 'D', $row),
            ];
            $legend['statuses'][] = [
                'status' => $decision,
                'meaning' => $meaning,
                'action' => $useWhen,
            ];
        }

        // Material finding types L11:L15
        for ($row = 11; $row <= 15; $row++) {
            $type = $this->cellValue($sheet, 'L', $row);
            if ($type !== '' && strcasecmp($type, 'Material Finding Type') !== 0) {
                $legend['finding_types'][] = $type;
            }
        }

        $legend['checklist'] = [
            'Classify architecture type before selecting scenarios',
            'Route using R/C/— matrix; document inclusion/exclusion rationale',
            'Create Risk Register rows only for material Gap, Risk, or Decision Required',
            'Score inherent risk before treatment; leave residual blank until verified',
            'Use ADR for trade-offs and Exception for policy deviations',
        ];

        return $legend;
    }

    /**
     * @param list<array<string, string>> $router
     * @param list<array<string, string>> $items
     * @return array<string, int|string>
     */
    private function computeKpis(array $router, array $items): array
    {
        $selected = 0;
        $conditional = 0;
        $excluded = 0;
        $unrouted = 0;
        foreach ($router as $row) {
            $decision = strtolower(trim($row['routing_decision'] ?? ''));
            if ($decision === 'selected') {
                $selected++;
            } elseif (str_contains($decision, 'conditional')) {
                $conditional++;
            } elseif ($decision === 'excluded' || $decision === '—') {
                $excluded++;
            } else {
                $unrouted++;
            }
        }

        $highCritical = 0;
        foreach ($items as $item) {
            $level = Assessment::normalizeRiskLevel($item['risk_level'] ?? $item['inherent_level'] ?? '');
            if (in_array($level, ['High', 'Critical'], true)) {
                $highCritical++;
            }
        }

        return [
            'routed' => $selected + $conditional,
            'selected' => $selected,
            'conditional' => $conditional,
            'excluded' => $excluded,
            'unrouted' => $unrouted,
            'material_findings' => count($items),
            'high_critical' => $highCritical,
        ];
    }

    /**
     * @param array<string, string> $metadata
     * @param array<string, mixed> $classification
     * @return list<array{label: string, value: string, use: string}>
     */
    private function buildSummaryFields(
        ?Worksheet $summary,
        ?Worksheet $risk,
        ?Worksheet $classification,
        array $metadata,
        array $classificationData
    ): array {
        $wanted = [
            'Due Diligence ID' => $metadata['ddr_id'] ?? '',
            'Technology Risk ID' => $metadata['vra_id'] ?? '',
            'Solution Name' => $metadata['solution_name'] ?? '',
            'Vendor / Product' => $metadata['vendor'] ?? '',
            'Business Unit' => $metadata['business_unit'] ?? '',
            'Facilities / Region' => $metadata['scope'] ?? '',
            'Assessment Type / Tier' => $metadata['assessment_tier'] ?? '',
            'Hosting / Deployment' => $metadata['data_hosting'] ?? '',
            'Data Classification' => $metadata['data_classification'] ?? '',
            'Clinical / Business Criticality' => $metadata['clinical_criticality'] ?? '',
            'Overall Risk Rating' => $metadata['overall_risk_rating'] ?? '',
            'Decision Gate' => $metadata['decision_gate'] ?? '',
            'Overall Recommendation' => $metadata['technology_recommendation'] ?? '',
            'Primary Architecture Type' => (string) ($classificationData['primary_type'] ?? $metadata['architecture_model'] ?? ''),
            'Secondary Types' => (string) ($classificationData['secondary_types'] ?? $metadata['secondary_types'] ?? ''),
            'Classification Confidence' => (string) ($classificationData['confidence'] ?? $metadata['classification_confidence'] ?? ''),
            'Target Go-Live' => $metadata['target_go_live'] ?? '',
        ];

        // Prefer live sheet values when present.
        foreach ([$summary, $risk, $classification] as $sheet) {
            if ($sheet === null) {
                continue;
            }
            $pairs = $this->scanLabelValuePairs($sheet, 1, min(20, $sheet->getHighestRow()), 16);
            foreach ($wanted as $label => $current) {
                $key = $this->normalizeKey($label);
                if (($pairs[$key] ?? '') !== '' && $current === '') {
                    $wanted[$label] = $pairs[$key];
                }
            }
        }

        $fields = [];
        foreach ($wanted as $label => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $fields[] = [
                'label' => $label,
                'value' => (string) $value,
                'use' => 'adaptive',
            ];
        }

        return $fields;
    }

    /**
     * @param list<array<string, string>> $exceptions
     * @return list<array<string, string>>
     */
    private function exceptionsToFindings(array $exceptions): array
    {
        $findings = [];
        foreach ($exceptions as $ex) {
            $findings[] = [
                'id' => $ex['exception_id'] ?? '',
                'finding' => $ex['deviation'] ?? '',
                'policy_reference' => $ex['policy_id'] ?? '',
                'impact' => $ex['impact'] ?? '',
                'mitigation' => $ex['compensating'] ?? '',
                'owner' => $ex['owner'] ?? '',
                'timeline' => $ex['expiration_date'] ?? '',
                'status' => $ex['approval_status'] ?? 'Open',
            ];
        }

        return $findings;
    }

    private function cellBelowOrAdjacent(Worksheet $sheet, string $labelCol, int $labelRow): string
    {
        $labelIndex = Coordinate::columnIndexFromString($labelCol);
        $right = Coordinate::stringFromColumnIndex($labelIndex + 1);
        $value = $this->cellValue($sheet, $right, $labelRow);
        if ($value !== '') {
            return $value;
        }

        return $this->cellValue($sheet, $labelCol, $labelRow + 1);
    }

    /**
     * Scan for label cells and read the immediate right-hand value cell only.
     * Adaptive context grids place labels in A/E/I/M (or A/I) and values in the next column.
     *
     * @return array<string, string> normalized label => value
     */
    private function scanLabelValuePairs(Worksheet $sheet, int $startRow, int $endRow, int $maxCol): array
    {
        $pairs = [];
        for ($row = $startRow; $row <= $endRow; $row++) {
            for ($col = 1; $col <= $maxCol; $col++) {
                $colLetter = Coordinate::stringFromColumnIndex($col);
                $label = $this->cellValue($sheet, $colLetter, $row);
                if ($label === '' || strlen($label) > 90) {
                    continue;
                }
                $normalized = $this->normalizeKey($label);
                if ($normalized === '' || strlen($normalized) < 3 || !$this->looksLikeFieldLabel($label)) {
                    continue;
                }

                $candidate = $this->cellValue($sheet, Coordinate::stringFromColumnIndex($col + 1), $row);
                if ($candidate === '' || $this->normalizeKey($candidate) === $normalized) {
                    continue;
                }
                if ($this->looksLikeFieldLabel($candidate)) {
                    continue;
                }
                if (!isset($pairs[$normalized])) {
                    $pairs[$normalized] = $candidate;
                }
            }
        }

        return $pairs;
    }

    private function looksLikeFieldLabel(string $value): bool
    {
        $normalized = $this->normalizeKey($value);
        if ($normalized === '') {
            return false;
        }

        static $known = null;
        if ($known === null) {
            $known = [
                'duediligenceid', 'technologyriskid', 'solutionname', 'vendorproduct', 'vendor',
                'facilitiesregion', 'businessunit', 'assessmenttypetier', 'targetgolive',
                'architectureowner', 'businessowner', 'reviewer', 'reviewdate',
                'hostingdeployment', 'dataclassification', 'clinicalbusinesscriticality',
                'overallriskrating', 'primaryarchitecturetype', 'secondarytypes',
                'secondaryarchitecturetypes', 'classificationconfidence', 'decisiongate',
                'overallrecommendation', 'conditionsunresolveditems', 'evidenceset',
                'openexceptions', 'scopefacilities', 'assessmentmode', 'assessmentstatus',
                'compositepatternwhymultiplelensesapply', 'inscopecomponents',
                'hostingoperationalcontrolboundary', 'integrationpatterns',
                'datatypesclassification', 'clinicalpatientsafetyimpact',
                'classificationrationalewithsourcereferences', 'selectedquestionmodules',
                'excludedmodulesandrationale', 'keyassumptions', 'requiredsmevalidation',
                'detectedarchitecturetype', 'routedscenarios', 'materialfindings',
                'highcritical', 'signalid', 'detectionquestion',
            ];
            $known = array_fill_keys($known, true);
        }

        return isset($known[$normalized]);
    }

    /**
     * @param array<string, string> $aliases normalized header => field
     * @return array<string, string> field => column letter
     */
    private function mapColumns(Worksheet $sheet, int $headerRow, int $startCol, int $endCol, array $aliases): array
    {
        $map = [];
        for ($col = $startCol; $col <= $endCol; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $header = $this->normalizeKey($this->cellValue($sheet, $letter, $headerRow));
            if ($header === '') {
                continue;
            }
            foreach ($aliases as $alias => $field) {
                if ($header === $alias || str_starts_with($header, $alias) || str_contains($header, $alias)) {
                    if (!isset($map[$field])) {
                        $map[$field] = $letter;
                    }
                    break;
                }
            }
        }

        return $map;
    }

    /** @param array<string, string> $map */
    private function mappedCell(Worksheet $sheet, array $map, string $field, int $row): string
    {
        if (!isset($map[$field])) {
            return '';
        }

        return $this->cellValue($sheet, $map[$field], $row);
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

    private function findSheetWithTable(Spreadsheet $spreadsheet, string $tableName): ?Worksheet
    {
        return self::findSheetWithTableStatic($spreadsheet, $tableName);
    }

    private static function findSheetWithTableStatic(Spreadsheet $spreadsheet, string $tableName): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getTableCollection() as $table) {
                if (strcasecmp($table->getName(), $tableName) === 0) {
                    return $sheet;
                }
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
            $endCol = Coordinate::columnIndexFromString($sheet->getHighestColumn($row));
            for ($col = 1; $col <= $endCol; $col++) {
                $value = $this->normalizeKey($this->cellValue($sheet, Coordinate::stringFromColumnIndex($col), $row));
                if ($value !== '') {
                    $headers[] = $value;
                }
            }

            $matched = 0;
            foreach ($requiredNormalizedHeaders as $required) {
                foreach ($headers as $header) {
                    if ($header === $required || str_starts_with($header, $required) || str_contains($header, $required)) {
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
        try {
            $value = $sheet->getCell($column . $row)->getCalculatedValue();
        } catch (\Throwable) {
            $value = $sheet->getCell($column . $row)->getValue();
        }

        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value;
    }
}
