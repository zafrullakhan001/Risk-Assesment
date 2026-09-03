<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Models\Assessment;

final class DashboardRenderer
{
    /** @var array<string, string> */
    private const STATUS_COLOR = [
        'Pass' => '#0f766e',
        'Gap' => '#c2410c',
        'Risk' => '#be123c',
        'TBD' => '#64748b',
        'N/A' => '#94a3b8',
    ];

    /** @var array<string, string> */
    private const RISK_COLOR = [
        'High' => '#be123c',
        'Med' => '#c2410c',
        'Low' => '#0f766e',
    ];

    /** @var list<string> */
    private const SECTION_COLORS = [
        '#0e7490', '#0f766e', '#0284c7', '#155e75', '#c2410c', '#be123c', '#64748b', '#0369a1',
    ];

    /**
     * @param array<string, mixed> $comparison
     * @param list<array<string, mixed>> $versions
     * @param array<string, array{action: string, comment: string, updated_at?: string}> $responses
     * @param array{
     *   evaluator_name?: string,
     *   evaluator_email?: string,
     *   notes?: string,
     *   ready_to_golive?: bool,
     *   updated_at?: string
     * }|null $evaluation
     */
    public function render(
        Assessment $assessment,
        string $sourceFilename = '',
        int $assessmentId = 0,
        array $comparison = [],
        array $versions = [],
        string $csrfToken = '',
        string $flash = '',
        array $responses = [],
        ?array $evaluation = null
    ): string {
        $metadata = $assessment->metadata;
        $summary = $assessment->summary;
        $items = $assessment->items;
        $dueItems = $assessment->dueDiligenceItems;
        $workbook = $assessment->workbook;
        $ddSummary = is_array($summary['due_diligence'] ?? null) ? $summary['due_diligence'] : Assessment::summarizeItems($dueItems);
        $insights = (new AssessmentInsights())->build($assessment);
        $decisionViews = new DashboardDecisionViews();
        $changedKeys = is_array($comparison['changed_keys'] ?? null) ? $comparison['changed_keys'] : [];
        $actionableItems = $this->collectActionableItems($items, $dueItems, $responses);

        $solutionName = $metadata['solution_name'] ?: 'Risk Assessment Dashboard';
        $assessmentDate = $metadata['date'] ?: date('Y-m-d');
        $hasDueDiligence = $dueItems !== [];
        $hasGovernance = ($workbook['fields'] ?? []) !== [] || ($workbook['findings'] ?? []) !== [];
        $hasLegend = ($workbook['legend']['statuses'] ?? []) !== [] || ($workbook['legend']['checklist'] ?? []) !== [];

        $statusSlices = $this->buildStatusSlices($summary);
        $riskSlices = $this->buildRiskSlices($summary);
        $sectionSlices = $this->buildSectionSlices($summary);
        $ddStatusSlices = $this->buildStatusSlices($ddSummary);
        $ddRiskSlices = $this->buildRiskSlices($ddSummary);
        $ddSectionSlices = $this->buildSectionSlices($ddSummary);

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($solutionName) ?></title>
    <?php require dirname(__DIR__) . '/public/includes/theme-head.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(dirname(__DIR__) . '/public/assets/css/dashboard.css') ?>">
</head>
<body data-assessment-id="<?= (int) $assessmentId ?>" data-csrf-token="<?= $this->e($csrfToken) ?>" class="<?= !empty($evaluation['ready_to_golive']) ? 'is-ready-golive' : '' ?>">
    <div class="shell">
        <header class="topbar">
            <a class="brand brand-link" href="index.php#find-projects" title="Back to find projects">
                <?= $this->brandMark() ?>
                <div>
                    <div class="brand-title">Architecture Risk</div>
                    <h1>Assessment register</h1>
                </div>
            </a>
            <div class="topbar-actions">
                <a class="button ghost home-link" href="index.php#find-projects">← Find projects</a>
                <?php require dirname(__DIR__) . '/public/includes/theme-controls.php'; ?>
                <div class="updated">
                    <span class="live-dot"></span>
                    <span>Assessment date <?= $this->e($assessmentDate) ?></span>
                </div>
            </div>
        </header>

        <main>
            <?php if ($flash !== ''): ?>
                <div class="alert alert-success desk-flash"><?= $this->e($flash) ?></div>
            <?php endif; ?>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">Architecture risk signal desk</div>
                            <h2>Risk <em>posture</em></h2>
                            <?= $decisionViews->renderTrendChips($comparison) ?>
                        </div>
                        <div class="hero-art">
                            <?= $this->renderDonutChart($statusSlices, 'hero-donut', (string) $summary['total'], 'checks', true) ?>
                        </div>
                    </div>
                    <div class="hero-project">
                        <span class="hero-project-label">Project</span>
                        <p class="hero-project-name"><?= $this->e($solutionName) ?></p>
                        <?php if (!empty($evaluation['ready_to_golive'])): ?>
                            <span class="golive-pill">Ready to go-live</span>
                        <?php endif; ?>
                        <?= $this->renderRiskSpectrum($summary) ?>
                    </div>
                    <div class="hero-actions">
                        <a class="button ghost" href="index.php#find-projects">← Home · Find projects</a>
                        <a class="button ghost" href="index.php#upload">Upload another file</a>
                        <a class="button button-primary" href="#risk-register">View register</a>
                    </div>
                </div>
            </section>

            <?= $decisionViews->renderDecisionDesk($insights, $assessmentId, $comparison, $evaluation) ?>

            <?= $this->renderKpis($summary, 'architecture') ?>

            <section class="meta-grid">
                <div class="meta-item meta-vendor"><span class="label">Vendor</span><strong><?= $this->e($metadata['vendor'] ?? '') ?></strong></div>
                <div class="meta-item meta-scope"><span class="label">Scope</span><strong><?= $this->e($metadata['scope'] ?? '') ?></strong></div>
                <div class="meta-item meta-arch"><span class="label">Architecture model</span><strong><?= $this->e($metadata['architecture_model'] ?? '') ?></strong></div>
                <div class="meta-item meta-reviewer"><span class="label">Reviewer</span><strong><?= $this->e($metadata['reviewer'] ?? '') ?></strong></div>
                <?php if (($metadata['ddr_id'] ?? '') !== ''): ?>
                    <div class="meta-item meta-file"><span class="label">DDR</span><strong><?= $this->e($metadata['ddr_id']) ?></strong></div>
                <?php endif; ?>
                <?php if (($metadata['vra_id'] ?? '') !== ''): ?>
                    <div class="meta-item meta-file"><span class="label">VRA</span><strong><?= $this->e($metadata['vra_id']) ?></strong></div>
                <?php endif; ?>
                <?php if (($metadata['overall_risk_rating'] ?? '') !== ''): ?>
                    <div class="meta-item meta-reviewer"><span class="label">Overall risk rating</span><strong><?= $this->e($metadata['overall_risk_rating']) ?></strong></div>
                <?php endif; ?>
                <?php if (($metadata['business_unit'] ?? '') !== ''): ?>
                    <div class="meta-item meta-scope"><span class="label">Business unit</span><strong><?= $this->e($metadata['business_unit']) ?></strong></div>
                <?php endif; ?>
                <?php if ($sourceFilename !== ''): ?>
                    <div class="meta-item meta-file"><span class="label">Source file</span><strong><?= $this->e($sourceFilename) ?></strong></div>
                <?php endif; ?>
            </section>

            <nav class="dash-tabs" role="tablist" aria-label="Workbook tabs">
                <button type="button" class="dash-tab is-active" role="tab" aria-selected="true" data-tab="architecture">Architecture checks</button>
                <?php if ($hasDueDiligence): ?>
                    <button type="button" class="dash-tab" role="tab" aria-selected="false" data-tab="due-diligence">Due diligence</button>
                <?php endif; ?>
                <button type="button" class="dash-tab" role="tab" aria-selected="false" data-tab="actions">Actions</button>
                <?php if ($hasGovernance): ?>
                    <button type="button" class="dash-tab" role="tab" aria-selected="false" data-tab="governance">Governance summary</button>
                <?php endif; ?>
                <?php if ($hasLegend): ?>
                    <button type="button" class="dash-tab" role="tab" aria-selected="false" data-tab="legend">Scoring legend</button>
                <?php endif; ?>
            </nav>

            <div class="dash-panel is-active" data-panel="architecture">
                <?= $this->renderChartsBlock($statusSlices, $riskSlices, $sectionSlices, $summary, 'architecture') ?>
                <?= $this->renderSectionBars($summary['by_section'] ?? []) ?>
                <?= $this->renderRegister(
                    'architecture',
                    'risk-register',
                    'Architecture checks',
                    'Risk register',
                    $items,
                    $summary,
                    false,
                    $changedKeys,
                    $responses,
                    $assessmentId
                ) ?>
            </div>

            <?php if ($hasDueDiligence): ?>
                <div class="dash-panel" data-panel="due-diligence" hidden>
                    <?php if (($workbook['context'] ?? '') !== ''): ?>
                        <div class="context-banner"><?= $this->e((string) $workbook['context']) ?></div>
                    <?php endif; ?>
                    <?= $this->renderKpis($ddSummary, 'due_diligence') ?>
                    <?= $this->renderChartsBlock($ddStatusSlices, $ddRiskSlices, $ddSectionSlices, $ddSummary, 'due_diligence') ?>
                    <?= $this->renderSectionBars($ddSummary['by_section'] ?? []) ?>
                    <?= $this->renderRegister(
                        'due_diligence',
                        'dd-register',
                        'Due diligence extension',
                        'Technology risk template',
                        $dueItems,
                        $ddSummary,
                        true,
                        $changedKeys,
                        $responses,
                        $assessmentId
                    ) ?>
                </div>
            <?php endif; ?>

            <div class="dash-panel" data-panel="actions" hidden>
                <?= $decisionViews->renderActionsPanel($insights, $comparison, $versions, $assessmentId, $csrfToken, $actionableItems) ?>
            </div>

            <?php if ($hasGovernance): ?>
                <div class="dash-panel" data-panel="governance" hidden>
                    <?= $this->renderGovernancePanel($workbook, $metadata) ?>
                </div>
            <?php endif; ?>

            <?php if ($hasLegend): ?>
                <div class="dash-panel" data-panel="legend" hidden>
                    <?= $this->renderLegendPanel($workbook['legend'] ?? []) ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/dashboard.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/js/dashboard.js') ?>"></script>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $summary */
    private function renderKpis(array $summary, string $scope): string
    {
        $prefix = $scope === 'due_diligence' ? 'dd-' : '';
        ob_start();
        ?>
        <section class="kpis" id="<?= $prefix ?>kpi-tiles" data-filter-scope="<?= $this->e($scope) ?>">
            <button type="button" class="kpi kpi-clickable tone-all is-active" data-filter-type="all" data-filter-value="" aria-pressed="true">
                <div class="eyebrow">Total</div>
                <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
                <span>View all rows</span>
            </button>
            <?php foreach (['Pass', 'Gap', 'Risk', 'TBD', 'N/A'] as $status): ?>
                <?php if (!isset($summary['by_status'][$status]) && $status === 'N/A') { continue; } ?>
                <?php if (($summary['by_status'][$status] ?? 0) === 0 && $status === 'N/A') { continue; } ?>
                <button
                    type="button"
                    class="kpi kpi-clickable tone-<?= strtolower(str_replace('/', '', $status)) ?>"
                    data-filter-type="status"
                    data-filter-value="<?= $this->e($status) ?>"
                    aria-pressed="false"
                >
                    <div class="eyebrow"><?= $this->e($status) ?></div>
                    <strong><?= (int) ($summary['by_status'][$status] ?? 0) ?></strong>
                    <span>Filter <?= $this->e(strtolower($status)) ?> rows</span>
                </button>
            <?php endforeach; ?>
            <?php foreach (['High', 'Med', 'Low'] as $risk): ?>
                <button
                    type="button"
                    class="kpi kpi-clickable tone-<?= strtolower($risk) ?>"
                    data-filter-type="risk"
                    data-filter-value="<?= $this->e($risk) ?>"
                    aria-pressed="false"
                >
                    <div class="eyebrow"><?= $this->e($risk) ?> risk</div>
                    <strong><?= (int) ($summary['by_risk'][$risk] ?? 0) ?></strong>
                    <span>Filter <?= $this->e(strtolower($risk)) ?> risk rows</span>
                </button>
            <?php endforeach; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string, mixed>> $statusSlices
     * @param list<array<string, mixed>> $riskSlices
     * @param list<array<string, mixed>> $sectionSlices
     * @param array<string, mixed> $summary
     */
    private function renderChartsBlock(
        array $statusSlices,
        array $riskSlices,
        array $sectionSlices,
        array $summary,
        string $scope
    ): string {
        $idPrefix = $scope === 'due_diligence' ? 'dd-' : '';
        ob_start();
        ?>
        <section class="charts-grid">
            <div class="chart-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Flow health</div>
                        <h3>Status mix</h3>
                    </div>
                </div>
                <div class="chart-panel">
                    <?= $this->renderDonutChart($statusSlices, $idPrefix . 'status-donut', (string) ($summary['by_status']['Risk'] ?? 0), 'risk items', false) ?>
                    <?= $this->renderChartLegend($statusSlices, 'status') ?>
                </div>
            </div>
            <div class="chart-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Risk exposure</div>
                        <h3>Risk levels</h3>
                    </div>
                </div>
                <div class="chart-panel">
                    <?= $this->renderDonutChart($riskSlices, $idPrefix . 'risk-donut', (string) ($summary['by_risk']['High'] ?? 0), 'high risk', false) ?>
                    <?= $this->renderChartLegend($riskSlices, 'risk') ?>
                </div>
            </div>
            <div class="chart-card chart-card-wide">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Section coverage</div>
                        <h3>Section distribution</h3>
                    </div>
                </div>
                <div class="chart-panel chart-panel-split">
                    <?= $this->renderPieChart($sectionSlices, $idPrefix . 'section-pie') ?>
                    <?= $this->renderChartLegend($sectionSlices, 'section') ?>
                </div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, array<string, int>> $bySection */
    private function renderSectionBars(array $bySection): string
    {
        ob_start();
        ?>
        <section class="chart-card section-bars-card">
            <div class="card-heading">
                <div>
                    <div class="eyebrow">Section drill-down</div>
                    <h3>Checks by section</h3>
                </div>
            </div>
            <div class="state-bars">
                <?php foreach ($bySection as $section => $counts): ?>
                    <?php $max = max(1, (int) $counts['total']); ?>
                    <button
                        type="button"
                        class="bar-row bar-row-clickable"
                        data-filter-type="section"
                        data-filter-value="<?= $this->e($section) ?>"
                    >
                        <span><?= $this->e($section) ?></span>
                        <div class="bar-track">
                            <span class="bar-fill bar-pass" style="width: <?= $this->percent((int) ($counts['Pass'] ?? 0), $max) ?>%"></span>
                            <span class="bar-fill bar-gap" style="width: <?= $this->percent((int) ($counts['Gap'] ?? 0), $max) ?>%"></span>
                            <span class="bar-fill bar-risk" style="width: <?= $this->percent((int) ($counts['Risk'] ?? 0), $max) ?>%"></span>
                            <span class="bar-fill bar-tbd" style="width: <?= $this->percent((int) ($counts['TBD'] ?? 0), $max) ?>%"></span>
                        </div>
                        <b><?= (int) $counts['total'] ?></b>
                    </button>
                    <div class="section-meta">
                        <span><?= (int) ($counts['High'] ?? 0) ?> High</span>
                        <span><?= (int) ($counts['Med'] ?? 0) ?> Med</span>
                        <span><?= (int) ($counts['Low'] ?? 0) ?> Low</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string, string>> $items
     * @param array<string, mixed> $summary
     * @param array<string, true> $changedKeys
     * @param array<string, array{action: string, comment: string, updated_at?: string}> $responses
     */
    private function renderRegister(
        string $scope,
        string $registerId,
        string $heading,
        string $eyebrow,
        array $items,
        array $summary,
        bool $extendedColumns,
        array $changedKeys = [],
        array $responses = [],
        int $assessmentId = 0
    ): string {
        $tableId = $scope === 'due_diligence' ? 'dd-table' : 'risk-table';
        $prefix = $scope === 'due_diligence' ? 'dd-' : '';
        $checkLabel = $extendedColumns ? 'Assessment item' : 'Check';
        $notesLabel = $extendedColumns ? 'Finding / evidence' : 'Notes';
        $timelineLabel = $extendedColumns ? 'Timeline' : 'Remediation timeline';
        $itemType = $scope === 'due_diligence' ? 'due_diligence' : 'architecture';
        $actionLabels = \RiskAssessment\Repositories\ItemResponseRepository::ACTION_LABELS;

        ob_start();
        ?>
        <section class="toolbar" data-filter-scope="<?= $this->e($scope) ?>">
            <div class="search-wrap">
                <span>Search</span>
                <input type="search" id="<?= $prefix ?>filter-search" placeholder="Search check, notes, owner, mitigation...">
            </div>
            <select id="<?= $prefix ?>filter-section">
                <option value="">All sections</option>
                <?php foreach (array_keys($summary['by_section'] ?? []) as $section): ?>
                    <option value="<?= $this->e((string) $section) ?>"><?= $this->e((string) $section) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="<?= $prefix ?>filter-status">
                <option value="">All statuses</option>
                <?php foreach (['Pass', 'Gap', 'Risk', 'TBD', 'N/A'] as $status): ?>
                    <option value="<?= $this->e($status) ?>"><?= $this->e($status) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="<?= $prefix ?>filter-risk">
                <option value="">All risk levels</option>
                <?php foreach (['High', 'Med', 'Low'] as $risk): ?>
                    <option value="<?= $this->e($risk) ?>"><?= $this->e($risk) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="<?= $prefix ?>filter-response">
                <option value="">All responses</option>
                <option value="needs">Needs response (Gap/Risk/TBD)</option>
                <option value="open">Open</option>
                <option value="take_care">Taken care</option>
                <option value="ignore">Ignore</option>
                <option value="not_applicable">Not applicable</option>
                <option value="closed">Closed</option>
                <option value="commented">Has comment</option>
            </select>
            <select id="<?= $prefix ?>filter-changed">
                <option value="">All rows</option>
                <option value="changed">Changed since last upload</option>
            </select>
            <button type="button" class="button ghost" id="<?= $prefix ?>clearFilters">Reset</button>
        </section>

        <section class="table-card" id="<?= $this->e($registerId) ?>" data-filter-scope="<?= $this->e($scope) ?>">
            <div class="card-heading">
                <div>
                    <div class="eyebrow"><?= $this->e($eyebrow) ?></div>
                    <h3><?= $this->e($heading) ?></h3>
                </div>
                <span class="result-count" id="<?= $prefix ?>filter-count"><?= count($items) ?> shown</span>
            </div>
            <div class="bulk-response-bar" data-bulk-scope="<?= $this->e($scope) ?>" data-bulk-table="<?= $this->e($tableId) ?>">
                <label class="bulk-select-all">
                    <input type="checkbox" class="bulk-select-all-toggle" title="Select all listed actionable rows">
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
                            <th>Section</th>
                            <th><?= $this->e($checkLabel) ?></th>
                            <th>Status</th>
                            <th>Risk level</th>
                            <th>Our response</th>
                            <th><?= $this->e($notesLabel) ?></th>
                            <th>Mitigation / controls</th>
                            <th>Owner</th>
                            <th><?= $this->e($timelineLabel) ?></th>
                            <?php if ($extendedColumns): ?>
                                <th>Review question</th>
                                <th>Source</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $owner = (string) ($item['owner'] ?? '');
                            $timeline = (string) ($item['remediation_timeline'] ?? '');
                            $mitigation = (string) ($item['mitigation'] ?? '');
                            $notes = (string) ($item['notes'] ?? '');
                            $status = (string) ($item['status'] ?? '');
                            $riskLevel = (string) ($item['risk_level'] ?? '');
                            $key = AssessmentComparer::itemKey(
                                (string) ($item['item_type'] ?? $itemType),
                                (string) ($item['section'] ?? ''),
                                (string) ($item['check'] ?? '')
                            );
                            $isChanged = isset($changedKeys[$key]);
                            $isActionable = \RiskAssessment\Repositories\ItemResponseRepository::isActionableStatus($status, $riskLevel);
                            $response = $responses[$key] ?? ['action' => 'open', 'comment' => ''];
                            $responseAction = \RiskAssessment\Repositories\ItemResponseRepository::normalizeAction((string) ($response['action'] ?? 'open'));
                            $responseComment = (string) ($response['comment'] ?? '');
                            $searchParts = [
                                $item['section'] ?? '',
                                $item['check'] ?? '',
                                $notes,
                                $mitigation,
                                $owner,
                                $item['review_question'] ?? '',
                                $item['source_reference'] ?? '',
                                $responseComment,
                                $actionLabels[$responseAction] ?? '',
                            ];
                            $ownersAttr = strtolower(preg_replace('/\s*(?:\+|\/|,|;|\band\b)\s*/i', '|', $owner !== '' ? $owner : 'unassigned') ?? 'unassigned');
                            $timelineLane = $this->timelineLane($timeline);
                            ?>
                            <tr
                                class="data-row<?= $isChanged ? ' row-changed' : '' ?><?= $isActionable ? ' row-actionable' : '' ?>"
                                data-section="<?= $this->e($item['section'] ?? '') ?>"
                                data-status="<?= $this->e($status) ?>"
                                data-risk="<?= $this->e($riskLevel) ?>"
                                data-owner="<?= $this->e($ownersAttr) ?>"
                                data-timeline="<?= $this->e($timelineLane) ?>"
                                data-changed="<?= $isChanged ? '1' : '0' ?>"
                                data-actionable="<?= $isActionable ? '1' : '0' ?>"
                                data-response="<?= $this->e($responseAction) ?>"
                                data-has-comment="<?= $responseComment !== '' ? '1' : '0' ?>"
                                data-item-key="<?= $this->e($key) ?>"
                                data-missing-owner="<?= $owner === '' ? '1' : '0' ?>"
                                data-missing-timeline="<?= $timeline === '' ? '1' : '0' ?>"
                                data-missing-mitigation="<?= $mitigation === '' ? '1' : '0' ?>"
                                data-search="<?= $this->e(strtolower(implode(' ', $searchParts))) ?>"
                            >
                                <td class="col-select">
                                    <?php if ($isActionable): ?>
                                        <input type="checkbox" class="row-select" value="<?= $this->e($key) ?>" aria-label="Select <?= $this->e($item['check'] ?? '') ?>">
                                    <?php else: ?>
                                        <span class="response-na">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="section-name"><?= $this->e($item['section'] ?? '') ?></span><?php if ($isChanged): ?><span class="change-flag">Changed</span><?php endif; ?></td>
                                <td>
                                    <div class="check-name"><?= $this->e($item['check'] ?? '') ?></div>
                                    <?php if ($owner !== ''): ?>
                                        <div class="subtext"><?= $this->e($owner) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $this->pill($status, 'status') ?></td>
                                <td><?= $this->pill($riskLevel, 'risk') ?></td>
                                <td class="response-cell">
                                    <?php if ($isActionable): ?>
                                        <div class="item-response" data-item-key="<?= $this->e($key) ?>">
                                            <select class="item-response-action" aria-label="Response for <?= $this->e($item['check'] ?? '') ?>">
                                                <?php foreach ($actionLabels as $value => $label): ?>
                                                    <option value="<?= $this->e($value) ?>" <?= $responseAction === $value ? 'selected' : '' ?>><?= $this->e($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <textarea
                                                class="item-response-comment"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Comment (optional)"
                                                aria-label="Comment for <?= $this->e($item['check'] ?? '') ?>"
                                            ><?= $this->e($responseComment) ?></textarea>
                                            <span class="item-response-save" hidden>Saved</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="response-na">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><div class="clamp-text" data-expandable><?= $this->e($notes) ?></div></td>
                                <td><div class="clamp-text" data-expandable><?= $this->e($mitigation) ?></div></td>
                                <td><?= $this->e($owner) ?></td>
                                <td><?= $this->e($timeline) ?></td>
                                <?php if ($extendedColumns): ?>
                                    <td><div class="clamp-text" data-expandable><?= $this->e($item['review_question'] ?? '') ?></div></td>
                                    <td><?= $this->e($item['source_reference'] ?? '') ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($assessmentId <= 0): ?>
                <p class="response-hint">Save this assessment (upload) to persist responses in the database. Until then they stay in this browser only.</p>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string, string>> $items
     * @param list<array<string, string>> $dueItems
     * @param array<string, array{action: string, comment: string, updated_at?: string}> $responses
     * @return list<array<string, mixed>>
     */
    private function collectActionableItems(array $items, array $dueItems, array $responses): array
    {
        $out = [];
        foreach (array_merge($items, $dueItems) as $item) {
            $status = (string) ($item['status'] ?? '');
            $riskLevel = (string) ($item['risk_level'] ?? '');
            if (!\RiskAssessment\Repositories\ItemResponseRepository::isActionableStatus($status, $riskLevel)) {
                continue;
            }
            $type = (string) ($item['item_type'] ?? 'architecture');
            $key = AssessmentComparer::itemKey($type, (string) ($item['section'] ?? ''), (string) ($item['check'] ?? ''));
            $response = $responses[$key] ?? ['action' => 'open', 'comment' => ''];
            $out[] = [
                'key' => $key,
                'item_type' => $type,
                'section' => (string) ($item['section'] ?? ''),
                'check' => (string) ($item['check'] ?? ''),
                'status' => $status,
                'risk_level' => $riskLevel,
                'owner' => (string) ($item['owner'] ?? ''),
                'action' => \RiskAssessment\Repositories\ItemResponseRepository::normalizeAction((string) ($response['action'] ?? 'open')),
                'comment' => (string) ($response['comment'] ?? ''),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $rank = static function (array $row): int {
                $action = (string) ($row['action'] ?? 'open');
                if ($action === 'open') {
                    return 0;
                }
                if ($action === 'take_care') {
                    return 1;
                }
                return 2;
            };
            $cmp = $rank($a) <=> $rank($b);
            if ($cmp !== 0) {
                return $cmp;
            }
            $riskRank = ['High' => 0, 'Med' => 1, 'Low' => 2];
            $ra = $riskRank[$a['risk_level']] ?? 3;
            $rb = $riskRank[$b['risk_level']] ?? 3;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return strcmp((string) $a['check'], (string) $b['check']);
        });

        return $out;
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

    /** @param array<string, mixed> $workbook */
    /** @param array<string, string> $metadata */
    private function renderGovernancePanel(array $workbook, array $metadata): string
    {
        $fields = $workbook['fields'] ?? [];
        $findings = $workbook['findings'] ?? [];
        $note = (string) ($workbook['note'] ?? '');

        ob_start();
        ?>
        <?php if (($metadata['tprm_recommendation'] ?? '') !== '' || ($metadata['technology_recommendation'] ?? '') !== '' || ($metadata['governance_action'] ?? '') !== ''): ?>
            <section class="governance-highlights">
                <?php if (($metadata['overall_risk_rating'] ?? '') !== ''): ?>
                    <article class="highlight-card">
                        <span class="label">Overall rating</span>
                        <strong><?= $this->e($metadata['overall_risk_rating']) ?></strong>
                    </article>
                <?php endif; ?>
                <?php if (($metadata['tprm_recommendation'] ?? '') !== ''): ?>
                    <article class="highlight-card">
                        <span class="label">TPRM recommendation</span>
                        <strong><?= $this->e($metadata['tprm_recommendation']) ?></strong>
                    </article>
                <?php endif; ?>
                <?php if (($metadata['technology_recommendation'] ?? '') !== ''): ?>
                    <article class="highlight-card">
                        <span class="label">Technology recommendation</span>
                        <strong><?= $this->e($metadata['technology_recommendation']) ?></strong>
                    </article>
                <?php endif; ?>
                <?php if (($metadata['governance_action'] ?? '') !== ''): ?>
                    <article class="highlight-card highlight-warning">
                        <span class="label">Required governance action</span>
                        <strong><?= $this->e($metadata['governance_action']) ?></strong>
                    </article>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($fields !== []): ?>
            <section class="table-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">JSON due diligence</div>
                        <h3>Summary fields</h3>
                    </div>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Value</th>
                                <th>Assessment use</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fields as $field): ?>
                                <tr>
                                    <td><strong><?= $this->e((string) ($field['label'] ?? '')) ?></strong></td>
                                    <td><?= $this->e((string) ($field['value'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($field['use'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($findings !== []): ?>
            <section class="table-card" style="margin-top: 10px;">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Exceptions</div>
                        <h3>Documented findings</h3>
                    </div>
                    <span class="result-count"><?= count($findings) ?> findings</span>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Finding / control</th>
                                <th>Policy / reference</th>
                                <th>Impact</th>
                                <th>Required exception / mitigation</th>
                                <th>Owner</th>
                                <th>Timeline</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($findings as $finding): ?>
                                <tr>
                                    <td><?= $this->e((string) ($finding['finding'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($finding['policy_reference'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($finding['impact'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($finding['mitigation'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($finding['owner'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($finding['timeline'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($note !== ''): ?>
            <div class="context-banner context-note"><?= $this->e($note) ?></div>
        <?php endif; ?>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $legend */
    private function renderLegendPanel(array $legend): string
    {
        $statuses = $legend['statuses'] ?? [];
        $riskLevels = $legend['risk_levels'] ?? [];
        $checklist = $legend['checklist'] ?? [];

        ob_start();
        ?>
        <div class="legend-grid">
            <section class="table-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Scoring</div>
                        <h3>Status meanings</h3>
                    </div>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Meaning</th>
                                <th>Typical action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($statuses as $row): ?>
                                <tr>
                                    <td><?= $this->pill((string) ($row['status'] ?? ''), 'status') ?></td>
                                    <td><?= $this->e((string) ($row['meaning'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($row['action'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="table-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Scoring</div>
                        <h3>Risk level guidance</h3>
                    </div>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Risk level</th>
                                <th>Use when</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($riskLevels as $row): ?>
                                <tr>
                                    <td><?= $this->pill((string) ($row['risk_level'] ?? ''), 'risk') ?></td>
                                    <td><?= $this->e((string) ($row['use_when'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <?php if ($checklist !== []): ?>
            <section class="table-card" style="margin-top: 10px;">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Evidence</div>
                        <h3>Minimum evidence checklist</h3>
                    </div>
                </div>
                <ol class="checklist">
                    <?php foreach ($checklist as $item): ?>
                        <li><?= $this->e((string) $item) ?></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $summary */
    /** @return list<array<string, mixed>> */
    private function buildStatusSlices(array $summary): array
    {
        $slices = [];
        foreach (['Pass', 'Gap', 'Risk', 'TBD', 'N/A'] as $status) {
            $value = (int) ($summary['by_status'][$status] ?? 0);
            if ($status === 'N/A' && $value === 0) {
                continue;
            }
            $slices[] = [
                'label' => $status,
                'value' => $value,
                'color' => self::STATUS_COLOR[$status] ?? '#94a3b8',
                'filterType' => 'status',
                'filterValue' => $status,
            ];
        }

        return $slices;
    }

    /** @param array<string, mixed> $summary */
    /** @return list<array<string, mixed>> */
    private function buildRiskSlices(array $summary): array
    {
        $slices = [];
        foreach (['High', 'Med', 'Low'] as $risk) {
            $slices[] = [
                'label' => $risk,
                'value' => (int) ($summary['by_risk'][$risk] ?? 0),
                'color' => self::RISK_COLOR[$risk],
                'filterType' => 'risk',
                'filterValue' => $risk,
            ];
        }

        return $slices;
    }

    /** @param array<string, mixed> $summary */
    /** @return list<array<string, mixed>> */
    private function buildSectionSlices(array $summary): array
    {
        $slices = [];
        $index = 0;
        foreach (($summary['by_section'] ?? []) as $section => $counts) {
            $slices[] = [
                'label' => (string) $section,
                'value' => (int) ($counts['total'] ?? 0),
                'color' => self::SECTION_COLORS[$index % count(self::SECTION_COLORS)],
                'filterType' => 'section',
                'filterValue' => (string) $section,
            ];
            $index++;
        }

        return $slices;
    }

    /** @param array<string, mixed> $summary */
    private function renderRiskSpectrum(array $summary): string
    {
        $total = max(1, (int) ($summary['total'] ?? 0));
        $parts = [
            'pass' => (int) ($summary['by_status']['Pass'] ?? 0),
            'gap' => (int) ($summary['by_status']['Gap'] ?? 0),
            'risk' => (int) ($summary['by_status']['Risk'] ?? 0),
            'tbd' => (int) ($summary['by_status']['TBD'] ?? 0),
            'na' => (int) ($summary['by_status']['N/A'] ?? 0),
        ];

        ob_start();
        ?>
        <div class="risk-spectrum" aria-label="Status mix">
            <div class="risk-spectrum-track">
                <?php foreach ($parts as $key => $count): ?>
                    <?php if ($count <= 0) { continue; } ?>
                    <span
                        class="risk-spectrum-seg <?= $this->e($key) ?>"
                        style="width: <?= $this->percent($count, $total) ?>%; animation-delay: <?= array_search($key, array_keys($parts), true) * 0.05 ?>s"
                        title="<?= $this->e(strtoupper($key)) ?>: <?= $count ?>"
                    ></span>
                <?php endforeach; ?>
            </div>
            <div class="risk-spectrum-legend">
                <?php foreach (['Pass' => 'pass', 'Gap' => 'gap', 'Risk' => 'risk', 'TBD' => 'tbd'] as $label => $key): ?>
                    <span><b><?= (int) ($parts[$key] ?? 0) ?></b> <?= $this->e($label) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param list<array<string, mixed>> $slices */
    private function renderDonutChart(array $slices, string $chartId, string $centerValue, string $centerLabel, bool $hero): string
    {
        $total = max(1, array_sum(array_column($slices, 'value')));
        $radius = 62;
        $stroke = 22;
        $circumference = 2 * M_PI * $radius;
        $offset = 0.0;
        $size = $hero ? 220 : 190;
        $center = $size / 2;

        ob_start();
        ?>
        <div class="donut-wrap<?= $hero ? ' donut-wrap-hero' : '' ?>" data-chart-id="<?= $this->e($chartId) ?>">
            <svg viewBox="0 0 <?= $size ?> <?= $size ?>" class="donut-chart" aria-hidden="true">
                <circle cx="<?= $center ?>" cy="<?= $center ?>" r="<?= $radius ?>" fill="none" stroke="#edf1ee" stroke-width="<?= $stroke ?>"></circle>
                <?php foreach ($slices as $index => $slice): ?>
                    <?php
                    $value = (int) $slice['value'];
                    if ($value <= 0) {
                        continue;
                    }
                    $length = ($value / $total) * $circumference;
                    $gap = $total > $value ? 2 : 0;
                    ?>
                    <circle
                        class="donut-segment"
                        cx="<?= $center ?>"
                        cy="<?= $center ?>"
                        r="<?= $radius ?>"
                        fill="none"
                        stroke="<?= $this->e((string) $slice['color']) ?>"
                        stroke-width="<?= $stroke ?>"
                        stroke-dasharray="<?= round($length - $gap, 2) ?> <?= round($circumference - $length + $gap, 2) ?>"
                        stroke-dashoffset="<?= round(-$offset, 2) ?>"
                        transform="rotate(-90 <?= $center ?> <?= $center ?>)"
                        data-filter-type="<?= $this->e((string) $slice['filterType']) ?>"
                        data-filter-value="<?= $this->e((string) $slice['filterValue']) ?>"
                        data-segment-index="<?= $index ?>"
                    ></circle>
                    <?php $offset += $length; ?>
                <?php endforeach; ?>
            </svg>
            <div class="donut-center">
                <strong><?= $this->e($centerValue) ?></strong>
                <span><?= $this->e($centerLabel) ?></span>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param list<array<string, mixed>> $slices */
    private function renderPieChart(array $slices, string $chartId): string
    {
        $total = array_sum(array_column($slices, 'value'));
        $center = 95;
        $radius = 72;
        $angle = -90.0;

        ob_start();
        ?>
        <div class="pie-wrap" data-chart-id="<?= $this->e($chartId) ?>">
            <svg viewBox="0 0 190 190" class="pie-chart" aria-hidden="true">
                <?php if ($total <= 0): ?>
                    <circle cx="<?= $center ?>" cy="<?= $center ?>" r="<?= $radius ?>" fill="#edf1ee"></circle>
                <?php else: ?>
                    <?php foreach ($slices as $index => $slice): ?>
                        <?php
                        $value = (int) $slice['value'];
                        if ($value <= 0) {
                            continue;
                        }
                        $sliceAngle = ($value / $total) * 360;
                        $path = $this->pieSlicePath($center, $center, $radius, $angle, $angle + $sliceAngle);
                        $angle += $sliceAngle;
                        ?>
                        <path
                            class="pie-segment"
                            d="<?= $this->e($path) ?>"
                            fill="<?= $this->e((string) $slice['color']) ?>"
                            data-filter-type="<?= $this->e((string) $slice['filterType']) ?>"
                            data-filter-value="<?= $this->e((string) $slice['filterValue']) ?>"
                            data-segment-index="<?= $index ?>"
                        ></path>
                    <?php endforeach; ?>
                <?php endif; ?>
                <circle cx="<?= $center ?>" cy="<?= $center ?>" r="34" fill="#fff"></circle>
            </svg>
            <div class="pie-center">
                <strong><?= (int) $total ?></strong>
                <span>checks</span>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param list<array<string, mixed>> $slices */
    private function renderChartLegend(array $slices, string $kind): string
    {
        ob_start();
        ?>
        <div class="chart-legend" data-legend-kind="<?= $this->e($kind) ?>">
            <?php foreach ($slices as $slice): ?>
                <?php if ((int) $slice['value'] <= 0) { continue; } ?>
                <button
                    type="button"
                    class="legend-item legend-clickable"
                    data-filter-type="<?= $this->e((string) $slice['filterType']) ?>"
                    data-filter-value="<?= $this->e((string) $slice['filterValue']) ?>"
                >
                    <span class="legend-swatch" style="background: <?= $this->e((string) $slice['color']) ?>"></span>
                    <span class="legend-copy">
                        <strong><?= $this->e((string) $slice['label']) ?></strong>
                        <em><?= (int) $slice['value'] ?></em>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function pieSlicePath(float $cx, float $cy, float $radius, float $startAngle, float $endAngle): string
    {
        if ($endAngle - $startAngle >= 359.999) {
            return sprintf(
                'M %.2F %.2F m -%.2F 0 a %.2F %.2F 0 1 0 %.2F 0 a %.2F %.2F 0 1 0 -%.2F 0',
                $cx,
                $cy,
                $radius,
                $radius,
                $radius,
                $radius * 2,
                $radius,
                $radius,
                $radius * 2
            );
        }

        $start = $this->polarToCartesian($cx, $cy, $radius, $endAngle);
        $end = $this->polarToCartesian($cx, $cy, $radius, $startAngle);
        $largeArc = ($endAngle - $startAngle) > 180 ? 1 : 0;

        return sprintf(
            'M %.2F %.2F L %.2F %.2F A %.2F %.2F 0 %d 0 %.2F %.2F Z',
            $cx,
            $cy,
            $start['x'],
            $start['y'],
            $radius,
            $radius,
            $largeArc,
            $end['x'],
            $end['y']
        );
    }

    /** @return array{x: float, y: float} */
    private function polarToCartesian(float $cx, float $cy, float $radius, float $angle): array
    {
        $radians = deg2rad($angle);

        return [
            'x' => $cx + ($radius * cos($radians)),
            'y' => $cy + ($radius * sin($radians)),
        ];
    }

    private function brandMark(): string
    {
        $path = dirname(__DIR__) . '/public/includes/brand-mark.php';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function pill(string $value, string $type): string
    {
        $normalized = strtolower(preg_replace('/\s+/', '', $value) ?? '');

        if ($type === 'status') {
            $class = match ($normalized) {
                'pass' => 'teal',
                'gap' => 'amber',
                'risk' => 'coral',
                'tbd', 'na', 'n/a' => 'gray',
                default => 'gray',
            };
        } else {
            $class = match ($normalized) {
                'high' => 'coral',
                'med', 'medium' => 'amber',
                'low' => 'teal',
                default => 'gray',
            };
        }

        return sprintf('<span class="pill %s">%s</span>', $this->e($class), $this->e($value));
    }

    private function percent(int $value, int $max): float
    {
        return round(($value / max(1, $max)) * 100, 2);
    }
}
