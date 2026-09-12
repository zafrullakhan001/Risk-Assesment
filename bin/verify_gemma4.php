<?php

declare(strict_types=1);

/**
 * Verify local Ollama model connectivity for Ticket Dossier.
 * Usage: php bin/verify_gemma4.php [project_id]
 */

require_once dirname(__DIR__) . '/public/ticket-dossier/includes/config.php';
require_once dirname(__DIR__) . '/public/ticket-dossier/includes/helpers.php';
require_once dirname(__DIR__) . '/public/ticket-dossier/includes/OllamaClient.php';
require_once dirname(__DIR__) . '/public/ticket-dossier/includes/ProjectJsonExport.php';
require_once dirname(__DIR__) . '/public/ticket-dossier/includes/ProjectSummaryMapper.php';
require_once dirname(__DIR__) . '/public/ticket-dossier/includes/DossierReasoningService.php';

$service = new DossierReasoningService();
$status = $service->status();

echo "Model: {$status['model']}\n";
echo "Available: " . ($status['available'] ? 'yes' : 'no') . "\n";
echo "Message: {$status['message']}\n";
if ($status['models'] !== []) {
    echo "Installed models:\n";
    foreach ($status['models'] as $name) {
        echo "  - {$name}\n";
    }
}

if (!$status['available']) {
    exit(1);
}

$projectId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($projectId <= 0) {
    echo "\nSmoke chat…\n";
    $client = new OllamaClient();
    $result = $client->chat(TD_OLLAMA_MODEL, [
        ['role' => 'user', 'content' => 'Reply with exactly: qwen-ready'],
    ], ['temperature' => 0], false);
    echo 'Response: ' . trim($result['content']) . "\n";
    echo "OK\n";
    exit(0);
}

require_once dirname(__DIR__) . '/public/ticket-dossier/includes/security.php';
require_once dirname(__DIR__) . '/public/ticket-dossier/includes/db.php';
require_once dirname(__DIR__) . '/public/ticket-dossier/includes/ProjectRepository.php';

$project = ProjectRepository::find($projectId);
if ($project === null) {
    fwrite(STDERR, "Project {$projectId} not found.\n");
    exit(1);
}

$parsed = json_decode((string) $project['parsed_json'], true);
if (!is_array($parsed)) {
    $parsed = [];
}

echo "\nReasoning over project #{$projectId}: {$project['title']}\n";
$exportChars = strlen(ProjectJsonExport::toReasoningText($project, $parsed));
echo "Export JSON context chars: {$exportChars} (no PDF binaries)\n";
@set_time_limit(TD_GEMMA_TIMEOUT_SECONDS + 30);
$mapped = ProjectSummaryMapper::map($project, $parsed);
$reasoning = $service->reason(
    $project,
    $parsed,
    $mapped,
    static function (array $p): void {
        echo sprintf(
            "[%3d%%] %-14s ETA %3ds  %s\n",
            (int) ($p['percent'] ?? 0),
            (string) ($p['stage'] ?? ''),
            (int) ($p['eta_seconds'] ?? 0),
            (string) ($p['message'] ?? '')
        );
    }
);
$summary = ProjectSummaryMapper::mergeWithAi($mapped, is_array($reasoning['template'] ?? null) ? $reasoning['template'] : null);
ProjectRepository::saveAiReasoning($projectId, $reasoning);
echo "Executive summary:\n{$reasoning['executive_summary']}\n";
echo "Source: {$reasoning['source']} · Context: {$reasoning['context_chars']} chars · Duration: {$reasoning['duration_ms']} ms\n";
$gemmaFields = 0;
foreach (['product_summary', 'business_requirements', 'goals', 'owners', 'third_party_review'] as $section) {
    foreach (($summary[$section] ?? []) as $field) {
                if (($field['source'] ?? '') === 'gemma' || ($field['source'] ?? '') === 'ai') {
                    $gemmaFields++;
                }
    }
}
echo "Template fields filled by AI (named sections): {$gemmaFields}\n";
echo "Product name: " . ($summary['product_summary']['product_name']['value'] ?? '') . "\n";
echo "Saved AI reasoning + template for project {$projectId}.\n";
