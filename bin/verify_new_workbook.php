<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\DashboardRenderer;
use RiskAssessment\Database\Database;
use RiskAssessment\ExcelParser;
use RiskAssessment\Repositories\AssessmentRepository;

$path = dirname(__DIR__) . '/Architecture_Risk_Assessment_DataSheet_FibroScan_AI_Enabled.xlsx';
if (!is_readable($path)) {
    fwrite(STDERR, "Workbook missing: $path\n");
    exit(1);
}

$parser = new ExcelParser();
$assessment = $parser->parse($path);

echo 'arch=' . count($assessment->items) . PHP_EOL;
echo 'dd=' . count($assessment->dueDiligenceItems) . PHP_EOL;
echo 'fields=' . count($assessment->workbook['fields']) . PHP_EOL;
echo 'findings=' . count($assessment->workbook['findings']) . PHP_EOL;
echo 'legend_statuses=' . count($assessment->workbook['legend']['statuses']) . PHP_EOL;
echo 'legend_checklist=' . count($assessment->workbook['legend']['checklist']) . PHP_EOL;
echo 'arch_status=' . json_encode($assessment->summary['by_status']) . PHP_EOL;
echo 'dd_status=' . json_encode($assessment->summary['due_diligence']['by_status']) . PHP_EOL;
echo 'ddr=' . ($assessment->metadata['ddr_id'] ?? '') . PHP_EOL;
echo 'vra=' . ($assessment->metadata['vra_id'] ?? '') . PHP_EOL;
echo 'rating=' . ($assessment->metadata['overall_risk_rating'] ?? '') . PHP_EOL;
echo 'context=' . substr($assessment->workbook['context'], 0, 80) . PHP_EOL;

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$repo = new AssessmentRepository(Database::connection($dbConfig));
$id = $repo->save($assessment, $path, basename($path));
$loaded = $repo->findById($id);
if ($loaded === null) {
    fwrite(STDERR, "Failed to reload assessment\n");
    exit(1);
}

$html = (new DashboardRenderer())->render($loaded['assessment'], $loaded['source_filename']);
echo 'saved_id=' . $id . PHP_EOL;
echo 'html_len=' . strlen($html) . PHP_EOL;
echo 'has_dd_tab=' . (str_contains($html, 'data-tab="due-diligence"') ? 'yes' : 'no') . PHP_EOL;
echo 'has_gov_tab=' . (str_contains($html, 'data-tab="governance"') ? 'yes' : 'no') . PHP_EOL;
echo 'has_legend_tab=' . (str_contains($html, 'data-tab="legend"') ? 'yes' : 'no') . PHP_EOL;
echo 'reloaded_dd=' . count($loaded['assessment']->dueDiligenceItems) . PHP_EOL;
echo 'reloaded_findings=' . count($loaded['assessment']->workbook['findings']) . PHP_EOL;
