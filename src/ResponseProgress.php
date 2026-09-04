<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\ItemResponseRepository;

final class ResponseProgress
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, array{action?: string, comment?: string}> $responses
     * @return array{
     *   risk: array{total: int, open: int, addressed: int},
     *   gap: array{total: int, open: int, addressed: int},
     *   tbd: array{total: int, open: int, addressed: int},
     *   high: array{total: int, open: int, addressed: int},
     *   actionable: array{total: int, open: int, addressed: int},
     *   by_status_open: array<string, int>,
     *   by_status_total: array<string, int>
     * }
     */
    public function compute(array $items, array $responses): array
    {
        $buckets = [
            'risk' => ['total' => 0, 'open' => 0, 'addressed' => 0],
            'gap' => ['total' => 0, 'open' => 0, 'addressed' => 0],
            'tbd' => ['total' => 0, 'open' => 0, 'addressed' => 0],
            'high' => ['total' => 0, 'open' => 0, 'addressed' => 0],
            'actionable' => ['total' => 0, 'open' => 0, 'addressed' => 0],
        ];
        $byStatusOpen = ['Pass' => 0, 'Gap' => 0, 'Risk' => 0, 'TBD' => 0, 'N/A' => 0, 'Addressed' => 0];
        $byStatusTotal = ['Pass' => 0, 'Gap' => 0, 'Risk' => 0, 'TBD' => 0, 'N/A' => 0];

        foreach ($items as $item) {
            $status = Models\Assessment::normalizeStatus((string) ($item['status'] ?? ''));
            $riskLevel = Models\Assessment::normalizeRiskLevel((string) ($item['risk_level'] ?? ''));
            $key = AssessmentComparer::itemKey(
                (string) ($item['item_type'] ?? 'architecture'),
                (string) ($item['section'] ?? ''),
                (string) ($item['check'] ?? '')
            );
            $action = ItemResponseRepository::normalizeAction((string) ($responses[$key]['action'] ?? 'open'));
            $addressed = ItemResponseRepository::isAddressed($action);
            $actionable = ItemResponseRepository::isActionableStatus($status, $riskLevel);

            if (isset($byStatusTotal[$status])) {
                $byStatusTotal[$status]++;
            }

            if ($actionable && $addressed) {
                $byStatusOpen['Addressed']++;
            } elseif (isset($byStatusOpen[$status])) {
                $byStatusOpen[$status]++;
            }

            if ($actionable) {
                $buckets['actionable']['total']++;
                if ($addressed) {
                    $buckets['actionable']['addressed']++;
                } else {
                    $buckets['actionable']['open']++;
                }
            }

            $this->bump($buckets, 'risk', $status === 'Risk' || $status === 'Decision Required', $addressed);
            $this->bump($buckets, 'gap', $status === 'Gap', $addressed);
            $this->bump($buckets, 'tbd', $status === 'TBD', $addressed);
            $this->bump($buckets, 'high', Models\Assessment::isElevatedRisk($riskLevel), $addressed);
        }

        return [
            'risk' => $buckets['risk'],
            'gap' => $buckets['gap'],
            'tbd' => $buckets['tbd'],
            'high' => $buckets['high'],
            'actionable' => $buckets['actionable'],
            'by_status_open' => $byStatusOpen,
            'by_status_total' => $byStatusTotal,
        ];
    }

    /**
     * @param array<string, array{total: int, open: int, addressed: int}> $buckets
     */
    private function bump(array &$buckets, string $key, bool $matches, bool $addressed): void
    {
        if (!$matches) {
            return;
        }
        $buckets[$key]['total']++;
        if ($addressed) {
            $buckets[$key]['addressed']++;
        } else {
            $buckets[$key]['open']++;
        }
    }
}
