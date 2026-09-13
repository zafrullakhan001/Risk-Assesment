<?php

declare(strict_types=1);

/**
 * Offline verify for ServiceNow task packet parser (no live ServiceNow).
 *
 * Usage: php bin/verify_servicenow_task_packet.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/public/ticket-dossier/includes/config.php';
require_once $root . '/public/ticket-dossier/includes/security.php';
require_once $root . '/public/ticket-dossier/includes/helpers.php';
require_once $root . '/public/ticket-dossier/includes/parsers/ServicenowTaskPacketParser.php';
require_once $root . '/public/ticket-dossier/includes/FileClassifier.php';
require_once $root . '/public/ticket-dossier/includes/parsers/ServicenowPdfParser.php';
require_once $root . '/public/ticket-dossier/includes/parsers/DdrJsonParser.php';

use RiskAssessment\ServiceNow\ServiceNowBrowserSync;

$fixture = $root . '/public/ticket-dossier/sample/TASK0001234_packet.json';
if (!is_file($fixture)) {
    fwrite(STDERR, "Missing fixture: {$fixture}\n");
    exit(1);
}

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL {$message}\n";
};

$assert(ServicenowTaskPacketParser::looksLikePacket($fixture), 'looksLikePacket recognizes fixture');
$assert(FileClassifier::classify($fixture, 'TASK0001234.json') === 'packet', 'FileClassifier returns packet');

$parsed = ServicenowTaskPacketParser::parse($fixture);
$assert(($parsed['kind'] ?? '') === 'packet', 'kind is packet');
$assert(($parsed['root_number'] ?? '') === 'TASK0001234', 'root_number');
$assert(is_array($parsed['task'] ?? null), 'maps root task section');
$assert(($parsed['task']['number'] ?? '') === 'TASK0001234', 'task number');
$assert(is_array($parsed['story'] ?? null) && ($parsed['story']['number'] ?? '') === 'STRY0005678', 'maps first STRY to story');
$assert(is_array($parsed['demand'] ?? null) && ($parsed['demand']['number'] ?? '') === 'DMND0009999', 'maps first DMND to demand');
$assert(is_array($parsed['related_tickets'] ?? null) && count($parsed['related_tickets']) === 1, 'remaining related tickets');
$assert(($parsed['related_tickets'][0]['number'] ?? '') === 'TASK0002222', 'related TASK0002222 kept');
$assert(count($parsed['relationships'] ?? []) === 3, 'three relationships');
$assert(($parsed['packet_meta']['attachment_count'] ?? 0) === 2, 'attachment_count meta');

$sections = availableSections($parsed);
$assert(in_array('related', $sections, true), 'availableSections includes related');
$assert(in_array('task', $sections, true), 'availableSections includes task');

try {
    $origin = ServiceNowBrowserSync::normalizeInstanceOrigin('example.service-now.com');
    $assert($origin === 'https://example.service-now.com', 'normalizeInstanceOrigin adds https');
    $task = ServiceNowBrowserSync::normalizeTaskNumber('task0001');
    $assert($task === 'TASK0001', 'normalizeTaskNumber uppercases');
} catch (Throwable $e) {
    $assert(false, 'ServiceNowBrowserSync normalize: ' . $e->getMessage());
}

try {
    ServiceNowBrowserSync::normalizeTaskNumber('INC001');
    $assert(false, 'normalizeTaskNumber should reject INC');
} catch (Throwable) {
    $assert(true, 'normalizeTaskNumber rejects non-TASK');
}

$safe = ServiceNowBrowserSync::sanitizeStoredFilename('../evil/report.pdf');
$assert($safe === 'report.pdf', 'sanitizeStoredFilename strips path');

$ddrFixture = $root . '/public/ticket-dossier/sample/DDR_DDR0005151.json';
$largeDdrPath = sys_get_temp_dir() . '/DDR_DDR0005151.json.txt';
try {
    $largeDdr = json_decode((string) file_get_contents($ddrFixture), true, 512, JSON_THROW_ON_ERROR);
    $largeDdr['_verification_padding'] = str_repeat('x', 210000);
    file_put_contents($largeDdrPath, json_encode($largeDdr, JSON_THROW_ON_ERROR));
    $assert(filesize($largeDdrPath) > 200000, 'large DDR JSON/text fixture exceeds old detection limit');
    $assert(
        FileClassifier::classify($largeDdrPath, 'DDR_DDR0005151.json.txt') === 'ddr',
        'FileClassifier recognizes large DDR .json.txt content'
    );
    $largeParsed = DdrJsonParser::parse($largeDdrPath);
    $assert(($largeParsed['number'] ?? '') === 'DDR0005151', 'DdrJsonParser parses large DDR .json.txt content');
} catch (Throwable $e) {
    $assert(false, 'large DDR JSON/text verification: ' . $e->getMessage());
} finally {
    if (is_file($largeDdrPath)) {
        @unlink($largeDdrPath);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "\nAll ServiceNow task packet checks passed.\n";
exit(0);
