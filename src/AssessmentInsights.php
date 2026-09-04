<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Models\Assessment;

final class AssessmentInsights
{
    /**
     * @param array<string, array{action?: string, comment?: string}> $responses
     * @param array<string, array{status?: string, comment?: string, servicenow_links?: list<string>}|string> $findingStatuses
     * @return array{
     *   readiness: array{score: int, band: string, verdict: string, summary: string, auto_verdict?: string, auto_summary?: string, custom_verdict?: string, custom_summary?: string, is_custom?: bool},
     *   residual: array{high: int, risk: int, tbd: int, gap: int, open_findings: int},
     *   findings: list<array<string, mixed>>,
     *   owners: list<array{owner: string, total: int, high: int, risk: int, tbd: int, gap: int}>,
     *   timelines: list<array{lane: string, label: string, total: int, high: int, risk: int, tbd: int}>,
     *   blockers: list<array{type: string, label: string, count: int, filter_type: string, filter_value: string}>,
     *   evidence: array{total: int, covered: int, partial: int, missing: int, items: list<array{item: string, status: string, evidence: string}>},
     *   top_risks: list<array<string, string>>
     * }
     */
    public function build(Assessment $assessment, array $responses = [], array $findingStatuses = []): array
    {
        $items = array_merge($assessment->items, $assessment->dueDiligenceItems);
        $findings = $this->normalizeFindings($assessment->workbook['findings'] ?? [], $findingStatuses);
        $checklist = array_values($assessment->workbook['legend']['checklist'] ?? []);

        $high = 0;
        $risk = 0;
        $tbd = 0;
        $gap = 0;
        $missingOwner = 0;
        $missingTimeline = 0;
        $missingMitigation = 0;

        $ownerMap = [];
        $timelineMap = [
            'before_go_live' => ['lane' => 'before_go_live', 'label' => 'Before go-live', 'total' => 0, 'high' => 0, 'risk' => 0, 'tbd' => 0],
            'roadmap' => ['lane' => 'roadmap', 'label' => 'Roadmap / Q1 2028', 'total' => 0, 'high' => 0, 'risk' => 0, 'tbd' => 0],
            'ongoing' => ['lane' => 'ongoing', 'label' => 'Ongoing / recurring', 'total' => 0, 'high' => 0, 'risk' => 0, 'tbd' => 0],
            'intake' => ['lane' => 'intake', 'label' => 'At intake / design', 'total' => 0, 'high' => 0, 'risk' => 0, 'tbd' => 0],
            'unspecified' => ['lane' => 'unspecified', 'label' => 'Unspecified timeline', 'total' => 0, 'high' => 0, 'risk' => 0, 'tbd' => 0],
        ];

        $topRisks = [];

        foreach ($items as $item) {
            $status = Assessment::normalizeStatus($item['status'] ?? '');
            $riskLevel = Assessment::normalizeRiskLevel($item['risk_level'] ?? '');
            $owner = trim($item['owner'] ?? '');
            $timeline = trim($item['remediation_timeline'] ?? '');
            $mitigation = trim($item['mitigation'] ?? '');
            $key = AssessmentComparer::itemKey(
                (string) ($item['item_type'] ?? 'architecture'),
                (string) ($item['section'] ?? ''),
                (string) ($item['check'] ?? '')
            );
            $action = \RiskAssessment\Repositories\ItemResponseRepository::normalizeAction(
                (string) ($responses[$key]['action'] ?? 'open')
            );
            $addressed = \RiskAssessment\Repositories\ItemResponseRepository::isAddressed($action);

            if (Assessment::isElevatedRisk($riskLevel) && !$addressed) {
                $high++;
            }
            if ($status === 'Risk' && !$addressed) {
                $risk++;
            }
            if ($status === 'TBD' && !$addressed) {
                $tbd++;
            }
            if ($status === 'Gap' && !$addressed) {
                $gap++;
            }
            if ($owner === '' && !$addressed) {
                $missingOwner++;
            }
            if ($timeline === '' && !$addressed) {
                $missingTimeline++;
            }
            if ($mitigation === '' && in_array($status, ['Risk', 'Gap', 'TBD'], true) && !$addressed) {
                $missingMitigation++;
            }

            if ($addressed) {
                continue;
            }

            foreach ($this->splitOwners($owner) as $ownerName) {
                if (!isset($ownerMap[$ownerName])) {
                    $ownerMap[$ownerName] = ['owner' => $ownerName, 'total' => 0, 'high' => 0, 'risk' => 0, 'tbd' => 0, 'gap' => 0];
                }
                $ownerMap[$ownerName]['total']++;
                if (Assessment::isElevatedRisk($riskLevel)) {
                    $ownerMap[$ownerName]['high']++;
                }
                if ($status === 'Risk') {
                    $ownerMap[$ownerName]['risk']++;
                }
                if ($status === 'TBD') {
                    $ownerMap[$ownerName]['tbd']++;
                }
                if ($status === 'Gap') {
                    $ownerMap[$ownerName]['gap']++;
                }
            }

            $lane = $this->timelineLane($timeline);
            $timelineMap[$lane]['total']++;
            if (Assessment::isElevatedRisk($riskLevel)) {
                $timelineMap[$lane]['high']++;
            }
            if ($status === 'Risk') {
                $timelineMap[$lane]['risk']++;
            }
            if ($status === 'TBD') {
                $timelineMap[$lane]['tbd']++;
            }

            if (Assessment::isElevatedRisk($riskLevel) || $status === 'Risk' || $status === 'Decision Required') {
                $topRisks[] = $item;
            }
        }

        usort($topRisks, static function (array $a, array $b): int {
            $rank = static function (array $row): int {
                $level = Assessment::normalizeRiskLevel((string) ($row['risk_level'] ?? ''));
                return match ($level) {
                    'Critical' => 0,
                    'High' => 1,
                    default => 2,
                };
            };
            return $rank($a) <=> $rank($b);
        });
        $topRisks = array_slice($topRisks, 0, 5);

        $openFindings = 0;
        foreach ($findings as $finding) {
            if (($finding['status'] ?? 'Open') === 'Open') {
                $openFindings++;
            }
        }

        $score = 100;
        $score -= $high * 12;
        $score -= $risk * 5;
        $score -= $tbd * 6;
        $score -= $openFindings * 8;
        $score -= $missingOwner * 2;
        $score -= $missingTimeline * 2;
        $score = max(0, min(100, $score));

        if ($score >= 80 && $high === 0 && $openFindings === 0) {
            $band = 'ready';
            $verdict = 'Conditional go-live ready';
            $summary = sprintf(
                'Residual exposure is limited: %d Gap and %d TBD items remain, with no open High risks or governance exceptions.',
                $gap,
                $tbd
            );
        } elseif ($score >= 55) {
            $band = 'conditional';
            $verdict = 'Proceed only with controls';
            $summary = sprintf(
                'Do not treat as clear to go live yet: %d High risks, %d Risk-status checks, %d TBD items, and %d open exceptions need owners and closure dates.',
                $high,
                $risk,
                $tbd,
                $openFindings
            );
        } else {
            $band = 'blocked';
            $verdict = 'Not ready for go-live';
            $summary = sprintf(
                'Material blockers remain: %d High risks, %d Risk items, %d TBD decisions, and %d open exceptions must be mitigated or formally accepted first.',
                $high,
                $risk,
                $tbd,
                $openFindings
            );
        }

        $owners = array_values($ownerMap);
        usort($owners, static fn(array $a, array $b): int => $b['total'] <=> $a['total'] ?: $b['high'] <=> $a['high']);

        $timelines = array_values(array_filter($timelineMap, static fn(array $row): bool => $row['total'] > 0));

        $blockers = [];
        if ($risk > 0) {
            $blockers[] = ['type' => 'risk', 'label' => 'Risk items', 'count' => $risk, 'filter_type' => 'action_tab', 'filter_value' => 'risks'];
        }
        if ($gap > 0) {
            $blockers[] = ['type' => 'gap', 'label' => 'Gap items', 'count' => $gap, 'filter_type' => 'action_tab', 'filter_value' => 'gaps'];
        }
        if ($high > 0) {
            $blockers[] = ['type' => 'high', 'label' => 'High risk checks', 'count' => $high, 'filter_type' => 'action_tab', 'filter_value' => 'risks'];
        }
        if ($tbd > 0) {
            $blockers[] = ['type' => 'tbd', 'label' => 'TBD decisions', 'count' => $tbd, 'filter_type' => 'action_tab', 'filter_value' => 'tbd'];
        }
        if ($openFindings > 0) {
            $blockers[] = ['type' => 'exception', 'label' => 'Open exceptions', 'count' => $openFindings, 'filter_type' => 'action_tab', 'filter_value' => 'exceptions'];
        }
        if ($missingOwner > 0) {
            $blockers[] = ['type' => 'owner', 'label' => 'Missing owner', 'count' => $missingOwner, 'filter_type' => 'action_tab', 'filter_value' => 'exceptions'];
        }
        if ($missingTimeline > 0) {
            $blockers[] = ['type' => 'timeline', 'label' => 'Missing timeline', 'count' => $missingTimeline, 'filter_type' => 'action_tab', 'filter_value' => 'exceptions'];
        }
        if ($missingMitigation > 0) {
            $blockers[] = ['type' => 'mitigation', 'label' => 'Missing mitigation', 'count' => $missingMitigation, 'filter_type' => 'action_tab', 'filter_value' => 'exceptions'];
        }

        return [
            'readiness' => [
                'score' => $score,
                'band' => $band,
                'verdict' => $verdict,
                'summary' => $summary,
            ],
            'residual' => [
                'high' => $high,
                'risk' => $risk,
                'tbd' => $tbd,
                'gap' => $gap,
                'open_findings' => $openFindings,
            ],
            'findings' => $findings,
            'owners' => $owners,
            'timelines' => $timelines,
            'blockers' => $blockers,
            'evidence' => $this->scoreEvidence($checklist, $items, $assessment->workbook),
            'top_risks' => $topRisks,
        ];
    }

