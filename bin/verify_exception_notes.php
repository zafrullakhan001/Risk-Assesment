<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\FindingStatusRepository;

$db = require dirname(__DIR__) . '/config/database.php';
$pdo = Database::connection($db);

echo "Columns:\n";
foreach ($pdo->query('PRAGMA table_info(finding_statuses)') as $col) {
    echo ' - ' . $col['name'] . "\n";
}

$repo = new FindingStatusRepository($pdo);
$id = (int) $pdo->query('SELECT id FROM assessments ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($id <= 0) {
    echo "No assessments\n";
    exit(0);
}

$ok = $repo->upsert($id, 'verify-temp-finding', 'Open', "Large comment\nline 2", [
    'https://example.service-now.com/nav_to.do?uri=exception.do?sys_id=1',
    'https://example.service-now.com/nav_to.do?uri=exception.do?sys_id=2',
]);
echo $ok ? "Upsert ok\n" : "Upsert failed\n";

$list = $repo->listForAssessment($id);
$row = $list['verify-temp-finding'] ?? null;
echo 'Loaded comment length: ' . strlen((string) ($row['comment'] ?? '')) . "\n";
echo 'Loaded links: ' . count($row['servicenow_links'] ?? []) . "\n";

$repo->deleteOne($id, 'verify-temp-finding');
echo "Cleanup done\n";
