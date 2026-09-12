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
        'Decision Required' => '#7c3aed',
        'Accepted Risk' => '#a16207',
        'Closed' => '#0e7490',
        'TBD' => '#64748b',
        'N/A' => '#94a3b8',
        'Addressed' => '#0e7490',
    ];

    /** @var array<string, string> */
    private const RISK_COLOR = [
        'Critical' => '#7f1d1d',
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
     * @param array<string, array{status?: string, comment?: string, servicenow_links?: list<string>}|string> $findingStatuses
     * @param array{verdict?: string, summary?: string} $executiveOverride
     * @param array<string, list<array<string, mixed>>> $itemResponseHistory
     * @param list<array<string, mixed>> $evaluationHistory
     * @param array{name?: string, email?: string} $evaluatorDefaults
     * @param list<array{id: int, title: string, mime_type: string, original_filename: string, sort_order: int}> $projectPictures
     * @param list<array{id: int, created_at: string, created_by_username: string, expires_at: ?string, last_accessed_at: ?string, is_active: bool}> $shareLinks
     * @param string|null $freshShareUrl Absolute URL shown after creating a share link
     * @param array{project_name: string, folder_url: string, items: list<array<string, mixed>>}|null $sharePointCatalog
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
        array $projectPictures = [],
        bool $readOnly = false,
        string $shareToken = '',
        array $shareLinks = [],
        ?string $freshShareUrl = null,
        ?array $sharePointCatalog = null,
        bool $smtpEnabled = false,
        bool $viewerIsAdmin = false,
        bool $isOwner = false,
        bool $isLocked = false,
        array $projectEditors = [],
        array $eligibleEditors = []
    ): string {
        $metadata = $assessment->metadata;
        $summary = $assessment->summary;
        $items = $assessment->items;
        $dueItems = $assessment->dueDiligenceItems;
        $workbook = $assessment->workbook;
        $isAdaptive = ($workbook['format'] ?? '') === 'adaptive';
        $isPublicShare = $readOnly && $shareToken !== '';
        $signedInViewOnly = $readOnly && !$isPublicShare;
        if ($isAdaptive) {
            $items = $this->enrichAdaptiveItems($items, $workbook['material_findings'] ?? []);
        }
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
        $hasGovernance = ($workbook['fields'] ?? []) !== []
            || ($workbook['findings'] ?? []) !== []
            || ($isAdaptive && (
                ($workbook['classification'] ?? []) !== []
                || ($workbook['decisions'] ?? []) !== []
                || ($workbook['lifecycle'] ?? []) !== []
                || ($workbook['exceptions'] ?? []) !== []
            ));
        $hasLegend = ($workbook['legend']['statuses'] ?? []) !== []
            || ($workbook['legend']['checklist'] ?? []) !== []
            || ($workbook['legend']['routing'] ?? []) !== [];
        $hasRouter = $isAdaptive && ($workbook['router'] ?? []) !== [];
        $adaptiveViews = $isAdaptive ? new AdaptiveDashboardViews() : null;

        $statusSlices = $this->buildProgressStatusSlices($archProgress);
        $riskSlices = $this->buildProgressRiskSlices($archProgress, $summary);
        $sectionSlices = $this->buildSectionSlices($summary);
        $ddStatusSlices = $this->buildProgressStatusSlices($ddProgress);
        $ddRiskSlices = $this->buildProgressRiskSlices($ddProgress, $ddSummary);
        $ddSectionSlices = $this->buildSectionSlices($ddSummary);

        $progressJson = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $branding = Branding::current();
        $effectiveCsrf = $readOnly ? '' : $csrfToken;
        $bodyClasses = [];
        if (!empty($evaluation['ready_to_golive'])) {
            $bodyClasses[] = 'is-ready-golive';
        }
        if ($readOnly) {
            $bodyClasses[] = 'is-readonly-share';
        }
        if ($signedInViewOnly) {
            $bodyClasses[] = 'is-readonly-signed-in';
        }
        if ($isLocked) {
            $bodyClasses[] = 'is-project-locked';
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($solutionName) ?><?= $isPublicShare ? ' (shared)' : ($signedInViewOnly ? ' (view only)' : '') ?> · <?= $this->e($branding->brandTitle()) ?></title>
    <?php if ($isPublicShare): ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <?php require dirname(__DIR__) . '/public/includes/theme-head.php'; ?>
    <?php require dirname(__DIR__) . '/public/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(dirname(__DIR__) . '/public/assets/css/dashboard.css') ?>">
</head>
<body
    data-assessment-id="<?= (int) $assessmentId ?>"
    data-csrf-token="<?= $this->e($effectiveCsrf) ?>"
    data-readonly="<?= $readOnly ? '1' : '0' ?>"
    data-share-token="<?= $this->e($shareToken) ?>"
    data-progress="<?= $this->e($progressJson) ?>"
    data-golive-gates="<?= $this->e($goliveGatesJson) ?>"
    class="<?= $this->e(implode(' ', $bodyClasses)) ?>"
>
    <div class="shell">
        <header class="topbar topbar-uplift">
            <?php if ($isPublicShare): ?>
                <div class="brand brand-link brand-static" title="Shared read-only view">
                    <?= $branding->renderMark() ?>
                    <div class="brand-text">
                        <div class="brand-title"><?= $this->e($branding->brandTitle()) ?></div>
                        <h1><?= $this->e($branding->brandSubtitle()) ?></h1>
                    </div>
                </div>
            <?php else: ?>
                <a class="brand brand-link" href="index.php#find-projects" title="Back to find projects">
                    <?= $branding->renderMark() ?>
                    <div class="brand-text">
                        <div class="brand-title"><?= $this->e($branding->brandTitle()) ?></div>
                        <h1><?= $this->e($branding->brandSubtitle()) ?></h1>
                    </div>
                </a>
            <?php endif; ?>
            <div class="topbar-actions">
                <?php if ($isPublicShare): ?>
                    <span class="share-readonly-pill" title="Anyone with this link can view this assessment">🔒 Read-only share</span>
                    <?php require dirname(__DIR__) . '/public/includes/topbar-menu-start.php'; ?>
                    <?php require dirname(__DIR__) . '/public/includes/topbar-menu-end.php'; ?>
                <?php else: ?>
                    <?php if ($signedInViewOnly): ?>
                        <span class="share-readonly-pill" title="Only the owner can grant edit access">👁 View only</span>
                    <?php endif; ?>
                    <?php if ($isLocked): ?>
                        <span class="project-lock-badge" title="This project is locked">🔒 Locked</span>
                    <?php endif; ?>
                    <?php require dirname(__DIR__) . '/public/includes/topbar-menu-start.php'; ?>
                    <?php require dirname(__DIR__) . '/public/includes/app-nav-links.php'; ?>
                    <?php require dirname(__DIR__) . '/public/includes/updates-nav.php'; ?>
                    <?php require dirname(__DIR__) . '/public/includes/topbar-menu-end.php'; ?>
                <?php endif; ?>
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
            <?php if ($signedInViewOnly): ?>
                <div class="alert alert-info desk-flash project-access-banner">
                    You can view this project. Only the owner can grant edit access.
                </div>
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
                        <?php if ($readOnly): ?>
                            <span class="button ghost is-disabled" aria-disabled="true">🔒 Shared view</span>
                            <a class="button button-primary" href="#risk-register">📋 View register</a>
                        <?php else: ?>
                            <a class="button ghost" href="index.php#find-projects">🏠 Home · Find projects</a>
                            <a class="button ghost" href="index.php#upload">📤 Upload another file</a>
                            <a class="button button-primary" href="#risk-register">📋 View register</a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?= $decisionViews->renderDecisionDesk($insights, $assessmentId, $comparison, $evaluation, $progress, $readOnly) ?>

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
                <?php if (($metadata['decision_gate'] ?? '') !== ''): ?>
                    <div class="meta-item meta-arch"><span class="label">🚪 Decision gate</span><strong><?= $this->e($metadata['decision_gate']) ?></strong></div>
                <?php endif; ?>
                <?php if ($sourceFilename !== ''): ?>
                    <div class="meta-item meta-file"><span class="label">📎 Source file</span><strong><?= $this->e($sourceFilename) ?></strong></div>
                <?php endif; ?>
            </section>

            <?php if ($adaptiveViews !== null): ?>
                <?= $adaptiveViews->renderMetaChips($workbook, $metadata) ?>
            <?php endif; ?>

            <nav class="dash-tabs dash-tabs-uplift" role="tablist" aria-label="Workbook tabs">
                <?php if ($hasRouter): ?>
                    <button type="button" class="dash-tab dash-tab-theme-router is-active" role="tab" aria-selected="true" data-tab="router" data-tooltip="Start here: browse selected, conditional, and excluded scenarios by module. Material findings and due diligence flow from these routing decisions.">🧭 Question Router</button>
                <?php endif; ?>
                <button type="button" class="dash-tab dash-tab-theme-architecture<?= $hasRouter ? '' : ' is-active' ?>" role="tab" aria-selected="<?= $hasRouter ? 'false' : 'true' ?>" data-tab="architecture" data-tooltip="<?= $isAdaptive ? 'Material Gap, Risk, and Decision Required findings from the Architecture Risk Register. Respond to them under Actions → Material findings.' : 'Browse architecture control status, risk levels, charts, and the full risk register.' ?>"><?= $isAdaptive ? '📋 Material findings' : '🏛️ Architecture checks' ?></button>
                <?php if ($hasDueDiligence): ?>
                    <button type="button" class="dash-tab dash-tab-theme-diligence" role="tab" aria-selected="false" data-tab="due-diligence" data-tooltip="<?= $isAdaptive ? 'Evidence and control-attestation items for the detected architecture. Respond to them under Actions → Due diligence.' : 'Review technology risk / due-diligence items, evidence notes, and extended coverage.' ?>">🔍 Due diligence</button>
                <?php endif; ?>
                <button type="button" class="dash-tab dash-tab-theme-actions" role="tab" aria-selected="false" data-tab="actions" data-tooltip="<?= $isAdaptive ? 'Respond to actionable items from Material findings and Due diligence (shown as source sub-tabs), then sign off and manage versions.' : 'Record responses on risks, gaps, TBDs, and exceptions; manage sign-off, versions, and workload views.' ?>">✅ Actions</button>
                <button type="button" class="dash-tab dash-tab-theme-project" role="tab" aria-selected="false" data-tab="project" data-tooltip="Add architecture diagrams, pictures, and useful project links for this assessment.">📐 Diagram &amp; links</button>
                <?php if ($hasGovernance): ?>
                    <button type="button" class="dash-tab dash-tab-theme-governance" role="tab" aria-selected="false" data-tab="governance" data-tooltip="View governance fields, documented exceptions, and recommended compliance actions.">⚖️ Governance summary</button>
                <?php endif; ?>
                <?php if ($hasLegend): ?>
                    <button type="button" class="dash-tab dash-tab-theme-legend" role="tab" aria-selected="false" data-tab="legend" data-tooltip="<?= $isAdaptive ? 'Routing decisions, score bands, material finding types, and the materiality gate for Risk Register rows.' : 'Reference status meanings, risk-level guidance, and the minimum evidence checklist.' ?>">📊 Scoring legend</button>
                <?php endif; ?>
            </nav>

            <?php if ($hasRouter && $adaptiveViews !== null): ?>
                <?= $adaptiveViews->renderRouterPanel(
                    $workbook,
                    fn (array $slices, string $id, string $center, string $label, bool $hero = false): string => $this->renderDonutChart($slices, $id, $center, $label, $hero),
                    fn (array $slices, string $type): string => $this->renderChartLegend($slices, $type),
                    fn (string $icon, string $eyebrow, string $title, string $help): string => $this->renderPanelIntro($icon, $eyebrow, $title, $help),
                    true
                ) ?>
            <?php endif; ?>

            <div class="dash-panel dash-panel-theme-architecture<?= $hasRouter ? '' : ' is-active' ?>" data-panel="architecture"<?= $hasRouter ? ' hidden' : '' ?>>
                <?= $this->renderPanelIntro(
                    $isAdaptive ? '📋' : '🏛️',
                    $isAdaptive ? 'Material findings' : 'Architecture review',
                    $isAdaptive ? 'Architecture Risk Register' : 'Architecture checks',
                    $isAdaptive
                        ? 'Material Gap, Risk, and Decision Required findings only — the Question Router holds the full scenario catalog. Respond to actionable rows under Actions → Material findings.'
                        : 'Browse status, risk levels, charts, and the full register for every architecture control.'
                ) ?>
                <?= $this->renderKpis($summary, 'architecture', $archProgress) ?>
                <?php if ($adaptiveViews !== null): ?>
                    <?= $adaptiveViews->renderFindingsRiskCharts(
                        $items,
                        fn (array $slices, string $id, string $center, string $label, bool $hero = false): string => $this->renderDonutChart($slices, $id, $center, $label, $hero),
                        fn (array $slices, string $type): string => $this->renderChartLegend($slices, $type)
                    ) ?>
                <?php else: ?>
                    <?= $this->renderChartsBlock($statusSlices, $riskSlices, $sectionSlices, $summary, 'architecture', $archProgress) ?>
                <?php endif; ?>
                <?= $this->renderSectionBars($summary['by_section'] ?? []) ?>
                <?= $this->renderRegister(
                    'architecture',
                    'risk-register',
                    $isAdaptive ? 'Material findings' : 'Architecture checks',
                    $isAdaptive ? 'Architecture Risk Register' : 'Risk register',
                    $items,
                    $summary,
                    $isAdaptive,
                    $changedKeys,
                    $responses,
                    $assessmentId,
                    $readOnly
                ) ?>
            </div>

            <?php if ($hasDueDiligence): ?>
                <div class="dash-panel dash-panel-theme-diligence" data-panel="due-diligence" hidden>
                    <?= $this->renderPanelIntro('🔍', 'Extended review', 'Due diligence', $isAdaptive ? 'Evidence and control-attestation layer for the detected solution architecture. Actionable rows are answered under Actions → Due diligence.' : 'Technology risk template items, evidence notes, and extended diligence coverage.') ?>
                    <?php if (($workbook['context'] ?? '') !== ''): ?>
                        <div class="context-banner context-banner-uplift">💡 <?= $this->e((string) $workbook['context']) ?></div>
                    <?php endif; ?>
                    <?= $this->renderKpis($ddSummary, 'due_diligence', $ddProgress) ?>
                    <?= $this->renderChartsBlock($ddStatusSlices, $ddRiskSlices, $ddSectionSlices, $ddSummary, 'due_diligence', $ddProgress) ?>
                    <?= $this->renderSectionBars($ddSummary['by_section'] ?? []) ?>
                    <?= $this->renderRegister(
                        'due_diligence',
                        'dd-register',
                        $isAdaptive ? 'Due diligence evidence' : 'Due diligence extension',
                        $isAdaptive ? 'Evidence catalog' : 'Technology risk template',
                        $dueItems,
                        $ddSummary,
                        true,
                        $changedKeys,
                        $responses,
                        $assessmentId,
                        $readOnly
                    ) ?>
                </div>
            <?php endif; ?>

            <div class="dash-panel dash-panel-theme-actions" data-panel="actions" hidden>
                <?= $decisionViews->renderActionsPanel($insights, $comparison, $versions, $assessmentId, $effectiveCsrf, $actionableItems, $evaluation, $goliveGates, $evaluationHistory, $evaluatorDefaults, $readOnly, $shareLinks, $freshShareUrl, $isAdaptive, $smtpEnabled, $viewerIsAdmin, $isOwner, $isLocked, $projectEditors, $eligibleEditors) ?>
            </div>

            <div class="dash-panel dash-panel-theme-project" data-panel="project" hidden>
                <?= $projectResources->render($assessmentId, $projectLinks, $projectDiagrams, $projectPictures, !$readOnly, $shareToken, $sharePointCatalog) ?>
            </div>

            <?php if ($hasGovernance): ?>
                <div class="dash-panel dash-panel-theme-governance" data-panel="governance" hidden>
                    <?= $this->renderPanelIntro('⚖️', 'Governance & compliance', 'Governance summary', $isAdaptive ? 'Classification signals, ADRs, lifecycle inventory, and policy exceptions from the Adaptive workbook.' : 'JSON diligence fields, documented exceptions, and recommended governance actions.') ?>
                    <?= $this->renderGovernancePanel($workbook, $metadata) ?>
                    <?php if ($adaptiveViews !== null): ?>
                        <?= $adaptiveViews->renderAdaptiveGovernanceExtras($workbook, $metadata) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hasLegend): ?>
                <div class="dash-panel dash-panel-theme-legend" data-panel="legend" hidden>
                    <?= $this->renderPanelIntro('📊', 'Scoring reference', 'Scoring legend', $isAdaptive ? 'Routing decisions, score bands (Low/Moderate/High/Critical), material finding types, and the materiality gate for Risk Register rows.' : 'Status meanings, risk level guidance, and minimum evidence checklist from the workbook.') ?>
                    <?= $this->renderLegendPanel($workbook['legend'] ?? []) ?>
                    <?php if ($adaptiveViews !== null): ?>
                        <?= $adaptiveViews->renderAdaptiveLegendExtras($workbook['legend'] ?? []) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
        <?php if (!$readOnly): ?>
            <?= $this->renderAddItemDialog($assessmentId) ?>
        <?php endif; ?>
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
            'Decision Required' => '🟣',
            'Accepted Risk' => '🟤',
            'Closed' => '☑️',
            'TBD' => '❓',
            'N/A' => '➖',
        ];
        $riskEmoji = ['Critical' => '🛑', 'High' => '🚨', 'Med' => '⚠️', 'Low' => '🟢'];
        $statusOrder = array_keys($statusEmoji);
        $riskOrder = array_keys($riskEmoji);
        ob_start();
        ?>
        <section class="kpis kpis-uplift" id="<?= $prefix ?>kpi-tiles" data-filter-scope="<?= $this->e($scope) ?>">
            <button type="button" class="kpi kpi-clickable tone-all is-active" data-filter-type="all" data-filter-value="" aria-pressed="true">
                <span class="kpi-emoji" aria-hidden="true">📊</span>
                <div class="eyebrow">Total</div>
                <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
                <span>View all rows</span>
            </button>
            <?php foreach ($statusOrder as $status): ?>
                <?php if (!isset($summary['by_status'][$status])) { continue; } ?>
                <?php if (($summary['by_status'][$status] ?? 0) === 0 && in_array($status, ['N/A', 'Decision Required', 'Accepted Risk', 'Closed'], true)) { continue; } ?>
                <button type="button" class="kpi kpi-clickable tone-<?= $this->e(strtolower(str_replace(['/', ' '], ['', '-'], $status))) ?>" data-filter-type="status" data-filter-value="<?= $this->e($status) ?>" aria-pressed="false">
                    <span class="kpi-emoji" aria-hidden="true"><?= $statusEmoji[$status] ?></span>
                    <div class="eyebrow"><?= $this->e($status) ?></div>
                    <?php
                    $openKey = strtolower($status);
                    $open = (int) ($progress['by_status_open'][$status] ?? ($summary['by_status'][$status] ?? 0));
                    $total = (int) ($summary['by_status'][$status] ?? 0);
                    $addressed = max(0, $total - $open);
                    ?>
                    <?php if ($addressed > 0 && in_array($status, ['Gap', 'Risk', 'TBD', 'Decision Required'], true)): ?>
                        <strong><?= $open ?><small>/<?= $total ?></small></strong>
                        <span>Filter <?= $this->e(strtolower($status)) ?> rows</span>
                    <?php else: ?>
                        <strong><?= $total ?></strong>
                        <span>Filter <?= $this->e(strtolower($status)) ?> rows</span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
            <?php foreach ($riskOrder as $risk): ?>
                <?php if (!isset($summary['by_risk'][$risk])) { continue; } ?>
                <?php if (($summary['by_risk'][$risk] ?? 0) === 0 && $risk === 'Critical') { continue; } ?>
                <button type="button" class="kpi kpi-clickable tone-<?= $this->e(strtolower($risk)) ?>" data-filter-type="risk" data-filter-value="<?= $this->e($risk) ?>" aria-pressed="false">
                    <span class="kpi-emoji" aria-hidden="true"><?= $riskEmoji[$risk] ?></span>
                    <div class="eyebrow"><?= $this->e($risk) ?> risk</div>
                    <?php
                    $total = (int) ($summary['by_risk'][$risk] ?? 0);
                    ?>
                    <?php if ($risk === 'High' || $risk === 'Critical'): ?>
                        <?php
                        $open = (int) ($progress['high']['open'] ?? $total);
                        if ($risk === 'Critical') {
                            $open = $total; // critical counted in high bucket combined; show raw count
                        }
                        ?>
                        <strong><?= $total ?></strong>
                        <span>Filter <?= $this->e(strtolower($risk)) ?> risk rows</span>
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
        int $assessmentId = 0,
        bool $readOnly = false
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
                <option value=""><?= $scope === 'due_diligence' ? 'All categories' : 'All sections' ?></option>
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
            <?php if (!$readOnly): ?>
                <button type="button" class="button ghost" data-filter-type="action_tab" data-filter-value="risks">✅ Respond in Actions</button>
            <?php endif; ?>
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
                <div class="register-heading-actions">
                    <span class="result-count result-count-badge" id="<?= $prefix ?>filter-count"><?= count($items) ?> shown</span>
                    <?php if (!$readOnly): ?>
                        <button
                            type="button"
                            class="button button-primary register-add-row"
                            data-item-type="<?= $this->e($itemType) ?>"
                            <?= $assessmentId <= 0 ? 'disabled' : '' ?>
                            title="<?= $assessmentId <= 0 ? 'Save this assessment first' : 'Add a manual row' ?>"
                        >➕ Add row</button>
                    <?php endif; ?>
                </div>
            </div>
            <p class="panel-help dashboard-readonly-hint"><?= $readOnly
                ? '👁️ Read-only shared view. Responses and comments are visible but cannot be changed here.'
                : '✏️ Use the pencil on actionable rows to record Taken care / Ignore / comments. ➕ Add manual rows or 🗑️ delete any row for this assessment only (not carried to the next Excel upload).' ?></p>
            <div class="table-scroll">
                <table id="<?= $this->e($tableId) ?>">
                    <thead>
                        <tr>
                            <th class="col-row-num">#</th>
                            <th><?= $scope === 'due_diligence' ? 'Category' : 'Section' ?></th>
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
                            <th class="col-row-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $itemIndex => $item): ?>
                            <?php
                            $rowNumber = $itemIndex + 1;
                            $owner = (string) ($item['owner'] ?? '');
                            $timeline = (string) ($item['remediation_timeline'] ?? '');
                            $mitigation = (string) ($item['mitigation'] ?? '');
                            $notes = (string) ($item['notes'] ?? '');
                            $status = (string) ($item['status'] ?? '');
                            $riskLevel = (string) ($item['risk_level'] ?? '');
                            $itemId = (int) ($item['id'] ?? 0);
                            $origin = strtolower(trim((string) ($item['origin'] ?? 'excel'))) === 'manual' ? 'manual' : 'excel';
                            $isManual = $origin === 'manual';
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
                            $reviewQuestion = (string) ($item['review_question'] ?? '');
                            $sourceReference = (string) ($item['source_reference'] ?? '');
                            $checkTitle = (string) ($item['check'] ?? '');
                            $sectionName = (string) ($item['section'] ?? '');
                            $sourceLabel = $itemType === 'due_diligence'
                                ? 'Due diligence'
                                : ($extendedColumns ? 'Material findings' : 'Architecture');
                            $sectionSub = $sectionName;
                            if ($owner !== '') {
                                $sectionSub .= ($sectionSub !== '' ? ' · ' : '') . $owner;
                            }
                            $commentPreview = $responseComment !== ''
                                ? (mb_strlen($responseComment) > 90 ? mb_substr($responseComment, 0, 87) . '…' : $responseComment)
                                : '';
                            $updatedAt = (string) ($response['updated_at'] ?? '');
                            $updatedByLabel = (string) ($response['updated_by_label'] ?? '');
                            $searchParts = [
                                (string) $rowNumber,
                                $sectionName,
                                $checkTitle,
                                $notes,
                                $mitigation,
                                $owner,
                                $reviewQuestion,
                                $sourceReference,
                                $responseComment,
                                $responseLabel,
                                $isManual ? 'manual' : '',
                            ];
                            $ownersAttr = strtolower(preg_replace('/\s*(?:\+|\/|,|;|\band\b)\s*/i', '|', $owner !== '' ? $owner : 'unassigned') ?? 'unassigned');
                            $timelineLane = $this->timelineLane($timeline);
                            ?>
                            <tr
                                class="data-row<?= $isChanged ? ' row-changed' : '' ?><?= $isActionable ? ' row-actionable' : '' ?><?= $isManual ? ' row-manual' : '' ?>"
                                data-section="<?= $this->e($sectionName) ?>"
                                data-status="<?= $this->e($status) ?>"
                                data-risk="<?= $this->e($riskLevel) ?>"
                                data-owner="<?= $this->e($ownersAttr) ?>"
                                data-timeline="<?= $this->e($timelineLane) ?>"
                                data-changed="<?= $isChanged ? '1' : '0' ?>"
                                data-actionable="<?= $isActionable ? '1' : '0' ?>"
                                data-response="<?= $this->e($responseAction) ?>"
                                data-has-comment="<?= $responseComment !== '' ? '1' : '0' ?>"
                                data-item-key="<?= $this->e($key) ?>"
                                data-item-id="<?= $itemId ?>"
                                data-row-number="<?= $rowNumber ?>"
                                data-origin="<?= $this->e($origin) ?>"
                                data-missing-owner="<?= $owner === '' ? '1' : '0' ?>"
                                data-missing-timeline="<?= $timeline === '' ? '1' : '0' ?>"
                                data-missing-mitigation="<?= $mitigation === '' ? '1' : '0' ?>"
                                data-search="<?= $this->e(strtolower(implode(' ', $searchParts))) ?>"
                            >
                                <td class="col-row-num"><span class="row-number" title="<?= $this->e($sourceLabel) ?> row <?= $rowNumber ?>">#<?= $rowNumber ?></span></td>
                                <td><span class="section-name"><?= $this->e($sectionName) ?></span><?php if ($isChanged): ?><span class="change-flag">Changed</span><?php endif; ?></td>
                                <td>
                                    <div class="check-name">
                                        <?= $this->e($checkTitle) ?>
                                        <?php if ($isManual): ?>
                                            <span class="manual-row-badge" title="Added manually">Manual</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($owner !== ''): ?>
                                        <div class="subtext"><?= $this->e($owner) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $this->pill($status, 'status') ?></td>
                                <td><?= $this->pill($riskLevel, 'risk') ?></td>
                                <td class="response-cell">
                                    <?php if ($isActionable): ?>
                                        <div
                                            class="item-response"
                                            data-item-key="<?= $this->e($key) ?>"
                                            data-item-title="<?= $this->e($checkTitle) ?>"
                                            data-item-sub="<?= $this->e($sectionSub) ?>"
                                            data-item-source="<?= $this->e($sourceLabel) ?>"
                                            data-item-section="<?= $this->e($sectionName) ?>"
                                            data-item-status="<?= $this->e($status) ?>"
                                            data-item-risk="<?= $this->e($riskLevel) ?>"
                                            data-item-owner="<?= $this->e($owner) ?>"
                                            data-item-timeline="<?= $this->e($timeline) ?>"
                                            data-item-notes="<?= $this->e($notes) ?>"
                                            data-item-mitigation="<?= $this->e($mitigation) ?>"
                                            data-item-review-question="<?= $this->e($reviewQuestion) ?>"
                                            data-item-source-ref="<?= $this->e($sourceReference) ?>"
                                        >
                                            <div class="item-response-summary">
                                                <div class="item-response-summary-main">
                                                    <span class="response-pill response-<?= $this->e($responseAction) ?>"><?= $this->e($responseLabel) ?></span>
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
                                                        <option value="<?= $this->e($value) ?>" <?= $responseAction === $value ? 'selected' : '' ?>><?= $this->e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <textarea
                                                    class="item-response-comment"
                                                    rows="2"
                                                    maxlength="2000"
                                                    placeholder="Comment (optional)"
                                                    tabindex="-1"
                                                ><?= $this->e($responseComment) ?></textarea>
                                                <span class="item-response-save" hidden>Saved</span>
                                            </div>
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
                                    <td><div class="clamp-text" data-expandable><?= $this->e($reviewQuestion) ?></div></td>
                                    <td><?= $this->e($sourceReference) ?></td>
                                <?php endif; ?>
                                <td class="col-row-actions">
                                    <?php if (!$readOnly && $itemId > 0 && $assessmentId > 0): ?>
                                        <button
                                            type="button"
                                            class="register-row-delete"
                                            data-item-id="<?= $itemId ?>"
                                            data-item-title="<?= $this->e($checkTitle) ?>"
                                            aria-label="Delete <?= $this->e($checkTitle) ?>"
                                            title="Delete row"
                                        >🗑️</button>
                                    <?php else: ?>
                                        <span class="response-na">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($assessmentId <= 0): ?>
                <p class="response-hint">Save this assessment (upload) so Actions responses and manual rows can be stored in the database.</p>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    private function renderAddItemDialog(int $assessmentId): string
    {
        ob_start();
        ?>
        <dialog class="response-dialog register-add-dialog" id="register-add-dialog" aria-labelledby="register-add-dialog-title">
            <form method="dialog" class="response-dialog-form" id="register-add-dialog-form">
                <div class="response-dialog-head">
                    <div>
                        <div class="eyebrow" id="register-add-dialog-eyebrow">➕ Add register row</div>
                        <h3 id="register-add-dialog-title">Add manual row</h3>
                        <p class="response-dialog-sub">Adds to this assessment only. A new Excel upload will not keep this row.</p>
                    </div>
                    <button type="button" class="button ghost-light response-dialog-close" id="register-add-dialog-close" aria-label="Close">✕</button>
                </div>
                <input type="hidden" id="register-add-item-type" value="architecture">
                <div class="register-add-grid">
                    <label class="response-dialog-field">
                        <span>📁 Section</span>
                        <input type="text" id="register-add-section" maxlength="255" required placeholder="e.g. IAM">
                    </label>
                    <label class="response-dialog-field">
                        <span id="register-add-check-label">✅ Check</span>
                        <input type="text" id="register-add-check" maxlength="500" required placeholder="Assessment item / check">
                    </label>
                    <label class="response-dialog-field">
                        <span>🚦 Status</span>
                        <select id="register-add-status" required>
                            <?php foreach (['Pass', 'Gap', 'Risk', 'TBD', 'N/A'] as $statusOption): ?>
                                <option value="<?= $this->e($statusOption) ?>" <?= $statusOption === 'TBD' ? 'selected' : '' ?>><?= $this->e($statusOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="response-dialog-field">
                        <span>⚠️ Risk level</span>
                        <select id="register-add-risk">
                            <option value="">—</option>
                            <?php foreach (['High', 'Med', 'Low'] as $riskOption): ?>
                                <option value="<?= $this->e($riskOption) ?>"><?= $this->e($riskOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="response-dialog-field register-add-span-2">
                        <span>📋 Notes / finding</span>
                        <textarea id="register-add-notes" rows="3" maxlength="4000" placeholder="Finding or evidence notes"></textarea>
                    </label>
                    <label class="response-dialog-field register-add-span-2">
                        <span>🛡️ Mitigation / controls</span>
                        <textarea id="register-add-mitigation" rows="3" maxlength="4000" placeholder="Mitigation or controls"></textarea>
                    </label>
                    <label class="response-dialog-field">
                        <span>👤 Owner</span>
                        <input type="text" id="register-add-owner" maxlength="255" placeholder="Owner">
                    </label>
                    <label class="response-dialog-field">
                        <span>🗓️ Timeline</span>
                        <input type="text" id="register-add-timeline" maxlength="255" placeholder="e.g. Before go-live">
                    </label>
                    <label class="response-dialog-field register-add-dd-only register-add-span-2" hidden>
                        <span>❓ Review question</span>
                        <textarea id="register-add-review-question" rows="2" maxlength="2000" placeholder="Review question"></textarea>
                    </label>
                    <label class="response-dialog-field register-add-dd-only" hidden>
                        <span>🔗 Source reference</span>
                        <input type="text" id="register-add-source-ref" maxlength="500" placeholder="Source reference">
                    </label>
                </div>
                <p class="response-dialog-status" id="register-add-dialog-status" hidden></p>
                <div class="response-dialog-actions">
                    <button type="button" class="button ghost" id="register-add-dialog-cancel">Cancel</button>
                    <button type="submit" class="button button-primary" id="register-add-dialog-save" <?= $assessmentId <= 0 ? 'disabled' : '' ?>>💾 Save row</button>
                </div>
            </form>
        </dialog>
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
        $appendActionable = static function (array $sourceItems, array $responses) use (&$out): void {
            foreach ($sourceItems as $index => $item) {
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
                    'row_number' => $index + 1,
                    'item_type' => $type,
                    'section' => (string) ($item['section'] ?? ''),
                    'check' => (string) ($item['check'] ?? ''),
                    'status' => $status,
                    'risk_level' => $riskLevel,
                    'owner' => (string) ($item['owner'] ?? ''),
                    'notes' => (string) ($item['notes'] ?? ''),
                    'mitigation' => (string) ($item['mitigation'] ?? ''),
                    'remediation_timeline' => (string) ($item['remediation_timeline'] ?? ''),
                    'review_question' => (string) ($item['review_question'] ?? ''),
                    'source_reference' => (string) ($item['source_reference'] ?? ''),
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
        };
        $appendActionable($items, $responses);
        $appendActionable($dueItems, $responses);

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
        foreach (['Pass', 'Gap', 'Risk', 'Decision Required', 'Accepted Risk', 'Closed', 'TBD', 'N/A'] as $status) {
            $value = (int) ($summary['by_status'][$status] ?? 0);
            if ($value === 0 && in_array($status, ['N/A', 'Decision Required', 'Accepted Risk', 'Closed'], true)) {
                continue;
            }
            if ($value === 0 && !in_array($status, ['Pass', 'Gap', 'Risk', 'TBD'], true)) {
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
        foreach (['Critical', 'High', 'Med', 'Low'] as $risk) {
            $value = (int) ($summary['by_risk'][$risk] ?? 0);
            if ($risk === 'Critical' && $value === 0) {
                continue;
            }
            $slices[] = [
                'label' => $risk,
                'value' => $value,
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

    /**
     * Reattach Adaptive material-finding score fields stored in workbook_json after DB reload.
     *
     * @param list<array<string, string>> $items
     * @param list<array<string, mixed>> $materialFindings
     * @return list<array<string, string>>
     */
    private function enrichAdaptiveItems(array $items, array $materialFindings): array
    {
        if ($materialFindings === []) {
            return $items;
        }

        $byCheck = [];
        $byRiskId = [];
        foreach ($materialFindings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $check = (string) ($finding['check'] ?? '');
            $riskId = (string) ($finding['risk_id'] ?? '');
            if ($check !== '') {
                $byCheck[$check] = $finding;
            }
            if ($riskId !== '') {
                $byRiskId[$riskId] = $finding;
            }
        }

        foreach ($items as &$item) {
            $check = (string) ($item['check'] ?? '');
            $match = $byCheck[$check] ?? null;
            if ($match === null && $check !== '') {
                foreach ($byRiskId as $riskId => $finding) {
                    if (str_starts_with($check, $riskId)) {
                        $match = $finding;
                        break;
                    }
                }
            }
            if (!is_array($match)) {
                continue;
            }
            foreach ([
                'risk_id', 'source_scenario_id', 'lens', 'quality_attribute',
                'likelihood', 'impact', 'inherent_score', 'inherent_level',
                'residual_likelihood', 'residual_impact', 'residual_score', 'residual_level',
                'closure_evidence',
            ] as $field) {
                if (($item[$field] ?? '') === '' && ($match[$field] ?? '') !== '') {
                    $item[$field] = (string) $match[$field];
                }
            }
        }
        unset($item);

        return $items;
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