    /**
     * Overlay a saved custom headline/body on the auto-generated readiness copy.
     *
     * @param array<string, mixed> $insights
     * @return array<string, mixed>
     */
    public function applyExecutiveOverride(array $insights, string $customVerdict, string $customSummary): array
    {
        $readiness = is_array($insights['readiness'] ?? null) ? $insights['readiness'] : [];
        $autoVerdict = (string) ($readiness['verdict'] ?? '');
        $autoSummary = (string) ($readiness['summary'] ?? '');
        $customVerdict = trim($customVerdict);
        $customSummary = trim($customSummary);

        $readiness['auto_verdict'] = $autoVerdict;
        $readiness['auto_summary'] = $autoSummary;
        $readiness['custom_verdict'] = $customVerdict;
        $readiness['custom_summary'] = $customSummary;
        $readiness['is_custom'] = $customVerdict !== '' || $customSummary !== '';
        if ($customVerdict !== '') {
            $readiness['verdict'] = $customVerdict;
        }
        if ($customSummary !== '') {
            $readiness['summary'] = $customSummary;
        }

        $insights['readiness'] = $readiness;

        return $insights;
    }

    /** @param list<array<string, mixed>> $findings */
    /** @param array<string, array{status?: string, comment?: string, servicenow_links?: list<string>}|string> $findingStatuses */
    /** @return list<array<string, mixed>> */
    private function normalizeFindings(array $findings, array $findingStatuses = []): array
    {
        $normalized = [];
        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $text = trim((string) ($finding['finding'] ?? ''));
            if ($text === '') {
                continue;
            }
            $findingId = (string) ($finding['id'] ?? ('finding-' . $index));
            $status = trim((string) ($finding['status'] ?? 'Open'));
            $comment = '';
            $links = [];
            if (isset($findingStatuses[$findingId])) {
                $meta = $findingStatuses[$findingId];
                if (is_array($meta)) {
                    $status = (string) ($meta['status'] ?? $status);
                    $comment = (string) ($meta['comment'] ?? '');
                    $links = is_array($meta['servicenow_links'] ?? null) ? $meta['servicenow_links'] : [];
                } else {
                    $status = (string) $meta;
                }
            }
            $status = \RiskAssessment\Repositories\FindingStatusRepository::normalizeStatus($status);
            $links = \RiskAssessment\Repositories\FindingStatusRepository::normalizeLinks($links);
            $normalized[] = [
                'id' => $findingId,
                'finding' => $text,
                'policy_reference' => trim((string) ($finding['policy_reference'] ?? '')),
                'impact' => trim((string) ($finding['impact'] ?? '')),
                'mitigation' => trim((string) ($finding['mitigation'] ?? '')),
                'owner' => trim((string) ($finding['owner'] ?? '')),
                'timeline' => trim((string) ($finding['timeline'] ?? '')),
                'origin' => trim((string) ($finding['origin'] ?? 'excel')),
                'status' => $status,
                'comment' => $comment,
                'servicenow_links' => $links,
            ];
        }

