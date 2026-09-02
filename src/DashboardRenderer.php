<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Models\Assessment;

final class DashboardRenderer
{
    /** @var array<string, string> */
    private const STATUS_EMOJI = [
        'Pass' => '✅',
        'Gap' => '⚠️',
        'Risk' => '🚨',
        'TBD' => '❓',
    ];

    /** @var array<string, string> */
    private const RISK_EMOJI = [
        'High' => '🔴',
        'Med' => '🟠',
        'Low' => '🟢',
    ];

    /** @var array<string, string> */
    private const STATUS_COLOR = [
        'Pass' => '#0f766e',
        'Gap' => '#d97706',
        'Risk' => '#dc2626',
        'TBD' => '#64748b',
    ];

    /** @var array<string, string> */
    private const RISK_COLOR = [
        'High' => '#b91c1c',
        'Med' => '#ea580c',
        'Low' => '#059669',
    ];

    /** @var list<string> */
    private const SECTION_COLORS = [
        '#0f766e', '#2c9b8d', '#059669', '#0891b2', '#6366f1', '#d97706', '#dc2626', '#7c3aed',
    ];

    public function render(Assessment $assessment, string $sourceFilename = ''): string
    {
        $metadata = $assessment->metadata;
        $summary = $assessment->summary;
        $items = $assessment->items;
        $solutionName = $metadata['solution_name'] ?: 'Risk Assessment Dashboard';
        $assessmentDate = $metadata['date'] ?: date('Y-m-d');

        $statusSlices = $this->buildStatusSlices($summary);
        $riskSlices = $this->buildRiskSlices($summary);
        $sectionSlices = $this->buildSectionSlices($summary);

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($solutionName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <div class="brand">
                <?= $this->brandMark() ?>
                <div>
                    <div class="brand-title">Architecture Risk</div>
                    <h1>Assessment register</h1>
                </div>
            </div>
            <div class="updated">
                <span class="live-dot"></span>
                <span>📅 Assessment date <?= $this->e($assessmentDate) ?></span>
            </div>
        </header>

        <main>
            <section class="hero">
                <div class="hero-copy">
                    <div class="eyebrow">Executive view / architecture risk</div>
                    <h2>Risk<br><em>Dashboard.</em></h2>
                    <div class="hero-project">
                        <span class="hero-project-label">Project</span>
                        <p class="hero-project-name"><?= $this->e($solutionName) ?></p>
                    </div>
                    <div class="hero-actions">
                        <a class="button ghost" href="index.php">Upload another file</a>
                    </div>
                </div>
                <div class="hero-art">
                    <?= $this->renderDonutChart($statusSlices, 'hero-donut', (string) $summary['total'], 'checks', true) ?>
                </div>
            </section>

            <section class="kpis" id="kpi-tiles">
                <button type="button" class="kpi kpi-clickable is-active" data-filter-type="all" data-filter-value="" aria-pressed="true">
                    <span class="kpi-emoji">📋</span>
                    <div class="eyebrow">Total checks</div>
                    <strong><?= (int) $summary['total'] ?></strong>
                    <span>Tap to view all rows</span>
                </button>
                <?php foreach (['Pass', 'Gap', 'Risk', 'TBD'] as $status): ?>
                    <button
                        type="button"
                        class="kpi kpi-clickable tone-<?= strtolower($this->e($status)) ?>"
                        data-filter-type="status"
                        data-filter-value="<?= $this->e($status) ?>"
                        aria-pressed="false"
                    >
                        <span class="kpi-emoji"><?= self::STATUS_EMOJI[$status] ?></span>
                        <div class="eyebrow"><?= $this->e($status) ?></div>
                        <strong><?= (int) ($summary['by_status'][$status] ?? 0) ?></strong>
                        <span>Filter <?= $this->e(strtolower($status)) ?> rows</span>
                    </button>
                <?php endforeach; ?>
                <?php foreach (['High', 'Med', 'Low'] as $risk): ?>
                    <button
                        type="button"
                        class="kpi kpi-clickable tone-<?= strtolower($this->e($risk)) ?>"
                        data-filter-type="risk"
                        data-filter-value="<?= $this->e($risk) ?>"
                        aria-pressed="false"
                    >
                        <span class="kpi-emoji"><?= self::RISK_EMOJI[$risk] ?></span>
                        <div class="eyebrow"><?= $this->e($risk) ?> risk</div>
                        <strong><?= (int) ($summary['by_risk'][$risk] ?? 0) ?></strong>
                        <span>Filter <?= $this->e(strtolower($risk)) ?> risk rows</span>
                    </button>
                <?php endforeach; ?>
            </section>

            <section class="meta-grid">
                <div class="meta-item"><span class="label">🏢 Vendor</span><strong><?= $this->e($metadata['vendor']) ?></strong></div>
                <div class="meta-item"><span class="label">📍 Scope</span><strong><?= $this->e($metadata['scope']) ?></strong></div>
                <div class="meta-item"><span class="label">🏗️ Architecture model</span><strong><?= $this->e($metadata['architecture_model']) ?></strong></div>
                <div class="meta-item"><span class="label">👤 Reviewer</span><strong><?= $this->e($metadata['reviewer']) ?></strong></div>
                <?php if ($sourceFilename !== ''): ?>
                    <div class="meta-item"><span class="label">📁 Source file</span><strong><?= $this->e($sourceFilename) ?></strong></div>
                <?php endif; ?>
            </section>

            <section class="charts-grid">
                <div class="chart-card">
                    <div class="card-heading">
                        <div>
                            <div class="eyebrow">Flow health</div>
                            <h3>✅ Status mix</h3>
                        </div>
                    </div>
                    <div class="chart-panel">
                        <?= $this->renderDonutChart($statusSlices, 'status-donut', (string) ($summary['by_status']['Risk'] ?? 0), 'risk items', false) ?>
                        <?= $this->renderChartLegend($statusSlices, 'status') ?>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="card-heading">
                        <div>
                            <div class="eyebrow">Risk exposure</div>
                            <h3>🎯 Risk levels</h3>
                        </div>
                    </div>
                    <div class="chart-panel">
                        <?= $this->renderDonutChart($riskSlices, 'risk-donut', (string) ($summary['by_risk']['High'] ?? 0), 'high risk', false) ?>
                        <?= $this->renderChartLegend($riskSlices, 'risk') ?>
                    </div>
                </div>

                <div class="chart-card chart-card-wide">
                    <div class="card-heading">
                        <div>
                            <div class="eyebrow">Section coverage</div>
                            <h3>🧩 Section distribution</h3>
                        </div>
                    </div>
                    <div class="chart-panel chart-panel-split">
                        <?= $this->renderPieChart($sectionSlices, 'section-pie') ?>
                        <?= $this->renderChartLegend($sectionSlices, 'section') ?>
                    </div>
                </div>
            </section>

            <section class="chart-card section-bars-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Section drill-down</div>
                        <h3>📈 Checks by section</h3>
                        <p>Click a section bar to jump to matching rows.</p>
                    </div>
                </div>
                <div class="state-bars">
                    <?php foreach ($summary['by_section'] as $section => $counts): ?>
                        <?php $max = max(1, (int) $counts['total']); ?>
                        <button
                            type="button"
                            class="bar-row bar-row-clickable"
                            data-filter-type="section"
                            data-filter-value="<?= $this->e($section) ?>"
                        >
                            <span>📂 <?= $this->e($section) ?></span>
                            <div class="bar-track">
                                <span class="bar-fill bar-pass" style="width: <?= $this->percent((int) $counts['Pass'], $max) ?>%"></span>
                                <span class="bar-fill bar-gap" style="width: <?= $this->percent((int) $counts['Gap'], $max) ?>%"></span>
                                <span class="bar-fill bar-risk" style="width: <?= $this->percent((int) $counts['Risk'], $max) ?>%"></span>
                                <span class="bar-fill bar-tbd" style="width: <?= $this->percent((int) $counts['TBD'], $max) ?>%"></span>
                            </div>
                            <b><?= (int) $counts['total'] ?></b>
                        </button>
                        <div class="section-meta">
                            <span>🔴 <?= (int) $counts['High'] ?> High</span>
                            <span>🟠 <?= (int) $counts['Med'] ?> Med</span>
                            <span>🟢 <?= (int) $counts['Low'] ?> Low</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="toolbar">
                <div class="search-wrap">
                    <span>🔎</span>
                    <input type="search" id="filter-search" placeholder="Search check, notes, owner, mitigation...">
                </div>
                <select id="filter-section">
                    <option value="">All sections</option>
                    <?php foreach (array_keys($summary['by_section']) as $section): ?>
                        <option value="<?= $this->e($section) ?>"><?= $this->e($section) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filter-status">
                    <option value="">All statuses</option>
                    <?php foreach (['Pass', 'Gap', 'Risk', 'TBD'] as $status): ?>
                        <option value="<?= $this->e($status) ?>"><?= self::STATUS_EMOJI[$status] ?> <?= $this->e($status) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filter-risk">
                    <option value="">All risk levels</option>
                    <?php foreach (['High', 'Med', 'Low'] as $risk): ?>
                        <option value="<?= $this->e($risk) ?>"><?= self::RISK_EMOJI[$risk] ?> <?= $this->e($risk) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button ghost" id="clearFilters">↩️ Reset</button>
            </section>

            <section class="table-card" id="risk-register">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Risk register</div>
                        <h3>📝 Architecture checks</h3>
                    </div>
                    <span class="result-count" id="filter-count"><?= (int) $summary['total'] ?> shown</span>
                </div>
                <div class="table-scroll">
                    <table id="risk-table">
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th>Check</th>
                                <th>Status</th>
                                <th>Risk level</th>
                                <th>Notes</th>
                                <th>Mitigation / controls</th>
                                <th>Owner</th>
                                <th>Remediation timeline</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr
                                    class="data-row"
                                    data-section="<?= $this->e($item['section']) ?>"
                                    data-status="<?= $this->e($item['status']) ?>"
                                    data-risk="<?= $this->e($item['risk_level']) ?>"
                                    data-search="<?= $this->e(strtolower($item['section'] . ' ' . $item['check'] . ' ' . $item['notes'] . ' ' . $item['mitigation'] . ' ' . $item['owner'])) ?>"
                                >
                                    <td><span class="section-name">📂 <?= $this->e($item['section']) ?></span></td>
                                    <td>
                                        <div class="check-name"><?= $this->e($item['check']) ?></div>
                                        <?php if ($item['owner'] !== ''): ?>
                                            <div class="subtext">👤 <?= $this->e($item['owner']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $this->pill($item['status'], 'status') ?></td>
                                    <td><?= $this->pill($item['risk_level'], 'risk') ?></td>
                                    <td><?= $this->e($item['notes']) ?></td>
                                    <td><?= $this->e($item['mitigation']) ?></td>
                                    <td><?= $this->e($item['owner']) ?></td>
                                    <td><?= $this->e($item['remediation_timeline']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    /** @param array<string, int> $summary */
    /** @return list<array<string, mixed>> */
    private function buildStatusSlices(array $summary): array
    {
        $slices = [];
        foreach (['Pass', 'Gap', 'Risk', 'TBD'] as $status) {
            $slices[] = [
                'label' => self::STATUS_EMOJI[$status] . ' ' . $status,
                'value' => (int) ($summary['by_status'][$status] ?? 0),
                'color' => self::STATUS_COLOR[$status],
                'filterType' => 'status',
                'filterValue' => $status,
                'emoji' => self::STATUS_EMOJI[$status],
            ];
        }

        return $slices;
    }

    /** @param array<string, int> $summary */
    /** @return list<array<string, mixed>> */
    private function buildRiskSlices(array $summary): array
    {
        $slices = [];
        foreach (['High', 'Med', 'Low'] as $risk) {
            $slices[] = [
                'label' => self::RISK_EMOJI[$risk] . ' ' . $risk,
                'value' => (int) ($summary['by_risk'][$risk] ?? 0),
                'color' => self::RISK_COLOR[$risk],
                'filterType' => 'risk',
                'filterValue' => $risk,
                'emoji' => self::RISK_EMOJI[$risk],
            ];
        }

        return $slices;
    }

    /** @param array<string, array<string, int>> $summary */
    /** @return list<array<string, mixed>> */
    private function buildSectionSlices(array $summary): array
    {
        $slices = [];
        $index = 0;
        foreach ($summary['by_section'] as $section => $counts) {
            $slices[] = [
                'label' => '📂 ' . $section,
                'value' => (int) $counts['total'],
                'color' => self::SECTION_COLORS[$index % count(self::SECTION_COLORS)],
                'filterType' => 'section',
                'filterValue' => $section,
                'emoji' => '📂',
            ];
            $index++;
        }

        return $slices;
    }

    /**
     * @param list<array<string, mixed>> $slices
     */
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

    /**
     * @param list<array<string, mixed>> $slices
     */
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

    /**
     * @param list<array<string, mixed>> $slices
     */
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
                        <em><?= (int) $slice['value'] ?> items</em>
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
                'tbd' => 'gray',
                default => 'gray',
            };
            $emoji = self::STATUS_EMOJI[$value] ?? '';
        } else {
            $class = match ($normalized) {
                'high' => 'coral',
                'med', 'medium' => 'amber',
                'low' => 'teal',
                default => 'gray',
            };
            $emoji = self::RISK_EMOJI[$value] ?? '';
        }

        $label = $emoji !== '' ? $emoji . ' ' . $value : $value;

        return sprintf('<span class="pill %s">%s</span>', $this->e($class), $this->e($label));
    }

    private function percent(int $value, int $max): float
    {
        return round(($value / $max) * 100, 2);
    }
}
