<?php

declare(strict_types=1);

require __DIR__ . '/../public/bootstrap.php';

use RiskAssessment\Repositories\SharePointArchiveRepository;
use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\SharePoint\SharePointCatalogComparer;

$leftKey = '_verify_cc_left';
$rightKey = '_verify_cc_right';
$catalog = new SharePointCatalogRepository($pdo);
$archives = new SharePointArchiveRepository($pdo);
$comparer = new SharePointCatalogComparer($pdo);

$failed = 0;
$passed = 0;

$assert = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    if ($ok) {
        $passed++;
        echo "ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
};

$item = static function (
    string $project,
    string $name,
    string $type = 'folder',
    string $path = '',
    string $modified = '2024-01-02T00:00:00Z'
): array {
    $path = $path !== '' ? $path : $name;

    return [
        'item_key' => $project . '/' . $path,
        'parent_item_key' => '',
        'project_name' => $project,
        'name' => $name,
        'item_type' => $type,
        'web_url' => 'https://example.com/catalog/' . rawurlencode($project) . '/' . rawurlencode($name),
        'relative_path' => $path,
        'last_modified' => $modified,
    ];
};

$cleanup = static function () use ($catalog, $pdo, $leftKey, $rightKey): void {
    $catalog->purgeItemsForSources([$leftKey, $rightKey]);
    $pdo->prepare(
        "DELETE FROM sharepoint_archives WHERE source_key IN (:left_key, :right_key)"
    )->execute([':left_key' => $leftKey, ':right_key' => $rightKey]);
};

$cleanup();

