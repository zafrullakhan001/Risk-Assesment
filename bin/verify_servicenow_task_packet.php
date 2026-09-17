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
require_once $root . '/public/ticket-dossier/includes/ProjectImporter.php';

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
$dedupedRelated = uniqueRelatedRecords([
    ['parent' => 'TASK7409837', 'child' => 'STRY0058970', 'type' => 'Contains::Task of'],
    ['parent' => 'TASK7409837', 'child' => 'STRY0058970', 'type' => 'Contains::Task of'],
    ['parent' => 'DMND0006626', 'child' => 'STRY0058970', 'type' => 'Contains::Task of'],
    ['parent' => 'DDR0005151', 'child' => 'STRY0058970', 'type' => 'Contains::Task of'],
    ['parent' => 'STRY0058970', 'child' => 'SPNT0011184', 'type' => 'Reference:parent'],
    ['parent' => 'DMND0006626', 'child' => 'STRY0058970', 'type' => 'Contains::Task of'],
    ['parent' => 'DDR0005151', 'child' => 'STRY0058970', 'type' => 'Contains::Task of'],
]);
$assert(count($dedupedRelated) === 4, 'related records drop exact duplicates');
$assert(array_keys($dedupedRelated) === [0, 2, 3, 4], 'related records keep the first index of each pair');
$assert(($parsed['packet_meta']['attachment_count'] ?? 0) === 2, 'attachment_count meta');
$assert(($parsed['instance'] ?? '') === 'https://example.service-now.com', 'packet instance origin');
$assert(($parsed['task']['table'] ?? '') === 'sc_task', 'task section keeps table');
$assert(($parsed['story']['sys_id'] ?? '') === 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'story sys_id');
$assert(
    servicenowInstanceOriginFromParsed($parsed) === 'https://example.service-now.com',
    'instance origin from parsed packet'
);
$storyUrl = servicenowRecordUrl(
    (string) $parsed['instance'],
    (string) $parsed['story']['number'],
    (string) $parsed['story']['sys_id'],
    (string) $parsed['story']['table'],
    'story'
);
$assert(
    $storyUrl === 'https://example.service-now.com/nav_to.do?uri=rm_story.do?sys_id=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    'story ServiceNow URL uses sys_id'
);
$demandUrl = servicenowRecordUrl('https://example.service-now.com', 'DMND0009999', '', '', 'demand');
$assert(
    $demandUrl === 'https://example.service-now.com/nav_to.do?uri=dmn_demand.do?sysparm_query=number=DMND0009999',
    'demand ServiceNow URL falls back to number query'
);
$ddrUrl = servicenowRecordUrl('https://example.service-now.com', 'DDR0005151', '', '', 'ddr');
$assert(
    $ddrUrl === 'https://example.service-now.com/nav_to.do?uri=sn_tprm_dd_request.do?sysparm_query=number=DDR0005151',
    'DDR ServiceNow URL uses diligence table'
);
$assert(servicenowRecordUrl('javascript:alert(1)', 'TASK0001234') === '', 'rejects non-http instance');
$assert(servicenowRecordUrl('https://example.service-now.com', 'INC0001') === '', 'rejects non ticket numbers');

$sections = availableSections($parsed);
$assert(in_array('related', $sections, true), 'availableSections includes related');
$assert(in_array('task', $sections, true), 'availableSections includes task');

try {
    $origin = ServiceNowBrowserSync::normalizeInstanceOrigin('example.service-now.com');
    $assert($origin === 'https://example.service-now.com', 'normalizeInstanceOrigin adds https');
    $task = ServiceNowBrowserSync::normalizeTaskNumber('task0001');
    $assert($task === 'TASK0001', 'normalizeTaskNumber uppercases');
    $assert(ServiceNowBrowserSync::normalizeTicketNumber('dmnd42') === 'DMND42', 'normalizeTicketNumber accepts Demand');
    $assert(ServiceNowBrowserSync::normalizeTicketNumber('STRY9') === 'STRY9', 'normalizeTicketNumber accepts Story');
    $assert(ServiceNowBrowserSync::normalizeTicketNumber('DDR1') === 'DDR1', 'normalizeTicketNumber accepts DDR');
    $assert(ServiceNowBrowserSync::normalizeTicketNumber('prj100') === 'PRJ100', 'normalizeTicketNumber accepts Project');
    $assert(ServiceNowBrowserSync::tableForTicketNumber('DMND42') === 'dmn_demand', 'tableForTicketNumber demand');
    $assert(ServiceNowBrowserSync::tableForTicketNumber('STRY9') === 'rm_story', 'tableForTicketNumber story');
    $assert(ServiceNowBrowserSync::tableForTicketNumber('DDR1') === 'sn_tprm_dd_request', 'tableForTicketNumber ddr');
    $assert(ServiceNowBrowserSync::tableForTicketNumber('PRJ100') === 'pm_project', 'tableForTicketNumber project');
    $assert(ServiceNowBrowserSync::tableForTicketNumber('TASK1') === 'task', 'tableForTicketNumber task');
} catch (Throwable $e) {
    $assert(false, 'ServiceNowBrowserSync normalize: ' . $e->getMessage());
}

try {
    ServiceNowBrowserSync::normalizeTicketNumber('TASK-001');
    $assert(false, 'normalizeTicketNumber should reject hyphenated numbers');
} catch (Throwable) {
    $assert(true, 'normalizeTicketNumber rejects hyphenated numbers');
}

