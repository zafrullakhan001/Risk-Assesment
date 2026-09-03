<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\Database\Database;
use RiskAssessment\ExcelParser;
use RiskAssessment\Repositories\AssessmentRepository;

$config = require dirname(__DIR__) . '/config/config.php';
$dbConfig = require dirname(__DIR__) . '/config/database.php';

$samplePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Architecture_Risk_Assessment_DataSheet_FibroScan_AI_Enabled.xlsx';
if (!is_readable($samplePath)) {
    $samplePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Architecture_Risk_Assessment_DataSheet_FibroScan (2)1.xlsx';
}
if (!is_readable($samplePath)) {
    fwrite(STDERR, "Sample workbook not found.\n");
    exit(1);
}

$pdo = Database::connection($dbConfig);
$repository = new AssessmentRepository($pdo);

$existing = $repository->searchByProjectName('FibroScan', 1);
if ($existing !== []) {
    echo 'Sample project already exists with id ' . (int) $existing[0]['id'] . PHP_EOL;
    exit(0);
}

$parser = new ExcelParser();
$assessment = $parser->parse($samplePath);

$uploadDir = $config['upload_dir'];
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    fwrite(STDERR, "Unable to create upload directory.\n");
    exit(1);
}

$storedName = 'seed_fibroscan_' . bin2hex(random_bytes(8)) . '.xlsx';
$destination = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
if (!copy($samplePath, $destination)) {
    fwrite(STDERR, "Unable to copy sample workbook into uploads.\n");
    exit(1);
}

$id = $repository->save($assessment, $destination, basename($samplePath));

echo 'Seeded assessment id ' . $id . ' (' . ($assessment->metadata['solution_name'] ?? 'Unknown') . ')' . PHP_EOL;
