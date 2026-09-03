<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\DashboardDecisionViews;
use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\AssessmentRepository;

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$repo = new AssessmentRepository(Database::connection($dbConfig));

$pdo = Database::connection($dbConfig);
$rows = $pdo->query(
    'SELECT solution_name, COUNT(*) AS c FROM assessments GROUP BY solution_name HAVING c >= 2 ORDER BY c DESC LIMIT 1'
)->fetchAll();

if ($rows === []) {
    echo "skip=no multi-version project in DB\n";
    exit(0);
}

$solutionName = (string) $rows[0]['solution_name'];
$versions = $repo->listVersionsBySolutionName($solutionName, 50);
$keepId = (int) $versions[0]['id'];
$before = count($versions);
$removed = $repo->deleteOlderVersions($solutionName, $keepId);
$after = $repo->listVersionsBySolutionName($solutionName, 50);

$views = new DashboardDecisionViews();
$html = $views->renderActionsPanel(
    ['findings' => [], 'owners' => [], 'timelines' => [], 'evidence' => ['total' => 0, 'covered' => 0, 'partial' => 0, 'missing' => 0, 'items' => []], 'top_risks' => []],
    ['has_prior' => false],
    $versions,
    $keepId,
    'csrf-test'
);

echo "solution=$solutionName keep=$keepId before=$before removed=$removed after=" . count($after) . PHP_EOL;
echo 'only_keep=' . (count($after) === 1 && (int) $after[0]['id'] === $keepId ? 'yes' : 'no') . PHP_EOL;
echo 'has_drop_older_ui=' . (str_contains($html, 'delete_older_versions') ? 'yes' : 'no') . PHP_EOL;
echo 'has_per_delete=' . (str_contains($html, 'delete_assessment') ? 'yes' : 'no') . PHP_EOL;
