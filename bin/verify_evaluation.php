<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\DashboardRenderer;
use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\AssessmentRepository;
use RiskAssessment\Repositories\FinalEvaluationRepository;

$db = require dirname(__DIR__) . '/config/database.php';
$pdo = Database::connection($db);
$repo = new AssessmentRepository($pdo);
$evalRepo = new FinalEvaluationRepository($pdo);

$id = (int) $pdo->query('SELECT id FROM assessments ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($id <= 0) {
    echo "no assessments\n";
    exit(0);
}

$previousOverride = $repo->findExecutiveOverride($id);
try {
    $ok = $evalRepo->upsert($id, 'Jane Evaluator', 'jane@example.com', 'Residual risks accepted. Ready pending CAB.', true);
    $saved = $evalRepo->findByAssessmentId($id);
    $overrideOk = $repo->saveExecutiveOverride($id, 'Conditional go-live ready', 'Custom residual narrative for this version.');
    $override = $repo->findExecutiveOverride($id);
    $record = $repo->findById($id);
    $html = (new DashboardRenderer())->render(
        $record['assessment'],
        $record['source_filename'],
        $id,
        [],
        [],
        'csrf',
        '',
        [],
        $saved,
        [],
        [],
        [],
        $override
    );

    echo "id=$id upsert=" . ($ok ? 'yes' : 'no') . PHP_EOL;
    echo 'ready=' . (!empty($saved['ready_to_golive']) ? 'yes' : 'no') . PHP_EOL;
    echo 'name=' . ($saved['evaluator_name'] ?? '') . PHP_EOL;
    echo 'has_form=' . (str_contains($html, 'final-evaluation-form') ? 'yes' : 'no') . PHP_EOL;
    echo 'override_ok=' . ($overrideOk ? 'yes' : 'no') . PHP_EOL;
    echo 'override_saved=' . (($override['summary'] ?? '') === 'Custom residual narrative for this version.' ? 'yes' : 'no') . PHP_EOL;
    echo 'has_custom_copy=' . (str_contains($html, 'Custom residual narrative for this version.') ? 'yes' : 'no') . PHP_EOL;
    echo 'has_exec_edit=' . (str_contains($html, 'btn-edit-exec-summary') ? 'yes' : 'no') . PHP_EOL;
    echo 'has_exec_form=' . (str_contains($html, 'exec-summary-form') ? 'yes' : 'no') . PHP_EOL;
    echo 'has_toggle=' . (str_contains($html, 'Ready to go-live') ? 'yes' : 'no') . PHP_EOL;
    echo 'has_pill=' . (str_contains($html, 'golive-pill') ? 'yes' : 'no') . PHP_EOL;
    echo 'has_action_tabs=' . (str_contains($html, 'action-tabs') ? 'yes' : 'no') . PHP_EOL;
    echo 'has_gaps_tab=' . (str_contains($html, 'data-action-tab="gaps"') ? 'yes' : 'no') . PHP_EOL;
    echo 'dashboard_readonly=' . (str_contains($html, 'Dashboard view only') ? 'yes' : 'no') . PHP_EOL;
    echo 'actions_workbench=' . (str_contains($html, 'action-risks-table') ? 'yes' : 'no') . PHP_EOL;
} finally {
    $repo->saveExecutiveOverride($id, $previousOverride['verdict'], $previousOverride['summary']);
}
