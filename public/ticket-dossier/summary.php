<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$project = $id > 0 ? ProjectRepository::find($id) : null;

if ($project === null) {
    flashSet('error', 'Project not found.');
    redirect('index.php');
}

$parsed = json_decode((string) $project['parsed_json'], true);
if (!is_array($parsed)) {
    $parsed = [];
}

$summary = ProjectSummaryMapper::map($project, $parsed);
$ownerName = projectOwnerName($project);
$ownerTitle = projectOwnerTitle($project);
$cssV = cssVersion();
$jsV = jsVersion();

/**
 * @param array{label: string, value: string, available: bool} $field
 */
function renderSummaryField(array $field, string $extraClass = ''): void
{
    $available = !empty($field['available']);
    $classes = trim('summary-field' . ($available ? '' : ' is-missing') . ($extraClass !== '' ? ' ' . $extraClass : ''));
    echo '<div class="' . e($classes) . '">';
    echo '<dt>' . e((string) $field['label']) . '</dt>';
    echo '<dd>' . nl2br(e((string) $field['value'])) . '</dd>';
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="teal">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product &amp; Design Summary · <?= e((string) $project['title']) ?> · Ticket Dossier · <?= e($branding->documentTitle()) ?></title>
    <?php require dirname(__DIR__) . '/includes/theme-head.php'; ?>
    <?php require dirname(__DIR__) . '/includes/head-branding.php'; ?>
    <link rel="stylesheet" href="<?= e($auth->publicPrefix()) ?>assets/css/dashboard.css?v=<?= e(dashboardCssVersion()) ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= e($cssV) ?>">
</head>
<body>
<div class="shell dossier-page summary-page">
    <header class="topbar topbar-uplift">
        <a class="brand brand-link" href="index.php" title="Ticket Dossier home">
            <?php require dirname(__DIR__) . '/includes/brand-mark.php'; ?>
            <div class="brand-text">
                <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                <h1>Ticket Dossier</h1>
            </div>
        </a>
        <div class="topbar-actions">
            <?php
            ob_start();
            ?>
            <a class="button ghost home-link" data-menu-group="ticket" data-menu-tone="sky" href="project.php?id=<?= (int) $id ?>" title="Return to the full project dossier"><span class="topbar-menu-emoji" aria-hidden="true">📋</span>Full dossier</a>
            <a class="button ghost home-link" data-menu-group="ticket" data-menu-tone="sky" href="index.php" title="Return to the Ticket Dossier project list"><span class="topbar-menu-emoji" aria-hidden="true">🗂</span>All projects</a>
            <button type="button" class="button ghost home-link" data-menu-group="ticket" data-menu-tone="mint" onclick="window.print()" title="Print or save this summary as PDF"><span class="topbar-menu-emoji" aria-hidden="true">🖨</span>Print</button>
            <?php
            $topbarMenuExtraBefore = ob_get_clean();
            require __DIR__ . '/includes/app-nav.php';
            ?>
        </div>
    </header>

    <main>
        <section class="hero hero-compact">
            <div class="hero-main">
                <div class="hero-intro">
                    <p class="eyebrow">Product &amp; Design Summary</p>
                    <h2><?= e((string) $project['title']) ?></h2>
                    <p>Read-only summary mapped from Demand, Story, Task, DDR, vendor, and assessment answers. Fields without a reliable dossier source show as “Not available in dossier.”</p>
                    <p class="hero-edit">
                        <a class="button ghost button-small" href="project.php?id=<?= (int) $id ?>">← Back to full dossier</a>
                    </p>
                </div>
                <div class="hero-chips">
                    <span class="pill teal" data-search-label="Owner"<?= $ownerTitle !== '' ? ' title="' . e($ownerTitle) . '"' : '' ?>>👤 Owner: <?= e($ownerName !== '' ? $ownerName : 'Unknown') ?></span>
                    <?php if (!empty($project['vendor'])): ?>
                        <span class="pill gray">🏢 <?= e((string) $project['vendor']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($project['demand_number'])): ?>
                        <span class="pill teal">🎯 <?= e((string) $project['demand_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($project['ddr_number'])): ?>
                        <span class="pill teal">🛡 <?= e((string) $project['ddr_number']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="summary-layout">
            <div class="summary-column">
                <section class="panel panel-tone-overview summary-panel">
                    <h2>Product Summary</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['product_summary'] as $field): ?>
                            <?php renderSummaryField($field); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-demand summary-panel">
                    <h2>Design Summary</h2>
                    <h3 class="summary-subhead">Business Requirements and Key Constraints</h3>
                    <div class="summary-field-list">
                        <?php foreach ($summary['business_requirements'] as $key => $field): ?>
                            <?php
                            if ($key === 'driving_factors') {
                                echo '<div class="summary-key-questions">';
                                echo '<h4>Key Questions</h4>';
                                echo '<div class="summary-field-list summary-field-list-nested">';
                                foreach ($summary['key_questions'] as $question) {
                                    renderSummaryField($question, 'summary-field-question');
                                }
                                echo '</div></div>';
                            }
                            renderSummaryField($field);
                            ?>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="summary-subhead">This design includes</h3>
                    <ul class="summary-checklist">
                        <?php foreach ($summary['design_includes'] as $item): ?>
                            <li class="summary-check tone-<?= e((string) $item['tone']) ?><?= empty($item['available']) ? ' is-missing' : '' ?>">
                                <span class="summary-check-label"><?= e((string) $item['label']) ?></span>
                                <span class="summary-check-value"><?= nl2br(e((string) $item['value'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            </div>

            <div class="summary-column">
                <section class="panel panel-tone-story summary-panel">
                    <h2>Goals</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['goals'] as $field): ?>
                            <?php renderSummaryField($field); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-task summary-panel">
                    <h2>Any Integrations</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['integrations'] as $field): ?>
                            <?php renderSummaryField($field); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-ddr summary-panel">
                    <h2>3rd Party Review Status</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['third_party_review'] as $field): ?>
                            <?php renderSummaryField($field); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-vendor summary-panel">
                    <h2>Owners</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['owners'] as $field): ?>
                            <?php renderSummaryField($field, 'summary-field-owner'); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-assessments summary-panel">
                    <h2>Vendor Commitments</h2>
                    <p class="summary-note"><?= e((string) $summary['vendor_commitments']['note']) ?></p>
                    <h3 class="summary-subhead">Additional info to be collected</h3>
                    <div class="summary-field-list">
                        <?php foreach ($summary['vendor_commitments']['items'] as $field): ?>
                            <?php renderSummaryField($field, 'summary-field-collect'); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
    <?php require dirname(__DIR__) . '/includes/site-footer.php'; ?>
</div>
<script src="<?= e($auth->publicPrefix()) ?>assets/js/theme.js?v=<?= e(themeJsVersion()) ?>"></script>
</body>
</html>