try {
    ServiceNowBrowserSync::normalizeTicketNumber('12345');
    $assert(false, 'normalizeTicketNumber should reject digits-only');
} catch (Throwable) {
    $assert(true, 'normalizeTicketNumber rejects digits-only');
}

$prjUrl = servicenowRecordUrl('https://example.service-now.com', 'PRJ0001', '', '', 'project');
$assert(
    $prjUrl === 'https://example.service-now.com/nav_to.do?uri=pm_project.do?sysparm_query=number=PRJ0001',
    'project ServiceNow URL uses pm_project table'
);
$assert(servicenowKindFromNumber('PRJ0001') === 'project', 'servicenowKindFromNumber recognizes PRJ');

// Demand-root packet: root fills demand slot; TASK/STRY still classified; do not force root into task.
$demandRootPacket = [
    'format' => ServicenowTaskPacketParser::FORMAT,
    'instance' => 'https://example.service-now.com',
    'root_number' => 'DMND0009999',
    'root_sys_id' => 'cccccccccccccccccccccccccccccccc',
    'relationships' => [
        [
            'parent' => 'DMND0009999',
            'child' => 'STRY0005678',
            'type' => 'Related',
            'parent_sys_id' => 'cccccccccccccccccccccccccccccccc',
            'child_sys_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ],
        [
            'parent' => 'STRY0005678',
            'child' => 'TASK0001234',
            'type' => 'Related',
            'parent_sys_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'child_sys_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
    ],
    'tickets' => [
        [
            'number' => 'DMND0009999',
            'sys_id' => 'cccccccccccccccccccccccccccccccc',
            'sys_class_name' => 'dmn_demand',
            'table' => 'dmn_demand',
            'state' => 'Open',
            'short_description' => 'Demand root',
            'description' => 'Demand as packet root',
            'fields' => [],
            'journal' => [],
            'attachments' => [],
        ],
        [
            'number' => 'STRY0005678',
            'sys_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'sys_class_name' => 'rm_story',
            'table' => 'rm_story',
            'state' => 'Work in Progress',
            'short_description' => 'Story',
            'description' => '',
            'fields' => [],
            'journal' => [],
            'attachments' => [],
        ],
        [
            'number' => 'TASK0001234',
            'sys_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'sys_class_name' => 'sc_task',
            'table' => 'sc_task',
            'state' => 'Open',
            'short_description' => 'Task',
            'description' => '',
            'fields' => [],
            'journal' => [],
            'attachments' => [],
        ],
        [
            'number' => 'PRJ0007777',
            'sys_id' => 'dddddddddddddddddddddddddddddddd',
            'sys_class_name' => 'pm_project',
            'table' => 'pm_project',
            'state' => 'Pending',
            'short_description' => 'Linked project',
            'description' => '',
            'fields' => [],
            'journal' => [],
            'attachments' => [],
        ],
    ],
];
$demandParsed = ServicenowTaskPacketParser::parseArray($demandRootPacket);
$assert(($demandParsed['root_number'] ?? '') === 'DMND0009999', 'demand-root root_number');
$assert(($demandParsed['packet_meta']['root_kind'] ?? '') === 'demand', 'demand-root meta kind');
$assert(is_array($demandParsed['demand'] ?? null) && ($demandParsed['demand']['number'] ?? '') === 'DMND0009999', 'demand-root fills demand slot');
$assert(is_array($demandParsed['story'] ?? null) && ($demandParsed['story']['number'] ?? '') === 'STRY0005678', 'demand-root maps story');
$assert(is_array($demandParsed['task'] ?? null) && ($demandParsed['task']['number'] ?? '') === 'TASK0001234', 'demand-root maps task without forcing demand into task');
$assert(
    is_array($demandParsed['related_tickets'] ?? null)
    && count($demandParsed['related_tickets']) === 1
    && ($demandParsed['related_tickets'][0]['number'] ?? '') === 'PRJ0007777',
    'demand-root keeps PRJ in related_tickets'
);
$assert(($demandParsed['related_tickets'][0]['kind'] ?? '') === 'project', 'related PRJ section kind is project');

$projectRootPacket = $demandRootPacket;
$projectRootPacket['root_number'] = 'PRJ0007777';
$projectRootPacket['root_sys_id'] = 'dddddddddddddddddddddddddddddddd';
foreach ($projectRootPacket['tickets'] as &$projectRootTicket) {
    if (($projectRootTicket['number'] ?? '') === 'PRJ0007777') {
        // pm_project instances commonly expose the project name in Name rather
        // than Short description.
        $projectRootTicket['short_description'] = '';
        $projectRootTicket['fields'] = ['Name' => 'Enterprise Imaging Upgrade'];
    }
}
unset($projectRootTicket);
$projectParsed = ServicenowTaskPacketParser::parseArray($projectRootPacket);
$projectMeta = ProjectImporter::deriveProjectMeta($projectParsed, null);
$assert(($projectParsed['packet_meta']['root_kind'] ?? '') === 'project', 'project-root meta kind');
$assert(($projectParsed['overview']['title'] ?? '') === 'Enterprise Imaging Upgrade', 'project-root parser uses Name field');
$assert(($projectMeta['title'] ?? '') === 'Enterprise Imaging Upgrade', 'project-root dossier uses project name');
$projectMetaFromUntitled = ProjectImporter::deriveProjectMeta($projectParsed, 'Untitled Project');
$assert(
    ($projectMetaFromUntitled['title'] ?? '') === 'Enterprise Imaging Upgrade',
    'project-root refresh replaces Untitled Project'
);

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
