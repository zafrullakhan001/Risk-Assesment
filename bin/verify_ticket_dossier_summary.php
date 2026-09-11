<?php

declare(strict_types=1);

/**
 * CLI verifier for Ticket Dossier Product & Design Summary mapping.
 *
 * Usage: php bin/verify_ticket_dossier_summary.php
 */

require_once __DIR__ . '/../public/ticket-dossier/includes/helpers.php';
require_once __DIR__ . '/../public/ticket-dossier/includes/ProjectSummaryMapper.php';

$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "PASS  {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$message}\n";
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) {
        assertTrue(true, $message);
        return;
    }
    assertTrue(false, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

$project = [
    'id' => 1,
    'title' => 'LogTag Temperature Monitoring',
    'vendor' => 'LogTag Recorders',
    'demand_number' => 'DMND0001111',
    'ddr_number' => 'DDR0005151',
];

$parsed = [
    'overview' => [
        'title' => 'LogTag Temperature Monitoring',
        'vendor' => 'LogTag Recorders',
        'description' => 'Monitor cold-chain temperatures across pharmacies.',
        'business_case' => 'Reduce spoilage and improve compliance.',
    ],
    'demand' => [
        'description' => 'Demand description fallback',
        'business_case' => 'Demand business case',
        'fields' => [
            'Product(s) Name' => 'LogTag Cloud',
            'Business Owner' => 'Jane Business',
            'AIT Product Owner' => 'Pat Product',
            'Impacted End Users' => '120 pharmacy staff',
            'Facility' => 'Central Pharmacy',
            'Division/Region' => 'Northeast',
            'Primary goal' => 'Validate temperature alerting in 90 days',
            'Goals & Benefits' => 'Faster recall response',
            'Exception Status' => 'None known',
            'QP-Go-Live' => '2026-10-15',
            'Core Level' => 'Core 4',
            'AI Enabled Technologies' => 'No',
            'Impacted Business Applications' => 'Epic Willow; LIS gateway',
            'Submitted By' => 'Alex Submitter',
            'Funding Status' => 'Approved',
        ],
    ],
    'story' => [
        'fields' => [
            'Acceptance criteria' => 'Alerts reach on-call within 5 minutes',
            'Validation plan' => 'Pilot two sites then expand',
        ],
    ],
    'task' => [
        'fields' => [
            'Assignee' => 'Ops Desk',
        ],
    ],
    'ddr' => [
        'description' => 'DDR solution description',
        'fields' => [
            'Engagement' => 'LogTag Engagement',
            'Core Level' => 'Core 4',
            'TPRM Recommendation' => 'Proceed',
            'TPRM Review Date' => '2026-08-01',
            'Technology Recommendation' => 'Approved with conditions',
            'TR Review Date' => '2026-08-05',
            'Made SLA' => 'true',
            'SLA due' => '2026-09-01',
            'Is AI Enabled Technologies' => 'false',
        ],
    ],
    'vendor' => [
        'fields' => [
            'Name' => 'LogTag Recorders',
            'Street' => '29 Pembroke Road',
            'City' => 'Portsmouth',
            'Country' => 'USA',
            'Zip / postal code' => '03301',
        ],
    ],
    'assessments' => [
        'external' => [
            [
                'name' => 'External Security',
                'questionnaires' => [
                    [
                        'name' => 'Auth',
                        'instances' => [
                            [
                                'qa' => [
                                    [
                                        'question' => 'B2 - 1.3 Which AD groups are required for SSO?',
                                        'answer' => 'APP-LOGTAG-USERS',
                                    ],
                                    [
                                        'question' => 'A2 - Please provide a URL to the website',
                                        'answer' => 'https://vendor.example/logtag',
                                    ],
                                    [
                                        'question' => 'K1 - Does the solution integrate via HL7, FHIR, or Cerner Millenium?',
                                        'answer' => 'HL7 ADT feed to Epic',
                                    ],
                                    [
                                        'question' => 'Does the solution support CLSI LIS02-A2?',
                                        'answer' => 'Yes, optional LIS bridge',
                                    ],
                                    [
                                        'question' => 'F2 - 1.1 What supported OS versions are required?',
                                        'answer' => 'Windows Server 2022; RHEL 9',
                                    ],
                                    [
                                        'question' => 'F2 - 1.5 Is Bomgar / vendor PRA remote access required?',
                                        'answer' => 'Yes, scheduled PRA sessions',
                                    ],
                                    [
                                        'question' => 'A3 - Please attach installation guides or a solution deployment document',
                                        'answer' => 'Install guide v3 attached in DDR',
                                    ],
                                    [
                                        'question' => 'G1 - 1.4 Are isolated VLANs required?',
                                        'answer' => 'Yes, IoT VLAN 214',
                                    ],
                                    [
                                        'question' => 'A1 - Describe the solution technology design',
                                        'answer' => 'Cloud SaaS with on-prem collectors',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'internal' => [],
    ],
];

$summary = ProjectSummaryMapper::map($project, $parsed);

assertSame('LogTag Cloud', $summary['product_summary']['product_name']['value'], 'Product Name prefers demand Product(s) Name');
assertSame(true, $summary['product_summary']['product_name']['available'], 'Product Name is available');
assertSame('Monitor cold-chain temperatures across pharmacies.', $summary['product_summary']['purpose']['value'], 'Purpose uses overview description');
assertSame(ProjectSummaryMapper::MISSING, $summary['product_summary']['app_short_name']['value'], 'App Short Name missing when absent');
assertSame(false, $summary['product_summary']['app_short_name']['available'], 'App Short Name marked unavailable');

assertSame('Jane Business', $summary['owners']['business_owner']['value'], 'Business Owner from demand');
assertSame('Pat Product', $summary['owners']['product_owner']['value'], 'Product Owner from demand');
assertSame(ProjectSummaryMapper::MISSING, $summary['owners']['support_owner']['value'], 'Support Owner missing when absent');

assertTrue(str_contains($summary['third_party_review']['tprm']['value'], 'Proceed'), 'TPRM includes recommendation');
assertTrue(str_contains($summary['third_party_review']['technology_review']['value'], 'Approved with conditions'), 'Technology Review mapped');
assertSame(ProjectSummaryMapper::MISSING, $summary['third_party_review']['grc_profile']['value'], 'GRC profile missing when no URL');

assertSame('APP-LOGTAG-USERS', $summary['key_questions'][0]['value'], 'AD groups from questionnaire');
assertSame('https://vendor.example/logtag', $summary['vendor_commitments']['items'][0]['value'], 'Vendor website from QA A2');
assertSame(ProjectSummaryMapper::MISSING, $summary['vendor_commitments']['items'][1]['value'], 'Vendor support methods missing');
assertSame(ProjectSummaryMapper::MISSING, $summary['vendor_commitments']['items'][2]['value'], 'Linux super user accounts missing without explicit answer');

assertSame('2026-10-15', array_values(array_filter(
    $summary['design_includes'],
    static fn (array $item): bool => $item['label'] === 'Go-Live date'
))[0]['value'] ?? '', 'Go-Live date from QP-Go-Live');

$labels = array_map(static fn (array $item): string => $item['label'], $summary['design_includes']);
assertTrue(!in_array('Storage requirements (SAN/NAS)', $labels, true), 'Storage requirements omitted');
assertTrue(!in_array('Storage requirements', $labels, true), 'No Storage requirements label present');
assertTrue(in_array('SSO requirements', $labels, true), 'SSO checklist retained');
assertTrue(in_array('Server specs (RAM, CPU, OS)', $labels, true), 'Server specs checklist retained');

$server = array_values(array_filter(
    $summary['design_includes'],
    static fn (array $item): bool => $item['label'] === 'Server specs (RAM, CPU, OS)'
))[0] ?? null;
assertTrue(is_array($server) && str_contains($server['value'], 'Windows Server 2022'), 'Server specs from OS QA');

assertTrue(str_contains($summary['integrations'][0]['value'], 'HL7'), 'EMR/EPIC from K1 QA');
assertTrue(str_contains($summary['integrations'][1]['value'], 'LIS'), 'LIS from questionnaire');

// Precedence: demand product name wins over project title / DDR engagement.
$emptyDemandName = $parsed;
$emptyDemandName['demand']['fields']['Product(s) Name'] = '';
$emptyDemandName['demand']['fields']['Application Name'] = '';
$emptyDemandName['demand']['fields']['Name'] = '';
$emptyDemandName['demand']['fields']['Short Description'] = '';
$fallback = ProjectSummaryMapper::map($project, $emptyDemandName);
assertSame('LogTag Engagement', $fallback['product_summary']['product_name']['value'], 'Product Name falls back to DDR Engagement');

// HTML-safety: mapper returns raw text; view layer escapes. Ensure mapper does not pre-escape.
$xssParsed = $parsed;
$xssParsed['demand']['fields']['Business Owner'] = '<script>alert(1)</script>';
$xssSummary = ProjectSummaryMapper::map($project, $xssParsed);
assertSame('<script>alert(1)</script>', $xssSummary['owners']['business_owner']['value'], 'Mapper keeps raw text for view escaping');

// Completely empty dossier still returns template shape with missing markers.
$empty = ProjectSummaryMapper::map(['title' => 'Empty'], []);
assertSame(ProjectSummaryMapper::MISSING, $empty['product_summary']['purpose']['value'], 'Empty dossier purpose missing');
assertSame(11, count($empty['design_includes']), 'Empty dossier still has 11 checklist items (storage omitted)');
assertSame(3, count($empty['vendor_commitments']['items']), 'Vendor commitments always has 3 collect items');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "\nOK — Ticket Dossier summary mapper verified.\n";
exit(0);
