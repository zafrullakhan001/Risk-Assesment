<?php

declare(strict_types=1);

namespace RiskAssessment;

final class DashboardDecisionViews
{
    /** @param array<string, mixed> $insights */
    /** @param array<string, mixed> $comparison */
    public function renderDecisionDesk(array $insights, int $assessmentId, array $comparison = []): string
    {
        $readiness = $insights['readiness'];
        $residual = $insights['residual'];
        $band = (string) $readiness['band'];

        ob_start();
        ?>
        <section class="decision-desk" id="decision-desk" data-assessment-id="<?= (int) $assessmentId ?>">
            <article class="exec-summary band-<?= $this->e($band) ?>">
                <div class="exec-score">
                    <span class="label">Go-live readiness</span>
                    <strong><?= (int) $readiness['score'] ?></strong>
                    <em>/ 100</em>
                </div>
                <div class="exec-copy">
                    <div class="eyebrow">Executive summary</div>
                    <h3><?= $this->e((string) $readiness['verdict']) ?></h3>
                    <p><?= $this->e((string) $readiness['summary']) ?></p>
                    <div class="exec-metrics">
                        <span><b><?= (int) $residual['high'] ?></b> High</span>
                        <span><b><?= (int) $residual['risk'] ?></b> Risk</span>
                        <span><b><?= (int) $residual['tbd'] ?></b> TBD</span>
                        <span><b><?= (int) $residual['open_findings'] ?></b> Open exceptions</span>
                    </div>
                </div>
                <div class="exec-actions no-print-hide">
                    <button type="button" class="button button-primary" id="btn-presentation">Presentation mode</button>
                    <button type="button" class="button ghost-light" id="btn-print">Print one-pager</button>
                    <button type="button" class="button ghost-light" id="btn-export-csv">Export CSV</button>
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
                <div class="diff-summary">
                    <span class="label">Since last upload</span>
                    <strong><?= count($comparison['changes'] ?? []) ?> field changes</strong>
                    <span><?= count($comparison['added'] ?? []) ?> added</span>
                    <span><?= count($comparison['removed'] ?? []) ?> removed</span>
                    <a class="button ghost-light" href="#version-history">Manage versions</a>
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
     */
    public function renderActionsPanel(array $insights, array $comparison = [], array $versions = [], int $currentId = 0, string $csrfToken = ''): string
    {
        $findings = $insights['findings'] ?? [];
        $owners = $insights['owners'] ?? [];
        $timelines = $insights['timelines'] ?? [];
        $evidence = $insights['evidence'] ?? ['total' => 0, 'covered' => 0, 'partial' => 0, 'missing' => 0, 'items' => []];
        $topRisks = $insights['top_risks'] ?? [];

        ob_start();
        ?>
        <div class="actions-grid">
            <section class="table-card" id="version-history">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Comparison & history</div>
                        <h3>Saved versions</h3>
                    </div>
                    <span class="result-count"><?= count($versions) ?> version<?= count($versions) === 1 ? '' : 's' ?></span>
                </div>
                <?php if ($versions === []): ?>
                    <p class="empty-panel">No other saved versions for this project yet. Upload again to enable diff and trends.</p>
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

            <section class="table-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Exception tracker</div>
                        <h3>Governance findings</h3>
                    </div>
                    <span class="result-count" id="exception-open-count"><?= (int) ($insights['residual']['open_findings'] ?? 0) ?> open</span>
                </div>
                <?php if ($findings === []): ?>
                    <p class="empty-panel">No documented exceptions in this workbook.</p>
                <?php else: ?>
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
                                            <select class="exception-status" data-finding-id="<?= $this->e((string) $finding['id']) ?>">
                                                <?php foreach (['Open', 'Approved', 'Expired'] as $status): ?>
                                                    <option value="<?= $this->e($status) ?>" <?= ($finding['status'] ?? 'Open') === $status ? 'selected' : '' ?>><?= $this->e($status) ?></option>
                                                <?php endforeach; ?>
                                            </select>
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

            <section class="table-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Actionability</div>
                        <h3>Owner workload</h3>
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

            <section class="table-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Timeline heat</div>
                        <h3>Remediation lanes</h3>
                    </div>
                </div>
                <div class="timeline-lanes">
                    <?php
                    $maxTimeline = 1;
                    foreach ($timelines as $lane) {
                        $maxTimeline = max($maxTimeline, (int) $lane['total']);
                    }
                    ?>
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

            <section class="table-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Trust / completeness</div>
                        <h3>Evidence checklist</h3>
                    </div>
                    <span class="result-count"><?= (int) $evidence['covered'] ?>/<?= (int) $evidence['total'] ?> covered</span>
                </div>
                <div class="evidence-meter">
                    <?php $total = max(1, (int) $evidence['total']); ?>
                    <span class="ev-covered" style="width: <?= round(((int) $evidence['covered'] / $total) * 100, 2) ?>%"></span>
                    <span class="ev-partial" style="width: <?= round(((int) $evidence['partial'] / $total) * 100, 2) ?>%"></span>
                    <span class="ev-missing" style="width: <?= round(((int) $evidence['missing'] / $total) * 100, 2) ?>%"></span>
                </div>
                <ul class="evidence-list">
                    <?php foreach ($evidence['items'] as $item): ?>
                        <li class="evidence-<?= $this->e((string) $item['status']) ?>">
                            <strong><?= $this->e((string) $item['item']) ?></strong>
                            <span><?= $this->e((string) $item['evidence']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
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

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
