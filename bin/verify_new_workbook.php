<?php

declare(strict_types=1);

require dirname(__DIR__) . '/public/bootstrap.php';

use RiskAssessment\DashboardRenderer;
use RiskAssessment\ExcelParser;
use RiskAssessment\Repositories\AssessmentRepository;

$path = dirname(__DIR__) . '/templates/Architecture_Risk_Assessment_Blank_Table_Template.xlsx';
if (!is_readable($path)) {
    fwrite(STDERR, "Workbook missing: $path\n");
    exit(1);
}

$parser = new ExcelParser();
$assessment = $parser->parse($path);

$arch = count($assessment->items);
$dd = count($assessment->dueDiligenceItems);
$fields = count($assessment->workbook['fields']);
$findings = count($assessment->workbook['findings']);
$legendStatuses = count($assessment->workbook['legend']['statuses']);
$legendRisk = count($assessment->workbook['legend']['risk_levels']);
$legendChecklist = count($assessment->workbook['legend']['checklist']);

echo 'arch=' . $arch . PHP_EOL;
echo 'dd=' . $dd . PHP_EOL;
echo 'fields=' . $fields . PHP_EOL;
echo 'findings=' . $findings . PHP_EOL;
echo 'legend_statuses=' . $legendStatuses . PHP_EOL;
echo 'legend_risk=' . $legendRisk . PHP_EOL;
echo 'legend_checklist=' . $legendChecklist . PHP_EOL;
echo 'arch_status=' . json_encode($assessment->summary['by_status']) . PHP_EOL;
echo 'dd_status=' . json_encode($assessment->summary['due_diligence']['by_status']) . PHP_EOL;
echo 'first_arch_status=' . ($assessment->items[0]['status'] ?? '') . PHP_EOL;
echo 'first_arch_risk=[' . ($assessment->items[0]['risk_level'] ?? '') . ']' . PHP_EOL;
echo 'checklist0=' . ($assessment->workbook['legend']['checklist'][0] ?? '') . PHP_EOL;

$errors = [];
if ($arch !== 32) {
    $errors[] = "expected 32 architecture rows, got {$arch}";
}
if ($dd !== 26) {
    $errors[] = "expected 26 due diligence rows, got {$dd}";
}
if ($legendStatuses !== 5) {
    $errors[] = "expected 5 legend statuses, got {$legendStatuses}";
}
if ($legendRisk !== 3) {
    $errors[] = "expected 3 legend risk levels, got {$legendRisk}";
}
if ($legendChecklist !== 7) {
    $errors[] = "expected 7 checklist items, got {$legendChecklist}";
}
if (($assessment->items[0]['status'] ?? '') !== 'TBD') {
    $errors[] = 'blank architecture status should normalize to TBD';
}
if (($assessment->items[0]['risk_level'] ?? '') !== '') {
    $errors[] = 'blank architecture risk should stay empty';
}
if (($assessment->workbook['legend']['checklist'][0] ?? '') === 'Evidence requirement') {
    $errors[] = 'checklist should not include the Evidence requirement header';
}
if (($assessment->summary['by_status']['Other'] ?? 0) !== 0) {
    $errors[] = 'blank template should not flood Other status counts';
}
if (($assessment->summary['by_risk']['Other'] ?? 0) !== 0) {
    $errors[] = 'blank template should not flood Other risk counts';
}

$repo = new AssessmentRepository($pdo);
$id = $repo->save($assessment, $path, basename($path));
$loaded = $repo->findById($id);
if ($loaded === null) {
    $errors[] = 'Failed to reload assessment';
} else {
    $html = (new DashboardRenderer())->render($loaded['assessment'], $loaded['source_filename']);
    echo 'saved_id=' . $id . PHP_EOL;
    echo 'html_len=' . strlen($html) . PHP_EOL;
    echo 'has_dd_tab=' . (str_contains($html, 'data-tab="due-diligence"') ? 'yes' : 'no') . PHP_EOL;
    echo 'has_gov_tab=' . (str_contains($html, 'data-tab="governance"') ? 'yes' : 'no') . PHP_EOL;
    echo 'has_legend_tab=' . (str_contains($html, 'data-tab="legend"') ? 'yes' : 'no') . PHP_EOL;
    echo 'reloaded_dd=' . count($loaded['assessment']->dueDiligenceItems) . PHP_EOL;
    echo 'reloaded_findings=' . count($loaded['assessment']->workbook['findings']) . PHP_EOL;
}

if ($errors !== []) {
    fwrite(STDERR, "VERIFY FAILED:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "VERIFY OK\n";
