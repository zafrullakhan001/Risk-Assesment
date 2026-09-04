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
        return new self(
            $metadata,
            $items,
            self::buildSummary($items, $dueDiligenceItems),
            $dueDiligenceItems,
            self::normalizeWorkbook($workbook)
        );
    }

    /**
     * @param list<array<string, string>> $items
     * @param list<array<string, string>> $dueDiligenceItems
     * @return array<string, mixed>
     */
    public static function buildSummary(array $items, array $dueDiligenceItems = []): array
    {
        return [
            'architecture' => self::summarizeItems($items),
            'due_diligence' => self::summarizeItems($dueDiligenceItems),
            'combined' => self::summarizeItems(array_merge($items, $dueDiligenceItems)),
            // Backwards-compatible top-level keys for the architecture register.
            'total' => count($items),
            'by_status' => self::summarizeItems($items)['by_status'],
            'by_risk' => self::summarizeItems($items)['by_risk'],
            'by_section' => self::summarizeItems($items)['by_section'],
        ];
    }

    /**
     * @param list<array<string, string>> $items
     * @return array{total: int, by_status: array<string, int>, by_risk: array<string, int>, by_section: array<string, array<string, int>>}
     */
    public static function summarizeItems(array $items): array
    {
        $summary = [
            'total' => count($items),
            'by_status' => [
                'Pass' => 0,
                'Gap' => 0,
                'Risk' => 0,
                'TBD' => 0,
                'N/A' => 0,
                'Other' => 0,
            ],
            'by_risk' => [
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
                    'TBD' => 0,
                    'N/A' => 0,
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
        return [
            'context' => (string) ($workbook['context'] ?? ''),
            'fields' => array_values($workbook['fields'] ?? []),
            'findings' => array_values($workbook['findings'] ?? []),
            'note' => (string) ($workbook['note'] ?? ''),
            'legend' => [
                'statuses' => array_values($workbook['legend']['statuses'] ?? []),
                'risk_levels' => array_values($workbook['legend']['risk_levels'] ?? []),
                'checklist' => array_values($workbook['legend']['checklist'] ?? []),
            ],
        ];
    }

    public static function normalizeStatus(string $value): string
    {
        $value = trim($value);
        $normalized = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        return match ($normalized) {
            'pass' => 'Pass',
            'gap' => 'Gap',
            'risk' => 'Risk',
            'tbd' => 'TBD',
            'na', 'n/a', 'notapplicable' => 'N/A',
            default => $value !== '' ? $value : 'TBD',
        };
    }

    public static function normalizeRiskLevel(string $value): string
    {
        $value = trim($value);
        $normalized = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        return match ($normalized) {
            'low' => 'Low',
            'med', 'medium' => 'Med',
            'high' => 'High',
            default => $value !== '' ? $value : '',
        };
    }
}
