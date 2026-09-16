<?php

declare(strict_types=1);

/**
 * Audit project_portfolio_mapping.csv against public + private architecture catalogs.
 * Read-only: does not modify catalog data.
 *
 * Usage: php bin/verify_project_portfolio_mapping.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\SharePointArchiveRepository;
use RiskAssessment\SharePoint\SharePointPortfolioMapping;

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$pdo = Database::connection($dbConfig);

SharePointPortfolioMapping::clearCache();
$map = SharePointPortfolioMapping::load();
$loadError = SharePointPortfolioMapping::lastError();

echo 'mapping_rows=' . count($map) . PHP_EOL;
echo 'mapping_path=' . SharePointPortfolioMapping::defaultPath() . PHP_EOL;
if ($loadError !== null) {
    echo 'mapping_warnings=' . $loadError . PHP_EOL;
}

$sourceKeys = ['default', 'architectural-projects-private'];
$visible = SharePointArchiveRepository::visibleProjectSql('sharepoint_items');
$placeholders = implode(',', array_fill(0, count($sourceKeys), '?'));
$statement = $pdo->prepare(
    "SELECT DISTINCT project_name
     FROM sharepoint_items
     WHERE source_key IN ($placeholders)
       AND {$visible}
     ORDER BY LOWER(project_name) ASC"
);
$statement->execute($sourceKeys);
$projects = [];
foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
    $name = trim((string) $name);
    if ($name !== '') {
        $projects[] = $name;
    }
}

$coverage = SharePointPortfolioMapping::coverageStats($projects, $map);
echo 'catalog_projects=' . $coverage['total'] . PHP_EOL;
echo 'mapped=' . $coverage['mapped'] . PHP_EOL;
echo 'unmapped=' . $coverage['unmapped'] . PHP_EOL;
echo 'needs_review=' . $coverage['needs_review'] . PHP_EOL;
echo 'low_confidence=' . $coverage['low_confidence'] . PHP_EOL;

$unmappedSamples = [];
foreach ($projects as $name) {
    $resolved = SharePointPortfolioMapping::resolve($name, $map);
    if (!$resolved['mapped']) {
        $unmappedSamples[] = $name;
        if (count($unmappedSamples) >= 10) {
            break;
        }
    }
}
if ($unmappedSamples !== []) {
    echo 'unmapped_samples=' . implode(' | ', $unmappedSamples) . PHP_EOL;
}

$exit = 0;
if (count($map) === 0) {
    echo "FAIL: mapping is empty\n";
    $exit = 1;
}
if ($coverage['unmapped'] > 0) {
    echo "WARN: catalog has unmapped projects (shown in Needs Review / Unmapped heatmap bucket)\n";
}
if ($loadError !== null && str_contains($loadError, 'header is invalid')) {
    echo "FAIL: invalid mapping header\n";
    $exit = 1;
}

echo $exit === 0 ? "OK\n" : "FAILED\n";
exit($exit);
