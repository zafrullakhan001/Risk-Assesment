<?php

declare(strict_types=1);

namespace RiskAssessment\Models;

final class Assessment
{
    /** @param array<string, string> $metadata */
    /** @param list<array<string, string>> $items */
    public function __construct(
        public readonly array $metadata,
        public readonly array $items,
        public readonly array $summary,
    ) {
    }

    public function getMetadata(string $key, string $default = ''): string
    {
        return $this->metadata[$key] ?? $default;
    }

    /** @param list<array<string, string>> $items */
    public static function fromParsedData(array $metadata, array $items): self
    {
        return new self($metadata, $items, self::buildSummary($items));
    }

    /** @param list<array<string, string>> $items */
    public static function buildSummary(array $items): array
    {
        $summary = [
            'total' => count($items),
            'by_status' => [
                'Pass' => 0,
                'Gap' => 0,
                'Risk' => 0,
                'TBD' => 0,
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

            if (isset($summary['by_risk'][$risk])) {
                $summary['by_risk'][$risk]++;
            } else {
                $summary['by_risk']['Other']++;
            }

            if (!isset($summary['by_section'][$section])) {
                $summary['by_section'][$section] = [
                    'total' => 0,
                    'Pass' => 0,
                    'Gap' => 0,
                    'Risk' => 0,
                    'TBD' => 0,
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

    public static function normalizeStatus(string $value): string
    {
        $value = trim($value);
        $normalized = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        return match ($normalized) {
            'pass' => 'Pass',
            'gap' => 'Gap',
            'risk' => 'Risk',
            'tbd' => 'TBD',
            default => $value !== '' ? $value : 'Other',
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
            default => $value !== '' ? $value : 'Other',
        };
    }
}
