<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Models\Assessment;

final class AssessmentComparer
{
    /**
     * Compare current assessment to a prior version.
     *
     * @return array{
     *   has_prior: bool,
     *   prior_id: int,
     *   prior_uploaded_at: string,
     *   trends: list<array{key: string, label: string, delta: int, direction: string}>,
     *   changes: list<array{section: string, check: string, field: string, from: string, to: string, item_type: string}>,
     *   added: list<array{section: string, check: string, status: string, risk_level: string, item_type: string}>,
     *   removed: list<array{section: string, check: string, status: string, risk_level: string, item_type: string}>,
     *   changed_keys: array<string, true>
     * }
     */
    public function compare(Assessment $current, ?Assessment $prior, int $priorId = 0, string $priorUploadedAt = ''): array
    {
        if ($prior === null) {
            return [
                'has_prior' => false,
                'prior_id' => 0,
                'prior_uploaded_at' => '',
                'trends' => [],
                'changes' => [],
                'added' => [],
                'removed' => [],
                'changed_keys' => [],
            ];
        }

        $currentMap = $this->indexItems(array_merge($current->items, $current->dueDiligenceItems));
        $priorMap = $this->indexItems(array_merge($prior->items, $prior->dueDiligenceItems));

        $changes = [];
        $changedKeys = [];
        $added = [];
        $removed = [];

        foreach ($currentMap as $key => $item) {
            if (!isset($priorMap[$key])) {
                $added[] = [
                    'section' => $item['section'],
                    'check' => $item['check'],
                    'status' => $item['status'],
                    'risk_level' => $item['risk_level'],
                    'item_type' => $item['item_type'],
                ];
                $changedKeys[$key] = true;
                continue;
            }

            $before = $priorMap[$key];
            foreach (['status' => 'Status', 'risk_level' => 'Risk'] as $field => $label) {
                if (($before[$field] ?? '') !== ($item[$field] ?? '')) {
                    $changes[] = [
                        'section' => $item['section'],
                        'check' => $item['check'],
                        'field' => $label,
                        'from' => (string) ($before[$field] ?? ''),
                        'to' => (string) ($item[$field] ?? ''),
                        'item_type' => $item['item_type'],
                    ];
                    $changedKeys[$key] = true;
                }
            }
        }

        foreach ($priorMap as $key => $item) {
            if (!isset($currentMap[$key])) {
                $removed[] = [
                    'section' => $item['section'],
                    'check' => $item['check'],
                    'status' => $item['status'],
                    'risk_level' => $item['risk_level'],
                    'item_type' => $item['item_type'],
                ];
            }
        }

        $currRisk = (int) ($current->summary['by_status']['Risk'] ?? 0);
        $priorRisk = (int) ($prior->summary['by_status']['Risk'] ?? 0);
        $currHigh = (int) ($current->summary['by_risk']['High'] ?? 0);
        $priorHigh = (int) ($prior->summary['by_risk']['High'] ?? 0);
        $currTbd = (int) ($current->summary['by_status']['TBD'] ?? 0);
        $priorTbd = (int) ($prior->summary['by_status']['TBD'] ?? 0);
        $currGap = (int) ($current->summary['by_status']['Gap'] ?? 0);
        $priorGap = (int) ($prior->summary['by_status']['Gap'] ?? 0);
        $currPass = (int) ($current->summary['by_status']['Pass'] ?? 0);
        $priorPass = (int) ($prior->summary['by_status']['Pass'] ?? 0);

        $trends = [];
        foreach ([
            ['key' => 'risk', 'label' => 'Risk', 'delta' => $currRisk - $priorRisk],
            ['key' => 'high', 'label' => 'High', 'delta' => $currHigh - $priorHigh],
            ['key' => 'tbd', 'label' => 'TBD', 'delta' => $currTbd - $priorTbd],
            ['key' => 'gap', 'label' => 'Gap', 'delta' => $currGap - $priorGap],
            ['key' => 'pass', 'label' => 'Pass', 'delta' => $currPass - $priorPass],
        ] as $trend) {
            if ($trend['delta'] === 0) {
                continue;
            }
            $trends[] = [
                'key' => $trend['key'],
                'label' => $trend['label'],
                'delta' => $trend['delta'],
                'direction' => $trend['delta'] > 0 ? 'up' : 'down',
            ];
        }

        return [
            'has_prior' => true,
            'prior_id' => $priorId,
            'prior_uploaded_at' => $priorUploadedAt,
            'trends' => $trends,
            'changes' => $changes,
            'added' => $added,
            'removed' => $removed,
            'changed_keys' => $changedKeys,
        ];
    }

    public static function itemKey(string $itemType, string $section, string $check): string
    {
        return strtolower(trim($itemType) . '|' . trim($section) . '|' . trim($check));
    }

    /**
     * @param list<array<string, string>> $items
     * @return array<string, array<string, string>>
     */
    private function indexItems(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $key = self::itemKey(
                (string) ($item['item_type'] ?? 'architecture'),
                (string) ($item['section'] ?? ''),
                (string) ($item['check'] ?? '')
            );
            $map[$key] = [
                'item_type' => (string) ($item['item_type'] ?? 'architecture'),
                'section' => (string) ($item['section'] ?? ''),
                'check' => (string) ($item['check'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'risk_level' => (string) ($item['risk_level'] ?? ''),
            ];
        }

        return $map;
    }
}
