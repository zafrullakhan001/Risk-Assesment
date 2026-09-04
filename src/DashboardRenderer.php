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
        'Addressed' => '#0e7490',
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
     * @param list<array{id: int, label: string, url: string, sort_order: int}> $projectLinks
     * @param list<array{id: int, title: string, source: string, sort_order: int}> $projectDiagrams
     * @param array<string, string> $findingStatuses
     * @param array{verdict?: string, summary?: string} $executiveOverride
     * @param array<string, list<array<string, mixed>>> $itemResponseHistory
     * @param list<array<string, mixed>> $evaluationHistory
     * @param array{name?: string, email?: string} $evaluatorDefaults
     * @param list<array{id: int, title: string, mime_type: string, original_filename: string, sort_order: int}> $projectPictures
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
        ?array $evaluation = null,
        array $projectLinks = [],
        array $projectDiagrams = [],
        array $findingStatuses = [],
        array $executiveOverride = [],
        array $itemResponseHistory = [],
        array $evaluationHistory = [],
        array $evaluatorDefaults = [],
        array $projectPictures = []
    ): string {
        $metadata = $assessment->metadata;
        $summary = $assessment->summary;
        $items = $assessment->items;
        $dueItems = $assessment->dueDiligenceItems;
        $workbook = $assessment->workbook;
        $ddSummary = is_array($summary['due_diligence'] ?? null) ? $summary['due_diligence'] : Assessment::summarizeItems($dueItems);
        $insightBuilder = new AssessmentInsights();
        $insights = $insightBuilder->build($assessment, $responses, $findingStatuses);
        $insights = $insightBuilder->applyExecutiveOverride(
            $insights,
            (string) ($executiveOverride['verdict'] ?? ''),
            (string) ($executiveOverride['summary'] ?? '')
        );
        $evalNotes = (string) ($evaluation['notes'] ?? '');
        $goliveGates = (new GoliveGate())->evaluate($assessment, $responses, $findingStatuses, $evalNotes);
        $goliveGatesJson = json_encode($goliveGates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $decisionViews = new DashboardDecisionViews();
        $projectResources = new DashboardProjectResources();
        $changedKeys = is_array($comparison['changed_keys'] ?? null) ? $comparison['changed_keys'] : [];
        $actionableItems = $this->collectActionableItems($items, $dueItems, $responses);
        foreach ($actionableItems as &$actionableRow) {
            $key = (string) ($actionableRow['key'] ?? '');
            $actionableRow['history'] = $itemResponseHistory[$key] ?? [];
        }
        unset($actionableRow);
        $progress = (new ResponseProgress())->compute(array_merge($items, $dueItems), $responses);
        $archProgress = (new ResponseProgress())->compute($items, $responses);
        $ddProgress = (new ResponseProgress())->compute($dueItems, $responses);

        $solutionName = $metadata['solution_name'] ?: 'Risk Assessment Dashboard';
        $assessmentDate = $metadata['date'] ?: date('Y-m-d');
        $hasDueDiligence = $dueItems !== [];
        $hasGovernance = ($workbook['fields'] ?? []) !== [] || ($workbook['findings'] ?? []) !== [];
        $hasLegend = ($workbook['legend']['statuses'] ?? []) !== [] || ($workbook['legend']['checklist'] ?? []) !== [];

        $statusSlices = $this->buildProgressStatusSlices($archProgress);
        $riskSlices = $this->buildProgressRiskSlices($archProgress, $summary);
        $sectionSlices = $this->buildSectionSlices($summary);
        $ddStatusSlices = $this->buildProgressStatusSlices($ddProgress);
        $ddRiskSlices = $this->buildProgressRiskSlices($ddProgress, $ddSummary);
        $ddSectionSlices = $this->buildSectionSlices($ddSummary);

        $progressJson = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $branding = Branding::current();

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($solutionName) ?> · <?= $this->e($branding->brandTitle()) ?></title>
    <?php require dirname(__DIR__) . '/public/includes/theme-head.php'; ?>
    <?php require dirname(__DIR__) . '/public/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(dirname(__DIR__) . '/public/assets/css/dashboard.css') ?>">
</head>
<body
    data-assessment-id="<?= (int) $assessmentId ?>"
    data-csrf-token="<?= $this->e($csrfToken) ?>"
    data-progress="<?= $this->e($progressJson) ?>"
    data-golive-gates="<?= $this->e($goliveGatesJson) ?>"
    class="<?= !empty($evaluation['ready_to_golive']) ? 'is-ready-golive' : '' ?>"
>
    <div class="shell">
        <header class="topbar">
            <a class="brand brand-link" href="index.php#find-projects" title="Back to find projects">
                <?= $branding->renderMark() ?>
                <div>
                    <div class="brand-title"><?= $this->e($branding->brandTitle()) ?></div>
                    <h1><?= $this->e($branding->brandSubtitle()) ?></h1>
                </div>
            </a>
            <div class="topbar-actions">
                <a class="button ghost home-link" href="index.php#find-projects">← Find projects</a>
                <?php require dirname(__DIR__) . '/public/includes/updates-nav.php'; ?>
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
                            <div class="eyebrow">📡 Architecture risk signal desk</div>
                            <h2>Risk <em>posture</em></h2>
                            <?= $decisionViews->renderTrendChips($comparison) ?>
                        </div>
                        <div class="hero-art">
                            <?= $this->renderDonutChart(
                                $statusSlices,
                                'hero-donut',
                                (int) ($progress['actionable']['open'] ?? 0) . '/' . (int) ($progress['actionable']['total'] ?? 0),
                                'open residual',
                                true
                            ) ?>
                        </div>
                    </div>
                    <div class="hero-project">
                        <span class="hero-project-label">📁 Project</span>
                        <p class="hero-project-name"><?= $this->e($solutionName) ?></p>
                        <?php if (!empty($evaluation['ready_to_golive'])): ?>
                            <span class="golive-pill">🚀 Ready to go-live</span>
                        <?php endif; ?>
                        <?= $this->renderProgressMeter($progress) ?>
                        <?= $this->renderRiskSpectrum($archProgress) ?>
                    </div>
                    <div class="hero-actions">
                        <a class="button ghost" href="index.php#find-projects">🏠 Home · Find projects</a>
                        <a class="button ghost" href="index.php#upload">📤 Upload another file</a>
                        <a class="button button-primary" href="#risk-register">📋 View register</a>
                    </div>
                </div>
            </section>

            <?= $decisionViews->renderDecisionDesk($insights, $assessmentId, $comparison, $evaluation, $progress) ?>

            <section class="meta-grid meta-grid-uplift">
                <div class="meta-item meta-vendor"><span class="label">🏢 Vendor</span><strong><?= $this->e($metadata['vendor'] ?? '') ?></strong></div>
                <div class="meta-item meta-scope"><span class="label">🎯 Scope</span><strong><?= $this->e($metadata['scope'] ?? '') ?></strong></div>
                <div class="meta-item meta-arch"><span class="label">🏗️ Architecture model</span><strong><?= $this->e($metadata['architecture_model'] ?? '') ?></strong></div>
                <div class="meta-item meta-reviewer"><span class="label">👤 Reviewer</span><strong><?= $this->e($metadata['reviewer'] ?? '') ?></strong></div>
                <?php if (($metadata['ddr_id'] ?? '') !== ''): ?>
                    <div class="meta-item meta-file"><span class="label">📄 DDR</span><strong><?= $this->e($metadata['ddr_id']) ?></strong></div>
                <?php endif; ?>
                <?php if (($metadata['vra_id'] ?? '') !== ''): ?>
                    <div class="meta-item meta-file"><span class="label">📄 VRA</span><strong><?= $this->e($metadata['vra_id']) ?></strong></div>
                <?php endif; ?>
                <?php if (($metadata['overall_risk_rating'] ?? '') !== ''): ?>
                    <div class="meta-item meta-reviewer"><span class="label">⚖️ Overall risk rating</span><strong><?= $this->e($metadata['overall_risk_rating']) ?></strong></div>
                <?php endif; ?>
                <?php if (($metadata['business_unit'] ?? '') !== ''): ?>
                    <div class="meta-item meta-scope"><span class="label">🏬 Business unit</span><strong><?= $this->e($metadata['business_unit']) ?></strong></div>
                <?php endif; ?>
                <?php if ($sourceFilename !== ''): ?>
                    <div class="meta-item meta-file"><span class="label">📎 Source file</span><strong><?= $this->e($sourceFilename) ?></strong></div>
                <?php endif; ?>
            </section>

            <nav class="dash-tabs dash-tabs-uplift" role="tablist" aria-label="Workbook tabs">
                <button type="button" class="dash-tab dash-tab-theme-architecture is-active" role="tab" aria-selected="true" data-tab="architecture">🏛️ Architecture checks</button>
                <?php if ($hasDueDiligence): ?>
                    <button type="button" class="dash-tab dash-tab-theme-diligence" role="tab" aria-selected="false" data-tab="due-diligence">🔍 Due diligence</button>
                <?php endif; ?>
                <button type="button" class="dash-tab dash-tab-theme-actions" role="tab" aria-selected="false" data-tab="actions">✅ Actions</button>
                <button type="button" class="dash-tab dash-tab-theme-project" role="tab" aria-selected="false" data-tab="project">📐 Diagram &amp; links</button>
                <?php if ($hasGovernance): ?>
                    <button type="button" class="dash-tab dash-tab-theme-governance" role="tab" aria-selected="false" data-tab="governance">⚖️ Governance summary</button>
                <?php endif; ?>
                <?php if ($hasLegend): ?>
                    <button type="button" class="dash-tab dash-tab-theme-legend" role="tab" aria-selected="false" data-tab="legend">📊 Scoring legend</button>
                <?php endif; ?>
            </nav>

            <div class="dash-panel dash-panel-theme-architecture is-active" data-panel="architecture">
                <?= $this->renderPanelIntro('🏛️', 'Architecture review', 'Architecture checks', 'Browse status, risk levels, charts, and the full register for every architecture control.') ?>
                <?= $this->renderKpis($summary, 'architecture', $archProgress) ?>
                <?= $this->renderChartsBlock($statusSlices, $riskSlices, $sectionSlices, $summary, 'architecture', $archProgress) ?>
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
                <div class="dash-panel dash-panel-theme-diligence" data-panel="due-diligence" hidden>
                    <?= $this->renderPanelIntro('🔍', 'Extended review', 'Due diligence', 'Technology risk template items, evidence notes, and extended diligence coverage.') ?>
                    <?php if (($workbook['context'] ?? '') !== ''): ?>
                        <div class="context-banner context-banner-uplift">💡 <?= $this->e((string) $workbook['context']) ?></div>
                    <?php endif; ?>
                    <?= $this->renderKpis($ddSummary, 'due_diligence', $ddProgress) ?>
                    <?= $this->renderChartsBlock($ddStatusSlices, $ddRiskSlices, $ddSectionSlices, $ddSummary, 'due_diligence', $ddProgress) ?>
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

            <div class="dash-panel dash-panel-theme-actions" data-panel="actions" hidden>
                <?= $decisionViews->renderActionsPanel($insights, $comparison, $versions, $assessmentId, $csrfToken, $actionableItems, $evaluation, $goliveGates, $evaluationHistory, $evaluatorDefaults) ?>
            </div>

            <div class="dash-panel dash-panel-theme-project" data-panel="project" hidden>
                <?= $projectResources->render($assessmentId, $projectLinks, $projectDiagrams, $projectPictures) ?>
            </div>

            <?php if ($hasGovernance): ?>
                <div class="dash-panel dash-panel-theme-governance" data-panel="governance" hidden>
                    <?= $this->renderPanelIntro('⚖️', 'Governance & compliance', 'Governance summary', 'JSON diligence fields, documented exceptions, and recommended governance actions.') ?>
                    <?= $this->renderGovernancePanel($workbook, $metadata) ?>
                </div>
            <?php endif; ?>

            <?php if ($hasLegend): ?>
                <div class="dash-panel dash-panel-theme-legend" data-panel="legend" hidden>
                    <?= $this->renderPanelIntro('📊', 'Scoring reference', 'Scoring legend', 'Status meanings, risk level guidance, and minimum evidence checklist from the workbook.') ?>
                    <?= $this->renderLegendPanel($workbook['legend'] ?? []) ?>
                </div>
            <?php endif; ?>
        </main>
        <?php require dirname(__DIR__) . '/public/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/js/theme.js') ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
    <script src="assets/js/project-resources.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/js/project-resources.js') ?>"></script>
    <script src="assets/js/dashboard.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/js/dashboard.js') ?>"></script>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $summary */
    /** @param array<string, mixed> $progress */
    private function renderKpis(array $summary, string $scope, array $progress = []): string
    {
        $prefix = $scope === 'due_diligence' ? 'dd-' : '';
        $statusEmoji = [
            'Pass' => '✅',
            'Gap' => '🟠',
            'Risk' => '🔴',
            'TBD' => '❓',
            'N/A' => '➖',
        ];
        $riskEmoji = ['High' => '🚨', 'Med' => '⚠️', 'Low' => '🟢'];
        ob_start();
        ?>
        <section class="kpis kpis-uplift" id="<?= $prefix ?>kpi-tiles" data-filter-scope="<?= $this->e($scope) ?>">
            <button type="button" class="kpi kpi-clickable tone-all is-active" data-filter-type="all" data-filter-value="" aria-pressed="true">
                <span class="kpi-emoji" aria-hidden="true">📊</span>
                <div class="eyebrow">Total</div>
                <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
                <span>View all rows</span>
            </button>
            <?php foreach (['Pass', 'Gap', 'Risk', 'TBD', 'N/A'] as $status): ?>
                <?php if (!isset($summary['by_status'][$status]) && $status === 'N/A') { continue; } ?>
                <?php if (($summary['by_status'][$status] ?? 0) === 0 && $status === 'N/A') { continue; } ?>
                <?php
                $total = (int) ($summary['by_status'][$status] ?? 0);
                $bucketKey = strtolower($status);
                $showProgress = in_array($status, ['Gap', 'Risk', 'TBD'], true) && $total > 0;
                $open = $showProgress ? (int) ($progress[$bucketKey]['open'] ?? $total) : $total;
                $addressed = $showProgress ? (int) ($progress[$bucketKey]['addressed'] ?? 0) : 0;
                ?>
                <button
                    type="button"
                    class="kpi kpi-clickable tone-<?= strtolower(str_replace('/', '', $status)) ?>"
                    data-filter-type="status"
                    data-filter-value="<?= $this->e($status) ?>"
                    data-progress-key="<?= $showProgress ? $this->e($bucketKey) : '' ?>"
                    aria-pressed="false"
                >
                    <span class="kpi-emoji" aria-hidden="true"><?= $statusEmoji[$status] ?? '📌' ?></span>
                    <div class="eyebrow"><?= $this->e($status) ?></div>
                    <?php if ($showProgress): ?>
                        <?php $hasResolution = $addressed > 0; ?>
                        <strong
                            class="kpi-progress"
                            data-progress-display="<?= $this->e($bucketKey) ?>"
                            data-progress-has-resolution="<?= $hasResolution ? '1' : '0' ?>"
                        >
                            <span data-progress-open="<?= $this->e($bucketKey) ?>"><?= $hasResolution ? $open : $total ?></span><span class="kpi-progress-tail"<?= $hasResolution ? '' : ' hidden' ?>>/<span data-progress-total="<?= $this->e($bucketKey) ?>"><?= $total ?></span></span>
                        </strong>
                        <span data-progress-caption="<?= $this->e($bucketKey) ?>"<?= $hasResolution ? '' : ' hidden' ?>><?= $addressed ?> addressed · <?= $open ?> open</span>
                    <?php else: ?>
                        <strong><?= $total ?></strong>
                        <span>Filter <?= $this->e(strtolower($status)) ?> rows</span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
            <?php foreach (['High', 'Med', 'Low'] as $risk): ?>
                <?php
                $total = (int) ($summary['by_risk'][$risk] ?? 0);
                $showProgress = $risk === 'High' && $total > 0;
                $open = $showProgress ? (int) ($progress['high']['open'] ?? $total) : $total;
                $addressed = $showProgress ? (int) ($progress['high']['addressed'] ?? 0) : 0;
                $hasResolution = $showProgress && $addressed > 0;
                ?>
                <button
                    type="button"
                    class="kpi kpi-clickable tone-<?= strtolower($risk) ?>"
                    data-filter-type="risk"
                    data-filter-value="<?= $this->e($risk) ?>"
                    data-progress-key="<?= $showProgress ? 'high' : '' ?>"
                    aria-pressed="false"
                >
                    <span class="kpi-emoji" aria-hidden="true"><?= $riskEmoji[$risk] ?? '📌' ?></span>
                    <div class="eyebrow"><?= $this->e($risk) ?> risk</div>
                    <?php if ($showProgress): ?>
                        <strong
                            class="kpi-progress"
                            data-progress-display="high"
                            data-progress-has-resolution="<?= $hasResolution ? '1' : '0' ?>"
                        >
                            <span data-progress-open="high"><?= $hasResolution ? $open : $total ?></span><span class="kpi-progress-tail"<?= $hasResolution ? '' : ' hidden' ?>>/<span data-progress-total="high"><?= $total ?></span></span>
                        </strong>
                        <span data-progress-caption="high"<?= $hasResolution ? '' : ' hidden' ?>><?= $addressed ?> addressed · <?= $open ?> open</span>
                    <?php else: ?>
                        <strong><?= $total ?></strong>
                        <span>Filter <?= $this->e(strtolower($risk)) ?> risk rows</span>
                    <?php endif; ?>
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
     * @param array<string, mixed> $progress
     */
    private function renderChartsBlock(
        array $statusSlices,
        array $riskSlices,
        array $sectionSlices,
        array $summary,
        string $scope,
        array $progress = []
    ): string {
        $idPrefix = $scope === 'due_diligence' ? 'dd-' : '';
        $riskOpen = (int) ($progress['risk']['open'] ?? ($summary['by_status']['Risk'] ?? 0));
        $riskTotal = (int) ($progress['risk']['total'] ?? ($summary['by_status']['Risk'] ?? 0));
        $riskAddressed = (int) ($progress['risk']['addressed'] ?? 0);
        $highOpen = (int) ($progress['high']['open'] ?? ($summary['by_risk']['High'] ?? 0));
        $highTotal = (int) ($progress['high']['total'] ?? ($summary['by_risk']['High'] ?? 0));
        $highAddressed = (int) ($progress['high']['addressed'] ?? 0);
        $actionableOpen = (int) ($progress['actionable']['open'] ?? 0);
        $actionableTotal = (int) ($progress['actionable']['total'] ?? 0);
        $actionableAddressed = (int) ($progress['actionable']['addressed'] ?? 0);
        $checksTotal = (int) ($summary['total'] ?? 0);

        $riskCenter = $riskAddressed > 0 ? $riskOpen . '/' . $riskTotal : (string) $riskTotal;
        $riskLabel = $riskAddressed > 0 ? 'open risks' : 'risks';
        $highCenter = $highAddressed > 0 ? $highOpen . '/' . $highTotal : (string) $highTotal;
        $highLabel = $highAddressed > 0 ? 'open high' : 'high risk';
        $sectionCenter = $actionableAddressed > 0
            ? $actionableOpen . '/' . $actionableTotal
            : (string) $checksTotal;
        $sectionLabel = $actionableAddressed > 0 ? 'open residual' : 'checks';

        ob_start();
        ?>
        <section class="charts-grid charts-grid-uplift">
            <div class="chart-card chart-card-uplift chart-card-tone-status">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">💚</span>
                        <div>
                            <div class="eyebrow">Flow health</div>
                            <h3>Status mix</h3>
                        </div>
                    </div>
                </div>
                <div class="chart-panel">
                    <?= $this->renderDonutChart(
                        $statusSlices,
                        $idPrefix . 'status-donut',
                        $riskCenter,
                        $riskLabel,
                        false
                    ) ?>
                    <?= $this->renderChartLegend($statusSlices, 'status') ?>
                </div>
            </div>
            <div class="chart-card chart-card-uplift chart-card-tone-risk">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🎯</span>
                        <div>
                            <div class="eyebrow">Risk exposure</div>
                            <h3>Risk levels</h3>
                        </div>
                    </div>
                </div>
                <div class="chart-panel">
                    <?= $this->renderDonutChart(
                        $riskSlices,
                        $idPrefix . 'risk-donut',
                        $highCenter,
                        $highLabel,
                        false
                    ) ?>
                    <?= $this->renderChartLegend($riskSlices, 'risk') ?>
                </div>
            </div>
            <div class="chart-card chart-card-wide chart-card-uplift chart-card-tone-section">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🧩</span>
                        <div>
                            <div class="eyebrow">Section coverage</div>
                            <h3>Section distribution</h3>
                        </div>
                    </div>
                </div>
                <div class="chart-panel chart-panel-split">
                    <?= $this->renderPieChart(
                        $sectionSlices,
                        $idPrefix . 'section-pie',
                        $sectionCenter,
                        $sectionLabel
                    ) ?>
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
        <section class="chart-card section-bars-card chart-card-uplift chart-card-tone-section">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true">📚</span>
                    <div>
                        <div class="eyebrow">Section drill-down</div>
                        <h3>Checks by section</h3>
                    </div>
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
        <section class="toolbar toolbar-uplift" data-filter-scope="<?= $this->e($scope) ?>">
            <div class="search-wrap">
                <span>🔎 Search</span>
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
            <button type="button" class="button ghost" id="<?= $prefix ?>clearFilters">↩️ Reset</button>
            <button type="button" class="button ghost" data-filter-type="action_tab" data-filter-value="risks">✅ Respond in Actions</button>
        </section>

        <section class="table-card table-card-uplift" id="<?= $this->e($registerId) ?>" data-filter-scope="<?= $this->e($scope) ?>">
            <div class="card-heading card-heading-uplift">
                <div class="card-heading-with-icon">
                    <span class="card-icon" aria-hidden="true"><?= $extendedColumns ? '🔍' : '📋' ?></span>
                    <div>
                        <div class="eyebrow"><?= $this->e($eyebrow) ?></div>
                        <h3><?= $this->e($heading) ?></h3>
                    </div>
                </div>
                <span class="result-count result-count-badge" id="<?= $prefix ?>filter-count"><?= count($items) ?> shown</span>
            </div>
            <p class="panel-help dashboard-readonly-hint">👀 Dashboard view only. Record Taken care / Ignore / comments in the <strong>✅ Actions</strong> tab.</p>
            <div class="table-scroll">
                <table id="<?= $this->e($tableId) ?>">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th><?= $this->e($checkLabel) ?></th>
                            <th>Status</th>
                            <th>Risk level</th>
                            <th>Response</th>
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
                            $responseLabel = $actionLabels[$responseAction] ?? 'Open';
                            $searchParts = [
                                $item['section'] ?? '',
                                $item['check'] ?? '',
                                $notes,
                                $mitigation,
                                $owner,
                                $item['review_question'] ?? '',
                                $item['source_reference'] ?? '',
                                $responseComment,
                                $responseLabel,
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
                                        <span class="response-pill response-<?= $this->e($responseAction) ?>"><?= $this->e($responseLabel) ?></span>
                                        <?php if ($responseComment !== ''): ?>
                                            <div class="subtext clamp-text" data-expandable><?= $this->e($responseComment) ?></div>
                                        <?php endif; ?>
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
                <p class="response-hint">Save this assessment (upload) so Actions responses can be stored in the database.</p>
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
                'updated_at' => (string) ($response['updated_at'] ?? ''),
                'updated_by_label' => (string) ($response['updated_by_label'] ?? ''),
                'updated_by_username' => (string) ($response['updated_by_username'] ?? ''),
                'updated_by_display_name' => (string) ($response['updated_by_display_name'] ?? ''),
                'updated_by_auth_source' => (string) ($response['updated_by_auth_source'] ?? ''),
                'history' => [],
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
            <section class="governance-highlights governance-highlights-uplift">
                <?php if (($metadata['overall_risk_rating'] ?? '') !== ''): ?>
                    <article class="highlight-card">
                        <span class="label">⚖️ Overall rating</span>
                        <strong><?= $this->e($metadata['overall_risk_rating']) ?></strong>
                    </article>
                <?php endif; ?>
                <?php if (($metadata['tprm_recommendation'] ?? '') !== ''): ?>
                    <article class="highlight-card">
                        <span class="label">🛡️ TPRM recommendation</span>
                        <strong><?= $this->e($metadata['tprm_recommendation']) ?></strong>
                    </article>
                <?php endif; ?>
                <?php if (($metadata['technology_recommendation'] ?? '') !== ''): ?>
                    <article class="highlight-card">
                        <span class="label">💻 Technology recommendation</span>
                        <strong><?= $this->e($metadata['technology_recommendation']) ?></strong>
                    </article>
                <?php endif; ?>
                <?php if (($metadata['governance_action'] ?? '') !== ''): ?>
                    <article class="highlight-card highlight-warning">
                        <span class="label">⚠️ Required governance action</span>
                        <strong><?= $this->e($metadata['governance_action']) ?></strong>
                    </article>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($fields !== []): ?>
            <section class="table-card table-card-uplift chart-card-tone-governance">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">📄</span>
                        <div>
                            <div class="eyebrow">JSON due diligence</div>
                            <h3>Summary fields</h3>
                        </div>
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
            <section class="table-card table-card-uplift chart-card-tone-governance" style="margin-top: 10px;">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🚨</span>
                        <div>
                            <div class="eyebrow">Exceptions</div>
                            <h3>Documented findings</h3>
                        </div>
                    </div>
                    <span class="result-count result-count-badge"><?= count($findings) ?> findings</span>
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
            <div class="context-banner context-note context-banner-uplift">📝 <?= $this->e($note) ?></div>
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
        <div class="legend-grid legend-grid-uplift">
            <section class="table-card table-card-uplift chart-card-tone-legend">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🏷️</span>
                        <div>
                            <div class="eyebrow">Scoring</div>
                            <h3>Status meanings</h3>
                        </div>
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

            <section class="table-card table-card-uplift chart-card-tone-legend">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🎯</span>
                        <div>
                            <div class="eyebrow">Scoring</div>
                            <h3>Risk level guidance</h3>
                        </div>
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
            <section class="table-card table-card-uplift chart-card-tone-legend" style="margin-top: 10px;">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">✅</span>
                        <div>
                            <div class="eyebrow">Evidence</div>
                            <h3>Minimum evidence checklist</h3>
                        </div>
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

    /** @param array<string, mixed> $progress */
    /** @return list<array<string, mixed>> */
    private function buildProgressStatusSlices(array $progress): array
    {
        $open = is_array($progress['by_status_open'] ?? null) ? $progress['by_status_open'] : [];
        $slices = [];
        foreach (['Pass', 'Gap', 'Risk', 'TBD', 'N/A', 'Addressed'] as $status) {
            $value = (int) ($open[$status] ?? 0);
            if ($value <= 0 && in_array($status, ['N/A', 'Addressed'], true)) {
                continue;
            }
            if ($value <= 0 && $status !== 'Pass') {
                // Keep empty Gap/Risk/TBD out of the chart once cleared
                continue;
            }
            if ($status === 'Pass' && $value <= 0) {
                continue;
            }
            $slices[] = [
                'label' => $status,
                'value' => $value,
                'color' => self::STATUS_COLOR[$status] ?? '#94a3b8',
                'filterType' => $status === 'Addressed' ? 'response' : 'status',
                'filterValue' => $status === 'Addressed' ? 'addressed' : $status,
            ];
        }

        return $slices;
    }

    /**
     * @param array<string, mixed> $progress
     * @param array<string, mixed> $summary
     * @return list<array<string, mixed>>
     */
    private function buildProgressRiskSlices(array $progress, array $summary): array
    {
        $highOpen = (int) ($progress['high']['open'] ?? 0);
        $highAddressed = (int) ($progress['high']['addressed'] ?? 0);
        $slices = [
            [
                'label' => 'High open',
                'value' => $highOpen,
                'color' => self::RISK_COLOR['High'],
                'filterType' => 'risk',
                'filterValue' => 'High',
            ],
        ];
        if ($highAddressed > 0) {
            $slices[] = [
                'label' => 'High addressed',
                'value' => $highAddressed,
                'color' => '#0e7490',
                'filterType' => 'response',
                'filterValue' => 'addressed',
            ];
        }
        foreach (['Med', 'Low'] as $risk) {
            $slices[] = [
                'label' => $risk,
                'value' => (int) ($summary['by_risk'][$risk] ?? 0),
                'color' => self::RISK_COLOR[$risk],
                'filterType' => 'risk',
                'filterValue' => $risk,
            ];
        }

        return array_values(array_filter($slices, static fn(array $slice): bool => (int) $slice['value'] > 0));
    }

    /** @param array<string, mixed> $progress */
    private function renderProgressMeter(array $progress): string
    {
        $total = (int) ($progress['actionable']['total'] ?? 0);
        $addressed = (int) ($progress['actionable']['addressed'] ?? 0);
        $open = (int) ($progress['actionable']['open'] ?? 0);
        if ($total <= 0) {
            return '';
        }
        $pct = round(($addressed / max(1, $total)) * 100, 1);

        ob_start();
        ?>
        <div class="residual-progress" id="residual-progress" aria-label="Residual issue progress">
            <div class="residual-progress-head">
                <span>Issues addressed</span>
                <strong>
                    <span data-progress-addressed="actionable"><?= $addressed ?></span>/<span data-progress-total="actionable"><?= $total ?></span>
                </strong>
            </div>
            <div class="residual-progress-track">
                <span class="residual-progress-fill" data-progress-bar="actionable" style="width: <?= $pct ?>%"></span>
            </div>
            <div class="residual-progress-meta">
                <span data-progress-caption="actionable"><?= $open ?> still open</span>
                <span data-progress-fraction="actionable"><?= $open ?>/<?= $total ?> open</span>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $progress */
    private function renderRiskSpectrum(array $progress): string
    {
        $open = is_array($progress['by_status_open'] ?? null) ? $progress['by_status_open'] : [];
        $parts = [
            'pass' => (int) ($open['Pass'] ?? 0),
            'addressed' => (int) ($open['Addressed'] ?? 0),
            'gap' => (int) ($open['Gap'] ?? 0),
            'risk' => (int) ($open['Risk'] ?? 0),
            'tbd' => (int) ($open['TBD'] ?? 0),
            'na' => (int) ($open['N/A'] ?? 0),
        ];
        $total = max(1, array_sum($parts));

        ob_start();
        ?>
        <div class="risk-spectrum" aria-label="Open status mix">
            <div class="risk-spectrum-track">
                <?php foreach ($parts as $key => $count): ?>
                    <?php if ($count <= 0) { continue; } ?>
                    <span
                        class="risk-spectrum-seg <?= $this->e($key) ?>"
                        style="width: <?= $this->percent($count, $total) ?>%; animation-delay: <?= array_search($key, array_keys($parts), true) * 0.05 ?>s"
                        title="<?= $this->e(strtoupper($key)) ?>: <?= $count ?>"
                        data-spectrum-seg="<?= $this->e($key) ?>"
                    ></span>
                <?php endforeach; ?>
            </div>
            <div class="risk-spectrum-legend">
                <?php foreach (['Pass' => 'pass', 'Addressed' => 'addressed', 'Gap' => 'gap', 'Risk' => 'risk', 'TBD' => 'tbd'] as $label => $key): ?>
                    <span><b data-spectrum-count="<?= $this->e($key) ?>"><?= (int) ($parts[$key] ?? 0) ?></b> <?= $this->e($label) ?></span>
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
        $radius = $hero ? 70 : 64;
        $stroke = $hero ? 16 : 18;
        $circumference = 2 * M_PI * $radius;
        $offset = 0.0;
        $size = $hero ? 200 : 180;
        $center = $size / 2;
        $isFraction = str_contains($centerValue, '/');

        ob_start();
        ?>
        <div class="donut-wrap<?= $hero ? ' donut-wrap-hero' : '' ?><?= $isFraction ? ' has-fraction' : '' ?>" data-chart-id="<?= $this->e($chartId) ?>">
            <svg viewBox="0 0 <?= $size ?> <?= $size ?>" class="donut-chart" aria-hidden="true">
                <circle cx="<?= $center ?>" cy="<?= $center ?>" r="<?= $radius ?>" fill="none" stroke="rgba(148, 163, 184, 0.28)" stroke-width="<?= $stroke ?>"></circle>
                <?php foreach ($slices as $index => $slice): ?>
                    <?php
                    $value = (int) $slice['value'];
                    if ($value <= 0) {
                        continue;
                    }
                    $length = ($value / $total) * $circumference;
                    $gap = $total > $value ? 2.5 : 0;
                    ?>
                    <circle
                        class="donut-segment"
                        cx="<?= $center ?>"
                        cy="<?= $center ?>"
                        r="<?= $radius ?>"
                        fill="none"
                        stroke="<?= $this->e((string) $slice['color']) ?>"
                        stroke-width="<?= $stroke ?>"
                        stroke-linecap="butt"
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
            <div class="donut-center" data-donut-center="<?= $this->e($chartId) ?>">
                <strong data-donut-value="<?= $this->e($chartId) ?>"><?= $this->e($centerValue) ?></strong>
                <span data-donut-label="<?= $this->e($chartId) ?>"><?= $this->e($centerLabel) ?></span>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /** @param list<array<string, mixed>> $slices */
    private function renderPieChart(array $slices, string $chartId, string $centerValue = '', string $centerLabel = 'checks'): string
    {
        $total = array_sum(array_column($slices, 'value'));
        $center = 95;
        $radius = 74;
        $hole = 48;
        $angle = -90.0;
        if ($centerValue === '') {
            $centerValue = (string) (int) $total;
        }
        $isFraction = str_contains($centerValue, '/');

        ob_start();
        ?>
        <div class="pie-wrap<?= $isFraction ? ' has-fraction' : '' ?>" data-chart-id="<?= $this->e($chartId) ?>">
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
                <circle cx="<?= $center ?>" cy="<?= $center ?>" r="<?= $hole ?>" fill="var(--card, #fff)"></circle>
            </svg>
            <div class="pie-center" data-donut-center="<?= $this->e($chartId) ?>">
                <strong data-donut-value="<?= $this->e($chartId) ?>"><?= $this->e($centerValue) ?></strong>
                <span data-donut-label="<?= $this->e($chartId) ?>"><?= $this->e($centerLabel) ?></span>
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

    private function renderPanelIntro(string $emoji, string $eyebrow, string $title, string $description): string
    {
        ob_start();
        ?>
        <div class="dash-panel-intro">
            <div class="dash-panel-intro-copy">
                <span class="dash-panel-intro-icon" aria-hidden="true"><?= $emoji ?></span>
                <div>
                    <div class="eyebrow"><?= $this->e($eyebrow) ?></div>
                    <h2><?= $this->e($title) ?></h2>
                    <p><?= $this->e($description) ?></p>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
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
