<?php

declare(strict_types=1);

/**
 * Integration check for Ticket Dossier ZIP export names.
 *
 * Creates a temporary dossier, exports it as a ZIP, asserts the ZIP entries
 * keep the file names shown in the dossier file list (not the internal
 * stored_* names), then imports the ZIP back and asserts the names and bytes
 * survive the round trip. The temporary projects are removed in finally.
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/public/ticket-dossier/includes/config.php';
require_once $root . '/public/ticket-dossier/includes/security.php';
require_once $root . '/public/ticket-dossier/includes/db.php';
require_once $root . '/public/ticket-dossier/includes/helpers.php';
require_once $root . '/public/ticket-dossier/includes/layout.php';
require_once $root . '/public/ticket-dossier/includes/parsers/DdrJsonParser.php';
require_once $root . '/public/ticket-dossier/includes/parsers/ServicenowPdfParser.php';
require_once $root . '/public/ticket-dossier/includes/parsers/ServicenowTaskPacketParser.php';
require_once $root . '/public/ticket-dossier/includes/FileClassifier.php';
require_once $root . '/public/ticket-dossier/includes/ProjectRepository.php';
require_once $root . '/public/ticket-dossier/includes/ProjectImporter.php';
require_once $root . '/public/ticket-dossier/includes/DossierFileManager.php';
require_once $root . '/public/ticket-dossier/includes/ProjectPackager.php';

$pdfFixture = $root . '/public/ticket-dossier/sample/pm_project.pdf';
$jsonFixture = $root . '/public/ticket-dossier/sample/DDR_DDR0005151.json';

$projectId = 0;
$importedId = 0;
$zipPaths = [];
$failures = 0;
$tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'td-verify-zip-' . bin2hex(random_bytes(4));

$assert = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

/**
 * Each row is a file as the dossier file list shows it: kind, the name shown
 * in the list, the internal disk name it is stored under today, the ZIP entry
 * the export must produce, and the name the importer must restore.
 *
 * @var list<array{kind: string, original: string, stored: string, source: string, entry: string, restored: string}>
 */
$fixtures = [
    [
        'kind' => 'demand',
        'original' => 'DMND0006284/Perioperative Assessment (1).pdf',
        'stored' => 'att_00000000000001.pdf',
        'source' => $pdfFixture,
        'entry' => 'DMND0006284/Perioperative Assessment (1).pdf',
        'restored' => 'DMND0006284/Perioperative Assessment (1).pdf',
    ],
    [
        'kind' => 'story',
        'original' => 'STRY0059053FibroScan Gateway 2.0 Pre-requisites.pdf',
        'stored' => 'story_00000000000002.pdf',
        'source' => $pdfFixture,
        'entry' => 'STRY0059053FibroScan Gateway 2.0 Pre-requisites.pdf',
        'restored' => 'STRY0059053FibroScan Gateway 2.0 Pre-requisites.pdf',
    ],
    [
        'kind' => 'task',
        'original' => 'TASK7423021/Perioperative Assessment (1).pdf',
        'stored' => 'att_00000000000003.pdf',
        'source' => $pdfFixture,
        'entry' => 'TASK7423021/Perioperative Assessment (1).pdf',
        'restored' => 'TASK7423021/Perioperative Assessment (1).pdf',
    ],
    [
        'kind' => 'ddr',
        'original' => 'DDR_DDR0005151.json',
        'stored' => 'ddr_00000000000004.json',
        'source' => $jsonFixture,
        'entry' => 'DDR_DDR0005151.json',
        'restored' => 'DDR_DDR0005151.json',
    ],
    [
        // Same display name as the demand file: the ticket prefix keeps it unique.
        'kind' => 'attachment',
        'original' => 'DMND0006284/Perioperative Assessment (1).pdf',
        'stored' => 'att_00000000000005.pdf',
        'source' => $pdfFixture,
        'entry' => 'DMND0006284/Perioperative Assessment (1) (2).pdf',
        'restored' => 'DMND0006284/Perioperative Assessment (1).pdf',
    ],
    [
        // Path traversal in a legacy name is neutralised, not exported.
        'kind' => 'attachment',
        'original' => '../../evil.pdf',
        'stored' => 'att_00000000000006.pdf',
        'source' => $pdfFixture,
        'entry' => 'evil.pdf',
        'restored' => 'evil.pdf',
    ],
];

