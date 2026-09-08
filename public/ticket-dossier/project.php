<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/view.php';

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
$sources = json_decode((string) $project['sources_json'], true);
if (!is_array($sources)) {
    $sources = [];
}
$files = ProjectRepository::filesFor($id);
$sections = availableSections($parsed);
$overview = is_array($parsed['overview'] ?? null) ? $parsed['overview'] : [];
$flash = flashTake();
$token = csrfToken();
$cssV = cssVersion();
$jsV = jsVersion();

$missingKinds = [];
foreach (TD_SOURCE_KINDS as $kind) {
    if (empty($sources[$kind])) {
        $missingKinds[] = $kind;
    }
}

$ribbon = [
    [
        'key' => 'demand',
        'label' => 'Demand',
        'number' => (string) $project['demand_number'],
        'state' => (string) $project['demand_state'],
        'present' => !empty($sources['demand']),
    ],
    [
        'key' => 'story',
        'label' => 'Story',
        'number' => (string) $project['story_number'],
        'state' => (string) $project['story_state'],
        'present' => !empty($sources['story']),
    ],
    [
        'key' => 'task',
        'label' => 'Task',
        'number' => (string) $project['task_number'],
        'state' => (string) $project['task_state'],
        'present' => !empty($sources['task']),
    ],
    [
        'key' => 'ddr',
        'label' => 'DDR',
        'number' => (string) $project['ddr_number'],
        'state' => (string) $project['ddr_state'],
        'present' => !empty($sources['ddr']),
    ],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="teal">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e((string) $project['title']) ?> · Ticket Dossier · <?= e($branding->documentTitle()) ?></title>
    <?php require dirname(__DIR__) . '/includes/theme-head.php'; ?>
    <?php require dirname(__DIR__) . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= e($auth->publicPrefix()) ?>assets/css/dashboard.css?v=<?= e(dashboardCssVersion()) ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= e($cssV) ?>">
</head>
<body>
<div class="shell dossier-page">
    <header class="topbar topbar-uplift">
        <a class="brand brand-link" href="<?= e($auth->publicPrefix()) ?>index.php#find-projects" title="Find projects by name">
            <?php require dirname(__DIR__) . '/includes/brand-mark.php'; ?>
            <div class="brand-text">
                <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                <h1>Ticket Dossier</h1>
            </div>
        </a>
        <div class="topbar-actions">
            <a class="button ghost home-link" href="index.php">← All projects</a>
            <a
                class="button button-primary home-link"
                href="export.php?id=<?= (int) $id ?>"
                title="Download the complete dossier as JSON for AI analysis"
            >⬇️ Export JSON</a>
            <?php require __DIR__ . '/includes/app-nav.php'; ?>
        </div>
    </header>

    <main>
        <section class="hero hero-compact">
            <div class="hero-main">
                <div class="hero-intro">
                    <p class="eyebrow">Project dossier</p>
                    <h2><?= e((string) $project['title']) ?></h2>
                    <?php if (!empty($overview['description'])): ?>
                        <p><?= e(strlen((string) $overview['description']) > 420 ? substr((string) $overview['description'], 0, 417) . '…' : (string) $overview['description']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="hero-chips">
                    <?php if ($project['vendor']): ?>
                        <span class="pill gray">🏢 <?= e((string) $project['vendor']) ?></span>
                    <?php endif; ?>
                    <?php foreach ($ribbon as $node): ?>
                        <?php if ($node['number'] !== ''): ?>
                            <span class="pill <?= $node['key'] === 'story' ? 'amber' : ($node['key'] === 'task' ? 'gray' : 'teal') ?>">
                                <?= kindEmoji($node['key']) ?> <?= e($node['number']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($node['state'] !== ''): ?>
                            <span class="pill gray"><?= e($node['label']) ?>: <?= e($node['state']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>">
                <?= $flash['type'] === 'success' ? '✅ ' : ($flash['type'] === 'error' ? '⚠️ ' : 'ℹ️ ') ?>
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="global-search" id="global-search">
            <div class="search-input-wrap">
                <input 
                    type="search" 
                    id="global-search-input" 
                    placeholder="🔍 Search for vendor, contact, number, state, or any text..."
                    autocomplete="off"
                />
                <button type="button" class="search-clear hidden" id="search-clear" aria-label="Clear search">×</button>
            </div>
            <div class="search-results hidden" id="search-results"></div>
        </div>

        <nav class="ribbon" aria-label="Record relationship">
            <?php foreach ($ribbon as $node): ?>
                <div class="ribbon-node <?= $node['present'] ? 'present' : 'missing' ?>"
                     data-kind="<?= e($node['key']) ?>"
                     data-emoji="<?= kindEmoji($node['key']) ?>">
                    <span class="ribbon-label"><?= e($node['label']) ?></span>
                    <strong><?= $node['number'] !== '' ? e($node['number']) : '—' ?></strong>
                    <span class="ribbon-state">
                        <span class="status-dot" aria-hidden="true"></span>
                        <?= $node['present']
                            ? e($node['state'] !== '' ? $node['state'] : 'Uploaded')
                            : 'Not uploaded' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </nav>

        <section class="upload-card" id="complete-dossier">
            <h2><?= $missingKinds === [] ? '✏️ Replace or refresh a source' : '🧩 Complete this dossier' ?></h2>
            <?php if ($missingKinds !== []): ?>
                <p class="context-note">
                    Missing:
                    <?php foreach ($missingKinds as $i => $kind): ?>
                        <span class="source-pill off"><?= kindEmoji($kind) ?> <?= e(kindLabel($kind)) ?></span><?= $i < count($missingKinds) - 1 ? ' ' : '' ?>
                    <?php endforeach; ?>
                    — upload the file(s) below to fill the gaps. Replacing an existing source is also supported.
                </p>
            <?php else: ?>
                <p class="context-note">All four sources are present. Upload again to replace Demand, Story, Task, or DDR with a newer export.</p>
            <?php endif; ?>
            <form class="upload-form" id="upload-form" action="update.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" id="csrf-token" value="<?= e($token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $id ?>">

                <div class="dropzone" id="dropzone" tabindex="0" role="button" aria-label="Drop ServiceNow export files here">
                    <input type="file" id="file-input" name="files[]" accept=".pdf,.json,application/pdf,application/json" multiple hidden>
                    <div class="dropzone-inner">
                        <div class="dropzone-icon" aria-hidden="true">📂</div>
                        <p class="dropzone-title">Drag &amp; drop missing files here</p>
                        <p class="dropzone-hint">or <button type="button" class="linkish" id="browse-files">browse</button> · PDF / JSON · up to 10 files</p>
                    </div>
                </div>

                <div id="detect-status" class="detect-status hidden" aria-live="polite"></div>
                <ul id="file-preview" class="file-preview" aria-live="polite"></ul>

                <button type="submit" class="button button-primary" id="submit-upload" disabled>✨ Update dossier</button>
            </form>
        </section>

        <nav class="section-nav" aria-label="Sections" id="section-nav">
            <?php foreach ($sections as $section): ?>
                <a href="#section-<?= e($section) ?>"><?= e(sectionTitle($section)) ?></a>
            <?php endforeach; ?>
            <button type="button" class="button ghost button-small" id="toggle-all-fields" data-show-all="0">👁️ Show all fields</button>
        </nav>

        <section class="panel panel-tone-overview" id="section-overview">
            <h2><?= sectionTitle('overview') ?></h2>
            <div class="overview-grid">
                <article class="overview-card">
                    <h3>📝 Description</h3>
                    <p><?= !empty($overview['description']) ? nl2br(e((string) $overview['description'])) : '<span class="muted">No description available.</span>' ?></p>
                </article>
                <article class="overview-card">
                    <h3>💡 Business case</h3>
                    <p><?= !empty($overview['business_case']) ? nl2br(e((string) $overview['business_case'])) : '<span class="muted">Not available (upload demand PDF for business case).</span>' ?></p>
                </article>
                <article class="overview-card meta-card">
                    <h3>🔑 Key facts</h3>
                    <dl class="kv">
                        <div><dt>Vendor</dt><dd><span class="kv-value"><?= e((string) ($overview['vendor'] ?: ($project['vendor'] ?: '—'))) ?></span></dd></div>
                        <div><dt>Demand</dt><dd><span class="kv-value"><?= e((string) ($project['demand_number'] ?: '—')) ?></span><?= $project['demand_state'] ? ' <span class="pill teal">' . e((string) $project['demand_state']) . '</span>' : '' ?></dd></div>
                        <div><dt>Story</dt><dd><span class="kv-value"><?= e((string) ($project['story_number'] ?: '—')) ?></span><?= $project['story_state'] ? ' <span class="pill amber">' . e((string) $project['story_state']) . '</span>' : '' ?></dd></div>
                        <div><dt>Task</dt><dd><span class="kv-value"><?= e((string) ($project['task_number'] ?: '—')) ?></span><?= $project['task_state'] ? ' <span class="pill gray">' . e((string) $project['task_state']) . '</span>' : '' ?></dd></div>
                        <div><dt>DDR</dt><dd><span class="kv-value"><?= e((string) ($project['ddr_number'] ?: '—')) ?></span><?= $project['ddr_state'] ? ' <span class="pill teal">' . e((string) $project['ddr_state']) . '</span>' : '' ?></dd></div>
                    </dl>
                </article>
            </div>
        </section>

        <?php foreach (['demand', 'story', 'task'] as $kind): ?>
            <?php if (empty($parsed[$kind]) || !is_array($parsed[$kind])) {
                continue;
            }
            $section = $parsed[$kind];
            $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
            ?>
            <section class="panel panel-tone-<?= e($kind) ?>" id="section-<?= e($kind) ?>">
                <div class="section-head">
                    <h2><?= kindEmoji($kind) ?> <?= e(kindLabel($kind)) ?>
                        <?php if (!empty($section['number'])): ?>
                            <span class="<?= e(pillClassForKind($kind)) ?>"><?= e((string) $section['number']) ?></span>
                        <?php endif; ?>
                    </h2>
                    <?php if (!empty($section['state'])): ?>
                        <span class="pill gray">📌 <?= e((string) $section['state']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($section['description'])): ?>
                    <div class="prose-block">
                        <h3>📝 Description</h3>
                        <p><?= nl2br(e((string) $section['description'])) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($kind === 'demand' && !empty($section['business_case'])): ?>
                    <div class="prose-block">
                        <h3>💡 Business case</h3>
                        <p><?= nl2br(e((string) $section['business_case'])) ?></p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($section['related']) && is_array($section['related'])): ?>
                    <div class="related-list">
                        <h3>🔗 Related records</h3>
                        <ul>
                            <?php foreach ($section['related'] as $rel): ?>
                                <li>
                                    <code><?= e((string) ($rel['parent'] ?? '')) ?></code>
                                    →
                                    <code><?= e((string) ($rel['child'] ?? '')) ?></code>
                                    <span class="muted"><?= e((string) ($rel['type'] ?? '')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <div class="fields-wrap" data-fields>
                    <?php renderFieldGrid($fields, false); ?>
                </div>
                <div class="fields-wrap fields-all hidden" data-fields-all>
                    <?php renderFieldGrid($fields, true); ?>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if (!empty($parsed['ddr']) && is_array($parsed['ddr'])): ?>
            <?php $ddr = $parsed['ddr']; $ddrFields = is_array($ddr['fields'] ?? null) ? $ddr['fields'] : []; ?>
            <section class="panel panel-tone-ddr" id="section-ddr">
                <div class="section-head">
                    <h2><?= kindEmoji('ddr') ?> Due Diligence
                        <?php if (!empty($ddr['number'])): ?>
                            <span class="pill teal"><?= e((string) $ddr['number']) ?></span>
                        <?php endif; ?>
                    </h2>
                    <?php if (!empty($ddr['state'])): ?>
                        <span class="pill gray">📌 <?= e((string) $ddr['state']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ddr['description'])): ?>
                    <div class="prose-block">
                        <h3>📝 Description</h3>
                        <p><?= nl2br(e((string) $ddr['description'])) ?></p>
                    </div>
                <?php endif; ?>
                <div class="fields-wrap" data-fields>
                    <?php renderFieldGrid($ddrFields, false); ?>
                </div>
                <div class="fields-wrap fields-all hidden" data-fields-all>
                    <?php renderFieldGrid($ddrFields, true); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php
        $vendorFields = [];
        if (!empty($parsed['vendor']) && is_array($parsed['vendor'])) {
            $vendorFields = is_array($parsed['vendor']['fields'] ?? null)
                ? $parsed['vendor']['fields']
                : (is_array($parsed['vendor']) ? $parsed['vendor'] : []);
            if (isset($vendorFields['fields']) || isset($vendorFields['metadata'])) {
                $vendorFields = is_array($parsed['vendor']['fields'] ?? null) ? $parsed['vendor']['fields'] : [];
            }
        }
        ?>
        <?php if ($vendorFields !== []): ?>
            <section class="panel panel-tone-vendor" id="section-vendor">
                <h2><?= sectionTitle('vendor') ?></h2>
                <div class="fields-wrap" data-fields>
                    <?php renderFieldGrid($vendorFields, false); ?>
                </div>
                <div class="fields-wrap fields-all hidden" data-fields-all>
                    <?php renderFieldGrid($vendorFields, true); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php
        $assessments = is_array($parsed['assessments'] ?? null) ? $parsed['assessments'] : [];
        $external = is_array($assessments['external'] ?? null) ? $assessments['external'] : [];
        $internal = is_array($assessments['internal'] ?? null) ? $assessments['internal'] : [];
        $hasAssessments = $external !== [] || $internal !== [];
        ?>
        <?php if ($hasAssessments): ?>
            <section class="panel panel-tone-assessments" id="section-assessments">
                <div class="section-head">
                    <h2><?= sectionTitle('assessments') ?></h2>
                </div>
                <div class="assess-toolbar">
                    <label class="field inline">
                        <span>Search</span>
                        <input type="search" id="qa-search" placeholder="Filter questions or answers…">
                    </label>
                    <label class="check">
                        <input type="checkbox" id="qa-answered-only" checked>
                        ✅ Answered only
                    </label>
                </div>

                <?php foreach ([['🌐 External', $external], ['🏠 Internal', $internal]] as [$groupLabel, $group]): ?>
                    <?php if ($group === []) {
                        continue;
                    } ?>
                    <h3 class="assess-group"><?= e($groupLabel) ?> assessments</h3>
                    <?php foreach ($group as $assessment): ?>
                        <?php
                        if (!is_array($assessment)) {
                            continue;
                        }
                        $questionnaires = is_array($assessment['questionnaires'] ?? null) ? $assessment['questionnaires'] : [];
                        $allQa = [];
                        foreach ($questionnaires as $q) {
                            foreach (($q['instances'] ?? []) as $inst) {
                                foreach (($inst['qa'] ?? []) as $qaItem) {
                                    $allQa[] = $qaItem;
                                }
                            }
                        }
                        $stats = qaStats($allQa);
                        $pct = $stats['total'] > 0 ? (int) round(100 * $stats['answered'] / $stats['total']) : 0;
                        ?>
                        <details class="assess-card" open>
                            <summary>
                                <div>
                                    <strong>📋 <?= e((string) ($assessment['name'] ?: ($assessment['number'] ?: 'Assessment'))) ?></strong>
                                    <?php if (!empty($assessment['number'])): ?>
                                        <span class="pill teal"><?= e((string) $assessment['number']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($assessment['state'])): ?>
                                        <span class="pill gray"><?= e((string) $assessment['state']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="progress-wrap">
                                    <div class="progress"><span style="width: <?= $pct ?>%"></span></div>
                                    <small><?= (int) $stats['answered'] ?> / <?= (int) $stats['total'] ?> answered (<?= $pct ?>%)</small>
                                </div>
                            </summary>

                            <?php
                            $aFields = is_array($assessment['fields'] ?? null) ? $assessment['fields'] : [];
                            if ($aFields !== []):
                                ?>
                                <div class="fields-wrap" data-fields>
                                    <?php renderFieldGrid($aFields, false); ?>
                                </div>
                                <div class="fields-wrap fields-all hidden" data-fields-all>
                                    <?php renderFieldGrid($aFields, true); ?>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($questionnaires as $questionnaire): ?>
                                <?php
                                $instances = is_array($questionnaire['instances'] ?? null) ? $questionnaire['instances'] : [];
                                foreach ($instances as $instance):
                                    $qaList = is_array($instance['qa'] ?? null) ? $instance['qa'] : [];
                                    if ($qaList === []) {
                                        continue;
                                    }
                                    ?>
                                    <div class="qa-block">
                                        <h4>🧾 <?= e((string) ($questionnaire['name'] ?? 'Questionnaire')) ?></h4>
                                        <div class="qa-list">
                                            <?php foreach ($qaList as $qaItem): ?>
                                                <?php
                                                $q = (string) ($qaItem['question'] ?? '');
                                                $a = (string) ($qaItem['answer'] ?? '');
                                                $answered = trim($a) !== '';
                                                ?>
                                                <article class="qa-item<?= $answered ? ' answered' : ' unanswered' ?>"
                                                         data-q="<?= e(strtolower($q . ' ' . $a)) ?>">
                                                    <p class="q"><?= e($q) ?></p>
                                                    <p class="a"><?= $answered ? nl2br(e($a)) : '<span class="muted">No answer</span>' ?></p>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </details>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="panel panel-tone-files" id="section-files">
            <div class="section-head">
                <h2><?= sectionTitle('files') ?></h2>
                <a
                    class="button button-small"
                    href="export.php?id=<?= (int) $id ?>"
                    title="Download the complete dossier as JSON for AI analysis"
                >⬇️ Export JSON</a>
            </div>
            <?php if ($files === []): ?>
                <p class="muted">No files stored.</p>
            <?php else: ?>
                <ul class="file-list">
                    <?php foreach ($files as $file): ?>
                        <li>
                            <span class="<?= e(pillClassForKind((string) $file['kind'])) ?>"><?= kindEmoji((string) $file['kind']) ?> <?= e(kindLabel((string) $file['kind'])) ?></span>
                            <a href="download.php?project_id=<?= $id ?>&amp;file_id=<?= (int) $file['id'] ?>">
                                ⬇️ <?= e((string) $file['original_name']) ?>
                            </a>
                            <span class="muted"><?= number_format((int) $file['size_bytes'] / 1024, 1) ?> KB</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>
    <?php require dirname(__DIR__) . '/includes/site-footer.php'; ?>
</div>
<script src="<?= e($auth->publicPrefix()) ?>assets/js/theme.js?v=<?= e(themeJsVersion()) ?>"></script>
<script src="assets/js/app.js?v=<?= e($jsV) ?>"></script>
</body>
</html>
