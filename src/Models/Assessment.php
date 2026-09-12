<?php

declare(strict_types=1);

namespace RiskAssessment\Models;

final class Assessment
{
    /**
     * @param array<string, string> $metadata
     * @param list<array<string, string>> $items
     * @param array<string, mixed> $summary
     * @param list<array<string, string>> $dueDiligenceItems
     * @param array<string, mixed> $workbook
     */
    public function __construct(
        public readonly array $metadata,
        public readonly array $items,
        public readonly array $summary,
        public readonly array $dueDiligenceItems = [],
        public readonly array $workbook = [],
    ) {
    }

    public function getMetadata(string $key, string $default = ''): string
    {
        return $this->metadata[$key] ?? $default;
    }

    public function isAdaptive(): bool
    {
        return ($this->workbook['format'] ?? '') === 'adaptive';
    }

    /**
     * @param array<string, string> $metadata
     * @param list<array<string, string>> $items
     * @param list<array<string, string>> $dueDiligenceItems
     * @param array<string, mixed> $workbook
     */
    public static function fromParsedData(
        array $metadata,
        array $items,
        array $dueDiligenceItems = [],
        array $workbook = [],
    ): self {
        $normalizedWorkbook = self::normalizeWorkbook($workbook);
        $isAdaptive = ($normalizedWorkbook['format'] ?? '') === 'adaptive';

        return new self(
            $metadata,
            $items,
            self::buildSummary($items, $dueDiligenceItems, $isAdaptive),
            $dueDiligenceItems,
            $normalizedWorkbook
        );
    }

    /**
     * @param list<array<string, string>> $items
     * @param list<array<string, string>> $dueDiligenceItems
     * @return array<string, mixed>
     */
    public static function buildSummary(array $items, array $dueDiligenceItems = [], bool $adaptive = false): array
    {
        return [
            'architecture' => self::summarizeItems($items, $adaptive),
            'due_diligence' => self::summarizeItems($dueDiligenceItems, $adaptive),
            'combined' => self::summarizeItems(array_merge($items, $dueDiligenceItems), $adaptive),
            // Backwards-compatible top-level keys for the architecture register.
            'total' => count($items),
            'by_status' => self::summarizeItems($items, $adaptive)['by_status'],
            'by_risk' => self::summarizeItems($items, $adaptive)['by_risk'],
            'by_section' => self::summarizeItems($items, $adaptive)['by_section'],
        ];
    }

    /**
     * @param list<array<string, string>> $items
     * @return array{total: int, by_status: array<string, int>, by_risk: array<string, int>, by_section: array<string, array<string, int>>}
     */
    public static function summarizeItems(array $items, bool $adaptive = false): array
    {
        $summary = [
            'total' => count($items),
            'by_status' => $adaptive
                ? [
                    'Pass' => 0,
                    'Gap' => 0,
                    'Risk' => 0,
                    'Decision Required' => 0,
                    'Accepted Risk' => 0,
                    'Closed' => 0,
                    'TBD' => 0,
                    'N/A' => 0,
                    'Other' => 0,
                ]
                : [
                    'Pass' => 0,
                    'Gap' => 0,
                    'Risk' => 0,
                    'TBD' => 0,
                    'N/A' => 0,
                    'Other' => 0,
                ],
            'by_risk' => $adaptive
                ? [
                    'Low' => 0,
                    'Med' => 0,
                    'High' => 0,
                    'Critical' => 0,
                    'Other' => 0,
                ]
                : [
                    'Low' => 0,
                    'Med' => 0,
                    'High' => 0,
                    'Other' => 0,
                ],
            'by_section' => [],
        ];

        foreach ($items as $item) {
            $status = self::normalizeStatus($item['status'] ?? '');
            $risk = self::normalizeRiskLevel($item['risk_level'] ?? '');
            $section = trim($item['section'] ?? '') ?: 'Uncategorized';

            if (isset($summary['by_status'][$status])) {
                $summary['by_status'][$status]++;
            } else {
                $summary['by_status']['Other']++;
            }

            if ($risk !== '') {
                if (isset($summary['by_risk'][$risk])) {
                    $summary['by_risk'][$risk]++;
                } else {
                    $summary['by_risk']['Other']++;
                }
            }

            if (!isset($summary['by_section'][$section])) {
                $summary['by_section'][$section] = [
                    'total' => 0,
                    'Pass' => 0,
                    'Gap' => 0,
                    'Risk' => 0,
                    'Decision Required' => 0,
                    'Accepted Risk' => 0,
                    'Closed' => 0,
                    'TBD' => 0,
                    'N/A' => 0,
                    'Critical' => 0,
                    'High' => 0,
                    'Med' => 0,
                    'Low' => 0,
                ];
            }

            $summary['by_section'][$section]['total']++;
            if (isset($summary['by_section'][$section][$status])) {
                $summary['by_section'][$section][$status]++;
            }
            if (isset($summary['by_section'][$section][$risk])) {
                $summary['by_section'][$section][$risk]++;
            }
        }

        return $summary;
    }