try {
    $leftItems = [
        $item('Encore', 'Encore', 'folder', 'Encore'),
        $item('Encore', 'Plan.pdf', 'file', 'Encore/Plan.pdf'),
        $item('Shared App', 'Shared App', 'folder', 'Shared App'),
        $item('Alpha Only', 'Alpha Only', 'folder', 'Alpha Only'),
        $item('Hidden App', 'Hidden App', 'folder', 'Hidden App'),
        $item('Page One', 'Page One', 'folder', 'Page One'),
        $item('Page Two', 'Page Two', 'folder', 'Page Two'),
        $item('Page Three', 'Page Three', 'folder', 'Page Three'),
    ];
    for ($i = 1; $i <= 20; $i++) {
        $name = sprintf('Zed %02d', $i);
        $leftItems[] = $item($name, $name, 'folder', $name);
    }
    $rightItems = [
        $item('encore', 'encore', 'folder', 'encore', '2024-06-01T00:00:00Z'),
        $item('encore', 'Notes.docx', 'file', 'encore/Notes.docx', '2024-06-01T00:00:00Z'),
        $item('Shared App', 'Shared App', 'folder', 'Shared App'),
        $item('Bravo Only', 'Bravo Only', 'folder', 'Bravo Only'),
        $item('Hidden App', 'Hidden App', 'folder', 'Hidden App'),
        $item('Page One', 'Page One', 'folder', 'Page One'),
        $item('Page Two', 'Page Two', 'folder', 'Page Two'),
        $item('Page Three', 'Page Three', 'folder', 'Page Three'),
    ];
    for ($i = 1; $i <= 20; $i++) {
        $name = sprintf('Zed %02d', $i);
        $rightItems[] = $item($name, $name, 'folder', $name);
    }

    $assert($catalog->replaceForSource($leftKey, $leftItems) >= 8, 'seed left catalog');
    $assert($catalog->replaceForSource($rightKey, $rightItems) >= 8, 'seed right catalog');

    $archives->setArchived($leftKey, 'project', true, 'Hidden App');
    $archives->setArchived($rightKey, 'project', true, 'Hidden App');

    $same = false;
    try {
        $comparer->compare($leftKey, $leftKey);
    } catch (InvalidArgumentException) {
        $same = true;
    }
    $assert($same, 'rejects comparing a catalog with itself');

    $all = $comparer->compare($leftKey, $rightKey, [
        'left_title' => 'Verify Left',
        'right_title' => 'Verify Right',
        'per_page' => 50,
        'sort' => 'name',
    ]);
    $assert(($all['totals']['in_both'] ?? 0) === 25, 'in_both excludes archived Hidden App');
    $assert(($all['totals']['only_left'] ?? 0) === 1, 'only_left is Alpha Only');
    $assert(($all['totals']['only_right'] ?? 0) === 1, 'only_right is Bravo Only');
    $assert(($all['left']['title'] ?? '') === 'Verify Left', 'preserves left catalog title');
    $assert(($all['right']['title'] ?? '') === 'Verify Right', 'preserves right catalog title');

    $byKey = [];
    foreach ($all['rows'] as $row) {
        $byKey[(string) ($row['name_key'] ?? '')] = $row;
    }
    $assert(isset($byKey['encore']), 'case-insensitive Encore/encore matched');
    $assert(($byKey['encore']['presence'] ?? '') === 'both', 'Encore presence is both');
    $assert(($byKey['encore']['left']['project_name'] ?? '') === 'Encore', 'preserves left Encore casing');
    $assert(($byKey['encore']['right']['project_name'] ?? '') === 'encore', 'preserves right encore casing');
    $assert(($byKey['alpha only']['presence'] ?? '') === 'left', 'Alpha Only is left-only');
    $assert($byKey['alpha only']['right'] === null, 'Alpha Only has no right side');
    $assert(($byKey['bravo only']['presence'] ?? '') === 'right', 'Bravo Only is right-only');
    $assert($byKey['bravo only']['left'] === null, 'Bravo Only has no left side');
    $assert(!isset($byKey['hidden app']), 'archived Hidden App is excluded by default');

    $search = $comparer->compare($leftKey, $rightKey, ['query' => 'Encore']);
    $assert(($search['row_count'] ?? 0) === 1, 'search Encore returns one matched folder');
    $assert(($search['rows'][0]['presence'] ?? '') === 'both', 'search Encore is in both');
    $assert(($search['totals']['in_both'] ?? 0) === 1, 'search totals follow the query');

    $leftOnly = $comparer->compare($leftKey, $rightKey, ['presence' => 'left']);
    $assert(($leftOnly['row_count'] ?? 0) === 1, 'presence left returns one row');
    $assert(($leftOnly['rows'][0]['left']['project_name'] ?? '') === 'Alpha Only', 'presence left row is Alpha Only');
    $assert(($leftOnly['totals']['only_right'] ?? 0) === 1, 'presence filter does not hide other totals');

    $page1 = $comparer->compare($leftKey, $rightKey, [
        'per_page' => 25,
        'page' => 1,
        'sort' => 'name',
        'dir' => 'asc',
    ]);
    $page2 = $comparer->compare($leftKey, $rightKey, [
        'per_page' => 25,
        'page' => 2,
        'sort' => 'name',
        'dir' => 'asc',
    ]);
    $assert(count($page1['rows']) === 25, 'page 1 returns 25 rows');
    $assert(count($page2['rows']) === 2, 'page 2 returns remaining rows');
    $assert(($page1['page_count'] ?? 0) === 2, '27 visible folders paginate into two pages of 25');
    $page1Keys = array_map(static fn (array $row): string => (string) ($row['name_key'] ?? ''), $page1['rows']);
    $page2Keys = array_map(static fn (array $row): string => (string) ($row['name_key'] ?? ''), $page2['rows']);
    $assert(array_intersect($page1Keys, $page2Keys) === [], 'pages do not repeat project folders');

    $withArchived = $comparer->compare($leftKey, $rightKey, ['include_archived' => true]);
    $assert(($withArchived['totals']['in_both'] ?? 0) === 26, 'include_archived restores Hidden App');
} finally {
    $cleanup();
}

echo "passed={$passed} failed={$failed}\n";
if ($failed > 0) {
    echo "FAIL\n";
    exit(1);
}

echo "OK\n";
