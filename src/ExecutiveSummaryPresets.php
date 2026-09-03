<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Clickable executive-summary presets for Decision Desk and Final evaluation editors.
 */
final class ExecutiveSummaryPresets
{
    /**
     * @return list<array{id: string, label: string, verdict: string, summary: string}>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'conditional',
                'label' => 'Conditional go-live',
                'verdict' => 'Conditional go-live ready',
                'summary' => 'Residual exposure is limited: remaining Gap and TBD items are tracked, with no open High risks or governance exceptions blocking release.',
            ],
            [
                'id' => 'controls',
                'label' => 'Proceed with controls',
                'verdict' => 'Proceed only with controls',
                'summary' => 'Go-live is acceptable only with named owners, closure dates, and compensating controls for remaining High risks, Risk-status checks, TBD items, and open exceptions.',
            ],
            [
                'id' => 'not_ready',
                'label' => 'Not ready',
                'verdict' => 'Not ready for go-live',
                'summary' => 'Material blockers remain: High risks, Risk items, TBD decisions, and open exceptions must be mitigated or formally accepted before production release.',
            ],
            [
                'id' => 'accepted',
                'label' => 'Accepted residual risk',
                'verdict' => 'Go-live with accepted residual risk',
                'summary' => 'Residual risk is formally accepted with compensating controls documented. Owners and review dates are assigned for follow-up after release.',
            ],
            [
                'id' => 'pilot',
                'label' => 'Pilot / limited release',
                'verdict' => 'Pilot / limited production release',
                'summary' => 'Approve a narrow production footprint only. Remaining checklist and exception items stay tracked with owners until full go-live criteria are met.',
            ],
            [
                'id' => 'after_exceptions',
                'label' => 'Ready after exceptions close',
                'verdict' => 'Ready after exceptions close',
                'summary' => 'Clear to go live once remaining governance exceptions are closed or approved. Checklist residual items are owned and do not block release after that gate.',
            ],
        ];
    }
}
