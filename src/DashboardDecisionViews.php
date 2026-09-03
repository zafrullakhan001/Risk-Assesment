<?php

declare(strict_types=1);

namespace RiskAssessment;

final class DashboardDecisionViews
{
    /**
     * @param array<string, mixed> $insights
     * @param array<string, mixed> $comparison
     * @param array{
     *   evaluator_name?: string,
     *   evaluator_email?: string,
     *   notes?: string,
     *   ready_to_golive?: bool,
     *   updated_at?: string
     * }|null $evaluation
     * @param array<string, mixed> $progress
     */
    public function renderDecisionDesk(array $insights, int $assessmentId, array $comparison = [], ?array $evaluation = null, array $progress = []): string
    {
        $readiness = $insights['readiness'];
        $residual = $insights['residual'];
        $band = (string) $readiness['band'];
        $evaluation = $evaluation ?? [];
        $evaluatorName = (string) ($evaluation['evaluator_name'] ?? '');
        $evalNotes = (string) ($evaluation['notes'] ?? '');
        $readyToGolive = !empty($evaluation['ready_to_golive']);
        $riskOpen = (int) ($progress['risk']['open'] ?? $residual['risk'] ?? 0);
        $riskTotal = (int) ($progress['risk']['total'] ?? $residual['risk'] ?? 0);
        $gapOpen = (int) ($progress['gap']['open'] ?? $residual['gap'] ?? 0);
        $gapTotal = (int) ($progress['gap']['total'] ?? $residual['gap'] ?? 0);
        $tbdOpen = (int) ($progress['tbd']['open'] ?? $residual['tbd'] ?? 0);
        $tbdTotal = (int) ($progress['tbd']['total'] ?? $residual['tbd'] ?? 0);
        $highOpen = (int) ($progress['high']['open'] ?? $residual['high'] ?? 0);
        $highTotal = (int) ($progress['high']['total'] ?? $residual['high'] ?? 0);

        ob_start();
        ?>
        <section class="decision-desk" id="decision-desk" data-assessment-id="<?= (int) $assessmentId ?>">
            <article class="exec-summary band-<?= $this->e($band) ?><?= $readyToGolive ? ' is-ready-golive' : '' ?>">
                <div class="exec-score">
                    <span class="label">🚀 Go-live readiness</span>
                    <strong><?= (int) $readiness['score'] ?></strong>
                    <em>/ 100</em>
                </div>
                <div class="exec-copy">
                    <div class="eyebrow">📋 Executive summary</div>
                    <h3><?= $this->e((string) $readiness['verdict']) ?></h3>
                    <p><?= $this->e((string) $readiness['summary']) ?></p>
                    <div class="exec-metrics">
                        <?php
                        $metric = static function (string $key, int $open, int $total, string $label) : string {
                            $addressed = max(0, $total - $open);
                            $hasResolution = $addressed > 0 && $total > 0;
                            $value = $hasResolution
                                ? '<span data-progress-open="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . $open . '</span><span class="exec-progress-tail">/<span data-progress-total="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . $total . '</span></span>'
                                : '<span data-progress-open="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . $total . '</span><span class="exec-progress-tail" hidden>/<span data-progress-total="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . $total . '</span></span>';
                            return '<span><b>' . $value . '</b> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                        };
                        echo $metric('high', $highOpen, $highTotal, '🚨 High open');
                        echo $metric('risk', $riskOpen, $riskTotal, '🔴 Risk open');
                        echo $metric('gap', $gapOpen, $gapTotal, '🟠 Gap open');
                        echo $metric('tbd', $tbdOpen, $tbdTotal, '❓ TBD open');
                        ?>
                        <span><b><?= (int) $residual['open_findings'] ?></b> ⚠️ Open exceptions</span>
                    </div>
                    <?php if ($readyToGolive): ?>
                        <div class="golive-badge" id="golive-status-badge">🚀 Ready to go-live<?= $evaluatorName !== '' ? ' · ' . $this->e($evaluatorName) : '' ?></div>
                    <?php else: ?>
                        <div class="golive-badge is-pending" id="golive-status-badge" <?= $evaluatorName === '' && $evalNotes === '' ? 'hidden' : '' ?>>
                            ⏳ Not ready to go-live<?= $evaluatorName !== '' ? ' · ' . $this->e($evaluatorName) : '' ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="exec-actions no-print-hide">
                    <button type="button" class="button button-primary" id="btn-presentation">🎬 Presentation mode</button>
                    <button type="button" class="button ghost-light" id="btn-print">🖨️ Print one-pager</button>
                    <button type="button" class="button ghost-light" id="btn-export-csv">📥 Export CSV</button>
                    <button type="button" class="button ghost-light" data-filter-type="action_tab" data-filter-value="risks">✅ Open Actions</button>
                    <button type="button" class="button ghost-light" data-filter-type="action_tab" data-filter-value="signoff">✍️ Final evaluation</button>
                </div>
            </article>

            <?php if (($insights['blockers'] ?? []) !== []): ?>
                <div class="blocker-row">
                    <?php foreach ($insights['blockers'] as $blocker): ?>
                        <button
                            type="button"
                            class="blocker-chip"
                            data-filter-type="<?= $this->e((string) $blocker['filter_type']) ?>"
                            data-filter-value="<?= $this->e((string) $blocker['filter_value']) ?>"
                        >
                            <b><?= (int) $blocker['count'] ?></b>
                            <span><?= $this->e((string) $blocker['label']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($comparison['has_prior'])): ?>
                <div class="diff-summary diff-summary-uplift">
                    <span class="label">📈 Since last upload</span>
                    <strong><?= count($comparison['changes'] ?? []) ?> field changes</strong>
                    <span>➕ <?= count($comparison['added'] ?? []) ?> added</span>
                    <span>➖ <?= count($comparison['removed'] ?? []) ?> removed</span>
                    <button type="button" class="button ghost-light" data-filter-type="action_tab" data-filter-value="versions">🗂️ Manage versions</button>
                </div>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $comparison */
    public function renderTrendChips(array $comparison): string
    {
        if (empty($comparison['has_prior']) || ($comparison['trends'] ?? []) === []) {
            return '';
        }

        ob_start();
        ?>
        <div class="trend-chips" aria-label="Changes since last upload">
            <?php foreach ($comparison['trends'] as $trend): ?>
                <?php
                $delta = (int) $trend['delta'];
                $direction = (string) $trend['direction'];
                $worsening = in_array((string) $trend['key'], ['risk', 'high', 'tbd', 'gap'], true)
                    ? $direction === 'up'
                    : $direction === 'down';
                ?>
                <span class="trend-chip <?= $worsening ? 'trend-bad' : 'trend-good' ?>">
                    <?= $this->e((string) $trend['label']) ?>
                    <?= $direction === 'up' ? '↑' : '↓' ?><?= abs($delta) ?>
                </span>
            <?php endforeach; ?>
            <span class="trend-chip trend-muted">vs prior #<?= (int) ($comparison['prior_id'] ?? 0) ?></span>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $insights
     * @param array<string, mixed> $comparison
     * @param list<array<string, mixed>> $versions
     * @param list<array<string, mixed>> $actionableItems
     * @param array{
     *   evaluator_name?: string,
     *   evaluator_email?: string,
     *   notes?: string,
     *   ready_to_golive?: bool,
     *   updated_at?: string
     * }|null $evaluation
     * @param array{
     *   ready_allowed: bool,
     *   rules: list<array{
     *     id: string,
     *     label: string,
     *     passed: bool,
     *     detail: string,
     *     filter_type: string,
     *     filter_value: string
     *   }>
     * } $goliveGates
     */
    public function renderActionsPanel(
        array $insights,
        array $comparison = [],
        array $versions = [],
        int $currentId = 0,
        string $csrfToken = '',
        array $actionableItems = [],
        ?array $evaluation = null,
        array $goliveGates = []
    ): string {
        $findings = $insights['findings'] ?? [];
        $owners = $insights['owners'] ?? [];
        $timelines = $insights['timelines'] ?? [];
        $evidence = $insights['evidence'] ?? ['total' => 0, 'covered' => 0, 'partial' => 0, 'missing' => 0, 'items' => []];
        $topRisks = $insights['top_risks'] ?? [];
        $actionLabels = \RiskAssessment\Repositories\ItemResponseRepository::ACTION_LABELS;

        $riskItems = [];
        $gapItems = [];
        $tbdItems = [];
        $openResponses = 0;
        foreach ($actionableItems as $row) {
            if (($row['action'] ?? 'open') === 'open') {
                $openResponses++;
            }
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if ($status === 'gap') {
                $gapItems[] = $row;
            } elseif ($status === 'tbd') {
                $tbdItems[] = $row;
            } else {
                $riskItems[] = $row;
            }
        }

        $evaluation = $evaluation ?? [];
        $evaluatorName = (string) ($evaluation['evaluator_name'] ?? '');
        $evaluatorEmail = (string) ($evaluation['evaluator_email'] ?? '');
        $evalNotes = (string) ($evaluation['notes'] ?? '');
        $readyToGolive = !empty($evaluation['ready_to_golive']);
        $evalUpdatedAt = (string) ($evaluation['updated_at'] ?? '');
        $openExceptions = (int) ($insights['residual']['open_findings'] ?? 0);

        ob_start();
        ?>
        <div class="actions-workspace actions-workspace-uplift" id="actions-workspace">
            <div class="actions-intro dash-panel-intro actions-intro-hero">
                <div class="dash-panel-intro-copy">
                    <span class="dash-panel-intro-icon" aria-hidden="true">✅</span>
                    <div>
                        <div class="eyebrow">Response workspace</div>
                        <h2>Actions</h2>
                        <p>Record decisions on risks, gaps, TBDs, and exceptions here. Other tabs stay as dashboards.</p>
                    </div>
                </div>
                <span class="result-count project-resources-badge" id="response-open-count"><?= (int) $openResponses ?> item responses open</span>
            </div>

            <nav class="action-tabs action-tabs-uplift" role="tablist" aria-label="Action categories">
                <button type="button" class="action-tab action-tab-risks is-active" role="tab" aria-selected="true" data-action-tab="risks">
                    🔴 Risks <em><?= count($riskItems) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-gaps" role="tab" aria-selected="false" data-action-tab="gaps">
                    🟠 Gaps <em><?= count($gapItems) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-tbd" role="tab" aria-selected="false" data-action-tab="tbd">
                    ❓ TBD <em><?= count($tbdItems) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-exceptions" role="tab" aria-selected="false" data-action-tab="exceptions">
                    ⚠️ Exceptions <em><?= count($findings) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-signoff" role="tab" aria-selected="false" data-action-tab="signoff">
                    ✍️ Sign-off
                </button>
                <button type="button" class="action-tab action-tab-versions" role="tab" aria-selected="false" data-action-tab="versions">
                    🗂️ Versions <em><?= count($versions) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-workspace" role="tab" aria-selected="false" data-action-tab="workspace">
                    🧰 Workspace
                </button>
            </nav>

            <div class="action-panel is-active" data-action-panel="risks" id="item-responses">
                <?= $this->renderResponseWorkbench(
                    'risks',
                    'Risk responses',
                    'Risk-status and other high-priority actionable items',
                    'action-risks-table',
                    $riskItems,
                    $actionLabels
                ) ?>
            </div>

            <div class="action-panel" data-action-panel="gaps" hidden>
                <?= $this->renderResponseWorkbench(
                    'gaps',
                    'Gap responses',
                    'Gap items that need a recorded decision',
                    'action-gaps-table',
                    $gapItems,
                    $actionLabels
                ) ?>
            </div>

            <div class="action-panel" data-action-panel="tbd" hidden>
                <?= $this->renderResponseWorkbench(
                    'tbd',
                    'TBD responses',
                    'Open decisions that still need an owner response',
                    'action-tbd-table',
                    $tbdItems,
                    $actionLabels
                ) ?>
            </div>

            <div class="action-panel" data-action-panel="exceptions" hidden>
                <section class="table-card table-card-uplift chart-card-tone-governance" id="exception-tracker">
                    <div class="card-heading card-heading-uplift">
                        <div class="card-heading-with-icon">
                            <span class="card-icon" aria-hidden="true">⚠️</span>
                            <div>
                                <div class="eyebrow">Exception tracker</div>
                                <h3>Governance findings</h3>
                            </div>
                        </div>
                        <span class="result-count result-count-badge" id="exception-open-count"><?= $openExceptions ?> open</span>
                    </div>
                    <?php if ($findings === []): ?>
                        <p class="empty-panel project-empty-state">✨ No documented exceptions in this workbook.</p>
                    <?php else: ?>
                        <p class="panel-help">📝 Update exception status as findings are approved, closed, or expire. Changes save as soon as you pick a status.</p>
                        <div class="table-scroll">
                            <table id="exception-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Finding</th>
                                        <th>Policy</th>
                                        <th>Owner</th>
                                        <th>Timeline</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($findings as $finding): ?>
                                        <tr data-finding-id="<?= $this->e((string) $finding['id']) ?>">
                                            <td>
                                                <div class="exception-status-wrap">
                                                    <select class="exception-status" data-finding-id="<?= $this->e((string) $finding['id']) ?>" aria-label="Exception status">
                                                        <?php foreach (\RiskAssessment\Repositories\FindingStatusRepository::STATUSES as $status): ?>
                                                            <option value="<?= $this->e($status) ?>" <?= ($finding['status'] ?? 'Open') === $status ? 'selected' : '' ?>><?= $this->e($status) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <span class="exception-status-save" hidden></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="clamp-text" data-expandable><?= $this->e((string) $finding['finding']) ?></div>
                                                <?php if (($finding['mitigation'] ?? '') !== ''): ?>
                                                    <div class="subtext clamp-text" data-expandable><?= $this->e((string) $finding['mitigation']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $this->e((string) ($finding['policy_reference'] ?? '')) ?></td>
                                            <td><?= $this->e((string) ($finding['owner'] ?? '')) ?></td>
                                            <td><?= $this->e((string) ($finding['timeline'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="action-panel" data-action-panel="signoff" hidden>
                <section class="table-card table-card-uplift final-evaluation-card chart-card-tone-signoff" id="final-evaluation">
                    <div class="card-heading card-heading-uplift">
                        <div class="card-heading-with-icon">
                            <span class="card-icon" aria-hidden="true">✍️</span>
                            <div>
                                <div class="eyebrow">Evaluator sign-off</div>
                                <h3>Final evaluation</h3>
                            </div>
                        </div>
                        <span class="result-count result-count-badge" id="final-eval-saved-label">
                            <?= $evalUpdatedAt !== '' ? '💾 Saved ' . $this->e($evalUpdatedAt) : 'Not saved yet' ?>
                        </span>
                    </div>
                    <?php if ($currentId <= 0): ?>
                        <p class="panel-help">📁 Upload and open a saved assessment to record the final evaluation.</p>
                    <?php else: ?>
                        <p class="panel-help">📝 Capture the evaluator’s notes, identity, and ready-to-go-live decision for this version.</p>
                        <?= $this->renderGoliveGates($goliveGates) ?>
                        <form id="final-evaluation-form" class="final-evaluation-form" novalidate>
                            <div class="final-eval-grid">
                                <label>
                                    <span>Evaluator name</span>
                                    <input type="text" name="evaluator_name" id="eval-name" maxlength="200" required value="<?= $this->e($evaluatorName) ?>" placeholder="Full name">
                                </label>
                                <label>
                                    <span>Evaluator email</span>
                                    <input type="email" name="evaluator_email" id="eval-email" maxlength="254" required value="<?= $this->e($evaluatorEmail) ?>" placeholder="name@company.com">
                                </label>
                            </div>
                            <label class="final-eval-notes">
                                <span>Final evaluation notes</span>
                                <textarea name="notes" id="eval-notes" rows="5" maxlength="8000" placeholder="Overall conclusion, residual risk acceptance, conditions, and follow-ups..."><?= $this->e($evalNotes) ?></textarea>
                            </label>
                            <div class="final-eval-footer">
                                <label class="golive-toggle">
                                    <input type="checkbox" name="ready_to_golive" id="eval-ready" value="1" <?= $readyToGolive ? 'checked' : '' ?> <?= empty($goliveGates['ready_allowed']) && !$readyToGolive ? 'disabled' : '' ?>>
                                    <span class="golive-toggle-ui" aria-hidden="true"></span>
                                    <span class="golive-toggle-label">🚀 Ready to go-live</span>
                                </label>
                                <p class="golive-gate-hint" id="golive-gate-hint" <?= !empty($goliveGates['ready_allowed']) ? 'hidden' : '' ?>>
                                    Complete all go-live gates before marking ready.
                                </p>
                                <div class="final-eval-actions">
                                    <span class="final-eval-status" id="final-eval-status" hidden></span>
                                    <button type="submit" class="button button-primary" id="btn-save-evaluation">💾 Save evaluation</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            </div>

            <div class="action-panel" data-action-panel="versions" hidden>
                <?= $this->renderVersionsSection($versions, $comparison, $currentId, $csrfToken) ?>
            </div>

            <div class="action-panel" data-action-panel="workspace" hidden>
                <div class="actions-grid">
                    <?= $this->renderOwnersSection($owners) ?>
                    <?= $this->renderTimelinesSection($timelines) ?>
                    <?= $this->renderEvidenceSection($evidence) ?>
                </div>
            </div>
        </div>

        <?php if ($topRisks !== []): ?>
            <section class="table-card print-only-block" id="top-risks-print" style="margin-top: 10px;">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Presentation</div>
                        <h3>Top residual risks</h3>
                    </div>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th>Check</th>
                                <th>Status</th>
                                <th>Risk</th>
                                <th>Owner</th>
                                <th>Timeline</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topRisks as $item): ?>
                                <tr>
                                    <td><?= $this->e((string) ($item['section'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($item['check'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($item['status'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($item['risk_level'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($item['owner'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($item['remediation_timeline'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $actionLabels
     */
    private function renderResponseWorkbench(
        string $scope,
        string $heading,
        string $help,
        string $tableId,
        array $items,
        array $actionLabels
    ): string {
        ob_start();
        ?>
        <section class="table-card table-card-uplift">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true"><?= match ($scope) { 'risks' => '🔴', 'gaps' => '🟠', 'tbd' => '❓', default => '📝' } ?></span>
                    <div>
                        <div class="eyebrow">Recorded responses</div>
                        <h3><?= $this->e($heading) ?></h3>
                    </div>
                </div>
                <span class="result-count result-count-badge"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
            </div>
            <?php if ($items === []): ?>
                <p class="empty-panel project-empty-state">✨ No items in this category right now.</p>
            <?php else: ?>
                <p class="panel-help">📋 <?= $this->e($help) ?>. Select listed rows to update them together, or edit one at a time.</p>
                <div class="bulk-response-bar" data-bulk-scope="<?= $this->e($scope) ?>" data-bulk-table="<?= $this->e($tableId) ?>">
                    <label class="bulk-select-all">
                        <input type="checkbox" class="bulk-select-all-toggle" title="Select all listed rows">
                        <span>Select all listed</span>
                    </label>
                    <span class="bulk-selected-count">0 selected</span>
                    <select class="bulk-response-action" aria-label="Bulk response">
                        <?php foreach ($actionLabels as $value => $label): ?>
                            <option value="<?= $this->e($value) ?>"><?= $this->e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="bulk-response-comment" maxlength="2000" placeholder="Comment for selected (optional)">
                    <button type="button" class="button button-primary bulk-response-apply">Update selected</button>
                    <span class="bulk-response-status" hidden></span>
                </div>
                <div class="table-scroll">
                    <table id="<?= $this->e($tableId) ?>">
                        <thead>
                            <tr>
                                <th class="col-select">Sel</th>
                                <th>Item</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Risk</th>
                                <th>Our response &amp; comment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $row): ?>
                                <?php
                                $key = (string) ($row['key'] ?? '');
                                $action = \RiskAssessment\Repositories\ItemResponseRepository::normalizeAction((string) ($row['action'] ?? 'open'));
                                $comment = (string) ($row['comment'] ?? '');
                                $itemType = (string) ($row['item_type'] ?? 'architecture');
                                ?>
                                <tr
                                    data-item-key="<?= $this->e($key) ?>"
                                    data-response="<?= $this->e($action) ?>"
                                    data-actionable="1"
                                    data-has-comment="<?= $comment !== '' ? '1' : '0' ?>"
                                >
                                    <td class="col-select">
                                        <input type="checkbox" class="row-select" value="<?= $this->e($key) ?>" aria-label="Select <?= $this->e((string) ($row['check'] ?? '')) ?>">
                                    </td>
                                    <td>
                                        <strong><?= $this->e((string) ($row['check'] ?? '')) ?></strong>
                                        <div class="subtext"><?= $this->e((string) ($row['section'] ?? '')) ?><?php if (($row['owner'] ?? '') !== ''): ?> · <?= $this->e((string) $row['owner']) ?><?php endif; ?></div>
                                    </td>
                                    <td><?= $itemType === 'due_diligence' ? 'Due diligence' : 'Architecture' ?></td>
                                    <td><?= $this->e((string) ($row['status'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($row['risk_level'] ?? '')) ?></td>
                                    <td>
                                        <div class="item-response" data-item-key="<?= $this->e($key) ?>">
                                            <select class="item-response-action" aria-label="Response">
                                                <?php foreach ($actionLabels as $value => $label): ?>
                                                    <option value="<?= $this->e($value) ?>" <?= $action === $value ? 'selected' : '' ?>><?= $this->e($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <textarea
                                                class="item-response-comment"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Comment (optional)"
                                            ><?= $this->e($comment) ?></textarea>
                                            <span class="item-response-save" hidden>Saved</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string, mixed>> $versions
     * @param array<string, mixed> $comparison
     */
    private function renderVersionsSection(array $versions, array $comparison, int $currentId, string $csrfToken): string
    {
        ob_start();
        ?>
        <section class="table-card table-card-uplift chart-card-tone-versions" id="version-history">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true">🗂️</span>
                    <div>
                        <div class="eyebrow">Comparison & history</div>
                        <h3>Saved versions</h3>
                    </div>
                </div>
                <span class="result-count result-count-badge"><?= count($versions) ?> version<?= count($versions) === 1 ? '' : 's' ?></span>
            </div>
            <?php if ($versions === []): ?>
                <p class="empty-panel project-empty-state">📁 No other saved versions for this project yet. Upload again to enable diff and trends.</p>
            <?php else: ?>
                <?php
                $olderCount = 0;
                foreach ($versions as $version) {
                    if ((int) ($version['id'] ?? 0) !== $currentId) {
                        $olderCount++;
                    }
                }
                ?>
                <?php if ($csrfToken !== '' && $olderCount > 0 && $currentId > 0): ?>
                    <div class="version-bulk">
                        <p>Drop every older upload for this project and keep the version you have open.</p>
                        <form method="post" action="index.php" class="inline-form" onsubmit="return confirm('Delete <?= (int) $olderCount ?> older version<?= $olderCount === 1 ? '' : 's' ?> permanently? The current version (#<?= (int) $currentId ?>) will be kept.');">
                            <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_older_versions">
                            <input type="hidden" name="keep_id" value="<?= (int) $currentId ?>">
                            <button type="submit" class="button danger-btn">Drop <?= (int) $olderCount ?> older version<?= $olderCount === 1 ? '' : 's' ?></button>
                        </form>
                    </div>
                <?php endif; ?>
                <div class="version-list">
                    <?php foreach ($versions as $version): ?>
                        <?php $versionId = (int) ($version['id'] ?? 0); ?>
                        <div class="version-row <?= $versionId === $currentId ? 'is-current' : '' ?>">
                            <div>
                                <strong>
                                    <?php if ($versionId === $currentId): ?>
                                        Current · #<?= $versionId ?>
                                    <?php else: ?>
                                        <a href="index.php?view=1&amp;id=<?= $versionId ?>">Version #<?= $versionId ?></a>
                                    <?php endif; ?>
                                </strong>
                                <span><?= $this->e((string) ($version['uploaded_at'] ?? '')) ?></span>
                                <span><?= $this->e((string) ($version['original_filename'] ?? '')) ?></span>
                            </div>
                            <div class="version-actions">
                                <?php if ($versionId !== $currentId): ?>
                                    <a class="button ghost" href="index.php?view=1&amp;id=<?= $versionId ?>">Open</a>
                                <?php endif; ?>
                                <?php if ($csrfToken !== ''): ?>
                                    <form method="post" action="index.php" class="inline-form" onsubmit="return confirm('Delete version #<?= $versionId ?> permanently?');">
                                        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_assessment">
                                        <input type="hidden" name="assessment_id" value="<?= $versionId ?>">
                                        <input type="hidden" name="redirect_id" value="<?= (int) $currentId ?>">
                                        <button type="submit" class="button danger-btn">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($comparison['has_prior'])): ?>
                <div class="card-heading" style="padding-top: 4px;">
                    <div>
                        <div class="eyebrow">Version diff</div>
                        <h3>Changes vs #<?= (int) $comparison['prior_id'] ?></h3>
                    </div>
                </div>
                <div class="diff-lists">
                    <?php if (($comparison['changes'] ?? []) !== []): ?>
                        <div class="diff-block">
                            <h4>Status / risk changes</h4>
                            <ul>
                                <?php foreach (array_slice($comparison['changes'], 0, 12) as $change): ?>
                                    <li>
                                        <strong><?= $this->e((string) $change['check']) ?></strong>
                                        <span><?= $this->e((string) $change['field']) ?>: <?= $this->e((string) $change['from']) ?> → <?= $this->e((string) $change['to']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (($comparison['added'] ?? []) !== []): ?>
                        <div class="diff-block">
                            <h4>Added checks</h4>
                            <ul>
                                <?php foreach (array_slice($comparison['added'], 0, 8) as $row): ?>
                                    <li><strong><?= $this->e((string) $row['check']) ?></strong> <span><?= $this->e((string) $row['status']) ?> / <?= $this->e((string) $row['risk_level']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (($comparison['removed'] ?? []) !== []): ?>
                        <div class="diff-block">
                            <h4>Removed checks</h4>
                            <ul>
                                <?php foreach (array_slice($comparison['removed'], 0, 8) as $row): ?>
                                    <li><strong><?= $this->e((string) $row['check']) ?></strong> <span><?= $this->e((string) $row['status']) ?> / <?= $this->e((string) $row['risk_level']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (($comparison['changes'] ?? []) === [] && ($comparison['added'] ?? []) === [] && ($comparison['removed'] ?? []) === []): ?>
                        <p class="empty-panel">No status or risk changes versus the prior version.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param list<array<string, mixed>> $owners */
    private function renderOwnersSection(array $owners): string
    {
        ob_start();
        ?>
        <section class="table-card table-card-uplift chart-card-tone-workspace">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true">👥</span>
                    <div>
                        <div class="eyebrow">Actionability</div>
                        <h3>Owner workload</h3>
                    </div>
                </div>
            </div>
            <div class="owner-list">
                <?php foreach ($owners as $owner): ?>
                    <button
                        type="button"
                        class="owner-row"
                        data-filter-type="owner"
                        data-filter-value="<?= $this->e((string) $owner['owner']) ?>"
                    >
                        <span class="owner-name"><?= $this->e((string) $owner['owner']) ?></span>
                        <span class="owner-stats">
                            <b><?= (int) $owner['total'] ?></b>
                            <em><?= (int) $owner['high'] ?>H</em>
                            <em><?= (int) $owner['risk'] ?>R</em>
                            <em><?= (int) $owner['tbd'] ?>T</em>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param list<array<string, mixed>> $timelines */
    private function renderTimelinesSection(array $timelines): string
    {
        $maxTimeline = 1;
        foreach ($timelines as $lane) {
            $maxTimeline = max($maxTimeline, (int) $lane['total']);
        }

        ob_start();
        ?>
        <section class="table-card table-card-uplift chart-card-tone-workspace">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true">⏱️</span>
                    <div>
                        <div class="eyebrow">Timeline heat</div>
                        <h3>Remediation lanes</h3>
                    </div>
                </div>
            </div>
            <div class="timeline-lanes">
                <?php foreach ($timelines as $lane): ?>
                    <button
                        type="button"
                        class="timeline-lane"
                        data-filter-type="timeline"
                        data-filter-value="<?= $this->e((string) $lane['lane']) ?>"
                    >
                        <div class="timeline-lane-head">
                            <strong><?= $this->e((string) $lane['label']) ?></strong>
                            <span><?= (int) $lane['total'] ?></span>
                        </div>
                        <div class="bar-track">
                            <span class="bar-fill bar-risk" style="width: <?= round(((int) $lane['high'] / $maxTimeline) * 100, 2) ?>%"></span>
                            <span class="bar-fill bar-gap" style="width: <?= round(((int) $lane['risk'] / $maxTimeline) * 100, 2) ?>%"></span>
                            <span class="bar-fill bar-tbd" style="width: <?= round(((int) $lane['tbd'] / $maxTimeline) * 100, 2) ?>%"></span>
                            <span class="bar-fill bar-pass" style="width: <?= round((max(0, (int) $lane['total'] - (int) $lane['high'] - (int) $lane['risk'] - (int) $lane['tbd']) / $maxTimeline) * 100, 2) ?>%"></span>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $evidence */
    private function renderEvidenceSection(array $evidence): string
    {
        $total = max(1, (int) ($evidence['total'] ?? 0));
        ob_start();
        ?>
        <section class="table-card table-card-uplift chart-card-tone-workspace">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true">✅</span>
                    <div>
                        <div class="eyebrow">Trust / completeness</div>
                        <h3>Evidence checklist</h3>
                    </div>
                </div>
                <span class="result-count result-count-badge"><?= (int) ($evidence['covered'] ?? 0) ?>/<?= (int) ($evidence['total'] ?? 0) ?> covered</span>
            </div>
            <div class="evidence-meter">
                <span class="ev-covered" style="width: <?= round(((int) ($evidence['covered'] ?? 0) / $total) * 100, 2) ?>%"></span>
                <span class="ev-partial" style="width: <?= round(((int) ($evidence['partial'] ?? 0) / $total) * 100, 2) ?>%"></span>
                <span class="ev-missing" style="width: <?= round(((int) ($evidence['missing'] ?? 0) / $total) * 100, 2) ?>%"></span>
            </div>
            <ul class="evidence-list">
                <?php foreach (($evidence['items'] ?? []) as $item): ?>
                    <li class="evidence-<?= $this->e((string) $item['status']) ?>">
                        <strong><?= $this->e((string) $item['item']) ?></strong>
                        <span><?= $this->e((string) $item['evidence']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array{
     *   ready_allowed?: bool,
     *   rules?: list<array{
     *     id: string,
     *     label: string,
     *     passed: bool,
     *     detail: string,
     *     filter_type: string,
     *     filter_value: string
     *   }>
     * } $goliveGates
     */
    private function renderGoliveGates(array $goliveGates): string
    {
        $rules = is_array($goliveGates['rules'] ?? null) ? $goliveGates['rules'] : [];
        $readyAllowed = !empty($goliveGates['ready_allowed']);

        ob_start();
        ?>
        <section
            class="golive-gates<?= $readyAllowed ? ' is-ready' : ' is-blocked' ?>"
            id="golive-gates"
            aria-live="polite"
        >
            <div class="golive-gates-head">
                <span class="golive-gates-icon" aria-hidden="true"><?= $readyAllowed ? '✅' : '🚧' ?></span>
                <div>
                    <div class="eyebrow">Go-live gates</div>
                    <h4><?= $readyAllowed ? 'All gates passed' : 'Gates must pass before sign-off' ?></h4>
                </div>
            </div>
            <ul class="golive-gates-list">
                <?php foreach ($rules as $rule): ?>
                    <?php
                    $passed = !empty($rule['passed']);
                    $ruleId = (string) ($rule['id'] ?? '');
                    ?>
                    <li
                        class="golive-gate<?= $passed ? ' is-pass' : ' is-fail' ?>"
                        data-gate-id="<?= $this->e($ruleId) ?>"
                    >
                        <span class="golive-gate-status" aria-hidden="true"><?= $passed ? '✅' : '❌' ?></span>
                        <div class="golive-gate-copy">
                            <strong><?= $this->e((string) ($rule['label'] ?? '')) ?></strong>
                            <span class="golive-gate-detail" data-gate-detail="<?= $this->e($ruleId) ?>">
                                <?= $this->e((string) ($rule['detail'] ?? '')) ?>
                            </span>
                        </div>
                        <?php if (!$passed && ($rule['filter_type'] ?? '') !== ''): ?>
                            <button
                                type="button"
                                class="gate-fix-link"
                                data-filter-type="<?= $this->e((string) ($rule['filter_type'] ?? '')) ?>"
                                data-filter-value="<?= $this->e((string) ($rule['filter_value'] ?? '')) ?>"
                            >
                                Fix →
                            </button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="golive-gates-summary" id="golive-gates-summary">
                <?= $readyAllowed
                    ? 'You may mark this version ready to go-live once the evaluation is saved.'
                    : 'Resolve each failing gate, then save with Ready to go-live checked.' ?>
            </p>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
