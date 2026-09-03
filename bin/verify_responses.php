<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\AssessmentRepository;
use RiskAssessment\Repositories\ItemResponseRepository;
use RiskAssessment\DashboardRenderer;
use RiskAssessment\AssessmentComparer;

$db = require dirname(__DIR__) . '/config/database.php';
$pdo = Database::connection($db);
$repo = new AssessmentRepository($pdo);
$responses = new ItemResponseRepository($pdo);

$id = (int) $pdo->query('SELECT id FROM assessments ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($id <= 0) {
    echo "no assessments\n";
    exit(0);
}

$record = $repo->findById($id);
$assessment = $record['assessment'];
$item = $assessment->items[0] ?? null;
$key = AssessmentComparer::itemKey(
    (string) ($item['item_type'] ?? 'architecture'),
    (string) ($item['section'] ?? ''),
    (string) ($item['check'] ?? '')
);

$ok = $responses->upsert($id, $key, 'take_care', 'Verified in test');
$bulk = $responses->upsertMany($id, [$key . '-bulk-a', $key . '-bulk-b'], 'ignore', 'bulk note');
$list = $responses->listForAssessment($id);
$html = (new DashboardRenderer())->render(
    $assessment,
    $record['source_filename'],
    $id,
    [],
    [],
    'csrf',
    '',
    $list
);

echo "id=$id key=$key upsert=" . ($ok ? 'yes' : 'no') . PHP_EOL;
echo "bulk_saved=$bulk" . PHP_EOL;
echo 'stored_action=' . ($list[$key]['action'] ?? '') . PHP_EOL;
echo 'has_response_col=' . (str_contains($html, 'Our response') ? 'yes' : 'no') . PHP_EOL;
echo 'has_bulk_bar=' . (str_contains($html, 'bulk-response-bar') ? 'yes' : 'no') . PHP_EOL;
echo 'has_select_all=' . (str_contains($html, 'Select all listed') ? 'yes' : 'no') . PHP_EOL;
echo 'has_tracker=' . (str_contains($html, 'id="item-responses"') ? 'yes' : 'no') . PHP_EOL;
echo 'has_take_care=' . (str_contains($html, 'take_care') ? 'yes' : 'no') . PHP_EOL;
echo 'table_exists=' . ($pdo->query("SELECT name FROM sqlite_master WHERE name='item_responses'")->fetchColumn() ? 'yes' : 'no') . PHP_EOL;