        return $normalized;
    }

    /** @return list<string> */
    private function splitOwners(string $owner): array
    {
        if ($owner === '') {
            return ['Unassigned'];
        }

        $parts = preg_split('/\s*(?:\+|\/|,|;|\band\b)\s*/i', $owner) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $clean[$part] = $part;
            }
        }

        return $clean === [] ? ['Unassigned'] : array_values($clean);
    }

    private function timelineLane(string $timeline): string
    {
        $value = strtolower($timeline);
        if ($value === '') {
            return 'unspecified';
        }
        if (str_contains($value, 'go-live') || str_contains($value, 'go live') || str_contains($value, 'before go')) {
            return 'before_go_live';
        }
        if (str_contains($value, '2028') || str_contains($value, 'roadmap') || str_contains($value, 'q1')) {
            return 'roadmap';
        }
        if (
            str_contains($value, 'ongoing')
            || str_contains($value, 'quarterly')
            || str_contains($value, 'monthly')
            || str_contains($value, 'annual')
            || str_contains($value, 'renewal')
            || str_contains($value, 'on change')
        ) {
            return 'ongoing';
        }
        if (str_contains($value, 'intake') || str_contains($value, 'design')) {
            return 'intake';
        }

        return 'ongoing';
    }

    /**
     * @param list<string> $checklist
     * @param list<array<string, string>> $items
     * @param array<string, mixed> $workbook
     * @return array{total: int, covered: int, partial: int, missing: int, items: list<array{item: string, status: string, evidence: string}>}
     */
    private function scoreEvidence(array $checklist, array $items, array $workbook): array
    {
        if ($checklist === []) {
            $checklist = [
                'Vendor/product/version and ownership',
                'Data types, residency, access, retention, and volume',
                'OS and dependency versions, support lifecycle, and patch owner',
                'Authentication/MFA, network segmentation, ports, TLS, certificates, and remote access',
                'Logging, monitoring, backup, RPO/RTO, failover, and downtime procedure',
                'Policy exceptions, certifications, contract commitments, and vendor support',
                'Interface testing, patient matching, result reconciliation, and exit/portability',
            ];
        }

        $corpus = '';
        foreach ($items as $item) {
            $corpus .= ' ' . ($item['section'] ?? '') . ' ' . ($item['check'] ?? '') . ' ' . ($item['notes'] ?? '') . ' ' . ($item['mitigation'] ?? '');
        }
        foreach (($workbook['findings'] ?? []) as $finding) {
            if (is_array($finding)) {
                $corpus .= ' ' . implode(' ', $finding);
            }
        }
        $corpus = strtolower($corpus);

        $keywordMap = [
            'vendor' => ['vendor', 'product', 'version', 'owner'],
            'data' => ['phi', 'pii', 'residency', 'retention', 'volume', 'privacy'],
            'os' => ['windows', 'os', 'dependency', 'mongodb', 'mirth', 'patch', 'lifecycle'],
            'authentication' => ['authentication', 'mfa', 'sso', 'tls', 'certificate', 'network', 'port', 'vlan'],
            'logging' => ['logging', 'monitoring', 'backup', 'rpo', 'rto', 'failover', 'dr'],
            'policy' => ['exception', 'policy', 'iso', 'contract', 'certification', 'support'],
            'interface' => ['interface', 'hl7', 'patient', 'result', 'portability', 'exit'],
        ];

        $covered = 0;
        $partial = 0;
        $missing = 0;
        $rows = [];

        foreach ($checklist as $item) {
            $lower = strtolower($item);
            $keys = [];
            if (str_contains($lower, 'vendor') || str_contains($lower, 'ownership')) {
                $keys = $keywordMap['vendor'];
            } elseif (str_contains($lower, 'data')) {
                $keys = $keywordMap['data'];
            } elseif (str_contains($lower, 'os') || str_contains($lower, 'dependency')) {
                $keys = $keywordMap['os'];
            } elseif (str_contains($lower, 'authentication') || str_contains($lower, 'tls') || str_contains($lower, 'network')) {
                $keys = $keywordMap['authentication'];
            } elseif (str_contains($lower, 'logging') || str_contains($lower, 'backup') || str_contains($lower, 'rpo')) {
                $keys = $keywordMap['logging'];
            } elseif (str_contains($lower, 'policy') || str_contains($lower, 'contract') || str_contains($lower, 'certif')) {
                $keys = $keywordMap['policy'];
            } elseif (str_contains($lower, 'interface') || str_contains($lower, 'patient') || str_contains($lower, 'exit')) {
                $keys = $keywordMap['interface'];
            } else {
                $keys = preg_split('/[^a-z0-9]+/', $lower) ?: [];
            }

            $hits = 0;
            foreach ($keys as $key) {
                if ($key !== '' && str_contains($corpus, $key)) {
                    $hits++;
                }
            }

            if ($hits >= 3) {
                $status = 'covered';
                $covered++;
                $evidence = 'Evidence language found across register and findings.';
            } elseif ($hits >= 1) {
                $status = 'partial';
                $partial++;
                $evidence = 'Partial evidence found; confirm completeness before closure.';
            } else {
                $status = 'missing';
                $missing++;
                $evidence = 'No clear evidence detected in the workbook yet.';
            }

            $rows[] = [
                'item' => $item,
                'status' => $status,
                'evidence' => $evidence,
            ];
        }

        return [
            'total' => count($rows),
            'covered' => $covered,
            'partial' => $partial,
            'missing' => $missing,
            'items' => $rows,
        ];
    }
}
