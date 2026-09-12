<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Models\Assessment;

/**
 * Adaptive Architecture workbook dashboard panels (router, classification, material findings extras).
 */
final class AdaptiveDashboardViews
{
    /** @var array<string, string> */
    private const ROUTING_COLOR = [
        'Selected' => '#0f766e',
        'Conditional – verify' => '#c2410c',
        'Excluded' => '#94a3b8',
        'Unrouted' => '#64748b',
    ];

    /** @var array<string, string> */
    private const RISK_COLOR = [
        'Critical' => '#7f1d1d',
        'High' => '#be123c',
        'Med' => '#c2410c',
        'Low' => '#0f766e',
    ];

    /** @var list<string> */
    private const MODULE_COLORS = [
        '#0e7490', '#0f766e', '#0284c7', '#155e75', '#c2410c', '#be123c', '#64748b', '#0369a1', '#7c3aed', '#a16207',
    ];

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $workbook
     * @param array<string, string> $metadata
     */
    public function renderMetaChips(array $workbook, array $metadata): string
    {
        $classification = is_array($workbook['classification'] ?? null) ? $workbook['classification'] : [];
        $kpis = is_array($workbook['kpis'] ?? null) ? $workbook['kpis'] : [];

        $chips = [
            ['🧭', 'Primary type', (string) ($classification['primary_type'] ?? $metadata['architecture_model'] ?? '') ?: 'Not classified'],
            ['🔀', 'Secondary types', (string) ($classification['secondary_types'] ?? $metadata['secondary_types'] ?? '') ?: '—'],
            ['📊', 'Confidence', (string) ($classification['confidence'] ?? $metadata['classification_confidence'] ?? '') ?: '—'],
            ['🚪', 'Decision gate', (string) ($metadata['decision_gate'] ?? '') ?: '—'],
            ['📌', 'Routed', (string) ((int) ($kpis['routed'] ?? 0))],
            ['📋', 'Material findings', (string) ((int) ($kpis['material_findings'] ?? 0))],
            ['🔥', 'High / Critical', (string) ((int) ($kpis['high_critical'] ?? 0))],
        ];

        ob_start();
        ?>
        <section class="meta-grid meta-grid-uplift adaptive-meta-chips" aria-label="Adaptive architecture summary">
            <?php foreach ($chips as [$icon, $label, $value]): ?>
                <div class="meta-item">
                    <span class="label"><?= $this->e($icon . ' ' . $label) ?></span>
                    <strong><?= $this->e($value) ?></strong>
                </div>
            <?php endforeach; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $workbook
     * @param callable $renderDonut
     * @param callable $renderLegend
     * @param callable $renderPanelIntro
     */
    public function renderRouterPanel(
        array $workbook,
        callable $renderDonut,
        callable $renderLegend,
        callable $renderPanelIntro,
        bool $isActive = false
    ): string {
        $router = array_values($workbook['router'] ?? []);
        $decisionCounts = [
            'Selected' => 0,
            'Conditional – verify' => 0,
            'Excluded' => 0,
            'Unrouted' => 0,
        ];
        $byModule = [];

        foreach ($router as $row) {
            if (!is_array($row)) {
                continue;
            }
            $decision = trim((string) ($row['routing_decision'] ?? ''));
            $bucket = 'Unrouted';
            $lower = strtolower($decision);
            if ($lower === 'selected') {
                $bucket = 'Selected';
            } elseif (str_contains($lower, 'conditional')) {
                $bucket = 'Conditional – verify';
            } elseif ($lower === 'excluded' || $decision === '—') {
                $bucket = 'Excluded';
            }
            $decisionCounts[$bucket]++;

            $module = trim((string) ($row['module'] ?? '')) ?: 'Other';
            if (!isset($byModule[$module])) {
                $byModule[$module] = ['total' => 0, 'Selected' => 0, 'Conditional – verify' => 0, 'Excluded' => 0, 'Unrouted' => 0];
            }
            $byModule[$module]['total']++;
            $byModule[$module][$bucket]++;
        }

        $decisionSlices = [];
        foreach ($decisionCounts as $label => $value) {
            if ($value <= 0) {
                continue;
            }
            $decisionSlices[] = [
                'label' => $label,
                'value' => $value,
                'color' => self::ROUTING_COLOR[$label] ?? '#94a3b8',
                'filterType' => 'router_decision',
                'filterValue' => $label,
            ];
        }

        $routed = (int) (($workbook['kpis']['routed'] ?? 0));
        $total = count($router);
        $selected = (int) $decisionCounts['Selected'];
        $conditional = (int) $decisionCounts['Conditional – verify'];
        $excluded = (int) $decisionCounts['Excluded'];
        $unrouted = (int) $decisionCounts['Unrouted'];
        $coveragePct = $total > 0 ? (int) round(($routed / $total) * 100) : 0;

        $kpiTiles = [
            ['all', '', '📚', 'Catalog', $total, 'scenarios', 'tone-all'],
            ['router_decision', 'Selected', '✅', 'Selected', $selected, 'assess these', 'tone-pass'],
            ['router_decision', 'Conditional – verify', '🔍', 'Conditional', $conditional, 'verify first', 'tone-gap'],
            ['router_decision', 'Excluded', '➖', 'Excluded', $excluded, 'out of scope', 'tone-na'],
            ['router_decision', 'Unrouted', '❓', 'Unrouted', $unrouted, 'not decided', 'tone-tbd'],
        ];

        ob_start();
        ?>
        <div class="dash-panel dash-panel-theme-router<?= $isActive ? ' is-active' : '' ?>" data-panel="router"<?= $isActive ? '' : ' hidden' ?>>
            <div class="dash-panel-intro router-intro-hero">
                <div class="dash-panel-intro-copy">
                    <span class="dash-panel-intro-icon" aria-hidden="true">🧭</span>
                    <div>
                        <div class="eyebrow">Adaptive routing</div>
                        <h2>Question Router</h2>
                        <p>Start here: scenario catalog with routing decisions. Selected and conditional scenarios drive Material findings and Due diligence; excluded scenarios are out of scope.</p>
                    </div>
                </div>
                <div class="router-coverage-meter" aria-label="Routing coverage">
                    <div class="router-coverage-ring" style="--coverage: <?= $coveragePct ?>">
                        <strong><?= $coveragePct ?>%</strong>
                        <span>routed</span>
                    </div>
                    <div class="router-coverage-copy">
                        <span class="label">Coverage</span>
                        <strong><?= $routed ?> / <?= $total ?></strong>
                        <span>selected + conditional of catalog</span>
                    </div>
                </div>
            </div>

            <section class="kpis kpis-uplift router-kpis" aria-label="Routing summary">
                <?php foreach ($kpiTiles as [$filterType, $filterValue, $emoji, $label, $value, $caption, $tone]): ?>
                    <button
                        type="button"
                        class="kpi kpi-clickable <?= $this->e($tone) ?>"
                        data-filter-type="<?= $this->e($filterType) ?>"
                        data-filter-value="<?= $this->e($filterValue) ?>"
                        aria-pressed="false"
                    >
                        <span class="kpi-emoji" aria-hidden="true"><?= $emoji ?></span>
                        <div class="eyebrow"><?= $this->e($label) ?></div>
                        <strong><?= (int) $value ?></strong>
                        <span><?= $this->e($caption) ?></span>
                    </button>
                <?php endforeach; ?>
            </section>

            <section class="charts-grid charts-grid-uplift router-charts">
                <div class="chart-card chart-card-uplift chart-card-tone-router">
                    <div class="card-heading card-heading-uplift">
                        <div class="card-heading-with-icon">
                            <span class="card-icon" aria-hidden="true">🛰️</span>
                            <div>
                                <div class="eyebrow">Routing mix</div>
                                <h3>AI routing decisions</h3>
                            </div>
                        </div>
                    </div>
                    <div class="chart-panel">
                        <?= $renderDonut($decisionSlices, 'router-decision-donut', (string) $routed, 'routed', false) ?>
                        <?= $renderLegend($decisionSlices, 'router_decision') ?>
                    </div>
                </div>
                <div class="chart-card chart-card-wide chart-card-uplift chart-card-tone-module">
                    <div class="card-heading card-heading-uplift">
                        <div class="card-heading-with-icon">
                            <span class="card-icon" aria-hidden="true">🧩</span>
                            <div>
                                <div class="eyebrow">Module coverage</div>
                                <h3>Scenarios by module</h3>
                            </div>
                        </div>
                        <div class="router-bar-legend" aria-hidden="true">
                            <span><i style="background:<?= self::ROUTING_COLOR['Selected'] ?>"></i>Selected</span>
                            <span><i style="background:<?= self::ROUTING_COLOR['Conditional – verify'] ?>"></i>Conditional</span>
                            <span><i style="background:<?= self::ROUTING_COLOR['Excluded'] ?>"></i>Excluded</span>
                            <span><i style="background:<?= self::ROUTING_COLOR['Unrouted'] ?>"></i>Unrouted</span>
                        </div>
                    </div>
                    <div class="section-bars adaptive-module-bars">
                        <?php foreach ($byModule as $module => $counts): ?>
                            <?php
                            $modTotal = max(1, (int) $counts['total']);
                            $selPct = ((int) $counts['Selected'] / $modTotal) * 100;
                            $condPct = ((int) $counts['Conditional – verify'] / $modTotal) * 100;
                            $exclPct = ((int) $counts['Excluded'] / $modTotal) * 100;
                            $unPct = ((int) $counts['Unrouted'] / $modTotal) * 100;
                            $routedInModule = (int) $counts['Selected'] + (int) $counts['Conditional – verify'];
                            ?>
                            <button type="button" class="section-bar-row" data-filter-type="router_module" data-filter-value="<?= $this->e($module) ?>" title="Filter catalog to <?= $this->e($module) ?>">
                                <span class="section-bar-label">
                                    <strong><?= $this->e($module) ?></strong>
                                    <em><?= $routedInModule ?>/<?= (int) $counts['total'] ?> routed</em>
                                </span>
                                <span class="section-bar-track" aria-hidden="true">
                                    <span class="bar-seg" style="width:<?= number_format($selPct, 2) ?>%;background:<?= self::ROUTING_COLOR['Selected'] ?>"></span>
                                    <span class="bar-seg" style="width:<?= number_format($condPct, 2) ?>%;background:<?= self::ROUTING_COLOR['Conditional – verify'] ?>"></span>
                                    <span class="bar-seg" style="width:<?= number_format($exclPct, 2) ?>%;background:<?= self::ROUTING_COLOR['Excluded'] ?>"></span>
                                    <span class="bar-seg" style="width:<?= number_format($unPct, 2) ?>%;background:<?= self::ROUTING_COLOR['Unrouted'] ?>"></span>
                                </span>
                                <b><?= (int) $counts['total'] ?></b>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="table-card table-card-uplift chart-card-tone-router" id="router-register">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">📚</span>
                        <div>
                            <div class="eyebrow">Scenario catalog</div>
                            <h3>Question Router</h3>
                        </div>
                    </div>
                    <span class="result-count result-count-badge" data-router-count><?= $total ?> scenarios</span>
                </div>
                <div class="register-toolbar router-toolbar">
                    <div class="router-filter-chips" role="group" aria-label="Quick routing filters">
                        <button type="button" class="router-chip is-active" data-filter-type="all" data-filter-value="" aria-pressed="true">All</button>
                        <button type="button" class="router-chip tone-selected" data-filter-type="router_decision" data-filter-value="Selected" aria-pressed="false">Selected</button>
                        <button type="button" class="router-chip tone-conditional" data-filter-type="router_decision" data-filter-value="Conditional – verify" aria-pressed="false">Conditional</button>
                        <button type="button" class="router-chip tone-excluded" data-filter-type="router_decision" data-filter-value="Excluded" aria-pressed="false">Excluded</button>
                        <button type="button" class="router-chip tone-unrouted" data-filter-type="router_decision" data-filter-value="Unrouted" aria-pressed="false">Unrouted</button>
                    </div>
                    <label class="search-field router-search-field">
                        <span class="visually-hidden">Search router</span>
                        <input type="search" data-router-search placeholder="Search scenario ID, module, domain, quality…" autocomplete="off">
                    </label>
                    <button type="button" class="button ghost router-clear-filters" data-router-clear hidden>↩️ Reset</button>
                </div>
                <div class="table-scroll">
                    <table id="adaptive-router-table" class="router-table">
                        <thead>
                            <tr>
                                <th>Scenario ID</th>
                                <th>Module</th>
                                <th>Domain</th>
                                <th>Quality attribute</th>
                                <th>Scenario</th>
                                <th>Routing</th>
                                <th>Disposition</th>
                                <th>Linked output</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($router as $row): ?>
                                <?php
                                if (!is_array($row)) {
                                    continue;
                                }
                                $decision = trim((string) ($row['routing_decision'] ?? ''));
                                $bucket = 'Unrouted';
                                $lower = strtolower($decision);
                                if ($lower === 'selected') {
                                    $bucket = 'Selected';
                                } elseif (str_contains($lower, 'conditional')) {
                                    $bucket = 'Conditional – verify';
                                } elseif ($lower === 'excluded' || $decision === '—') {
                                    $bucket = 'Excluded';
                                }
                                $module = (string) ($row['module'] ?? '');
                                $pillClass = match ($bucket) {
                                    'Selected' => 'pill-router-selected',
                                    'Conditional – verify' => 'pill-router-conditional',
                                    'Excluded' => 'pill-router-excluded',
                                    default => 'pill-router-unrouted',
                                };
                                $search = strtolower(implode(' ', [
                                    (string) ($row['scenario_id'] ?? ''),
                                    $module,
                                    (string) ($row['domain'] ?? ''),
                                    (string) ($row['quality_attribute'] ?? ''),
                                    (string) ($row['scenario'] ?? ''),
                                    $bucket,
                                    (string) ($row['disposition'] ?? ''),
                                    (string) ($row['linked_output_id'] ?? ''),
                                ]));
                                ?>
                                <tr
                                    data-router-decision="<?= $this->e($bucket) ?>"
                                    data-router-module="<?= $this->e($module) ?>"
                                    data-search="<?= $this->e($search) ?>"
                                >
                                    <td><code class="router-id"><?= $this->e((string) ($row['scenario_id'] ?? '')) ?></code></td>
                                    <td><span class="router-module-tag"><?= $this->e($module) ?></span></td>
                                    <td><?= $this->e((string) ($row['domain'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($row['quality_attribute'] ?? '')) ?></td>
                                    <td class="router-scenario-cell"><?= $this->e((string) ($row['scenario'] ?? '')) ?></td>
                                    <td><span class="pill pill-router <?= $pillClass ?>"><?= $this->e($decision !== '' ? $decision : 'Unrouted') ?></span></td>
                                    <td><?= $this->e((string) ($row['disposition'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($row['linked_output_id'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string, string>> $items
     * @param callable $renderDonut
     * @param callable $renderLegend
     */
    public function renderFindingsRiskCharts(array $items, callable $renderDonut, callable $renderLegend): string
    {
        $inherent = ['Low' => 0, 'Med' => 0, 'High' => 0, 'Critical' => 0];
        $residual = ['Low' => 0, 'Med' => 0, 'High' => 0, 'Critical' => 0];
        $assessment = [
            'Gap' => 0,
            'Risk' => 0,
            'Decision Required' => 0,
            'Accepted Risk' => 0,
            'Closed' => 0,
            'Pass' => 0,
            'TBD' => 0,
            'Other' => 0,
        ];

        foreach ($items as $item) {
            $status = Assessment::normalizeStatus((string) ($item['status'] ?? ''));
            if (isset($assessment[$status])) {
                $assessment[$status]++;
            } else {
                $assessment['Other']++;
            }
            $inh = Assessment::normalizeRiskLevel((string) ($item['inherent_level'] ?? $item['risk_level'] ?? ''));
            if (isset($inherent[$inh])) {
                $inherent[$inh]++;
            }
            $res = Assessment::normalizeRiskLevel((string) ($item['residual_level'] ?? ''));
            if (isset($residual[$res])) {
                $residual[$res]++;
            }
        }

        $statusSlices = [];
        $statusColors = [
            'Pass' => '#0f766e',
            'Gap' => '#c2410c',
            'Risk' => '#be123c',
            'Decision Required' => '#7c3aed',
            'Accepted Risk' => '#a16207',
            'Closed' => '#0e7490',
            'TBD' => '#64748b',
            'Other' => '#94a3b8',
        ];
        foreach ($assessment as $label => $value) {
            if ($value <= 0) {
                continue;
            }
            $statusSlices[] = [
                'label' => $label,
                'value' => $value,
                'color' => $statusColors[$label] ?? '#94a3b8',
                'filterType' => 'status',
                'filterValue' => $label,
            ];
        }

        $makeRiskSlices = static function (array $counts, string $filterType): array {
            $slices = [];
            foreach (['Critical', 'High', 'Med', 'Low'] as $label) {
                $value = (int) ($counts[$label] ?? 0);
                if ($value <= 0) {
                    continue;
                }
                $slices[] = [
                    'label' => $label,
                    'value' => $value,
                    'color' => self::RISK_COLOR[$label],
                    'filterType' => $filterType,
                    'filterValue' => $label,
                ];
            }

            return $slices;
        };

        $inherentSlices = $makeRiskSlices($inherent, 'risk');
        $residualSlices = $makeRiskSlices($residual, 'residual_risk');
        $findingCount = count($items);

        ob_start();
        ?>
        <section class="charts-grid charts-grid-uplift adaptive-findings-charts">
            <div class="chart-card chart-card-uplift">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🧪</span>
                        <div>
                            <div class="eyebrow">Assessment</div>
                            <h3>Finding type mix</h3>
                        </div>
                    </div>
                </div>
                <div class="chart-panel">
                    <?= $renderDonut($statusSlices, 'adaptive-assessment-donut', (string) $findingCount, 'findings', false) ?>
                    <?= $renderLegend($statusSlices, 'status') ?>
                </div>
            </div>
            <div class="chart-card chart-card-uplift">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">📈</span>
                        <div>
                            <div class="eyebrow">Inherent</div>
                            <h3>Inherent risk levels</h3>
                        </div>
                    </div>
                </div>
                <div class="chart-panel">
                    <?= $renderDonut($inherentSlices, 'adaptive-inherent-donut', (string) array_sum($inherent), 'scored', false) ?>
                    <?= $renderLegend($inherentSlices, 'risk') ?>
                </div>
            </div>
            <div class="chart-card chart-card-uplift">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">📉</span>
                        <div>
                            <div class="eyebrow">Residual</div>
                            <h3>Residual risk levels</h3>
                        </div>
                    </div>
                </div>
                <div class="chart-panel">
                    <?= $renderDonut($residualSlices, 'adaptive-residual-donut', (string) array_sum($residual), 'residual', false) ?>
                    <?= $renderLegend($residualSlices, 'residual_risk') ?>
                </div>
            </div>
        </section>
        <?php if ($findingCount === 0): ?>
            <div class="context-banner context-banner-uplift">ℹ️ No material findings yet. Create Risk Register rows only for material Gap, Risk, or Decision Required outcomes from selected scenarios.</div>
        <?php endif; ?>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $workbook
     * @param array<string, string> $metadata
     */
    public function renderAdaptiveGovernanceExtras(array $workbook, array $metadata): string
    {
        $classification = is_array($workbook['classification'] ?? null) ? $workbook['classification'] : [];
        $signals = array_values($classification['signals'] ?? []);
        $decisions = array_values($workbook['decisions'] ?? []);
        $lifecycle = array_values($workbook['lifecycle'] ?? []);
        $exceptions = array_values($workbook['exceptions'] ?? []);

        ob_start();
        ?>
        <?php if (($classification['primary_type'] ?? '') !== '' || $signals !== []): ?>
            <section class="table-card table-card-uplift chart-card-tone-governance" style="margin-top:10px;">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🧬</span>
                        <div>
                            <div class="eyebrow">Classification</div>
                            <h3>Architecture-type detection</h3>
                        </div>
                    </div>
                </div>
                <div class="governance-highlights governance-highlights-uplift" style="margin-bottom:12px;">
                    <article class="highlight-card">
                        <span class="label">Primary type</span>
                        <strong><?= $this->e((string) ($classification['primary_type'] ?? 'Not classified')) ?></strong>
                    </article>
                    <article class="highlight-card">
                        <span class="label">Secondary types</span>
                        <strong><?= $this->e((string) ($classification['secondary_types'] ?? '—')) ?></strong>
                    </article>
                    <article class="highlight-card">
                        <span class="label">Confidence</span>
                        <strong><?= $this->e((string) ($classification['confidence'] ?? '—')) ?></strong>
                    </article>
                    <article class="highlight-card">
                        <span class="label">Selected modules</span>
                        <strong><?= $this->e((string) ($classification['selected_modules'] ?? '—')) ?></strong>
                    </article>
                </div>
                <?php if ($signals !== []): ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Signal</th>
                                    <th>Question</th>
                                    <th>Answer</th>
                                    <th>Points to</th>
                                    <th>Confidence</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($signals as $signal): ?>
                                    <?php if (!is_array($signal)) {
                                        continue;
                                    } ?>
                                    <tr>
                                        <td><strong><?= $this->e((string) ($signal['signal_id'] ?? '')) ?></strong></td>
                                        <td><?= $this->e((string) ($signal['question'] ?? '')) ?></td>
                                        <td><?= $this->e((string) ($signal['answer'] ?? '')) ?></td>
                                        <td><?= $this->e((string) ($signal['points_to'] ?? '')) ?></td>
                                        <td><?= $this->e((string) ($signal['confidence'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($decisions !== []): ?>
            <section class="table-card table-card-uplift" style="margin-top:10px;">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">📝</span>
                        <div>
                            <div class="eyebrow">ADRs</div>
                            <h3>Architecture decisions</h3>
                        </div>
                    </div>
                    <span class="result-count result-count-badge"><?= count($decisions) ?></span>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>ADR ID</th>
                                <th>Decision</th>
                                <th>Status</th>
                                <th>Chosen direction</th>
                                <th>Owner</th>
                                <th>Related IDs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decisions as $adr): ?>
                                <?php if (!is_array($adr)) {
                                    continue;
                                } ?>
                                <tr>
                                    <td><strong><?= $this->e((string) ($adr['adr_id'] ?? '')) ?></strong></td>
                                    <td><?= $this->e((string) ($adr['decision'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($adr['status'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($adr['chosen'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($adr['owner'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($adr['related_ids'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($lifecycle !== []): ?>
            <section class="table-card table-card-uplift" style="margin-top:10px;">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🔄</span>
                        <div>
                            <div class="eyebrow">Dependencies</div>
                            <h3>Technology lifecycle</h3>
                        </div>
                    </div>
                    <span class="result-count result-count-badge"><?= count($lifecycle) ?></span>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Component ID</th>
                                <th>Component</th>
                                <th>Lens</th>
                                <th>Version</th>
                                <th>Currency</th>
                                <th>Owner</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lifecycle as $cmp): ?>
                                <?php if (!is_array($cmp)) {
                                    continue;
                                } ?>
                                <tr>
                                    <td><strong><?= $this->e((string) ($cmp['component_id'] ?? '')) ?></strong></td>
                                    <td><?= $this->e((string) ($cmp['component'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($cmp['lens'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($cmp['current_version'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($cmp['currency'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($cmp['owner'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($exceptions !== []): ?>
            <section class="table-card table-card-uplift" style="margin-top:10px;">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">⚠️</span>
                        <div>
                            <div class="eyebrow">Policy exceptions</div>
                            <h3>Exception register</h3>
                        </div>
                    </div>
                    <span class="result-count result-count-badge"><?= count($exceptions) ?></span>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Exception ID</th>
                                <th>Deviation</th>
                                <th>Policy</th>
                                <th>Approval status</th>
                                <th>Owner</th>
                                <th>Residual risk</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exceptions as $ex): ?>
                                <?php if (!is_array($ex)) {
                                    continue;
                                } ?>
                                <tr>
                                    <td><strong><?= $this->e((string) ($ex['exception_id'] ?? '')) ?></strong></td>
                                    <td><?= $this->e((string) ($ex['deviation'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($ex['policy_id'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($ex['approval_status'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($ex['owner'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($ex['residual_risk'] ?? '')) ?></td>
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
     * @param array<string, mixed> $legend
     */
    public function renderAdaptiveLegendExtras(array $legend): string
    {
        $routing = array_values($legend['routing'] ?? []);
        $findingTypes = $this->normalizeFindingTypes(array_values($legend['finding_types'] ?? []));
        $materialityGate = array_values($legend['materiality_gate'] ?? []);
        $materialityGuidance = array_values($legend['materiality_guidance'] ?? []);
        $materialityNotes = array_values($legend['materiality_notes'] ?? []);

        if ($materialityGate === []) {
            $materialityGate = $this->fallbackMaterialityGate();
        }
        if ($materialityGuidance === []) {
            $materialityGuidance = $this->fallbackMaterialityGuidance();
        }
        if ($materialityNotes === []) {
            $materialityNotes = $this->fallbackMaterialityNotes();
        }

        $hasMateriality = $findingTypes !== [] || $materialityGate !== [] || $materialityGuidance !== [];
        if ($routing === [] && !$hasMateriality) {
            return '';
        }

        ob_start();
        ?>
        <?php if ($routing !== []): ?>
            <section class="table-card table-card-uplift chart-card-tone-legend" style="margin-top:10px;">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🧭</span>
                        <div>
                            <div class="eyebrow">Routing</div>
                            <h3>Routing decisions</h3>
                        </div>
                    </div>
                </div>
                <p class="legend-section-lede">Route scenarios before scoring. Excluded questions are not deficiencies; selected scenarios without enough evidence stay pending unless the missing decision itself creates material exposure.</p>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Decision</th>
                                <th>Use when</th>
                                <th>Meaning</th>
                                <th>Risk register row?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($routing as $row): ?>
                                <?php if (!is_array($row)) {
                                    continue;
                                } ?>
                                <tr>
                                    <td><strong><?= $this->e((string) ($row['decision'] ?? '')) ?></strong></td>
                                    <td><?= $this->e((string) ($row['use_when'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($row['meaning'] ?? '')) ?></td>
                                    <td><?= $this->e((string) ($row['risk_register_row'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($hasMateriality): ?>
            <section class="table-card table-card-uplift chart-card-tone-legend materiality-legend" style="margin-top:10px;">
                <div class="card-heading card-heading-uplift">
                    <div class="card-heading-with-icon">
                        <span class="card-icon" aria-hidden="true">🏷️</span>
                        <div>
                            <div class="eyebrow">Materiality</div>
                            <h3>Finding types</h3>
                        </div>
                    </div>
                </div>
                <p class="legend-section-lede">
                    The Architecture Risk Register holds <strong>material design findings only</strong>—not every routed scenario.
                    Create a row when a selected scenario produces a Gap, Risk, or Decision Required that clears the materiality gate below.
                    Routine evidence gaps belong in Due Diligence; trade-offs belong in ADRs; policy deviations belong in the Exception Register.
                </p>

                <?php if ($findingTypes !== []): ?>
                    <div class="table-scroll">
                        <table class="materiality-types-table">
                            <thead>
                                <tr>
                                    <th>Finding type</th>
                                    <th>Meaning</th>
                                    <th>When to put it on the register</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($findingTypes as $row): ?>
                                    <tr>
                                        <td><?= $this->findingTypePill((string) ($row['type'] ?? '')) ?></td>
                                        <td><?= $this->e((string) ($row['meaning'] ?? '')) ?></td>
                                        <td><?= $this->e((string) ($row['register_action'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($materialityGate !== []): ?>
                    <div class="materiality-block">
                        <h4 class="materiality-block-title">Materiality gate</h4>
                        <p class="legend-section-lede legend-section-lede-tight">
                            Use all six checks before writing a Risk Register row. If any check fails, keep the outcome in the Question Router (or move it to ADR / Exception) instead of inflating the register.
                        </p>
                        <ol class="materiality-gate-list">
                            <?php foreach ($materialityGate as $gate): ?>
                                <?php if (!is_array($gate)) {
                                    continue;
                                } ?>
                                <li>
                                    <strong><?= $this->e((string) ($gate['step'] ?? '')) ?></strong>
                                    <span><?= $this->e((string) ($gate['criterion'] ?? '')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endif; ?>

                <?php if ($materialityGuidance !== []): ?>
                    <div class="materiality-block">
                        <h4 class="materiality-block-title">What belongs elsewhere</h4>
                        <p class="legend-section-lede legend-section-lede-tight">
                            Not every issue is a register finding. Prefer the lightest correct artifact so the register stays a decision-ready view of material exposure.
                        </p>
                        <ul class="materiality-guidance-list">
                            <?php foreach ($materialityGuidance as $item): ?>
                                <?php if (!is_array($item)) {
                                    continue;
                                } ?>
                                <?php
                                $action = (string) ($item['action'] ?? '');
                                $tone = str_contains(strtolower($action), 'do not') ? 'deny'
                                    : (str_contains(strtolower($action), 'adr') ? 'adr' : 'exception');
                                ?>
                                <li class="materiality-guidance-item tone-<?= $this->e($tone) ?>">
                                    <span class="materiality-guidance-action"><?= $this->e($action) ?></span>
                                    <span class="materiality-guidance-when"><?= $this->e((string) ($item['when'] ?? '')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($materialityNotes !== []): ?>
                    <div class="materiality-block materiality-notes">
                        <h4 class="materiality-block-title">Writing and closing findings</h4>
                        <div class="materiality-notes-grid">
                            <?php foreach ($materialityNotes as $note): ?>
                                <?php if (!is_array($note)) {
                                    continue;
                                } ?>
                                <div class="materiality-note-card">
                                    <div class="eyebrow"><?= $this->e((string) ($note['label'] ?? '')) ?></div>
                                    <p><?= $this->e((string) ($note['guidance'] ?? '')) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param list<mixed> $raw
     * @return list<array{type: string, meaning: string, register_action: string}>
     */
    private function normalizeFindingTypes(array $raw): array
    {
        $catalog = $this->fallbackFindingTypeCatalog();
        $out = [];

        foreach ($raw as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $type = trim($entry);
                $meta = $catalog[$type] ?? [
                    'meaning' => 'Material architecture finding recorded on the Risk Register.',
                    'register_action' => 'Record when material and actionable.',
                ];
                $out[] = [
                    'type' => $type,
                    'meaning' => $meta['meaning'],
                    'register_action' => $meta['register_action'],
                ];
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $type = trim((string) ($entry['type'] ?? $entry['finding_type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $meta = $catalog[$type] ?? null;
            $out[] = [
                'type' => $type,
                'meaning' => trim((string) ($entry['meaning'] ?? '')) !== ''
                    ? (string) $entry['meaning']
                    : (string) ($meta['meaning'] ?? 'Material architecture finding recorded on the Risk Register.'),
                'register_action' => trim((string) ($entry['register_action'] ?? '')) !== ''
                    ? (string) $entry['register_action']
                    : (string) ($meta['register_action'] ?? 'Record when material and actionable.'),
            ];
        }

        if ($out === [] && $catalog !== []) {
            foreach ($catalog as $type => $meta) {
                $out[] = [
                    'type' => $type,
                    'meaning' => $meta['meaning'],
                    'register_action' => $meta['register_action'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{meaning: string, register_action: string}>
     */
    private function fallbackFindingTypeCatalog(): array
    {
        return [
            'Gap' => [
                'meaning' => 'A selected scenario\'s design or control expectation is not met. The shortfall is design-specific and creates a credible consequence for a named objective.',
                'register_action' => 'Create a Risk Register row when the gap clears the materiality gate. Treat or remediate with an owner and evidence trail.',
            ],
            'Risk' => [
                'meaning' => 'Credible exposure remains for the actual architecture—even when some controls exist. Score inherent likelihood × impact before treatment.',
                'register_action' => 'Create a Risk Register row for material exposure. Track treatment, acceptance, or escalation until residual can be verified.',
            ],
            'Decision Required' => [
                'meaning' => 'An architecture choice, ownership call, or acceptance must be resolved before the scenario can close. Uncertainty itself may be material.',
                'register_action' => 'Create a Risk Register row when the open decision creates exposure. Prefer an ADR when the issue is a trade-off among options.',
            ],
            'Accepted Risk' => [
                'meaning' => 'Material exposure is formally accepted with rationale, residual conditions, and named decision authority—not silently left open.',
                'register_action' => 'Record acceptance on the register (and Exception Register when policy/control deviation applies). Keep residual conditions visible.',
            ],
            'Closed' => [
                'meaning' => 'Treatment or decision is implemented and verified with observable evidence. The finding no longer represents open material exposure.',
                'register_action' => 'Mark closed only when closure evidence exists. Set residual score after verification; do not close on intent alone.',
            ],
        ];
    }

    /**
     * @return list<array{step: string, criterion: string}>
     */
    private function fallbackMaterialityGate(): array
    {
        return [
            ['step' => '1. Applicable', 'criterion' => 'Scenario is selected for a detected type or evidenced trigger.'],
            ['step' => '2. Design-specific', 'criterion' => 'Finding explains the actual component, boundary, dependency or workflow.'],
            ['step' => '3. Credible consequence', 'criterion' => 'There is a plausible impact to a named objective.'],
            ['step' => '4. Actionable', 'criterion' => 'A decision, treatment or acceptance is required.'],
            ['step' => '5. Owned', 'criterion' => 'An accountable owner and decision/closure authority can be named.'],
            ['step' => '6. Traceable', 'criterion' => 'Source scenario and evidence IDs are linked.'],
        ];
    }

    /**
     * @return list<array{action: string, when: string}>
     */
    private function fallbackMaterialityGuidance(): array
    {
        return [
            ['action' => 'Do not create', 'when' => 'A copied DD/control question.'],
            ['action' => 'Do not create', 'when' => 'A generic best-practice statement with no design condition.'],
            ['action' => 'Do not create', 'when' => 'An N/A/excluded scenario.'],
            ['action' => 'Do not create', 'when' => 'A missing document with no demonstrated material consequence.'],
            ['action' => 'Use ADR', 'when' => 'A material choice among options/trade-offs.'],
            ['action' => 'Use Exception', 'when' => 'A formal policy/control deviation needing approval.'],
        ];
    }

    /**
     * @return list<array{label: string, guidance: string}>
     */
    private function fallbackMaterialityNotes(): array
    {
        return [
            [
                'label' => 'Risk statement',
                'guidance' => 'Because [design condition], when [trigger/dependency fails], [credible consequence], affecting [objective].',
            ],
            [
                'label' => 'Closure',
                'guidance' => 'Observable evidence proves treatment/decision is implemented.',
            ],
            [
                'label' => 'Residual score',
                'guidance' => 'Leave blank until closure evidence is implemented and verified.',
            ],
        ];
    }

    private function findingTypePill(string $type): string
    {
        $normalized = strtolower(preg_replace('/\s+/', '', $type) ?? '');
        $class = match ($normalized) {
            'gap' => 'amber',
            'risk' => 'coral',
            'decisionrequired' => 'violet',
            'acceptedrisk' => 'teal',
            'closed' => 'gray',
            default => 'gray',
        };

        return sprintf('<span class="pill %s">%s</span>', $this->e($class), $this->e($type));
    }
}
