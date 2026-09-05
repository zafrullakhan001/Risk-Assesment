<?php

declare(strict_types=1);

require __DIR__ . '/../public/bootstrap.php';

use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\SharePoint\SharePointListingImporter;

$path = dirname(__DIR__) . '/uploads/sharepoint-catalog-import.csv';
if (!is_readable($path)) {
    fwrite(STDERR, "Missing $path\n");
    exit(1);
}

$catalog = new SharePointCatalogRepository($pdo);
$importer = new SharePointListingImporter($catalog, $settings);
$result = $importer->importFile($path, 'sharepoint-catalog-import.csv');
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
echo 'db_count=' . $catalog->count() . ' projects=' . $catalog->countProjects() . PHP_EOL;
