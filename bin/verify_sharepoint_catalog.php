<?php

declare(strict_types=1);

require __DIR__ . '/../public/bootstrap.php';

use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\SharePoint\SharePointListingImporter;

$catalog = new SharePointCatalogRepository($pdo);
echo "count_before=" . $catalog->count() . PHP_EOL;

// Seed a tiny CSV import via temp file
$csv = <<<CSV
Name,Path,Type,URL
Demo Project,Demo Project,Folder,https://ahsonline.sharepoint.com/teams/AITTechnologyEngagement/Shared%20Documents/Architectural%20Projects%20%5BPublic%5D/Demo%20Project
Architecture.docx,Demo Project/Architecture.docx,File,https://ahsonline.sharepoint.com/teams/AITTechnologyEngagement/Shared%20Documents/Architectural%20Projects%20%5BPublic%5D/Demo%20Project/Architecture.docx
Runbook.pdf,Demo Project/docs/Runbook.pdf,File,https://example.com/runbook.pdf
Other App,Other App,Folder,https://ahsonline.sharepoint.com/teams/AITTechnologyEngagement/Shared%20Documents/Architectural%20Projects%20%5BPublic%5D/Other%20App
CSV;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sp_import_test.csv';
file_put_contents($tmp, $csv);

$importer = new SharePointListingImporter($catalog, $settings);
$result = $importer->importFile($tmp, 'sp_import_test.csv');
echo "import=" . json_encode($result) . PHP_EOL;
echo "count_after=" . $catalog->count() . PHP_EOL;
echo "projects=" . $catalog->countProjects() . PHP_EOL;

$search = $catalog->search('Demo');
echo "search_demo_groups=" . count($search) . PHP_EOL;
if ($search !== []) {
    echo "first_project=" . $search[0]['project_name'] . " items=" . count($search[0]['items']) . PHP_EOL;
}

$match = $catalog->findMatchingProject('Demo Project');
echo "exact_match=" . ($match['project_name'] ?? 'null') . PHP_EOL;

$fuzzy = $catalog->findMatchingProject('Demo');
echo "fuzzy_match=" . ($fuzzy['project_name'] ?? 'null') . PHP_EOL;

$none = $catalog->findMatchingProject('Does Not Exist XYZ');
echo "none_match=" . ($none === null ? 'null' : $none['project_name']) . PHP_EOL;

$status = [
    'host' => $settings->get('sharepoint_site_host'),
    'path' => $settings->get('sharepoint_site_path'),
    'folder' => $settings->get('sharepoint_folder_path'),
    'sync_status' => $settings->get('sharepoint_last_sync_status'),
];
echo "settings=" . json_encode($status) . PHP_EOL;

@unlink($tmp);
echo "OK\n";