/**
 * @return array{entries: list<string>, project: array<string, mixed>}
 */
$readZip = static function (string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the exported ZIP.');
    }
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (is_string($name) && $name !== '') {
            $entries[] = $name;
        }
    }
    $projectJson = (string) ($zip->getFromName('projects/0001/project.json') ?: '');
    $zip->close();
    $project = json_decode($projectJson, true);

    return ['entries' => $entries, 'project' => is_array($project) ? $project : []];
};

try {
    foreach ([$pdfFixture, $jsonFixture] as $fixture) {
        if (!is_file($fixture)) {
            throw new RuntimeException('Missing fixture: ' . $fixture);
        }
    }

    $projectId = ProjectRepository::create([
        'title' => 'ZIP name verification',
        'vendor' => 'Verify Vendor',
        'demand_number' => 'DMND0006284',
        'story_number' => 'STRY0059053',
        'task_number' => 'TASK7423021',
        'ddr_number' => 'DDR0005151',
        'demand_state' => 'Approved',
        'story_state' => 'In Progress',
        'task_state' => 'Open',
        'ddr_state' => 'Completed',
        'sources' => ['ddr' => true, 'demand' => true, 'story' => true, 'task' => true],
        'parsed' => ['overview' => ['title' => 'ZIP name verification', 'vendor' => 'Verify Vendor']],
        'owner_user_id' => 0,
        'owner_username' => 'verify-zip-names',
        'owner_display_name' => 'Verify ZIP Names',
        'owner_auth_source' => 'local',
    ], []);
    $assert($projectId > 0, 'temporary dossier created');

    $storageDir = TD_STORAGE_DIR . '/' . $projectId;
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Could not create the temporary storage folder.');
    }

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO project_files (project_id, kind, original_name, stored_name, size_bytes, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($fixtures as $fixture) {
        $dest = $storageDir . '/' . $fixture['stored'];
        if (!copy($fixture['source'], $dest)) {
            throw new RuntimeException('Could not copy the fixture for ' . $fixture['original']);
        }
        $stmt->execute([
            $projectId,
            $fixture['kind'],
            $fixture['original'],
            $fixture['stored'],
            (int) filesize($dest),
            nowUtc(),
        ]);
    }
    $assert(count(ProjectRepository::filesFor($projectId)) === count($fixtures), 'dossier file rows stored');

    $built = ProjectPackager::buildZip($projectId);
    $zipPaths[] = $built['path'];
    $assert(
        preg_match('/^ticket-dossier-\d+-[A-Za-z0-9._-]+\.zip$/', $built['filename']) === 1,
        'export filename is ticket-dossier-<id>-<title>.zip (' . $built['filename'] . ')'
    );

    $first = $readZip($built['path']);
    $entries = $first['entries'];
    $assert(in_array('manifest.json', $entries, true), 'manifest.json exported');
    $assert(in_array('projects/0001/project.json', $entries, true), 'project.json exported');
    $assert(count($entries) === count($fixtures) + 2, 'ZIP has one entry per dossier file (' . count($entries) . ')');

    foreach ($fixtures as $fixture) {
        $assert(
            in_array('projects/0001/files/' . $fixture['entry'], $entries, true),
            'ZIP entry keeps the dossier name: ' . $fixture['entry']
        );
        $assert(
            !in_array('projects/0001/files/' . $fixture['stored'], $entries, true),
            'ZIP does not use the internal stored name: ' . $fixture['stored']
        );
    }

    $manifestFiles = is_array($first['project']['files'] ?? null) ? $first['project']['files'] : [];
    $assert(count($manifestFiles) === count($fixtures), 'project.json lists every dossier file');
    $manifestByOriginal = [];
    foreach ($manifestFiles as $row) {
        $manifestByOriginal[(string) ($row['original_name'] ?? '')][] = (string) ($row['archive_name'] ?? '');
    }
    $assert(
        ($manifestByOriginal['DMND0006284/Perioperative Assessment (1).pdf'] ?? []) === [
            'DMND0006284/Perioperative Assessment (1).pdf',
            'DMND0006284/Perioperative Assessment (1) (2).pdf',
        ],
        'project.json records the de-duplicated archive name per file'
    );
    $assert(
        ($manifestByOriginal['DDR_DDR0005151.json'] ?? []) === ['DDR_DDR0005151.json'],
        'project.json keeps the DDR file name'
    );

    $again = ProjectPackager::buildZip($projectId);
    $zipPaths[] = $again['path'];
    $assert($readZip($again['path'])['entries'] === $entries, 'exporting twice gives identical entry names');

    $imported = ProjectPackager::import($built['path'], [
        'id' => 0,
        'username' => 'verify-zip-names',
        'display_name' => 'Verify ZIP Names',
        'auth_source' => 'local',
    ]);
    $importedId = (int) $imported['last_id'];
    $assert($imported['imported'] === 1, 'exported ZIP imports back');
    $assert($imported['warnings'] === [], 'round trip reports no warnings');

    $restored = $importedId > 0 ? ProjectRepository::filesFor($importedId) : [];
    $assert(count($restored) === count($fixtures), 'every dossier file restored');

    $expectedNames = [];
    foreach ($fixtures as $fixture) {
        $expectedNames[] = $fixture['restored'];
    }
    sort($expectedNames);
    $restoredNames = array_map(
        static fn (array $row): string => (string) $row['original_name'],
        $restored
    );
    sort($restoredNames);
    $assert($restoredNames === $expectedNames, 'restored names match the exported dossier names');

    $expectedHashes = [];
    foreach ($fixtures as $fixture) {
        $expectedHashes[] = (string) md5_file($fixture['source']);
    }
    sort($expectedHashes);
    $restoredHashes = [];
    foreach ($restored as $row) {
        $path = TD_STORAGE_DIR . '/' . $importedId . '/' . (string) $row['stored_name'];
        $restoredHashes[] = is_file($path) ? (string) md5_file($path) : '';
    }
    sort($restoredHashes);
    $assert($restoredHashes === $expectedHashes, 'restored file bytes match the originals');
    // Malicious or unrecognised entries must still be rejected on import.
    $unsafeEntries = [
        'projects/0001/files/../../evil.pdf',
        'projects/0001/files/C:evil.pdf',
        'projects/0001/files/evil.exe',
        'projects/0001/files/a/b/c/d/evil.pdf',
        'projects/../0001/project.json',
    ];
    $unsafeManifest = json_encode([
        'format' => ProjectPackager::FORMAT,
        'kind' => 'project',
        'project_count' => 1,
    ], JSON_UNESCAPED_SLASHES) ?: '{}';
    $unsafeProject = json_encode([
        'title' => 'Unsafe entry verification',
        'files' => [[
            'kind' => 'demand',
            'original_name' => 'evil.pdf',
            'archive_name' => 'evil.pdf',
            'stored_name' => 'demand_probe.pdf',
            'size_bytes' => 4,
        ]],
    ], JSON_UNESCAPED_SLASHES) ?: '{}';

    foreach ($unsafeEntries as $unsafeEntry) {
        $probePath = rtrim(sys_get_temp_dir(), '/\\') . '/td-verify-unsafe-' . bin2hex(random_bytes(4)) . '.zip';
        $probe = new ZipArchive();
        $probe->open($probePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $probe->addFromString('manifest.json', $unsafeManifest);
        $probe->addFromString('projects/0001/project.json', $unsafeProject);
        $probe->addFromString($unsafeEntry, 'data');
        $probe->close();
        $zipPaths[] = $probePath;

        $rejected = false;
        $probeId = 0;
        try {
            $probeId = (int) ProjectPackager::import($probePath, null)['last_id'];
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        if ($probeId > 0) {
            ProjectRepository::delete($probeId);
        }
        $assert($rejected, 'unsafe ZIP entry rejected on import: ' . $unsafeEntry);
    }
} catch (Throwable $e) {
    $failures++;
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . PHP_EOL);
} finally {
    if ($importedId > 0) {
        ProjectRepository::delete($importedId);
    }
    if ($projectId > 0) {
        ProjectRepository::delete($projectId);
    }
    foreach ($zipPaths as $zipPath) {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
    }
    if (is_dir($tempDir)) {
        @rmdir($tempDir);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " check(s) failed.\n");
    exit(1);
}

echo "All ticket dossier ZIP export name checks passed.\n";


