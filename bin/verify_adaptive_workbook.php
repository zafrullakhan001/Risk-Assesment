<?php

declare(strict_types=1);

require dirname(__DIR__) . '/public/bootstrap.php';

use RiskAssessment\DashboardRenderer;
use RiskAssessment\ExcelParser;
use RiskAssessment\Repositories\AssessmentRepository;

$path = dirname(__DIR__) . '/templates/Adaptive_Architecture_Risk_Assessment_Blank_Template.xlsx';
if (!is_readable($path)) {
    fwrite(STDERR, "Workbook missing: $path\n");
    exit(1);
}

$parser = new ExcelParser();
$assessment = $parser->parse($path);

$errors = [];

$format = (string) ($assessment->workbook['format'] ?? '');
if ($format !== 'adaptive') {
    $errors[] = "expected format=adaptive, got {$format}";
}

$arch = count($assessment->items);
$dd = count($assessment->dueDiligenceItems);
$router = count($assessment->workbook['router'] ?? []);
$signals = count($assessment->workbook['classification']['signals'] ?? []);
$decisions = count($assessment->workbook['decisions'] ?? []);
$lifecycle = count($assessment->workbook['lifecycle'] ?? []);
$exceptions = count($assessment->workbook['exceptions'] ?? []);
$legendRisk = count($assessment->workbook['legend']['risk_levels'] ?? []);
$legendRouting = count($assessment->workbook['legend']['routing'] ?? []);

echo 'format=' . $format . PHP_EOL;
echo 'arch=' . $arch . PHP_EOL;
echo 'dd=' . $dd . PHP_EOL;
echo 'router=' . $router . PHP_EOL;
echo 'signals=' . $signals . PHP_EOL;
echo 'decisions=' . $decisions . PHP_EOL;
echo 'lifecycle=' . $lifecycle . PHP_EOL;
echo 'exceptions=' . $exceptions . PHP_EOL;
echo 'legend_risk=' . $legendRisk . PHP_EOL;
echo 'legend_routing=' . $legendRouting . PHP_EOL;
echo 'kpis=' . json_encode($assessment->workbook['kpis'] ?? []) . PHP_EOL;
echo 'dd_status=' . json_encode($assessment->summary['due_diligence']['by_status'] ?? []) . PHP_EOL;
echo 'first_dd_status=' . ($assessment->dueDiligenceItems[0]['status'] ?? '') . PHP_EOL;

if ($arch !== 0) {
    $errors[] = "blank adaptive template should have 0 material findings, got {$arch}";
}
if ($dd !== 35) {
    $errors[] = "expected 35 due diligence rows, got {$dd}";
}
if ($router !== 85) {
    $errors[] = "expected 85 router scenarios, got {$router}";
}
if ($signals !== 16) {
    $errors[] = "expected 16 classification signals, got {$signals}";
}
if ($decisions !== 0) {
    $errors[] = "placeholder ADRs should be omitted, got {$decisions}";
}
if ($lifecycle !== 0) {
    $errors[] = "placeholder lifecycle rows should be omitted, got {$lifecycle}";
}
if ($exceptions !== 0) {
    $errors[] = "placeholder exceptions should be omitted, got {$exceptions}";
}
if (($assessment->dueDiligenceItems[0]['status'] ?? '') !== 'N/A') {
    $errors[] = 'blank evidence status Not Requested should normalize to N/A';
}
if (($assessment->summary['due_diligence']['by_status']['Other'] ?? 0) !== 0) {
    $errors[] = 'blank adaptive DD should not flood Other status counts';
}
if ($legendRisk < 3) {
    $errors[] = "expected risk legend bands, got {$legendRisk}";
}
if ($legendRouting < 3) {
    $errors[] = "expected routing legend rows, got {$legendRouting}";
}

$repo = new AssessmentRepository($pdo);
$id = $repo->save($assessment, $path, basename($path));
$loaded = $repo->findById($id);
if ($loaded === null) {
    $errors[] = 'Failed to reload adaptive assessment';
} else {
    $html = (new DashboardRenderer())->render($loaded['assessment'], $loaded['source_filename'], $id);
    echo 'saved_id=' . $id . PHP_EOL;
    echo 'html_has_router_tab=' . (str_contains($html, 'data-tab="router"') ? 'yes' : 'no') . PHP_EOL;
    echo 'html_has_material_findings=' . (str_contains($html, 'Material findings') || str_contains($html, 'Material Findings') ? 'yes' : 'no') . PHP_EOL;
    echo 'html_has_module_bars=' . (str_contains($html, 'adaptive-module-bars') ? 'yes' : 'no') . PHP_EOL;
    echo 'html_has_high_critical=' . (str_contains($html, 'High / Critical') ? 'yes' : 'no') . PHP_EOL;
    echo 'html_has_classification=' . (str_contains($html, 'Architecture-type detection') || str_contains($html, 'Detection Signals') || str_contains($html, 'classification') ? 'yes' : 'no') . PHP_EOL;

    if (!str_contains($html, 'data-tab="router"')) {
        $errors[] = 'dashboard missing Question Router tab';
    }
    if (!str_contains($html, 'adaptive-module-bars')) {
        $errors[] = 'dashboard missing module bars';
    }
    if (!str_contains($html, 'High / Critical')) {
        $errors[] = 'dashboard missing High / Critical KPI chip';
    }
    if (($loaded['assessment']->workbook['format'] ?? '') !== 'adaptive') {
        $errors[] = 'reloaded assessment lost adaptive format';
    }
    if (count($loaded['assessment']->workbook['router'] ?? []) !== 85) {
        $errors[] = 'reloaded assessment lost router rows';
    }
}

// Classic blank template still parses when present.
$classicPath = dirname(__DIR__) . '/templates/Architecture_Risk_Assessment_Blank_Table_Template.xlsx';
if (is_readable($classicPath)) {
    $classic = $parser->parse($classicPath);
    $classicFormat = (string) ($classic->workbook['format'] ?? 'classic');
    echo 'classic_format=' . $classicFormat . PHP_EOL;
    echo 'classic_arch=' . count($classic->items) . PHP_EOL;
    echo 'classic_dd=' . count($classic->dueDiligenceItems) . PHP_EOL;
    if ($classicFormat === 'adaptive') {
        $errors[] = 'classic blank template was mis-detected as adaptive';
    }
    if (count($classic->items) !== 32) {
        $errors[] = 'classic blank expected 32 architecture rows, got ' . count($classic->items);
    }
    if (count($classic->dueDiligenceItems) !== 26) {
        $errors[] = 'classic blank expected 26 DD rows, got ' . count($classic->dueDiligenceItems);
    }
} else {
    echo "classic_blank=skipped (missing)\n";
}

if ($errors !== []) {
    fwrite(STDERR, "FAIL\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "PASS\n";
