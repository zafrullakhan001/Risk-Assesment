<?php

declare(strict_types=1);

/**
 * Integration check for stored-file reparse and delete.
 *
 * Creates a temporary dossier in the local Ticket Dossier SQLite database and
 * removes it in finally.
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

$fixture = $root . '/public/ticket-dossier/sample/TASK0001234_packet.json';
$pdfFixture = $root . '/public/ticket-dossier/sample/sc_task.pdf';
$projectId = 0;
$failures = 0;

$assert = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

try {
    $packet = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
    $created = ProjectImporter::importServiceNowPacket($packet, [
        'owner_username' => 'verify-file-manager',
        'owner_display_name' => 'Verify File Manager',
        'owner_auth_source' => 'local',
    ], 'Temporary file manager verification');
    $projectId = (int) $created['project_id'];
    $assert($projectId > 0, 'temporary dossier created');

    if (!is_file($pdfFixture)) {
        throw new RuntimeException('Missing PDF fixture.');
    }
    $storageDir = TD_STORAGE_DIR . '/' . $projectId;
    $storedName = 'verify_' . bin2hex(random_bytes(5)) . '.pdf';
    if (!copy($pdfFixture, $storageDir . '/' . $storedName)) {
        throw new RuntimeException('Could not copy PDF fixture.');
    }

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO project_files (project_id, kind, original_name, stored_name, size_bytes, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $projectId,
        'task',
        'TASK0001234.pdf',
        $storedName,
        (int) filesize($pdfFixture),
        nowUtc(),
    ]);
    $fileId = (int) $db->lastInsertId();

    $reparse = DossierFileManager::reparseFiles($projectId, [$fileId]);
    $assert($reparse['parsed'] === 1, 'stored ticket PDF reparsed');

    $deleted = DossierFileManager::deleteFiles($projectId, [$fileId]);
    $assert($deleted['deleted'] === 1, 'selected stored file deleted');
    $assert(ProjectRepository::findFile($fileId, $projectId) === null, 'file row removed');

    $size = (int) filesize($pdfFixture);
    $insertCopy = static function (string $original, string $storedPrefix) use ($db, $projectId, $pdfFixture, $storageDir, $size): int {
        $storedName = $storedPrefix . '_' . bin2hex(random_bytes(4)) . '.pdf';
        if (!copy($pdfFixture, $storageDir . '/' . $storedName)) {
            throw new RuntimeException('Could not copy duplicate fixture.');
        }
        $stmt = $db->prepare(
            'INSERT INTO project_files (project_id, kind, original_name, stored_name, size_bytes, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$projectId, 'task', $original, $storedName, $size, nowUtc()]);

        return (int) $db->lastInsertId();
    };

    $keepId = $insertCopy('TASK0001/spec.pdf', 'keep');
    $dupExactId = $insertCopy('TASK0001/spec.pdf', 'dup_exact');
    $dupPrefixId = $insertCopy('STRY0002/spec.pdf', 'dup_prefix');
    $uniqueId = $insertCopy('notes.pdf', 'unique');

    $dupIds = DossierFileManager::duplicateFileIds(ProjectRepository::filesFor($projectId));
    $assert(in_array($dupExactId, $dupIds, true), 'exact name copy marked duplicate');
    $assert(in_array($dupPrefixId, $dupIds, true), 'same basename other ticket marked duplicate');
    $assert(!in_array($keepId, $dupIds, true), 'earliest copy kept');
    $assert(!in_array($uniqueId, $dupIds, true), 'different name not a duplicate');

    $removed = DossierFileManager::deleteDuplicateFiles($projectId);
    $assert($removed['deleted'] >= 2, 'duplicate files removed from dossier');
    $assert(ProjectRepository::findFile($keepId, $projectId) !== null, 'kept copy still present');
    $assert(ProjectRepository::findFile($dupExactId, $projectId) === null, 'exact duplicate deleted');
    $assert(ProjectRepository::findFile($dupPrefixId, $projectId) === null, 'prefix duplicate deleted');
    $assert(ProjectRepository::findFile($uniqueId, $projectId) !== null, 'unique file retained');

    $none = false;
    try {
        DossierFileManager::deleteDuplicateFiles($projectId);
    } catch (InvalidArgumentException) {
        $none = true;
    }
    $assert($none, 'removing dups with none left is rejected');
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

echo "All dossier file manager checks passed.\n";
