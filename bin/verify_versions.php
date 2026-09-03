<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\AssessmentComparer;
use RiskAssessment\DashboardRenderer;
use RiskAssessment\Database\Database;
use RiskAssessment\ExcelParser;
use RiskAssessment\Repositories\AssessmentRepository;

$path = dirname(__DIR__) . '/Architecture_Risk_Assessment_DataSheet_FibroScan_AI_Enabled.xlsx';
$dbConfig = require dirname(__DIR__) . '/config/database.php';
$repo = new AssessmentRepository(Database::connection($dbConfig));
$parser = new ExcelParser();
$assessment = $parser->parse($path);

$id1 = $repo->save($assessment, $path, 'v1.xlsx');
$id2 = $repo->save($assessment, $path, 'v2.xlsx');

$current = $repo->findById($id2);
$prior = $repo->findPreviousVersion($assessment->metadata['solution_name'], $id2);
$comparison = (new AssessmentComparer())->compare(
    $current['assessment'],
    $prior['assessment'] ?? null,
    (int) ($prior['id'] ?? 0),
    (string) ($prior['uploaded_at'] ?? '')
);

$versions = $repo->listVersionsBySolutionName($assessment->metadata['solution_name']);
$html = (new DashboardRenderer())->render(
    $current['assessment'],
    $current['source_filename'],
    $id2,
    $comparison,
    $versions,
    'test-csrf'
);

echo "id1=$id1 id2=$id2 prior=" . (int) ($prior['id'] ?? 0) . PHP_EOL;
echo 'has_prior=' . (!empty($comparison['has_prior']) ? 'yes' : 'no') . PHP_EOL;
echo 'versions=' . count($versions) . PHP_EOL;
echo 'has_trends_ui=' . (str_contains($html, 'trend-chips') || str_contains($html, 'trend-chip') || str_contains($html, 'Since last upload') ? 'yes' : 'no') . PHP_EOL;
echo 'has_version_history=' . (str_contains($html, 'version-history') ? 'yes' : 'no') . PHP_EOL;
echo 'has_delete=' . (str_contains($html, 'delete_assessment') ? 'yes' : 'no') . PHP_EOL;
echo 'has_decision=' . (str_contains($html, 'decision-desk') ? 'yes' : 'no') . PHP_EOL;

$repo->deleteById($id1);
$after = $repo->listVersionsBySolutionName($assessment->metadata['solution_name']);
$stillHasId1 = false;
foreach ($after as $row) {
    if ((int) $row['id'] === $id1) {
        $stillHasId1 = true;
    }
}
echo 'deleted_id1=' . ($stillHasId1 ? 'no' : 'yes') . PHP_EOL;
echo 'remaining_for_project=' . count($after) . PHP_EOL;

$id3 = $repo->save($assessment, $path, 'v3.xlsx');
$id4 = $repo->save($assessment, $path, 'v4.xlsx');
$beforeBulk = count($repo->listVersionsBySolutionName($assessment->metadata['solution_name']));
$removed = $repo->deleteOlderVersions($assessment->metadata['solution_name'], $id4);
$afterBulk = $repo->listVersionsBySolutionName($assessment->metadata['solution_name']);
$onlyId4 = count($afterBulk) === 1 && (int) $afterBulk[0]['id'] === $id4;
echo "bulk_before=$beforeBulk removed=$removed after=" . count($afterBulk) . ' only_keep=' . ($onlyId4 ? 'yes' : 'no') . PHP_EOL;
echo 'has_drop_older_ui=' . (str_contains($html, 'delete_older_versions') ? 'yes' : 'no') . PHP_EOL;
