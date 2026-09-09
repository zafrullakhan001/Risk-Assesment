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
    public function renderDecisionDesk(array $insights, int $assessmentId, array $comparison = [], ?array $evaluation = null, array $progress = [], bool $readOnly = false): string
    {
        $readiness = $insights['readiness'];
        $residual = $insights['residual'];
        $band = (string) $readiness['band'];
        $evaluation = $evaluation ?? [];
        $evaluatorName = (string) ($evaluation['evaluator_name'] ?? '');
        $evalNotes = (string) ($evaluation['notes'] ?? '');
        $readyToGolive = !empty($evaluation['ready_to_golive']);
        $autoVerdict = (string) ($readiness['auto_verdict'] ?? $readiness['verdict'] ?? '');
        $autoSummary = (string) ($readiness['auto_summary'] ?? $readiness['summary'] ?? '');
        $isCustomSummary = !empty($readiness['is_custom']);
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
                <div
                    class="exec-copy"
                    id="exec-copy"
                    data-custom="<?= $isCustomSummary ? '1' : '0' ?>"
                    data-auto-verdict="<?= $this->e($autoVerdict) ?>"
                    data-auto-summary="<?= $this->e($autoSummary) ?>"
                >
                    <div class="exec-copy-head">
                        <div class="eyebrow">📋 Executive summary</div>
                        <span class="exec-custom-pill" id="exec-custom-pill" <?= $isCustomSummary ? '' : 'hidden' ?>>✏️ Customized</span>
                    </div>
                    <div class="exec-copy-view" id="exec-summary-view">
                        <h3 id="exec-verdict"><?= $this->e((string) $readiness['verdict']) ?></h3>
                        <p id="exec-summary-text"><?= $this->e((string) $readiness['summary']) ?></p>
                    </div>
                    <?php if (!$readOnly): ?>
                    <form class="exec-summary-editor" id="exec-summary-form" hidden>
                        <label>
                            <span>Headline</span>
                            <input type="text" name="executive_verdict" id="exec-verdict-input" maxlength="200" value="<?= $this->e((string) $readiness['verdict']) ?>" placeholder="Conditional go-live ready">
                        </label>
                        <label>
                            <span>Summary</span>
                            <textarea name="executive_summary" id="exec-summary-input" rows="3" maxlength="2000" placeholder="Write the go-live narrative for this version..."><?= $this->e((string) $readiness['summary']) ?></textarea>
                        </label>
                        <?= $this->renderExecutivePresetChips('exec') ?>
                        <p class="exec-edit-help">Leave a field blank to keep the auto-generated text. Restore clears both and returns to the live score wording. Presets fill both fields — you can still edit before saving.</p>
                        <p class="exec-edit-status" id="exec-edit-status" hidden></p>
                        <div class="exec-edit-actions">
                            <button type="button" class="button ghost-light" id="btn-reset-exec-summary">↺ Restore auto text</button>
                            <button type="button" class="button ghost-light" id="btn-cancel-exec-summary">Cancel</button>
                            <button type="submit" class="button button-primary" id="btn-save-exec-summary">💾 Save summary</button>
                        </div>
                    </form>
                    <?php endif; ?>
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
                <div class="exec-actions no-print-hide" role="toolbar" aria-label="Executive summary actions">
                    <?php if (!$readOnly): ?>
                        <button type="button" class="button ghost-light exec-action-btn exec-edit-btn" id="btn-edit-exec-summary" title="Edit" aria-label="Edit">✏️</button>
                    <?php endif; ?>
                    <button type="button" class="button button-primary exec-action-btn" id="btn-presentation" title="Presentation mode" aria-label="Presentation mode">🎬</button>
                    <button type="button" class="button ghost-light exec-action-btn" id="btn-print" title="Print one-pager" aria-label="Print one-pager">🖨️</button>
                    <button type="button" class="button ghost-light exec-action-btn" id="btn-export-csv" title="Export CSV" aria-label="Export CSV">📥</button>
                    <button type="button" class="button ghost-light exec-action-btn" data-filter-type="action_tab" data-filter-value="risks" title="Open Actions" aria-label="Open Actions">✅</button>
                    <?php if (!$readOnly): ?>
                        <button type="button" class="button ghost-light exec-action-btn" data-filter-type="action_tab" data-filter-value="signoff" title="Final evaluation" aria-label="Final evaluation">✍️</button>
                        <button type="button" class="button ghost-light exec-action-btn" data-filter-type="action_tab" data-filter-value="share" title="Share read-only link" aria-label="Share read-only link">🔗</button>
                    <?php endif; ?>
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
                    <?php if (!$readOnly): ?>
                        <button type="button" class="button ghost-light" data-filter-type="action_tab" data-filter-value="versions">🗂️ Manage versions</button>
                    <?php endif; ?>
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
     *   updated_at?: string,
     *   updated_by_label?: string
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
     * @param list<array<string, mixed>> $evaluationHistory
     * @param array{name?: string, email?: string} $evaluatorDefaults
     * @param list<array{id: int, created_at: string, created_by_username: string, expires_at: ?string, last_accessed_at: ?string, is_active: bool}> $shareLinks
     */
    public function renderActionsPanel(
        array $insights,
        array $comparison = [],
        array $versions = [],
        int $currentId = 0,
        string $csrfToken = '',
        array $actionableItems = [],
        ?array $evaluation = null,
        array $goliveGates = [],
        array $evaluationHistory = [],
        array $evaluatorDefaults = [],
        bool $readOnly = false,
        array $shareLinks = [],
        ?string $freshShareUrl = null,
        bool $isAdaptive = false,
        bool $smtpEnabled = false,
        bool $viewerIsAdmin = false,
        bool $isOwner = false,
        bool $isLocked = false,
        array $projectEditors = [],
        array $eligibleEditors = []
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
        $archSourceCount = 0;
        $ddSourceCount = 0;
        foreach ($actionableItems as $row) {
            if (($row['action'] ?? 'open') === 'open') {
                $openResponses++;
            }
            $itemType = (string) ($row['item_type'] ?? 'architecture');
            if ($itemType === 'due_diligence') {
                $ddSourceCount++;
            } else {
                $archSourceCount++;
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

        $archSourceLabel = $isAdaptive ? 'Material findings' : 'Architecture';
        $ddSourceLabel = 'Due diligence';
        $showSourceTabs = $archSourceCount > 0 && $ddSourceCount > 0;

        $archSections = [];
        $ddSections = [];
        foreach ($actionableItems as $row) {
            $section = trim((string) ($row['section'] ?? ''));
            if ($section === '') {
                continue;
            }
            $itemType = (string) ($row['item_type'] ?? 'architecture');
            if ($itemType === 'due_diligence') {
                $ddSections[$section] = ($ddSections[$section] ?? 0) + 1;
            } else {
                $archSections[$section] = ($archSections[$section] ?? 0) + 1;
            }
        }
        ksort($archSections, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($ddSections, SORT_NATURAL | SORT_FLAG_CASE);

        $evaluation = $evaluation ?? [];
        $evaluatorName = (string) ($evaluation['evaluator_name'] ?? '');
        $evaluatorEmail = (string) ($evaluation['evaluator_email'] ?? '');
        if ($evaluatorName === '' && $evaluatorEmail === '') {
            $evaluatorName = (string) ($evaluatorDefaults['name'] ?? '');
            $evaluatorEmail = (string) ($evaluatorDefaults['email'] ?? '');
        }
        $evalNotes = (string) ($evaluation['notes'] ?? '');
        $readyToGolive = !empty($evaluation['ready_to_golive']);
        $evalUpdatedAt = (string) ($evaluation['updated_at'] ?? '');
        $evalUpdatedBy = (string) ($evaluation['updated_by_label'] ?? '');
        $openExceptions = (int) ($insights['residual']['open_findings'] ?? 0);
        $evalSavedLabel = 'Not saved yet';
        // Flip to true when the Owners / timelines / evidence overview is needed again.
        $showWorkspaceTab = false;
        if ($evalUpdatedAt !== '') {
            $evalSavedLabel = '💾 Saved ' . $evalUpdatedAt;
            if ($evalUpdatedBy !== '') {
                $evalSavedLabel .= ' · ' . $evalUpdatedBy;
            }
        }

        ob_start();
        ?>
        <div class="actions-workspace actions-workspace-uplift" id="actions-workspace">
            <div class="actions-intro dash-panel-intro actions-intro-hero">
                <div class="dash-panel-intro-copy">
                    <span class="dash-panel-intro-icon" aria-hidden="true">✅</span>
                    <div>
                        <div class="eyebrow">Response workspace</div>
                        <h2>Actions</h2>
                        <p><?php if ($readOnly): ?>
                            View recorded decisions on risks, gaps, TBDs, and exceptions. Editing is disabled on shared links.
                        <?php elseif ($isAdaptive): ?>
                            Respond to actionable items that come from <strong>Material findings</strong> and <strong>Due diligence</strong>. Use the source sub-tabs below to switch between those registers, then Risks / Gaps / TBD to categorize the work.
                        <?php else: ?>
                            Record decisions on risks, gaps, TBDs, and exceptions here. Other tabs stay as dashboards.
                        <?php endif; ?></p>
                    </div>
                </div>
                <span class="result-count project-resources-badge" id="response-open-count"><?= (int) $openResponses ?> item responses open</span>
            </div>

            <?php if ($showSourceTabs || $isAdaptive): ?>
                <div class="action-source-block">
                    <div class="action-source-label">
                        <span class="eyebrow">Finding sources</span>
                        <p>Items below come from the registers above. Pick a source, then a section<?= $isAdaptive ? ' / category' : '' ?>, then a response category.</p>
                    </div>
                    <nav class="action-source-tabs" role="tablist" aria-label="Finding sources for Actions">
                        <button type="button" class="action-source-tab is-active" role="tab" aria-selected="true" data-action-source="all" data-tooltip="Show actionable items from every register.">
                            📚 All sources <em><?= $archSourceCount + $ddSourceCount ?></em>
                        </button>
                        <button type="button" class="action-source-tab action-source-architecture" role="tab" aria-selected="false" data-action-source="architecture" data-tooltip="Only items from the <?= $this->e($archSourceLabel) ?> register.">
                            <?= $isAdaptive ? '📋' : '🏛️' ?> <?= $this->e($archSourceLabel) ?> <em><?= $archSourceCount ?></em>
                        </button>
                        <button type="button" class="action-source-tab action-source-diligence" role="tab" aria-selected="false" data-action-source="due_diligence" data-tooltip="Only items from the Due diligence register (grouped by category/section).">
                            🔍 <?= $this->e($ddSourceLabel) ?> <em><?= $ddSourceCount ?></em>
                        </button>
                    </nav>
                    <div class="action-section-filters" id="action-section-filters" aria-label="Filter Actions by section">
                        <span class="action-section-filters-label">Sections</span>
                        <div class="action-section-chips">
                            <button type="button" class="action-section-chip is-active" data-action-section="" data-action-section-source="all">
                                All sections
                            </button>
                            <?php foreach ($archSections as $section => $count): ?>
                                <button
                                    type="button"
                                    class="action-section-chip action-section-chip-architecture"
                                    data-action-section="<?= $this->e($section) ?>"
                                    data-action-section-source="architecture"
                                    hidden
                                    title="<?= $this->e($archSourceLabel) ?>"
                                >
                                    <?= $this->e($section) ?> <em><?= (int) $count ?></em>
                                </button>
                            <?php endforeach; ?>
                            <?php foreach ($ddSections as $section => $count): ?>
                                <button
                                    type="button"
                                    class="action-section-chip action-section-chip-diligence"
                                    data-action-section="<?= $this->e($section) ?>"
                                    data-action-section-source="due_diligence"
                                    hidden
                                    title="Due diligence category"
                                >
                                    <?= $this->e($section) ?> <em><?= (int) $count ?></em>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <nav class="action-tabs action-tabs-uplift" role="tablist" aria-label="Action categories">
                <button type="button" class="action-tab action-tab-risks is-active" role="tab" aria-selected="true" data-action-tab="risks" data-tooltip="Assign Taken care / Ignore responses and comments for high-priority risk items.">
                    🔴 Risks <em data-action-count="risks"><?= count($riskItems) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-gaps" role="tab" aria-selected="false" data-action-tab="gaps" data-tooltip="Record decisions for gap findings that still need a response.">
                    🟠 Gaps <em data-action-count="gaps"><?= count($gapItems) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-tbd" role="tab" aria-selected="false" data-action-tab="tbd" data-tooltip="Resolve items marked TBD — decide status and capture comments.">
                    ❓ TBD <em data-action-count="tbd"><?= count($tbdItems) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-exceptions" role="tab" aria-selected="false" data-action-tab="exceptions" data-tooltip="Track accepted exceptions, mitigations, owners, and timelines.">
                    ⚠️ Exceptions <em><?= count($findings) ?></em>
                </button>
                <button type="button" class="action-tab action-tab-signoff" role="tab" aria-selected="false" data-action-tab="signoff" data-tooltip="Capture the evaluator’s final notes and ready-to-go-live decision.">
                    ✍️ Sign-off
                </button>
                <button type="button" class="action-tab action-tab-versions" role="tab" aria-selected="false" data-action-tab="versions" data-tooltip="Compare assessment versions and manage uploaded workbook history.">
                    🗂️ Versions <em><?= count($versions) ?></em>
                </button>
                <?php if ($isOwner && !$readOnly && $currentId > 0): ?>
                    <button type="button" class="action-tab action-tab-access" role="tab" aria-selected="false" data-action-tab="access" data-tooltip="Lock the project and grant edit access to specific people.">
                        🔐 Access
                    </button>
                    <button type="button" class="action-tab action-tab-share" role="tab" aria-selected="false" data-action-tab="share" data-tooltip="Create a public read-only link anyone can open without signing in.">
                        🔗 Share
                    </button>
                <?php endif; ?>
                <?php if ($showWorkspaceTab): ?>
                    <button type="button" class="action-tab action-tab-workspace" role="tab" aria-selected="false" data-action-tab="workspace" data-tooltip="See owner workload, remediation timelines, and evidence completeness. Click a row to filter Actions.">
                        🧰 Workspace
                    </button>
                <?php endif; ?>
            </nav>

            <div class="action-panel is-active" data-action-panel="risks" id="item-responses">
                <?= $this->renderResponseWorkbench(
                    'risks',
                    'Risk responses',
                    'Risk-status and other high-priority actionable items',
                    'action-risks-table',
                    $riskItems,
                    $actionLabels,
                    $readOnly,
                    $isAdaptive
                ) ?>
            </div>

            <div class="action-panel" data-action-panel="gaps" hidden>
                <?= $this->renderResponseWorkbench(
                    'gaps',
                    'Gap responses',
                    'Gap items that need a recorded decision',
                    'action-gaps-table',
                    $gapItems,
                    $actionLabels,
                    $readOnly,
                    $isAdaptive
                ) ?>
            </div>

            <div class="action-panel" data-action-panel="tbd" hidden>
                <?= $this->renderResponseWorkbench(
                    'tbd',
                    'TBD responses',
                    'Open decisions that still need an owner response',
                    'action-tbd-table',
                    $tbdItems,
                    $actionLabels,
                    $readOnly,
                    $isAdaptive
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
                        <div class="exception-heading-actions">
                            <span class="result-count result-count-badge" id="exception-open-count"><?= $openExceptions ?> open</span>
                            <?php if ($currentId > 0): ?>
                                <button type="button" class="button button-secondary exception-add-row" id="exception-add-row">
                                    ➕ Add exception
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($currentId <= 0): ?>
                        <p class="panel-help">📁 Upload and open a saved assessment to manage exceptions, comments, and ServiceNow links.</p>
                    <?php endif; ?>
                    <?php if ($findings === []): ?>
                        <p class="empty-panel project-empty-state" id="exception-empty">✨ No documented exceptions yet. Add one to track a governance finding.</p>
                        <div class="table-scroll" id="exception-table-wrap" hidden>
                            <table id="exception-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Finding</th>
                                        <th>Notes &amp; links</th>
                                        <th>Owner</th>
                                        <th>Timeline</th>
                                        <th class="col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="panel-help">📝 Update status inline, or use ✏️ to add comments and up to 5 ServiceNow links. Add or remove rows as needed.</p>
                        <div class="table-scroll" id="exception-table-wrap">
                            <table id="exception-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Finding</th>
                                        <th>Notes &amp; links</th>
                                        <th>Owner</th>
                                        <th>Timeline</th>
                                        <th class="col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($findings as $finding): ?>
                                        <?= $this->renderExceptionRow($finding) ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <dialog class="response-dialog exception-edit-dialog" id="exception-edit-dialog" aria-labelledby="exception-edit-title">
                    <form method="dialog" class="response-dialog-form" id="exception-edit-form">
                        <div class="response-dialog-head">
                            <div>
                                <div class="eyebrow">⚠️ Exception details</div>
                                <h3 id="exception-edit-title">Edit exception</h3>
                                <p class="response-dialog-sub" id="exception-edit-sub"></p>
                            </div>
                            <button type="button" class="button ghost-light response-dialog-close" id="exception-edit-close" aria-label="Close">✕</button>
                        </div>
                        <section class="response-dialog-details" id="exception-edit-details">
                            <div class="response-dialog-details-banner">
                                <span class="response-dialog-details-icon" aria-hidden="true">⚠️</span>
                                <div>
                                    <strong>Governance finding</strong>
                                    <p class="response-dialog-details-lead">Record comments and ServiceNow exception links for this finding.</p>
                                </div>
                            </div>
                            <div class="response-dialog-details-meta">
                                <div class="response-dialog-detail-chip" data-exception-detail="policy" hidden>
                                    <span class="response-dialog-detail-label">📜 Policy</span>
                                    <span class="response-dialog-detail-value" id="exception-edit-policy"></span>
                                </div>
                                <div class="response-dialog-detail-chip" data-exception-detail="owner" hidden>
                                    <span class="response-dialog-detail-label">👤 Owner</span>
                                    <span class="response-dialog-detail-value" id="exception-edit-owner"></span>
                                </div>
                                <div class="response-dialog-detail-chip" data-exception-detail="timeline" hidden>
                                    <span class="response-dialog-detail-label">🗓️ Timeline</span>
                                    <span class="response-dialog-detail-value" id="exception-edit-timeline"></span>
                                </div>
                            </div>
                            <div class="response-dialog-detail-block" data-exception-detail="finding" hidden>
                                <span class="response-dialog-detail-label">📋 Finding</span>
                                <p class="response-dialog-detail-text" id="exception-edit-finding-text"></p>
                            </div>
                            <div class="response-dialog-detail-block" data-exception-detail="mitigation" hidden>
                                <span class="response-dialog-detail-label">🛡️ Mitigation</span>
                                <p class="response-dialog-detail-text" id="exception-edit-mitigation"></p>
                            </div>
                            <div class="response-dialog-detail-block" data-exception-detail="impact" hidden>
                                <span class="response-dialog-detail-label">💥 Impact</span>
                                <p class="response-dialog-detail-text" id="exception-edit-impact"></p>
                            </div>
                        </section>
                        <label class="response-dialog-field">
                            <span>Status</span>
                            <select id="exception-edit-status" required>
                                <?php foreach (\RiskAssessment\Repositories\FindingStatusRepository::STATUSES as $statusOption): ?>
                                    <option value="<?= $this->e($statusOption) ?>"><?= $this->e($statusOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="response-dialog-field">
                            <span>User comments</span>
                            <textarea
                                id="exception-edit-comment"
                                rows="6"
                                maxlength="<?= (int) \RiskAssessment\Repositories\FindingStatusRepository::MAX_COMMENT_LENGTH ?>"
                                placeholder="Record notes, decisions, and context for this exception"
                            ></textarea>
                        </label>
                        <div class="exception-sn-block" id="exception-edit-sn-block">
                            <div class="exception-sn-head">
                                <span>ServiceNow exception links</span>
                                <span class="exception-sn-count" id="exception-edit-sn-count">0 / <?= (int) \RiskAssessment\Repositories\FindingStatusRepository::MAX_LINKS ?></span>
                            </div>
                            <div class="exception-sn-list" id="exception-edit-sn-list"></div>
                            <button type="button" class="button button-secondary exception-sn-add" id="exception-edit-sn-add">
                                ➕ Add link
                            </button>
                        </div>
                        <p class="response-dialog-status" id="exception-edit-status-msg" hidden></p>
                        <div class="response-dialog-actions">
                            <button type="button" class="button ghost" id="exception-edit-cancel">Cancel</button>
                            <button type="submit" class="button button-primary" id="exception-edit-save">💾 Save exception</button>
                        </div>
                    </form>
                </dialog>

                <dialog class="response-dialog exception-add-dialog" id="exception-add-dialog">
                    <form method="dialog" id="exception-add-form" class="response-dialog-form">
                        <div class="response-dialog-head">
                            <div>
                                <div class="eyebrow" id="exception-add-eyebrow">➕ Add exception</div>
                                <h3 id="exception-add-title">New governance finding</h3>
                            </div>
                            <button type="submit" value="cancel" class="response-dialog-close" aria-label="Close">✕</button>
                        </div>
                        <div class="exception-add-grid">
                            <label class="exception-add-full">
                                <span>Finding / control</span>
                                <textarea name="finding" id="exception-add-finding" rows="3" maxlength="4000" required placeholder="Describe the exception or control gap"></textarea>
                            </label>
                            <label>
                                <span>Policy / reference</span>
                                <input type="text" name="policy_reference" id="exception-add-policy" maxlength="500" placeholder="Policy or control reference">
                            </label>
                            <label>
                                <span>Owner</span>
                                <input type="text" name="owner" id="exception-add-owner" maxlength="200" placeholder="Owner">
                            </label>
                            <label>
                                <span>Timeline</span>
                                <input type="text" name="timeline" id="exception-add-timeline" maxlength="200" placeholder="e.g. Before go-live">
                            </label>
                            <label class="exception-add-full">
                                <span>Impact</span>
                                <textarea name="impact" id="exception-add-impact" rows="2" maxlength="2000" placeholder="Business or security impact (optional)"></textarea>
                            </label>
                            <label class="exception-add-full">
                                <span>Required exception / mitigation</span>
                                <textarea name="mitigation" id="exception-add-mitigation" rows="2" maxlength="2000" placeholder="Mitigation or compensating control (optional)"></textarea>
                            </label>
                        </div>
                        <p class="exception-add-status" id="exception-add-status" hidden></p>
                        <div class="response-dialog-actions">
                            <button type="submit" value="cancel" class="button button-secondary">Cancel</button>
                            <button type="button" class="button button-primary" id="exception-add-save">Add exception</button>
                        </div>
                    </form>
                </dialog>
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
                            <?= $this->e($evalSavedLabel) ?>
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
                            <div class="final-eval-exec">
                                <div class="final-eval-exec-head">
                                    <span>Executive summary</span>
                                    <span class="exec-custom-pill" id="eval-exec-custom-pill" <?= !empty($insights['readiness']['is_custom']) ? '' : 'hidden' ?>>✏️ Customized</span>
                                </div>
                                <p class="panel-help">Optional override for the decision-desk headline and paragraph. Leave blank to keep the auto-generated “Conditional go-live ready” wording.</p>
                                <label>
                                    <span>Headline</span>
                                    <input type="text" name="executive_verdict" id="eval-exec-verdict" maxlength="200" value="<?= $this->e((string) ($insights['readiness']['custom_verdict'] ?? '')) ?>" placeholder="<?= $this->e((string) ($insights['readiness']['auto_verdict'] ?? 'Conditional go-live ready')) ?>">
                                </label>
                                <label>
                                    <span>Summary</span>
                                    <textarea name="executive_summary" id="eval-exec-summary" rows="3" maxlength="2000" placeholder="<?= $this->e((string) ($insights['readiness']['auto_summary'] ?? 'Write the go-live narrative for this version...')) ?>"><?= $this->e((string) ($insights['readiness']['custom_summary'] ?? '')) ?></textarea>
                                </label>
                                <?= $this->renderExecutivePresetChips('eval') ?>
                            </div>
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
                                    <?php if (!$readOnly): ?>
                                        <button type="submit" class="button button-primary" id="btn-save-evaluation">💾 Save evaluation</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                        <?= $this->renderChangeHistory(
                            'evaluation-history',
                            'Sign-off history',
                            $evaluationHistory,
                            false,
                            $currentId,
                            \RiskAssessment\Repositories\AssessmentChangeLogRepository::ENTITY_FINAL_EVALUATION,
                            '',
                            5
                        ) ?>
                    <?php endif; ?>
                </section>
            </div>

            <div class="action-panel" data-action-panel="versions" hidden>
                <?= $this->renderVersionsSection($versions, $comparison, $currentId, $csrfToken, $readOnly, $isOwner) ?>
            </div>

            <?php if ($isOwner && !$readOnly && $currentId > 0): ?>
                <div class="action-panel" data-action-panel="access" hidden>
                    <?= $this->renderAccessSection($currentId, $csrfToken, $isLocked, $projectEditors, $eligibleEditors) ?>
                </div>
                <div class="action-panel" data-action-panel="share" hidden>
                    <?= $this->renderShareSection($currentId, $csrfToken, $shareLinks, $freshShareUrl, $smtpEnabled, $viewerIsAdmin) ?>
                </div>
            <?php endif; ?>

            <?php if ($showWorkspaceTab): ?>
                <div class="action-panel" data-action-panel="workspace" hidden>
                    <div class="dash-panel-intro workspace-panel-intro">
                        <div class="dash-panel-intro-copy">
                            <span class="dash-panel-intro-icon" aria-hidden="true">🧰</span>
                            <div>
                                <div class="eyebrow">Readiness overview</div>
                                <h2>Actionability workspace</h2>
                                <p>This tab does not record responses. Use it to see who owns open work, when remediation is due, and how complete evidence is — then click a row to jump into Risks, Gaps, or TBD.</p>
                            </div>
                        </div>
                    </div>
                    <div class="actions-grid">
                        <?= $this->renderOwnersSection($owners) ?>
                        <?= $this->renderTimelinesSection($timelines) ?>
                        <?= $this->renderEvidenceSection($evidence) ?>
                    </div>
                </div>
            <?php endif; ?>

            <dialog class="response-dialog" id="item-response-dialog" aria-labelledby="response-dialog-title">
                <form method="dialog" class="response-dialog-form" id="item-response-dialog-form">
                    <div class="response-dialog-head">
                        <div>
                            <div class="eyebrow" id="response-dialog-eyebrow">📝 Recorded response</div>
                            <h3 id="response-dialog-title">Edit response</h3>
                            <p class="response-dialog-sub" id="response-dialog-sub"></p>
                        </div>
                        <button type="button" class="button ghost-light response-dialog-close" id="response-dialog-close" aria-label="Close">✕</button>
                    </div>
                    <section class="response-dialog-details" id="response-dialog-details" hidden>
                        <div class="response-dialog-details-banner">
                            <span class="response-dialog-details-icon" id="response-dialog-details-icon" aria-hidden="true">🏛️</span>
                            <div>
                                <div class="eyebrow">Risk context</div>
                                <p class="response-dialog-details-lead">Review the finding and controls before you respond.</p>
                            </div>
                        </div>
                        <div class="response-dialog-details-meta" id="response-dialog-details-meta">
                            <div class="response-dialog-detail-chip" data-detail="source" hidden>
                                <span class="response-dialog-detail-label">📦 Source</span>
                                <span class="response-dialog-detail-value" id="response-dialog-detail-source"></span>
                            </div>
                            <div class="response-dialog-detail-chip" data-detail="section" hidden>
                                <span class="response-dialog-detail-label">📁 Section</span>
                                <span class="response-dialog-detail-value" id="response-dialog-detail-section"></span>
                            </div>
                            <div class="response-dialog-detail-chip" data-detail="status" hidden>
                                <span class="response-dialog-detail-label">🚦 Status</span>
                                <span class="response-dialog-detail-value" id="response-dialog-detail-status"></span>
                            </div>
                            <div class="response-dialog-detail-chip" data-detail="risk" hidden>
                                <span class="response-dialog-detail-label">⚠️ Risk level</span>
                                <span class="response-dialog-detail-value" id="response-dialog-detail-risk"></span>
                            </div>
                            <div class="response-dialog-detail-chip" data-detail="owner" hidden>
                                <span class="response-dialog-detail-label">👤 Owner</span>
                                <span class="response-dialog-detail-value" id="response-dialog-detail-owner"></span>
                            </div>
                            <div class="response-dialog-detail-chip" data-detail="timeline" hidden>
                                <span class="response-dialog-detail-label">🗓️ Timeline</span>
                                <span class="response-dialog-detail-value" id="response-dialog-detail-timeline"></span>
                            </div>
                        </div>
                        <div class="response-dialog-detail-block" data-detail="notes" hidden>
                            <span class="response-dialog-detail-label">📋 Notes / finding</span>
                            <p class="response-dialog-detail-text" id="response-dialog-detail-notes"></p>
                        </div>
                        <div class="response-dialog-detail-block" data-detail="mitigation" hidden>
                            <span class="response-dialog-detail-label">🛡️ Mitigation / controls</span>
                            <p class="response-dialog-detail-text" id="response-dialog-detail-mitigation"></p>
                        </div>
                        <div class="response-dialog-detail-block" data-detail="review-question" hidden>
                            <span class="response-dialog-detail-label">❓ Review question</span>
                            <p class="response-dialog-detail-text" id="response-dialog-detail-review-question"></p>
                        </div>
                        <div class="response-dialog-detail-block" data-detail="source-ref" hidden>
                            <span class="response-dialog-detail-label">🔗 Source reference</span>
                            <p class="response-dialog-detail-text" id="response-dialog-detail-source-ref"></p>
                        </div>
                    </section>
                    <label class="response-dialog-field">
                        <span>✅ Our response</span>
                        <select id="response-dialog-action" required>
                            <?php foreach ($actionLabels as $value => $label): ?>
                                <option value="<?= $this->e($value) ?>"><?= $this->e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="response-dialog-field">
                        <span>💬 Comment</span>
                        <textarea id="response-dialog-comment" rows="4" maxlength="2000" placeholder="Add context, decision rationale, or next steps (optional)"></textarea>
                    </label>
                    <p class="item-response-attribution response-dialog-attribution" id="response-dialog-attribution" hidden></p>
                    <div
                        class="response-dialog-history history-panel is-compact"
                        id="response-dialog-history"
                        data-history-panel
                        data-assessment-id="<?= (int) $currentId ?>"
                        data-entity-type="<?= $this->e(\RiskAssessment\Repositories\AssessmentChangeLogRepository::ENTITY_ITEM_RESPONSE) ?>"
                        data-entity-key=""
                        data-heading="Activity log"
                        data-per-page="5"
                    >
                        <div class="history-panel-head">
                            <h4 class="history-panel-title">
                                🗂️ Activity log
                                <em class="history-panel-count" hidden>0</em>
                            </h4>
                            <label class="history-panel-search-wrap">
                                <span class="visually-hidden">Search history</span>
                                <input
                                    type="search"
                                    class="history-panel-search"
                                    placeholder="Search comments, status, author…"
                                    autocomplete="off"
                                >
                            </label>
                        </div>
                        <div class="history-panel-list" role="feed" aria-live="polite"></div>
                        <p class="history-panel-empty" hidden>📭 No history posts yet for this action item.</p>
                        <div class="history-panel-footer">
                            <span class="history-panel-meta"></span>
                            <div class="history-panel-footer-actions">
                                <label class="history-panel-per-page">
                                    <span>Rows</span>
                                    <select class="history-panel-per-page-select" aria-label="Rows per page">
                                        <option value="5" selected>5</option>
                                        <option value="10">10</option>
                                        <option value="20">20</option>
                                    </select>
                                </label>
                                <nav class="history-panel-pagination" aria-label="Activity log pages" hidden>
                                    <button type="button" class="button ghost history-panel-prev">← Prev</button>
                                    <span class="history-panel-page"></span>
                                    <button type="button" class="button ghost history-panel-next">Next →</button>
                                </nav>
                            </div>
                        </div>
                    </div>
                    <p class="response-dialog-status" id="response-dialog-status" hidden></p>
                    <div class="response-dialog-actions">
                        <button type="button" class="button ghost" id="response-dialog-cancel">Cancel</button>
                        <button type="submit" class="button button-primary" id="response-dialog-save">💾 Save response</button>
                    </div>
                </form>
            </dialog>
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
        array $actionLabels,
        bool $readOnly = false,
        bool $isAdaptive = false
    ): string {
        $archSourceLabel = $isAdaptive ? 'Material findings' : 'Architecture';
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
                <span class="result-count result-count-badge" data-workbench-count="<?= $this->e($scope) ?>"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
            </div>
            <?php if ($items === []): ?>
                <p class="empty-panel project-empty-state">✨ No items in this category right now.</p>
            <?php else: ?>
                <p class="panel-help">📋 <?= $this->e($help) ?><?= $readOnly ? '.' : '. Select listed rows to update them together, or edit one at a time.' ?></p>
                <p class="empty-panel project-empty-state action-source-empty" data-action-source-empty="<?= $this->e($scope) ?>" hidden>✨ No items from this source in this category.</p>
                <?php if (!$readOnly): ?>
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
                <?php endif; ?>
                <div class="table-scroll">
                    <table id="<?= $this->e($tableId) ?>">
                        <thead>
                            <tr>
                                <?php if (!$readOnly): ?><th class="col-select">Sel</th><?php endif; ?>
                                <th class="col-row-num">#</th>
                                <th>Section / category</th>
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
                                $rowNumber = (int) ($row['row_number'] ?? 0);
                                $action = \RiskAssessment\Repositories\ItemResponseRepository::normalizeAction((string) ($row['action'] ?? 'open'));
                                $comment = (string) ($row['comment'] ?? '');
                                $itemType = (string) ($row['item_type'] ?? 'architecture');
                                $sourceLabel = $itemType === 'due_diligence' ? 'Due diligence' : $archSourceLabel;
                                $sectionName = trim((string) ($row['section'] ?? ''));
                                $owner = (string) ($row['owner'] ?? '');
                                $updatedAt = (string) ($row['updated_at'] ?? '');
                                $updatedByLabel = (string) ($row['updated_by_label'] ?? '');
                                $history = is_array($row['history'] ?? null) ? $row['history'] : [];
                                ?>
                                <tr
                                    data-item-key="<?= $this->e($key) ?>"
                                    data-item-type="<?= $this->e($itemType) ?>"
                                    data-section="<?= $this->e($sectionName) ?>"
                                    data-row-number="<?= $rowNumber ?>"
                                    data-response="<?= $this->e($action) ?>"
                                    data-actionable="1"
                                    data-has-comment="<?= $comment !== '' ? '1' : '0' ?>"
                                >
                                    <?php if (!$readOnly): ?>
                                    <td class="col-select">
                                        <input type="checkbox" class="row-select" value="<?= $this->e($key) ?>" aria-label="Select <?= $this->e((string) ($row['check'] ?? '')) ?>">
                                    </td>
                                    <?php endif; ?>
                                    <td class="col-row-num">
                                        <?php if ($rowNumber > 0): ?>
                                            <span class="row-number" title="<?= $this->e($sourceLabel) ?> row <?= $rowNumber ?>">#<?= $rowNumber ?></span>
                                        <?php else: ?>
                                            <span class="response-na">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-section">
                                        <?php if ($sectionName !== ''): ?>
                                            <span class="action-section-label"><?= $this->e($sectionName) ?></span>
                                        <?php else: ?>
                                            <span class="response-na">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= $this->e((string) ($row['check'] ?? '')) ?></strong>
                                        <?php if ($owner !== ''): ?>
                                            <div class="subtext"><?= $this->e($owner) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="action-source-pill action-source-pill-<?= $itemType === 'due_diligence' ? 'diligence' : 'architecture' ?>"><?= $this->e($sourceLabel) ?></span></td>
                                    <td><?= $this->e((string) ($row['status'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($row['risk_level'] ?? '')) ?></td>
                                    <td class="response-cell">
                                        <?php
                                        $actionLabel = $actionLabels[$action] ?? 'Open';
                                        $commentPreview = $comment !== ''
                                            ? (mb_strlen($comment) > 90 ? mb_substr($comment, 0, 87) . '…' : $comment)
                                            : '';
                                        $checkTitle = (string) ($row['check'] ?? '');
                                        $sectionSub = $sectionName;
                                        if ($owner !== '') {
                                            $sectionSub .= ($sectionSub !== '' ? ' · ' : '') . $owner;
                                        }
                                        $notes = (string) ($row['notes'] ?? '');
                                        $mitigation = (string) ($row['mitigation'] ?? '');
                                        $timeline = (string) ($row['remediation_timeline'] ?? '');
                                        $reviewQuestion = (string) ($row['review_question'] ?? '');
                                        $sourceReference = (string) ($row['source_reference'] ?? '');
                                        ?>
                                        <div
                                            class="item-response"
                                            data-item-key="<?= $this->e($key) ?>"
                                            data-item-title="<?= $this->e($checkTitle) ?>"
                                            data-item-sub="<?= $this->e($sectionSub) ?>"
                                            data-item-source="<?= $this->e($sourceLabel) ?>"
                                            data-item-section="<?= $this->e($sectionName) ?>"
                                            data-item-status="<?= $this->e((string) ($row['status'] ?? '')) ?>"
                                            data-item-risk="<?= $this->e((string) ($row['risk_level'] ?? '')) ?>"
                                            data-item-owner="<?= $this->e($owner) ?>"
                                            data-item-timeline="<?= $this->e($timeline) ?>"
                                            data-item-notes="<?= $this->e($notes) ?>"
                                            data-item-mitigation="<?= $this->e($mitigation) ?>"
                                            data-item-review-question="<?= $this->e($reviewQuestion) ?>"
                                            data-item-source-ref="<?= $this->e($sourceReference) ?>"
                                        >
                                            <div class="item-response-summary">
                                                <div class="item-response-summary-main">
                                                    <span class="response-pill response-<?= $this->e($action) ?>"><?= $this->e($actionLabel) ?></span>
                                                    <?php if ($commentPreview !== ''): ?>
                                                        <p class="item-response-comment-preview"><?= $this->e($commentPreview) ?></p>
                                                    <?php else: ?>
                                                        <p class="item-response-comment-preview is-empty">No comment yet</p>
                                                    <?php endif; ?>
                                                    <span class="item-response-attribution" <?= $updatedAt === '' && $updatedByLabel === '' ? 'hidden' : '' ?>>
                                                        <?php if ($updatedByLabel !== ''): ?>
                                                            Updated by <?= $this->e($updatedByLabel) ?><?= $updatedAt !== '' ? ' · ' . $this->e($updatedAt) : '' ?>
                                                        <?php elseif ($updatedAt !== ''): ?>
                                                            Updated <?= $this->e($updatedAt) ?> · Not yet attributed
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="item-response-edit"
                                                    aria-label="Edit response for <?= $this->e($checkTitle) ?>"
                                                    title="Edit response"
                                                    <?= $readOnly ? 'hidden' : '' ?>
                                                >✏️</button>
                                            </div>
                                            <div class="item-response-fields" hidden>
                                                <select class="item-response-action" aria-label="Response" tabindex="-1">
                                                    <?php foreach ($actionLabels as $value => $label): ?>
                                                        <option value="<?= $this->e($value) ?>" <?= $action === $value ? 'selected' : '' ?>><?= $this->e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <textarea
                                                    class="item-response-comment"
                                                    rows="2"
                                                    maxlength="2000"
                                                    placeholder="Comment (optional)"
                                                    tabindex="-1"
                                                ><?= $this->e($comment) ?></textarea>
                                                <span class="item-response-save" hidden>Saved</span>
                                            </div>
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
    private function renderVersionsSection(array $versions, array $comparison, int $currentId, string $csrfToken, bool $readOnly = false, bool $isOwner = false): string
    {
        $canDelete = !$readOnly && $isOwner && $csrfToken !== '';
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
                <?php if ($canDelete && $olderCount > 0 && $currentId > 0): ?>
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
                                    <?php elseif ($readOnly): ?>
                                        Version #<?= $versionId ?>
                                    <?php else: ?>
                                        <a href="index.php?view=1&amp;id=<?= $versionId ?>">Version #<?= $versionId ?></a>
                                    <?php endif; ?>
                                </strong>
                                <span><?= $this->e((string) ($version['uploaded_at'] ?? '')) ?></span>
                                <span><?= $this->e((string) ($version['original_filename'] ?? '')) ?></span>
                            </div>
                            <div class="version-actions">
                                <?php if (!$readOnly && $versionId !== $currentId): ?>
                                    <a class="button ghost" href="index.php?view=1&amp;id=<?= $versionId ?>">Open</a>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
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

    /**
     * @param list<array{user_id: int, username: string, display_name: string, email: string, label: string, created_at: string}> $projectEditors
     * @param list<array<string, mixed>> $eligibleEditors
     */
    private function renderAccessSection(
        int $assessmentId,
        string $csrfToken,
        bool $isLocked,
        array $projectEditors,
        array $eligibleEditors
    ): string {
        ob_start();
        ?>
        <section class="table-card table-card-uplift project-access-card chart-card-tone-access" id="project-access-panel">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true">🔐</span>
                    <div>
                        <div class="eyebrow">👑 Ownership</div>
                        <h3>Project access</h3>
                    </div>
                </div>
                <span class="result-count result-count-badge project-access-status-badge<?= $isLocked ? ' is-locked' : ' is-open' ?>">
                    <?= $isLocked ? '🔒 Locked' : '👀 Open to view' ?>
                </span>
            </div>
            <p class="panel-help">You own this project. Everyone signed in can see it in Find projects. Editing requires your permission. Locking keeps the project listed, but only you, editors you invite, and administrators can open the details.</p>

            <div class="project-access-lock<?= $isLocked ? ' is-locked' : ' is-open' ?>">
                <div class="project-access-lock-copy">
                    <strong><?= $isLocked ? '🔒 Project is locked' : '🔓 Project is unlocked' ?></strong>
                    <p><?= $isLocked
                        ? 'People without access still see it in the list, but cannot open the dashboard.'
                        : 'Anyone signed in can open and view this project. Only you and invited editors can edit.' ?></p>
                </div>
                <?php if ($csrfToken !== '' && $assessmentId > 0): ?>
                    <form method="post" action="index.php" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                        <input type="hidden" name="assessment_id" value="<?= (int) $assessmentId ?>">
                        <input type="hidden" name="action" value="<?= $isLocked ? 'unlock_project' : 'lock_project' ?>">
                        <button type="submit" class="button <?= $isLocked ? 'button-primary' : 'ghost-light' ?>">
                            <?= $isLocked ? '🔓 Unlock project' : '🔒 Lock project' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="project-access-editors">
                <div class="card-heading card-heading-with-icon project-access-subhead">
                    <span class="card-icon card-icon-sm" aria-hidden="true">✏️</span>
                    <div>
                        <div class="eyebrow">👥 Editors</div>
                        <h3>People who can edit</h3>
                    </div>
                    <span class="result-count result-count-badge project-access-editor-count"><?= count($projectEditors) ?></span>
                </div>
                <p class="panel-help">Editors can change responses, resources, and upload new versions. They cannot lock the project, manage editors, create public share links, or delete versions.</p>
                <p class="panel-help project-access-notify-hint">📬 When Email is configured under Admin → Email, granting access or transferring ownership emails both parties. The recipient also sees a notice on the people icon.</p>

                <?php if ($csrfToken !== '' && $assessmentId > 0): ?>
                    <form method="post" action="index.php" class="project-access-grant-form">
                        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                        <input type="hidden" name="action" value="grant_editor">
                        <input type="hidden" name="assessment_id" value="<?= (int) $assessmentId ?>">
                        <label>
                            <span>➕ Grant edit access</span>
                            <select name="editor_user_id" required<?= $eligibleEditors === [] ? ' disabled' : '' ?>>
                                <option value=""><?= $eligibleEditors === [] ? 'No other approved users available' : 'Choose a user…' ?></option>
                                <?php foreach ($eligibleEditors as $user): ?>
                                    <?php
                                    $userId = (int) ($user['id'] ?? 0);
                                    $label = \RiskAssessment\Actor::formatLabel(
                                        trim((string) ($user['display_name'] ?? '')),
                                        trim((string) ($user['username'] ?? '')),
                                        trim((string) ($user['auth_source'] ?? ''))
                                    );
                                    ?>
                                    <option value="<?= $userId ?>"><?= $this->e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="button button-primary"<?= $eligibleEditors === [] ? ' disabled' : '' ?>>✨ Add editor</button>
                    </form>
                <?php endif; ?>

                <?php if ($projectEditors === []): ?>
                    <p class="empty-panel project-access-empty">👤 No editors yet. Only you can edit this project.</p>
                <?php else: ?>
                    <ul class="project-access-editor-list">
                        <?php foreach ($projectEditors as $editor): ?>
                            <li>
                                <div class="project-access-editor-identity">
                                    <span class="project-access-editor-avatar" aria-hidden="true">🧑‍💻</span>
                                    <div>
                                        <strong><?= $this->e((string) ($editor['label'] ?? '')) ?></strong>
                                        <?php if (trim((string) ($editor['email'] ?? '')) !== ''): ?>
                                            <span>✉️ <?= $this->e((string) $editor['email']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($csrfToken !== '' && $assessmentId > 0): ?>
                                    <form method="post" action="index.php" class="inline-form" onsubmit="return confirm('Remove edit access for this user?');">
                                        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="revoke_editor">
                                        <input type="hidden" name="assessment_id" value="<?= (int) $assessmentId ?>">
                                        <input type="hidden" name="editor_user_id" value="<?= (int) ($editor['user_id'] ?? 0) ?>">
                                        <button type="submit" class="button ghost-light">🗑️ Remove</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="project-access-transfer">
                <div class="card-heading card-heading-with-icon project-access-subhead">
                    <span class="card-icon card-icon-sm" aria-hidden="true">🧳</span>
                    <div>
                        <div class="eyebrow">👋 Leaving the team?</div>
                        <h3>Transfer ownership</h3>
                    </div>
                </div>
                <p class="panel-help">Make another person the owner of this project (all saved versions). They gain full control: edit, lock, share, delete, and manage editors. You can keep edit access as an editor after the handoff.</p>
                <?php
                $transferCandidates = [];
                $seenTransferIds = [];
                foreach (array_merge($eligibleEditors, $projectEditors) as $candidate) {
                    $candidateId = (int) ($candidate['id'] ?? $candidate['user_id'] ?? 0);
                    if ($candidateId <= 0 || isset($seenTransferIds[$candidateId])) {
                        continue;
                    }
                    $seenTransferIds[$candidateId] = true;
                    $transferCandidates[] = $candidate;
                }
                usort(
                    $transferCandidates,
                    static function (array $left, array $right): int {
                        $leftLabel = trim((string) ($left['display_name'] ?? '')) !== ''
                            ? (string) $left['display_name']
                            : (string) ($left['username'] ?? $left['label'] ?? '');
                        $rightLabel = trim((string) ($right['display_name'] ?? '')) !== ''
                            ? (string) $right['display_name']
                            : (string) ($right['username'] ?? $right['label'] ?? '');

                        return strcasecmp($leftLabel, $rightLabel);
                    }
                );
                ?>
                <?php if ($csrfToken !== '' && $assessmentId > 0): ?>
                    <form
                        method="post"
                        action="index.php"
                        class="project-access-grant-form project-access-transfer-form"
                        onsubmit="return confirm('Transfer ownership of this project to the selected user? This cannot be undone by you afterward.');"
                    >
                        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                        <input type="hidden" name="action" value="transfer_ownership">
                        <input type="hidden" name="assessment_id" value="<?= (int) $assessmentId ?>">
                        <label>
                            <span>👑 New owner</span>
                            <select name="new_owner_user_id" required<?= $transferCandidates === [] ? ' disabled' : '' ?>>
                                <option value=""><?= $transferCandidates === [] ? 'No other approved users available' : 'Choose a user…' ?></option>
                                <?php foreach ($transferCandidates as $user): ?>
                                    <?php
                                    $userId = (int) ($user['id'] ?? $user['user_id'] ?? 0);
                                    $label = trim((string) ($user['label'] ?? ''));
                                    if ($label === '') {
                                        $label = \RiskAssessment\Actor::formatLabel(
                                            trim((string) ($user['display_name'] ?? '')),
                                            trim((string) ($user['username'] ?? '')),
                                            trim((string) ($user['auth_source'] ?? ''))
                                        );
                                    }
                                    ?>
                                    <option value="<?= $userId ?>"><?= $this->e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="project-access-keep-editor">
                            <input type="checkbox" name="keep_former_editor" value="1" checked>
                            <span>🤝 Keep me as an editor after transfer</span>
                        </label>
                        <button type="submit" class="button button-primary"<?= $transferCandidates === [] ? ' disabled' : '' ?>>🚀 Transfer this project</button>
                    </form>
                    <p class="panel-help project-access-bulk-link"><a href="transfer-ownership.php">📦 Transfer several or all of your projects →</a></p>
                <?php endif; ?>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array{id: int, created_at: string, created_by_username: string, expires_at: ?string, last_accessed_at: ?string, is_active: bool, url?: string, can_copy?: bool}> $shareLinks
     */
    private function renderShareSection(
        int $assessmentId,
        string $csrfToken,
        array $shareLinks,
        ?string $freshShareUrl,
        bool $smtpEnabled = false,
        bool $viewerIsAdmin = false
    ): string {
        $activeLinks = array_values(array_filter($shareLinks, static fn (array $link): bool => !empty($link['is_active'])));
        $hasActive = $activeLinks !== [];
        $copyableActive = null;
        foreach ($activeLinks as $link) {
            if (!empty($link['can_copy']) && trim((string) ($link['url'] ?? '')) !== '') {
                $copyableActive = $link;
                break;
            }
        }

        ob_start();
        ?>
        <section class="table-card table-card-uplift share-link-card" id="share-link-panel">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true">🔗</span>
                    <div>
                        <div class="eyebrow">Public access</div>
                        <h3>Read-only share link</h3>
                    </div>
                </div>
                <span class="result-count result-count-badge"><?= $hasActive ? 'Active' : 'Off' ?></span>
            </div>
            <p class="panel-help">Create a public link so people can view this assessment version without signing in. Recipients cannot edit responses, diagrams, or evaluations. Creating a new link revokes the previous one. You can copy the active link again anytime from the list below.</p>

            <?php if ($freshShareUrl !== null && $freshShareUrl !== ''): ?>
                <?php $freshSharePreview = ShareUrlPresenter::truncate($freshShareUrl); ?>
                <div class="share-link-fresh alert alert-success">
                    <strong>Public share link created</strong>
                    <div class="share-link-copy-row share-link-copy-row-uplift">
                        <input type="hidden" id="share-link-url" value="<?= $this->e($freshShareUrl) ?>">
                        <code class="share-link-url-preview" title="<?= $this->e($freshShareUrl) ?>"><?= $this->e($freshSharePreview) ?></code>
                        <div class="share-link-action-group">
                            <button type="button" class="button button-primary share-link-copy-btn" id="btn-copy-share-link" data-copy-input="share-link-url" data-copy-status="share-link-copy-status" title="Copy full link">📋 Copy</button>
                            <a class="button ghost-light" href="<?= $this->e($freshShareUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open share link">↗ Open</a>
                        </div>
                    </div>
                    <p class="share-link-copy-status" id="share-link-copy-status" hidden></p>
                </div>
            <?php endif; ?>

            <div class="share-link-actions">
                <?php if ($csrfToken !== '' && $assessmentId > 0): ?>
                    <form method="post" action="index.php" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_share_link">
                        <input type="hidden" name="assessment_id" value="<?= (int) $assessmentId ?>">
                        <button type="submit" class="button button-primary">
                            <?= $hasActive ? '🔄 Create new link' : '🔗 Create share link' ?>
                        </button>
                    </form>
                    <?php if ($hasActive): ?>
                        <form method="post" action="index.php" class="inline-form" onsubmit="return confirm('Revoke the public share link? Anyone with the old URL will lose access.');">
                            <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                            <input type="hidden" name="action" value="revoke_share_link">
                            <input type="hidden" name="assessment_id" value="<?= (int) $assessmentId ?>">
                            <input type="hidden" name="share_id" value="<?= (int) $activeLinks[0]['id'] ?>">
                            <button type="submit" class="button danger-btn">🚫 Revoke link</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($copyableActive !== null && $csrfToken !== ''): ?>
                <?php if ($smtpEnabled): ?>
                    <form method="post" action="index.php" class="share-email-form">
                        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
                        <input type="hidden" name="action" value="email_share_link">
                        <input type="hidden" name="assessment_id" value="<?= (int) $assessmentId ?>">
                        <input type="hidden" name="share_id" value="<?= (int) $copyableActive['id'] ?>">
                        <p class="share-email-form-title">✉️ Email this link</p>
                        <label>
                            <span>To (comma or newline separated, max 20)</span>
                            <textarea name="email_to" rows="2" required maxlength="2000" placeholder="colleague@example.com"></textarea>
                        </label>
                        <label>
                            <span>Optional note</span>
                            <textarea name="email_note" rows="2" maxlength="1000" placeholder="Short message for the recipient…"></textarea>
                        </label>
                        <button type="submit" class="button button-primary">📨 Send email</button>
                    </form>
                <?php elseif ($viewerIsAdmin): ?>
                    <p class="share-email-hint">To email this link, configure SMTP under <a href="admin/email.php">Admin → Email</a>.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($shareLinks === []): ?>
                <p class="empty-panel project-empty-state">No share links yet for this version.</p>
            <?php else: ?>
                <div class="share-link-history">
                    <div class="card-heading" style="padding-top: 8px;">
                        <div>
                            <div class="eyebrow">History</div>
                            <h3>Recent share links</h3>
                        </div>
                    </div>
                    <ul class="share-link-list">
                        <?php foreach ($shareLinks as $link): ?>
                            <?php
                            $linkId = (int) ($link['id'] ?? 0);
                            $linkActive = !empty($link['is_active']);
                            $linkUrl = trim((string) ($link['url'] ?? ''));
                            $linkCanCopy = $linkActive && !empty($link['can_copy']) && $linkUrl !== '';
                            $rowUrlInputId = 'share-link-url-assessment-' . $linkId;
                            $rowStatusId = 'share-link-copy-status-assessment-' . $linkId;
                            $linkPreview = $linkUrl !== '' ? ShareUrlPresenter::truncate($linkUrl) : '';
                            $meta = 'Created ' . (string) ($link['created_at'] ?? '');
                            if (($link['created_by_username'] ?? '') !== '') {
                                $meta .= ' · ' . (string) $link['created_by_username'];
                            }
                            if (($link['last_accessed_at'] ?? null) !== null) {
                                $meta .= ' · Opened ' . (string) $link['last_accessed_at'];
                            }
                            ?>
                            <li class="share-link-row share-link-row-uplift <?= $linkActive ? 'is-active' : 'is-revoked' ?>">
                                <div class="share-link-row-main">
                                    <span class="share-link-status-pill <?= $linkActive ? 'is-active' : 'is-revoked' ?>"><?= $linkActive ? 'Active' : 'Revoked / expired' ?></span>
                                    <span class="share-link-row-meta"><?= $this->e($meta) ?></span>
                                </div>
                                <?php if ($linkCanCopy): ?>
                                    <div class="share-link-copy-row share-link-copy-row-uplift">
                                        <input type="hidden" id="<?= $this->e($rowUrlInputId) ?>" value="<?= $this->e($linkUrl) ?>">
                                        <code class="share-link-url-preview" title="<?= $this->e($linkUrl) ?>"><?= $this->e($linkPreview) ?></code>
                                        <div class="share-link-action-group">
                                            <button type="button" class="button button-primary share-link-copy-btn" data-copy-input="<?= $this->e($rowUrlInputId) ?>" data-copy-status="<?= $this->e($rowStatusId) ?>" title="Copy full link">📋 Copy</button>
                                            <a class="button ghost-light" href="<?= $this->e($linkUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open share link">↗ Open</a>
                                        </div>
                                    </div>
                                    <p class="share-link-copy-status" id="<?= $this->e($rowStatusId) ?>" hidden></p>
                                <?php elseif ($linkActive): ?>
                                    <p class="share-link-row-legacy">URL was not stored for this older link. Create a new link to copy the address again.</p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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
                        <h3>
                            Owner workload
                            <button type="button" class="section-help" data-tooltip="Open items grouped by assigned owner. Click a row to filter Actions to that owner’s work." aria-label="What is Owner workload?">?</button>
                        </h3>
                        <p class="section-stat-legend" aria-label="Number meanings">
                            <span class="has-tooltip" data-tooltip="Total open or actionable items assigned to this owner."><b>n</b> total</span>
                            <span class="has-tooltip" data-tooltip="Items with High risk level for this owner."><em>H</em> High risk</span>
                            <span class="has-tooltip" data-tooltip="Items with Risk status (not High) for this owner."><em>R</em> Risk status</span>
                            <span class="has-tooltip" data-tooltip="Items still marked TBD for this owner."><em>T</em> TBD</span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="owner-list">
                <?php if ($owners === []): ?>
                    <p class="empty-panel">No owners listed on open items yet.</p>
                <?php endif; ?>
                <?php foreach ($owners as $owner): ?>
                    <?php
                    $ownerName = (string) $owner['owner'];
                    $ownerTotal = (int) $owner['total'];
                    $ownerHigh = (int) $owner['high'];
                    $ownerRisk = (int) $owner['risk'];
                    $ownerTbd = (int) $owner['tbd'];
                    $rowTip = sprintf(
                        '%s — %d total item%s: %d High risk (H), %d Risk status (R), %d TBD (T). Click to filter Actions.',
                        $ownerName,
                        $ownerTotal,
                        $ownerTotal === 1 ? '' : 's',
                        $ownerHigh,
                        $ownerRisk,
                        $ownerTbd
                    );
                    ?>
                    <button
                        type="button"
                        class="owner-row has-tooltip"
                        data-filter-type="owner"
                        data-filter-value="<?= $this->e($ownerName) ?>"
                        data-tooltip="<?= $this->e($rowTip) ?>"
                    >
                        <span class="owner-name"><?= $this->e($ownerName) ?></span>
                        <span class="owner-stats" aria-hidden="true">
                            <b><?= $ownerTotal ?></b>
                            <em><?= $ownerHigh ?>H</em>
                            <em><?= $ownerRisk ?>R</em>
                            <em><?= $ownerTbd ?>T</em>
                        </span>
                        <span class="visually-hidden"><?= $this->e($rowTip) ?></span>
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
                        <h3>
                            Remediation lanes
                            <button type="button" class="section-help" data-tooltip="Open items grouped by remediation timeline. The number is the item count in that lane; the bar shows High / Risk / TBD mix. Click a lane to filter Actions." aria-label="What are Remediation lanes?">?</button>
                        </h3>
                        <p class="section-stat-legend" aria-label="Bar color meanings">
                            <span class="has-tooltip legend-swatch legend-high" data-tooltip="Red segment: High risk items in this timeline.">High</span>
                            <span class="has-tooltip legend-swatch legend-risk" data-tooltip="Orange segment: Risk-status items in this timeline.">Risk</span>
                            <span class="has-tooltip legend-swatch legend-tbd" data-tooltip="Gray segment: TBD items in this timeline.">TBD</span>
                            <span class="has-tooltip legend-swatch legend-other" data-tooltip="Green segment: remaining items in this timeline that are not High, Risk, or TBD.">Other</span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="timeline-lanes">
                <?php foreach ($timelines as $lane): ?>
                    <?php
                    $laneLabel = (string) $lane['label'];
                    $laneTotal = (int) $lane['total'];
                    $laneHigh = (int) $lane['high'];
                    $laneRisk = (int) $lane['risk'];
                    $laneTbd = (int) $lane['tbd'];
                    $laneOther = max(0, $laneTotal - $laneHigh - $laneRisk - $laneTbd);
                    $laneTip = sprintf(
                        '%s — %d item%s: %d High, %d Risk, %d TBD, %d other. Click to filter Actions.',
                        $laneLabel,
                        $laneTotal,
                        $laneTotal === 1 ? '' : 's',
                        $laneHigh,
                        $laneRisk,
                        $laneTbd,
                        $laneOther
                    );
                    ?>
                    <button
                        type="button"
                        class="timeline-lane has-tooltip"
                        data-filter-type="timeline"
                        data-filter-value="<?= $this->e((string) $lane['lane']) ?>"
                        data-tooltip="<?= $this->e($laneTip) ?>"
                    >
                        <div class="timeline-lane-head">
                            <strong><?= $this->e($laneLabel) ?></strong>
                            <span><?= $laneTotal ?></span>
                        </div>
                        <div class="bar-track" aria-hidden="true">
                            <span class="bar-fill bar-risk" style="width: <?= round(($laneHigh / $maxTimeline) * 100, 2) ?>%"></span>
                            <span class="bar-fill bar-gap" style="width: <?= round(($laneRisk / $maxTimeline) * 100, 2) ?>%"></span>
                            <span class="bar-fill bar-tbd" style="width: <?= round(($laneTbd / $maxTimeline) * 100, 2) ?>%"></span>
                            <span class="bar-fill bar-pass" style="width: <?= round(($laneOther / $maxTimeline) * 100, 2) ?>%"></span>
                        </div>
                        <span class="visually-hidden"><?= $this->e($laneTip) ?></span>
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
        $covered = (int) ($evidence['covered'] ?? 0);
        $partial = (int) ($evidence['partial'] ?? 0);
        $missing = (int) ($evidence['missing'] ?? 0);
        $evidenceTotal = (int) ($evidence['total'] ?? 0);
        $total = max(1, $evidenceTotal);
        $badgeTip = sprintf(
            '%d of %d checklist items have full evidence. %d partial, %d missing.',
            $covered,
            $evidenceTotal,
            $partial,
            $missing
        );

        ob_start();
        ?>
        <section class="table-card table-card-uplift chart-card-tone-workspace">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true">✅</span>
                    <div>
                        <div class="eyebrow">Trust / completeness</div>
                        <h3>
                            Evidence checklist
                            <button type="button" class="section-help" data-tooltip="Shows how complete supporting evidence is across key trust items. Covered = documented, Partial = incomplete notes, Missing = no evidence yet." aria-label="What is Evidence checklist?">?</button>
                        </h3>
                        <p class="section-stat-legend" aria-label="Evidence status meanings">
                            <span class="has-tooltip legend-swatch legend-covered" data-tooltip="Green: evidence is documented for this checklist item.">Covered</span>
                            <span class="has-tooltip legend-swatch legend-partial" data-tooltip="Amber: some evidence notes exist but are incomplete.">Partial</span>
                            <span class="has-tooltip legend-swatch legend-missing" data-tooltip="Red: no usable evidence recorded yet.">Missing</span>
                        </p>
                    </div>
                </div>
                <span
                    class="result-count result-count-badge has-tooltip"
                    data-tooltip="<?= $this->e($badgeTip) ?>"
                ><?= $covered ?>/<?= $evidenceTotal ?> covered</span>
            </div>
            <div
                class="evidence-meter has-tooltip"
                data-tooltip="<?= $this->e($badgeTip) ?>"
                role="img"
                aria-label="<?= $this->e($badgeTip) ?>"
            >
                <span class="ev-covered" style="width: <?= round(($covered / $total) * 100, 2) ?>%"></span>
                <span class="ev-partial" style="width: <?= round(($partial / $total) * 100, 2) ?>%"></span>
                <span class="ev-missing" style="width: <?= round(($missing / $total) * 100, 2) ?>%"></span>
            </div>
            <ul class="evidence-list">
                <?php foreach (($evidence['items'] ?? []) as $item): ?>
                    <?php
                    $status = (string) ($item['status'] ?? '');
                    $statusTip = match ($status) {
                        'covered' => 'Covered: evidence is documented.',
                        'partial' => 'Partial: evidence notes are incomplete.',
                        'missing' => 'Missing: no usable evidence yet.',
                        default => 'Evidence status for this checklist item.',
                    };
                    ?>
                    <li class="evidence-<?= $this->e($status) ?> has-tooltip" data-tooltip="<?= $this->e($statusTip) ?>">
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

    private function renderExecutivePresetChips(string $target): string
    {
        $presets = ExecutiveSummaryPresets::all();
        ob_start();
        ?>
        <div class="exec-preset-row" data-exec-preset-target="<?= $this->e($target) ?>">
            <span class="exec-preset-label">Presets</span>
            <div class="exec-preset-chips">
                <?php foreach ($presets as $preset): ?>
                    <button
                        type="button"
                        class="exec-preset-chip"
                        data-exec-preset
                        data-verdict="<?= $this->e((string) $preset['verdict']) ?>"
                        data-summary="<?= $this->e((string) $preset['summary']) ?>"
                        title="<?= $this->e((string) $preset['summary']) ?>"
                    ><?= $this->e((string) $preset['label']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string, mixed>> $entries Unused when loading via AJAX; kept for callers.
     */
    private function renderChangeHistory(
        string $id,
        string $heading,
        array $entries = [],
        bool $compact = false,
        int $assessmentId = 0,
        string $entityType = '',
        string $entityKey = '',
        int $perPage = 5
    ): string {
        $totalHint = count($entries);
        $hasSeed = $assessmentId > 0 && $entityType !== '';
        ob_start();
        ?>
        <section
            class="history-panel<?= $compact ? ' is-compact' : '' ?>"
            id="<?= $this->e($id) ?>"
            data-history-panel
            data-assessment-id="<?= (int) $assessmentId ?>"
            data-entity-type="<?= $this->e($entityType) ?>"
            data-entity-key="<?= $this->e($entityKey) ?>"
            data-heading="<?= $this->e($heading) ?>"
            data-per-page="<?= max(1, min(20, $perPage)) ?>"
            <?= !$hasSeed && $entries === [] ? 'hidden' : '' ?>
        >
            <div class="history-panel-head">
                <h4 class="history-panel-title">
                    <?= $this->e($heading) ?>
                    <em class="history-panel-count" <?= $totalHint === 0 ? 'hidden' : '' ?>><?= (int) $totalHint ?></em>
                </h4>
                <label class="history-panel-search-wrap">
                    <span class="visually-hidden">Search history</span>
                    <input
                        type="search"
                        class="history-panel-search"
                        placeholder="Search comments, status, author…"
                        autocomplete="off"
                    >
                </label>
            </div>
            <div class="history-panel-list" role="feed" aria-live="polite"></div>
            <p class="history-panel-empty" hidden>No history posts yet.</p>
            <div class="history-panel-footer">
                <span class="history-panel-meta"></span>
                <div class="history-panel-footer-actions">
                    <label class="history-panel-per-page">
                        <span>Rows</span>
                        <select class="history-panel-per-page-select" aria-label="Rows per page">
                            <?php foreach ([5, 10, 20] as $size): ?>
                                <option value="<?= (int) $size ?>"<?= max(1, min(20, $perPage)) === $size ? ' selected' : '' ?>><?= (int) $size ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <nav class="history-panel-pagination" aria-label="<?= $this->e($heading) ?> pages" hidden>
                        <button type="button" class="button ghost history-panel-prev">← Prev</button>
                        <span class="history-panel-page"></span>
                        <button type="button" class="button ghost history-panel-next">Next →</button>
                    </nav>
                </div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $finding
     */
    private function renderExceptionRow(array $finding): string
    {
        $findingId = (string) ($finding['id'] ?? '');
        $status = \RiskAssessment\Repositories\FindingStatusRepository::normalizeStatus((string) ($finding['status'] ?? 'Open'));
        $comment = (string) ($finding['comment'] ?? '');
        $links = is_array($finding['servicenow_links'] ?? null) ? $finding['servicenow_links'] : [];
        $links = \RiskAssessment\Repositories\FindingStatusRepository::normalizeLinks($links);
        $commentPreview = $comment !== ''
            ? (mb_strlen($comment) > 90 ? mb_substr($comment, 0, 87) . '…' : $comment)
            : '';
        $linkCount = count($links);

        ob_start();
        ?>
        <tr
            data-finding-id="<?= $this->e($findingId) ?>"
            class="exception-row"
            data-finding-text="<?= $this->e((string) ($finding['finding'] ?? '')) ?>"
            data-policy="<?= $this->e((string) ($finding['policy_reference'] ?? '')) ?>"
            data-owner="<?= $this->e((string) ($finding['owner'] ?? '')) ?>"
            data-timeline="<?= $this->e((string) ($finding['timeline'] ?? '')) ?>"
            data-mitigation="<?= $this->e((string) ($finding['mitigation'] ?? '')) ?>"
            data-impact="<?= $this->e((string) ($finding['impact'] ?? '')) ?>"
            data-comment="<?= $this->e($comment) ?>"
            data-servicenow-links="<?= $this->e(json_encode(array_values($links), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') ?>"
        >
            <td>
                <div class="exception-status-wrap">
                    <select class="exception-status" data-finding-id="<?= $this->e($findingId) ?>" aria-label="Exception status">
                        <?php foreach (\RiskAssessment\Repositories\FindingStatusRepository::STATUSES as $option): ?>
                            <option value="<?= $this->e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= $this->e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="exception-status-save" hidden></span>
                </div>
            </td>
            <td class="exception-finding-cell">
                <div class="clamp-text" data-expandable><?= $this->e((string) ($finding['finding'] ?? '')) ?></div>
                <?php if (($finding['policy_reference'] ?? '') !== ''): ?>
                    <div class="subtext">Policy: <?= $this->e((string) $finding['policy_reference']) ?></div>
                <?php endif; ?>
                <?php if (($finding['mitigation'] ?? '') !== ''): ?>
                    <div class="subtext clamp-text" data-expandable><?= $this->e((string) $finding['mitigation']) ?></div>
                <?php endif; ?>
            </td>
            <td class="exception-notes-preview-cell">
                <div class="exception-notes-preview">
                    <?php if ($commentPreview !== ''): ?>
                        <p class="exception-comment-preview"><?= $this->e($commentPreview) ?></p>
                    <?php else: ?>
                        <p class="exception-comment-preview is-empty">No comments yet</p>
                    <?php endif; ?>
                    <span class="exception-link-preview <?= $linkCount === 0 ? 'is-empty' : '' ?>">
                        <?= $linkCount === 0 ? 'No ServiceNow links' : $linkCount . ' ServiceNow link' . ($linkCount === 1 ? '' : 's') ?>
                    </span>
                </div>
            </td>
            <td><?= $this->e((string) ($finding['owner'] ?? '')) ?></td>
            <td><?= $this->e((string) ($finding['timeline'] ?? '')) ?></td>
            <td class="col-actions">
                <div class="exception-row-actions">
                    <button
                        type="button"
                        class="button button-secondary exception-edit-row"
                        data-finding-id="<?= $this->e($findingId) ?>"
                        title="Edit exception details"
                        aria-label="Edit exception details"
                    >✏️</button>
                    <button
                        type="button"
                        class="button button-secondary exception-delete-row"
                        data-finding-id="<?= $this->e($findingId) ?>"
                        title="Delete exception"
                        aria-label="Delete exception"
                    >🗑️</button>
                </div>
            </td>
        </tr>
        <?php

        return (string) ob_get_clean();
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
