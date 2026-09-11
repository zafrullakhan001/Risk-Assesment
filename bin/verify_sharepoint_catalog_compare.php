<?php

declare(strict_types=1);

require __DIR__ . '/../public/bootstrap.php';

use RiskAssessment\Repositories\SharePointArchiveRepository;
use RiskAssessment\Repositories\SharePointCatalogRepository;
use RiskAssessment\Repositories\SharePointSearchTagRepository;
use RiskAssessment\SharePoint\SharePointCatalogComparer;
use RiskAssessment\SharePoint\SharePointCatalogQuery;

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
    string $modified = '2024-01-02T00:00:00Z',
    array $extra = []
): array {
    $path = $path !== '' ? $path : $name;

    return $extra + [
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
    $pdo->prepare(
        'DELETE FROM sharepoint_search_tag_assignments WHERE source_key IN (:left_key, :right_key)'
    )->execute([':left_key' => $leftKey, ':right_key' => $rightKey]);
    $pdo->prepare("DELETE FROM sharepoint_search_tags WHERE slug IN (:slug, :slug2)")->execute([
        ':slug' => '_verify_cc_priority',
        ':slug2' => 'verify_cc_priority',
    ]);
};

$cleanup();

try {
    $leftItems = [
        $item('Encore', 'Encore', 'folder', 'Encore', '2024-01-02T00:00:00Z', [
            'date_created' => '2023-06-01T00:00:00Z',
            'person' => 'Bob Builder',
        ]),
        $item('Encore', 'Plan.pdf', 'file', 'Encore/Plan.pdf', '2024-01-02T00:00:00Z', [
            'modified_by' => 'Alice Chen',
        ]),
        $item('Shared App', 'Shared App', 'folder', 'Shared App'),
        $item('Shared App', 'Drawings', 'folder', 'Shared App/Drawings'),
        $item('Shared App', 'Plan.vsdx', 'file', 'Shared App/Drawings/Plan.vsdx'),
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

    $parsed = SharePointCatalogQuery::parse('tag:priority ext:pdf -exclude "exact phrase" person:"Last, First"');
    $assert($parsed['tags'] === ['priority'], 'parse tag:priority');
    $assert($parsed['extensions'] === ['pdf'], 'parse ext:pdf');
    $assert($parsed['excludes'] === ['exclude'], 'parse -exclude');
    $assert($parsed['phrases'] === ['exact phrase'], 'parse quoted phrase');
    $assert($parsed['person'] === 'last, first', 'parse person quoted');

    $fromGet = SharePointCatalogComparer::requestOptions([
        'q' => 'Plan',
        'mode' => 'or',
        'fuzzy' => '1',
        'deep' => '0',
        'scope' => 'files',
        'type' => 'pdf,visio',
        'ext' => 'xlsx',
    ]);
    $assert($fromGet['word_mode'] === 'or', 'requestOptions word mode');
    $assert($fromGet['fuzzy'] === true, 'requestOptions fuzzy');
    $assert($fromGet['deep'] === false, 'requestOptions deep off');
    $assert($fromGet['match_scope'] === 'files', 'requestOptions scope');

    $searchTags = new SharePointSearchTagRepository($pdo);
    $tag = $searchTags->create('_verify_cc_priority', ['username' => 'verify']);
    $searchTags->setProjectTags($leftKey, 'Encore', [(int) $tag['id']]);

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
    $assert(($byKey['encore']['left']['modified_by'] ?? '') === 'Alice Chen', 'includes project modified by');
    $assert(($byKey['encore']['left']['created_by'] ?? '') === 'Bob Builder', 'includes project creator');
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

    $pdf = $comparer->compare($leftKey, $rightKey, ['query' => 'ext:pdf']);
    $assert(($pdf['row_count'] ?? 0) === 1, 'ext:pdf matches Encore');
    $assert(($pdf['rows'][0]['name_key'] ?? '') === 'encore', 'ext:pdf row is Encore');

    $typePdf = $comparer->compare($leftKey, $rightKey, ['types' => 'pdf']);
    $assert(($typePdf['row_count'] ?? 0) === 1, 'type chip pdf matches Encore');

    $exclude = $comparer->compare($leftKey, $rightKey, ['query' => '-encore']);
    $assert(($exclude['row_count'] ?? 0) === 26, '-encore excludes the Encore folder');

    $andWords = $comparer->compare($leftKey, $rightKey, [
        'query' => 'page zed',
        'word_mode' => 'and',
    ]);
    $assert(($andWords['row_count'] ?? 0) === 0, 'AND page zed matches no folder');

    $orWords = $comparer->compare($leftKey, $rightKey, [
        'query' => 'page zed',
        'word_mode' => 'or',
    ]);
    $assert(($orWords['row_count'] ?? 0) === 23, 'OR page zed matches pages and zeds');

    $exactTypo = $comparer->compare($leftKey, $rightKey, [
        'query' => 'encre',
        'fuzzy' => false,
    ]);
    $assert(($exactTypo['row_count'] ?? 0) === 0, 'exact encre does not match Encore');

    $fuzzy = $comparer->compare($leftKey, $rightKey, [
        'query' => 'encre',
        'fuzzy' => true,
    ]);
    $assert(($fuzzy['row_count'] ?? 0) === 1, 'fuzzy encre matches Encore');

    $shallow = $comparer->compare($leftKey, $rightKey, [
        'query' => 'Plan',
        'deep' => false,
    ]);
    $assert(($shallow['row_count'] ?? 0) === 0, 'shallow Plan does not match file names');

    $deep = $comparer->compare($leftKey, $rightKey, [
        'query' => 'Plan',
        'deep' => true,
    ]);
    $assert(($deep['row_count'] ?? 0) === 2, 'deep Plan matches Encore pdf and Shared App vsdx');

    $namesOnly = $comparer->compare($leftKey, $rightKey, [
        'query' => 'Plan',
        'match_scope' => 'names',
        'deep' => true,
    ]);
    $assert(($namesOnly['row_count'] ?? 0) === 0, 'names scope ignores file names');

    $filesOnly = $comparer->compare($leftKey, $rightKey, [
        'query' => 'Plan',
        'match_scope' => 'files',
    ]);
    $assert(($filesOnly['row_count'] ?? 0) === 2, 'files scope finds Plan in nested files');

    $person = $comparer->compare($leftKey, $rightKey, ['query' => 'person:alice']);
    $assert(($person['row_count'] ?? 0) === 1, 'person:alice matches Encore');

    $path = $comparer->compare($leftKey, $rightKey, ['query' => 'path:drawings']);
    $assert(($path['row_count'] ?? 0) === 1, 'path:drawings matches Shared App');
    $assert(($path['rows'][0]['name_key'] ?? '') === 'shared app', 'path:drawings row is Shared App');

    $visio = $comparer->compare($leftKey, $rightKey, ['query' => 'type:visio']);
    $assert(($visio['row_count'] ?? 0) === 1, 'type:visio matches Shared App');

    $emptyAlpha = $comparer->compare($leftKey, $rightKey, ['query' => 'has:empty Alpha']);
    $assert(($emptyAlpha['row_count'] ?? 0) === 1, 'has:empty Alpha matches Alpha Only');

    $tagged = $comparer->compare($leftKey, $rightKey, ['query' => 'tag:priority']);
    $assert(($tagged['row_count'] ?? 0) === 1, 'tag:priority matches Encore');
} finally {
    $cleanup();
}

echo "passed={$passed} failed={$failed}\n";
if ($failed > 0) {
    echo "FAIL\n";
    exit(1);
}

echo "OK\n";
