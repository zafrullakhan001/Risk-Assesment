<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Models\Assessment;

final class GoliveGate
{
    /**
     * @param array<string, array{action?: string, comment?: string}> $responses
     * @param array<string, string> $findingStatuses
     * @return array{
     *   ready_allowed: bool,
     *   rules: list<array{
     *     id: string,
     *     label: string,
     *     passed: bool,
     *     detail: string,
     *     filter_type: string,
     *     filter_value: string
     *   }>,
     *   residual: array{high: int, open_findings: int}
     * }
     */
    public function evaluate(Assessment $assessment, array $responses, array $findingStatuses, string $notes): array
    {
        $insights = (new AssessmentInsights())->build($assessment, $responses, $findingStatuses);
        $highOpen = (int) ($insights['residual']['high'] ?? 0);
        $openExceptions = (int) ($insights['residual']['open_findings'] ?? 0);
        $notesFilled = trim($notes) !== '';

        $rules = [
            [
                'id' => 'high_risks',
                'label' => 'No open High risks',
                'passed' => $highOpen === 0,
                'detail' => $highOpen === 0 ? 'All High risks addressed' : $highOpen . ' High risk' . ($highOpen === 1 ? '' : 's') . ' still open',
                'filter_type' => 'action_tab',
                'filter_value' => 'risks',
            ],
            [
                'id' => 'exceptions',
                'label' => 'All exceptions closed, approved, or expired',
                'passed' => $openExceptions === 0,
                'detail' => $openExceptions === 0
                    ? 'No open governance exceptions'
                    : $openExceptions . ' exception' . ($openExceptions === 1 ? '' : 's') . ' still open',
                'filter_type' => 'action_tab',
                'filter_value' => 'exceptions',
            ],
            [
                'id' => 'notes',
                'label' => 'Evaluator notes completed',
                'passed' => $notesFilled,
                'detail' => $notesFilled ? 'Notes provided' : 'Add final evaluation notes before sign-off',
                'filter_type' => 'action_tab',
                'filter_value' => 'signoff',
            ],
        ];

        $readyAllowed = true;
        foreach ($rules as $rule) {
            if (!$rule['passed']) {
                $readyAllowed = false;
                break;
            }
        }

        return [
            'ready_allowed' => $readyAllowed,
            'rules' => $rules,
            'residual' => [
                'high' => $highOpen,
                'open_findings' => $openExceptions,
            ],
        ];
    }

    /**
     * @param array{rules: list<array{label: string, detail: string, passed: bool}>} $gate
     */
    public function formatFailureMessage(array $gate): string
    {
        $failed = [];
        foreach ($gate['rules'] ?? [] as $rule) {
            if (empty($rule['passed'])) {
                $failed[] = ($rule['label'] ?? 'Rule') . ': ' . ($rule['detail'] ?? 'Not met');
            }
        }

        if ($failed === []) {
            return 'Ready to go-live requirements are not met.';
        }

        return 'Cannot mark ready to go-live until all gates pass: ' . implode('; ', $failed);
    }
}