    /** @param array<string, mixed> $workbook */
    /** @return array<string, mixed> */
    private static function normalizeWorkbook(array $workbook): array
    {
        $normalized = [
            'format' => (string) ($workbook['format'] ?? 'classic'),
            'context' => (string) ($workbook['context'] ?? ''),
            'fields' => array_values($workbook['fields'] ?? []),
            'findings' => array_values($workbook['findings'] ?? []),
            'note' => (string) ($workbook['note'] ?? ''),
            'legend' => [
                'statuses' => array_values($workbook['legend']['statuses'] ?? []),
                'risk_levels' => array_values($workbook['legend']['risk_levels'] ?? []),
                'checklist' => array_values($workbook['legend']['checklist'] ?? []),
                'routing' => array_values($workbook['legend']['routing'] ?? []),
                'finding_types' => array_values($workbook['legend']['finding_types'] ?? []),
                'materiality_gate' => array_values($workbook['legend']['materiality_gate'] ?? []),
                'materiality_guidance' => array_values($workbook['legend']['materiality_guidance'] ?? []),
                'materiality_notes' => array_values($workbook['legend']['materiality_notes'] ?? []),
            ],
        ];

        if ($normalized['format'] === 'adaptive') {
            $normalized['classification'] = is_array($workbook['classification'] ?? null)
                ? $workbook['classification']
                : [];
            $normalized['router'] = array_values($workbook['router'] ?? []);
            $normalized['decisions'] = array_values($workbook['decisions'] ?? []);
            $normalized['lifecycle'] = array_values($workbook['lifecycle'] ?? []);
            $normalized['exceptions'] = array_values($workbook['exceptions'] ?? []);
            $normalized['material_findings'] = array_values($workbook['material_findings'] ?? []);
            $normalized['kpis'] = is_array($workbook['kpis'] ?? null) ? $workbook['kpis'] : [];
        }

        return $normalized;
    }

    public static function normalizeStatus(string $value): string
    {
        $value = trim($value);
        $lower = strtolower($value);
        $normalized = preg_replace('/\s+/', '', $lower) ?? '';
        $compact = preg_replace('/[^a-z0-9]+/', '', $lower) ?? '';

        return match (true) {
            $normalized === 'pass' || $compact === 'pass' => 'Pass',
            $normalized === 'gap' || $compact === 'gap' => 'Gap',
            $normalized === 'risk' || $compact === 'risk' => 'Risk',
            $normalized === 'tbd' || $compact === 'tbd' => 'TBD',
            in_array($normalized, ['na', 'n/a', 'notapplicable'], true) || $compact === 'na' || $compact === 'notapplicable' => 'N/A',
            // Adaptive evidence / routing statuses
            in_array($compact, ['notrequested', 'excluded'], true) => 'N/A',
            in_array($compact, ['notassessed', 'pendingevidence', 'conditionalverify'], true) => 'TBD',
            $compact === 'closed' => 'Closed',
            $compact === 'decisionrequired' => 'Decision Required',
            $compact === 'acceptedrisk' => 'Accepted Risk',
            default => $value !== '' ? $value : 'TBD',
        };
    }

    public static function normalizeRiskLevel(string $value): string
    {
        $value = trim($value);
        $normalized = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        return match ($normalized) {
            'low' => 'Low',
            'med', 'medium', 'moderate' => 'Med',
            'high' => 'High',
            'critical' => 'Critical',
            default => $value !== '' ? $value : '',
        };
    }

    /** Treat Critical the same as High for residual / go-live gates. */
    public static function isElevatedRisk(string $riskLevel): bool
    {
        $risk = self::normalizeRiskLevel($riskLevel);

        return $risk === 'High' || $risk === 'Critical';
    }
}
