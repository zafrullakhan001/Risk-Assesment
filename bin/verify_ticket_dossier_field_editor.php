<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/public/ticket-dossier/includes/config.php';
require_once $root . '/public/ticket-dossier/includes/db.php';
require_once $root . '/public/ticket-dossier/includes/helpers.php';
require_once $root . '/public/ticket-dossier/includes/ProjectRepository.php';
require_once $root . '/public/ticket-dossier/includes/DossierFieldEditor.php';

$projectId = 0;
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

try {
    $parsed = [
        'overview' => [
            'title' => 'Original title',
            'vendor' => 'Original vendor',
            'description' => 'Original description',
            'business_case' => '',
        ],
        'demand' => [
            'number' => 'DMND0001',
            'state' => 'Draft',
            'fields' => ['Priority' => 'Low'],
        ],
    ];
    $projectId = ProjectRepository::create([
        'title' => 'Original title',
        'vendor' => 'Original vendor',
        'demand_number' => 'DMND0001',
        'story_number' => '',
        'task_number' => '',
        'ddr_number' => '',
        'demand_state' => 'Draft',
        'story_state' => '',
        'task_state' => '',
        'ddr_state' => '',
        'sources' => ['demand' => true],
        'parsed' => $parsed,
    ], []);

    DossierFieldEditor::update($projectId, ['demand', 'fields', 'Priority'], 'Critical');
    DossierFieldEditor::update($projectId, ['demand', 'number'], 'DMND0099');
    DossierFieldEditor::update($projectId, ['overview', 'title'], 'Corrected title');

    $saved = ProjectRepository::find($projectId);
    $savedParsed = json_decode((string) ($saved['parsed_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $assert(($savedParsed['demand']['fields']['Priority'] ?? '') === 'Critical', 'nested parsed field corrected');
    $assert(($saved['demand_number'] ?? '') === 'DMND0099', 'record number column synchronized');
    $assert(($saved['title'] ?? '') === 'Corrected title', 'project title synchronized');

    $rejected = false;
    try {
        DossierFieldEditor::update($projectId, ['demand', 'fields', 'Not present'], 'value');
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert($rejected, 'unknown paths are rejected');
} catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . PHP_EOL);
} finally {
    if ($projectId > 0) {
        ProjectRepository::delete($projectId);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " check(s) failed.\n");
    exit(1);
}

echo "All dossier field editor checks passed.\n";
