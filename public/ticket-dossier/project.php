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
$ownerName = projectOwnerName($project);
$ownerTitle = projectOwnerTitle($project);
$currentOwnerUserId = (int) ($project['owner_user_id'] ?? 0);
$ownerUsers = [];
if (isset($users) && $users instanceof \RiskAssessment\Repositories\UserRepository) {
    $ownerUsers = $users->listApprovedActive();
}
$editDetailsOpen = $ownerName === '' || isset($_GET['edit']);
$flash = flashTake();
$token = csrfToken();
$cssV = cssVersion();
$jsV = jsVersion();
$floatingCssV = floatingSearchCssVersion();
$floatingJsV = floatingSearchJsVersion();

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
    <link rel="stylesheet" href="assets/css/floating-search.css?v=<?= e($floatingCssV) ?>">
</head>
<body>
<div class="shell dossier-page">
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
            <a class="button ghost home-link" data-menu-group="ticket" data-menu-tone="sky" href="index.php" title="Return to the Ticket Dossier project list"><span class="topbar-menu-emoji" aria-hidden="true">📋</span>All projects</a>
            <a
                class="button ghost home-link"
                data-menu-group="ticket"
                data-menu-tone="mint"
                href="export-zip.php?id=<?= (int) $id ?>"
                title="Download this dossier and its original files as a ZIP backup"
            ><span class="topbar-menu-emoji" aria-hidden="true">📦</span>Export ZIP</a>
            <a
                class="button button-primary home-link"
                data-menu-group="ticket"
                data-menu-tone="mint"
                href="export.php?id=<?= (int) $id ?>"
                title="Download this dossier as JSON for offline review or AI analysis"
            ><span class="topbar-menu-emoji" aria-hidden="true">⬇️</span>Export JSON</a>
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
                    <p class="eyebrow">Project dossier</p>
                    <h2><?= e((string) $project['title']) ?></h2>
                    <?php if (!empty($overview['description'])): ?>
                        <p><?= e(strlen((string) $overview['description']) > 420 ? substr((string) $overview['description'], 0, 417) . '…' : (string) $overview['description']) ?></p>
                    <?php endif; ?>
                    <p class="hero-edit">
                        <a class="button ghost button-small" href="#edit-details">✏️ Edit details</a>
                    </p>
                </div>
                <div class="hero-chips">
                    <span class="pill teal" data-search-label="Owner"<?= $ownerTitle !== '' ? ' title="' . e($ownerTitle) . '"' : '' ?>>👤 Owner: <?= e($ownerName !== '' ? $ownerName : 'Unknown') ?></span>
                    <?php if ($project['vendor']): ?>
                        <span class="pill gray" data-search-label="Vendor">🏢 <?= e((string) $project['vendor']) ?></span>
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

        <details class="upload-card" id="edit-details"<?= $editDetailsOpen ? ' open' : '' ?>>
            <summary class="upload-card-summary">
                <h2>✏️ Edit project details</h2>
            </summary>
            <p class="context-note">Change the project name, vendor, or owner if something was missed or needs a clearer label. Ticket numbers still come from the uploaded files.</p>
            <form class="details-form" action="edit.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <input type="hidden" name="project_id" value="<?= (int) $id ?>">
                <div class="details-form-grid">
                    <label class="field field-span-2">
                        <span>Project name</span>
                        <input type="text" name="title" maxlength="200" required value="<?= e((string) $project['title']) ?>" placeholder="Name this dossier">
                    </label>
                    <label class="field">
                        <span>Vendor <em>(optional)</em></span>
                        <input type="text" name="vendor" maxlength="200" value="<?= e((string) ($project['vendor'] ?? '')) ?>" placeholder="Vendor name">
                    </label>
                    <label class="field">
                        <span>Owner name</span>
                        <input type="text" name="owner_name" id="owner-name" maxlength="200" value="<?= e($ownerName) ?>" placeholder="Who owns this dossier?">
                    </label>
                    <label class="field field-span-2">
                        <span>Owner account <em>(optional)</em></span>
                        <select name="owner_user_id" id="owner-user-id">
                            <option value="0"<?= $currentOwnerUserId <= 0 ? ' selected' : '' ?>>Keep current account / not linked</option>
                            <?php foreach ($ownerUsers as $ownerUser): ?>
                                <?php
                                $uid = (int) ($ownerUser['id'] ?? 0);
                                $display = trim((string) ($ownerUser['display_name'] ?? ''));
                                $uname = trim((string) ($ownerUser['username'] ?? ''));
                                $optionLabel = \RiskAssessment\Actor::formatLabel(
                                    $display,
                                    $uname,
                                    (string) ($ownerUser['auth_source'] ?? '')
                                );
                                $optionName = $display !== '' ? $display : $uname;
                                ?>
                                <option
                                    value="<?= $uid ?>"
                                    data-display-name="<?= e($optionName) ?>"
                                    <?= $uid === $currentOwnerUserId ? ' selected' : '' ?>
                                ><?= e($optionLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Pick an app user to link the owner, or type a name above if they are not in the list.</small>
                    </label>
                </div>
                <button type="submit" class="button button-primary">💾 Save details</button>
            </form>
        </details>

        <div class="global-search" id="global-search" data-mode="inline" data-collapsed="0">
            <div class="search-dock-header" id="search-dock-header">
                <div class="search-dock-title" id="search-dock-drag" title="Drag to move">
                    <span class="search-dock-label">Dossier search <span class="search-dock-drag-hint" aria-hidden="true">⠿</span></span>
                    <span class="search-nav-status" id="search-nav-status" aria-live="polite"></span>
                </div>
                <div class="search-dock-actions">
                    <button type="button" class="search-nav-btn" id="search-prev" aria-label="Previous match" title="Previous match (Shift+Enter)" disabled>↑</button>
                    <button type="button" class="search-nav-btn" id="search-next" aria-label="Next match" title="Next match (Enter)" disabled>↓</button>
                    <button type="button" class="search-dock-btn" id="search-collapse" aria-label="Collapse search panel" title="Collapse" aria-pressed="false">⟷</button>
                    <button type="button" class="search-dock-btn" id="search-dock-close" aria-label="Close floating search" title="Close (Esc)">×</button>
                </div>
            </div>
            <div class="search-dock-body" id="search-dock-body">
                <div class="search-input-wrap">
                    <div class="search-input-field">
                        <input
                            type="search"
                            id="global-search-input"
                            placeholder="Search any text — names, numbers, vendor, sponsor…"
                            autocomplete="off"
                            aria-label="Search this dossier"
                            aria-controls="search-results"
                        >
                        <button type="button" class="search-clear hidden" id="search-clear" aria-label="Clear search">×</button>
                    </div>
                    <button
                        type="button"
                        class="search-fuzzy-toggle is-active"
                        id="search-fuzzy-toggle"
                        aria-pressed="true"
                        title="When on, includes close spellings and sounds-like matches"
                    >Fuzzy</button>
                </div>
                <div class="search-jumps" id="search-jumps" aria-label="Jump to people, vendor, and custom presets">
                    <div class="search-jumps-head">
                        <p class="search-jumps-label">Jump to</p>
                        <button type="button" class="search-preset-manage" id="search-preset-manage" aria-expanded="false" aria-controls="search-preset-form">+ Custom preset</button>
                    </div>
                    <div class="search-jumps-list" id="search-jumps-list" role="list"></div>
                    <form class="search-preset-form hidden" id="search-preset-form" autocomplete="off">
                        <p class="search-preset-form-title">Save a custom jump / search preset</p>
                        <div class="search-preset-grid">
                            <label class="field">
                                <span>Chip name</span>
                                <input type="text" id="preset-name" maxlength="40" required placeholder="e.g. Funding CFO">
                            </label>
                            <label class="field">
                                <span>Emoji <em>(optional)</em></span>
                                <input type="text" id="preset-emoji" maxlength="8" placeholder="🔖">
                            </label>
                            <label class="field field-span-2">
                                <span>Jump to field label <em>(optional)</em></span>
                                <input type="text" id="preset-field" maxlength="120" list="preset-field-suggestions" placeholder="Exact or partial field name, e.g. Funding CFO">
                                <datalist id="preset-field-suggestions"></datalist>
                            </label>
                            <label class="field field-span-2">
                                <span>Search query <em>(optional)</em></span>
                                <input type="text" id="preset-query" maxlength="200" placeholder="Text to search when clicked">
                            </label>
                            <label class="field field-span-2">
                                <span>Exclude / exceptions <em>(optional)</em></span>
                                <input type="text" id="preset-exclude" maxlength="200" placeholder="Words to exclude, e.g. internal draft — or -internal -draft">
                            </label>
                        </div>
                        <p class="search-preset-hint">Provide a field label to jump, a search query to run, or both. Use excludes to skip unwanted hits. Presets are stored in this browser and work on every dossier.</p>
                        <div class="search-preset-actions">
                            <button type="submit" class="button button-primary button-small">Save preset</button>
                            <button type="button" class="button ghost button-small" id="preset-cancel">Cancel</button>
                        </div>
                        <p class="search-preset-error hidden" id="preset-error" role="alert"></p>
                    </form>
                </div>
                <div class="search-results hidden" id="search-results" role="status" aria-live="polite"></div>
            </div>
            <button type="button" class="search-rail-expand" id="search-rail-expand" aria-label="Expand search panel" title="Expand search" hidden>
                <span aria-hidden="true">🔍</span>
                <span class="search-rail-expand-label">Search</span>
            </button>
            <div class="search-resize-handle" id="search-resize-handle" aria-hidden="true" title="Drag to resize"></div>
        </div>
        <button type="button" class="search-reopen hidden" id="search-reopen" aria-label="Reopen dossier search" title="Reopen search">🔍</button>

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

        <details class="upload-card" id="complete-dossier"<?= $missingKinds !== [] ? ' open' : '' ?>>
            <summary class="upload-card-summary">
                <h2><?= $missingKinds === [] ? '✏️ Replace or refresh a source' : '🧩 Complete this dossier' ?></h2>
            </summary>
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
        </details>

        <nav class="section-nav" aria-label="Sections" id="section-nav">
            <?php foreach ($sections as $section): ?>
                <a href="#section-<?= e($section) ?>"><?= e(sectionTitle($section)) ?></a>
            <?php endforeach; ?>
            <button type="button" class="button ghost button-small" id="toggle-section-dups" data-hide-dups="0" aria-pressed="false" title="Hide fields that repeat with the same value across Demand, Story, Task, and DDR">🧹 Hide section dups</button>
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
                        <div><dt>Owner</dt><dd><span class="kv-value" data-search-label="Owner"<?= $ownerTitle !== '' ? ' title="' . e($ownerTitle) . '"' : '' ?>><?= e($ownerName !== '' ? $ownerName : '—') ?></span></dd></div>
                        <div><dt>Vendor</dt><dd><span class="kv-value" data-search-label="Vendor"><?= e((string) ($overview['vendor'] ?: ($project['vendor'] ?: '—'))) ?></span></dd></div>
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
                    href="export-zip.php?id=<?= (int) $id ?>"
                    title="Download this dossier and its original files as a ZIP backup"
                >📦 Export ZIP</a>
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
<script src="<?= e($auth->publicPrefix()) ?>assets/js/fuzzy-search.js?v=<?= e(fuzzySearchJsVersion()) ?>"></script>
<script src="assets/js/floating-search.js?v=<?= e($floatingJsV) ?>"></script>
<script src="assets/js/app.js?v=<?= e($jsV) ?>"></script>
</body>
</html>
