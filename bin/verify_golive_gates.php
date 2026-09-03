<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\DashboardRenderer;
use RiskAssessment\Database\Database;
use RiskAssessment\GoliveGate;
use RiskAssessment\Repositories\AssessmentRepository;
use RiskAssessment\Repositories\FinalEvaluationRepository;
use RiskAssessment\Repositories\FindingStatusRepository;
use RiskAssessment\Repositories\ItemResponseRepository;

$db = require dirname(__DIR__) . '/config/database.php';
$pdo = Database::connection($db);
$repo = new AssessmentRepository($pdo);
$responseRepo = new ItemResponseRepository($pdo);
$findingRepo = new FindingStatusRepository($pdo);
$evalRepo = new FinalEvaluationRepository($pdo);
$gate = new GoliveGate();

$id = (int) $pdo->query('SELECT id FROM assessments ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($id <= 0) {
    echo "no assessments\n";
    exit(0);
}

$record = $repo->findById($id);
$responses = $responseRepo->listForAssessment($id);
$findings = $findingRepo->listForAssessment($id);
$evaluation = $evalRepo->findByAssessmentId($id) ?? [];
$notes = (string) ($evaluation['notes'] ?? '');

$result = $gate->evaluate($record['assessment'], $responses, $findings, $notes);
$html = (new DashboardRenderer())->render(
    $record['assessment'],
    $record['source_filename'],
    $id,
    [],
    [],
    'csrf',
    '',
    $responses,
    $evaluation,
    [],
    [],
    $findings
);

echo 'id=' . $id . PHP_EOL;
echo 'ready_allowed=' . (!empty($result['ready_allowed']) ? 'yes' : 'no') . PHP_EOL;
echo 'rules=' . count($result['rules']) . PHP_EOL;
echo 'has_golive_gates=' . (str_contains($html, 'golive-gates') ? 'yes' : 'no') . PHP_EOL;
echo 'has_high_rule=' . (str_contains($html, 'No open High risks') ? 'yes' : 'no') . PHP_EOL;
echo 'has_exceptions_rule=' . (str_contains($html, 'All exceptions closed, approved, or expired') ? 'yes' : 'no') . PHP_EOL;
echo 'has_notes_rule=' . (str_contains($html, 'Evaluator notes completed') ? 'yes' : 'no') . PHP_EOL;
