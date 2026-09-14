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
$snInstance = servicenowInstanceOriginFromParsed($parsed);
$snTickets = servicenowTicketLookup($parsed);
$snMeta = static function (string $number, string $kind = '', string $sysId = '', string $table = '') use ($snTickets): array {
    return servicenowTicketMeta($snTickets, $number, $kind, $sysId, $table);
};
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
<html lang="en" data-theme="teal"<?= $snInstance !== '' ? ' data-sn-instance="' . e($snInstance) . '"' : '' ?>>
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
                href="summary.php?id=<?= (int) $id ?>"
                title="Open the Product &amp; Design Summary for this project"
            ><span class="topbar-menu-emoji" aria-hidden="true">📄</span>Summary</a>
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
                    <h2><?php renderEditableValue((string) $project['title'], ['overview', 'title'], 'Project name'); ?></h2>
                    <?php if (!empty($overview['description'])): ?>
                        <p><?= e(strlen((string) $overview['description']) > 420 ? substr((string) $overview['description'], 0, 417) . '…' : (string) $overview['description']) ?></p>
                    <?php endif; ?>
                    <p class="hero-edit">
                        <a class="button ghost button-small" href="summary.php?id=<?= (int) $id ?>" title="Open the Product &amp; Design Summary for this project">📄 Product &amp; Design Summary</a>
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
                            <?php
                            $ticketMeta = $snMeta($node['number'], $node['key']);
                            renderServicenowTicketPill($node['number'], [
                                'kind' => $node['key'],
                                'instance' => $snInstance,
                                'sys_id' => $ticketMeta['sys_id'],
                                'table' => $ticketMeta['table'],
                                'prefix' => kindEmoji($node['key']),
                            ]);
                            ?>
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
                    <p><?php renderEditableValue((string) ($overview['description'] ?? ''), ['overview', 'description'], 'Overview description', true); ?></p>
                </article>
                <article class="overview-card">
                    <h3>💡 Business case</h3>
                    <p><?php renderEditableValue((string) ($overview['business_case'] ?? ''), ['overview', 'business_case'], 'Overview business case', true); ?></p>
                </article>
                <article class="overview-card meta-card">
                    <h3>🔑 Key facts</h3>
                    <dl class="kv">
                        <div><dt>Owner</dt><dd><span class="kv-value" data-search-label="Owner"<?= $ownerTitle !== '' ? ' title="' . e($ownerTitle) . '"' : '' ?>><?= e($ownerName !== '' ? $ownerName : '—') ?></span> <a class="field-edit-pencil" href="project.php?id=<?= (int) $id ?>&amp;edit=1#edit-details" aria-label="Edit owner" title="Edit owner"></a></dd></div>
                        <div><dt>Vendor</dt><dd><span class="kv-value" data-search-label="Vendor"><?php renderEditableValue((string) ($overview['vendor'] ?? $project['vendor'] ?? ''), ['overview', 'vendor'], 'Vendor'); ?></span></dd></div>
                        <?php foreach (['demand' => 'Demand', 'story' => 'Story', 'task' => 'Task', 'ddr' => 'DDR'] as $kind => $kindTitle): ?>
                            <?php
                            $ticketNumber = (string) ($project[$kind . '_number'] ?? '');
                            $ticketState = (string) ($project[$kind . '_state'] ?? '');
                            $ticketMeta = $snMeta($ticketNumber, $kind);
                            ?>
                            <div><dt><?= e($kindTitle) ?></dt><dd><span class="kv-value"><?php renderServicenowTicketNumber($ticketNumber, [
                                'kind' => $kind,
                                'instance' => $snInstance,
                                'sys_id' => $ticketMeta['sys_id'],
                                'table' => $ticketMeta['table'],
                            ]); ?></span><?= $ticketState !== '' ? ' <span class="' . e(pillClassForKind($kind)) . '">' . e($ticketState) . '</span>' : '' ?></dd></div>
                        <?php endforeach; ?>
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
                            <?php
                            $ticketMeta = $snMeta((string) $section['number'], $kind, (string) ($section['sys_id'] ?? ''), (string) ($section['table'] ?? $section['sys_class_name'] ?? ''));
                            renderServicenowTicketPill((string) $section['number'], [
                                'kind' => $kind,
                                'instance' => $snInstance,
                                'sys_id' => $ticketMeta['sys_id'],
                                'table' => $ticketMeta['table'],
                                'path' => [$kind, 'number'],
                                'label' => kindLabel($kind) . ' number',
                            ]);
                            ?>
                        <?php endif; ?>
                    </h2>
                    <?php if (!empty($section['state'])): ?>
                        <span class="pill gray">📌 <?php renderEditableValue((string) $section['state'], [$kind, 'state'], kindLabel($kind) . ' state'); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($section['description'])): ?>
                    <div class="prose-block">
                        <h3>📝 Description</h3>
                        <p><?php renderEditableValue((string) $section['description'], [$kind, 'description'], kindLabel($kind) . ' description', true); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($kind === 'demand' && !empty($section['business_case'])): ?>
                    <div class="prose-block">
                        <h3>💡 Business case</h3>
                        <p><?php renderEditableValue((string) $section['business_case'], [$kind, 'business_case'], 'Demand business case', true); ?></p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($section['related']) && is_array($section['related'])): ?>
                    <div class="related-list">
                        <h3>🔗 Related records</h3>
                        <ul>
                            <?php foreach ($section['related'] as $relIdx => $rel): ?>
                                <?php
                                $parentNumber = (string) ($rel['parent'] ?? '');
                                $childNumber = (string) ($rel['child'] ?? '');
                                $parentMeta = $snMeta($parentNumber, '', (string) ($rel['parent_sys_id'] ?? ''));
                                $childMeta = $snMeta($childNumber, '', (string) ($rel['child_sys_id'] ?? ''));
                                ?>
                                <li>
                                    <code><?php renderServicenowTicketNumber($parentNumber, [
                                        'kind' => $parentMeta['kind'],
                                        'instance' => $snInstance,
                                        'sys_id' => $parentMeta['sys_id'],
                                        'table' => $parentMeta['table'],
                                        'path' => [$kind, 'related', $relIdx, 'parent'],
                                        'label' => 'Relationship parent',
                                    ]); ?></code>
                                    →
                                    <code><?php renderServicenowTicketNumber($childNumber, [
                                        'kind' => $childMeta['kind'],
                                        'instance' => $snInstance,
                                        'sys_id' => $childMeta['sys_id'],
                                        'table' => $childMeta['table'],
                                        'path' => [$kind, 'related', $relIdx, 'child'],
                                        'label' => 'Relationship child',
                                    ]); ?></code>
                                    <span class="muted"><?php renderEditableValue((string) ($rel['type'] ?? ''), [$kind, 'related', $relIdx, 'type'], 'Relationship type'); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <div class="fields-wrap" data-fields>
                    <?php renderFieldGrid($fields, false, '', [$kind, 'fields']); ?>
                </div>
                <div class="fields-wrap fields-all hidden" data-fields-all>
                    <?php renderFieldGrid($fields, true, '', [$kind, 'fields']); ?>
                </div>
            </section>
        <?php endforeach; ?>

        <?php
        $relatedTickets = is_array($parsed['related_tickets'] ?? null) ? $parsed['related_tickets'] : [];
        if ($relatedTickets !== []):
        ?>
            <section class="panel panel-tone-task" id="section-related">
                <div class="section-head">
                    <h2><?= sectionTitle('related') ?>
                        <span class="pill gray"><?= count($relatedTickets) ?></span>
                    </h2>
                </div>
                <p class="context-note">Tickets from the root task’s <strong>Task Relationships</strong> list (direct only), excluding the Demand/Story already shown above when present.</p>
                <?php foreach ($relatedTickets as $relIdx => $relTicket): ?>
                    <?php
                    if (!is_array($relTicket)) {
                        continue;
                    }
                    $relFields = is_array($relTicket['fields'] ?? null) ? $relTicket['fields'] : [];
                    $relAtts = is_array($relTicket['attachments'] ?? null) ? $relTicket['attachments'] : [];
                    $relJournal = is_array($relTicket['journal'] ?? null) ? $relTicket['journal'] : [];
                    $relNumber = (string) ($relTicket['number'] ?? '');
                    $relKind = (string) ($relTicket['kind'] ?? 'task');
                    ?>
                    <article class="related-ticket-card" id="related-ticket-<?= (int) $relIdx ?>">
                        <div class="section-head">
                            <h3>
                                <?= kindEmoji($relKind) ?>
                                <code><?php
                                $ticketMeta = $snMeta($relNumber, $relKind, (string) ($relTicket['sys_id'] ?? ''), (string) ($relTicket['table'] ?? $relTicket['sys_class_name'] ?? ''));
                                renderServicenowTicketNumber($relNumber, [
                                    'kind' => $relKind,
                                    'instance' => $snInstance,
                                    'sys_id' => $ticketMeta['sys_id'],
                                    'table' => $ticketMeta['table'],
                                    'path' => ['related_tickets', $relIdx, 'number'],
                                    'label' => 'Related ticket number',
                                ]);
                                ?></code>
                                <?php if (!empty($relTicket['sys_class_name'])): ?>
                                    <span class="muted"><?php renderEditableValue((string) $relTicket['sys_class_name'], ['related_tickets', $relIdx, 'sys_class_name'], 'Related ticket class'); ?></span>
                                <?php endif; ?>
                            </h3>
                            <?php if (!empty($relTicket['state'])): ?>
                                <span class="pill gray">📌 <?php renderEditableValue((string) $relTicket['state'], ['related_tickets', $relIdx, 'state'], 'Related ticket state'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($relTicket['title']) || !empty($relTicket['short_description'])): ?>
                            <?php $relTitleKey = array_key_exists('title', $relTicket) ? 'title' : 'short_description'; ?>
                            <p><strong><?php renderEditableValue((string) ($relTicket[$relTitleKey] ?? ''), ['related_tickets', $relIdx, $relTitleKey], 'Related ticket title'); ?></strong></p>
                        <?php endif; ?>
                        <?php if (!empty($relTicket['description'])): ?>
                            <div class="prose-block">
                                <h4>📝 Description</h4>
                                <p><?php renderEditableValue((string) $relTicket['description'], ['related_tickets', $relIdx, 'description'], 'Related ticket description', true); ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($relTicket['related']) && is_array($relTicket['related'])): ?>
                            <div class="related-list">
                                <h4>🔗 Relationships</h4>
                                <ul>
                                    <?php foreach ($relTicket['related'] as $ticketRelIdx => $rel): ?>
                                        <?php
                                        $parentNumber = (string) ($rel['parent'] ?? '');
                                        $childNumber = (string) ($rel['child'] ?? '');
                                        $parentMeta = $snMeta($parentNumber, '', (string) ($rel['parent_sys_id'] ?? ''));
                                        $childMeta = $snMeta($childNumber, '', (string) ($rel['child_sys_id'] ?? ''));
                                        ?>
                                        <li>
                                            <code><?php renderServicenowTicketNumber($parentNumber, [
                                                'kind' => $parentMeta['kind'],
                                                'instance' => $snInstance,
                                                'sys_id' => $parentMeta['sys_id'],
                                                'table' => $parentMeta['table'],
                                                'path' => ['related_tickets', $relIdx, 'related', $ticketRelIdx, 'parent'],
                                                'label' => 'Relationship parent',
                                            ]); ?></code>
                                            →
                                            <code><?php renderServicenowTicketNumber($childNumber, [
                                                'kind' => $childMeta['kind'],
                                                'instance' => $snInstance,
                                                'sys_id' => $childMeta['sys_id'],
                                                'table' => $childMeta['table'],
                                                'path' => ['related_tickets', $relIdx, 'related', $ticketRelIdx, 'child'],
                                                'label' => 'Relationship child',
                                            ]); ?></code>
                                            <span class="muted"><?php renderEditableValue((string) ($rel['type'] ?? ''), ['related_tickets', $relIdx, 'related', $ticketRelIdx, 'type'], 'Relationship type'); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($relAtts !== []): ?>
                            <div class="related-attachments">
                                <h4>📎 Attachments</h4>
                                <ul class="file-list">
                                    <?php foreach ($relAtts as $att): ?>
                                        <?php
                                        $attName = (string) ($att['file_name'] ?? $att['filename'] ?? '');
                                        $attPath = (string) ($att['relative_path'] ?? '');
                                        $matchFile = null;
                                        foreach ($files as $file) {
                                            $orig = (string) ($file['original_name'] ?? '');
                                            if (
                                                $attName !== ''
                                                && (
                                                    $orig === $relNumber . '/' . $attName
                                                    || str_ends_with($orig, '/' . $attName)
                                                    || $orig === $attName
                                                )
                                            ) {
                                                $matchFile = $file;
                                                break;
                                            }
                                        }
                                        ?>
                                        <li>
                                            <?php if ($matchFile !== null): ?>
                                                <a href="download.php?project_id=<?= (int) $id ?>&amp;file_id=<?= (int) $matchFile['id'] ?>">
                                                    ⬇️ <?= e($relNumber !== '' ? $relNumber . '/' . $attName : $attName) ?>
                                                </a>
                                            <?php else: ?>
                                                <span><?= e($attName !== '' ? $attName : 'attachment') ?></span>
                                                <?php if ($attPath !== ''): ?>
                                                    <span class="muted"><?= e($attPath) ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($att['size_bytes'])): ?>
                                                <span class="muted"><?= number_format((int) $att['size_bytes'] / 1024, 1) ?> KB</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($relJournal !== []): ?>
                            <details class="journal-details">
                                <summary>🗒️ Work notes / comments (<?= count($relJournal) ?>)</summary>
                                <ul class="journal-list">
                                    <?php foreach ($relJournal as $journalIdx => $entry): ?>
                                        <li>
                                            <span class="muted">
                                                <?php renderEditableValue((string) ($entry['element'] ?? ''), ['related_tickets', $relIdx, 'journal', $journalIdx, 'element'], 'Journal type'); ?>
                                                · <?php renderEditableValue((string) ($entry['created'] ?? ''), ['related_tickets', $relIdx, 'journal', $journalIdx, 'created'], 'Journal date'); ?>
                                                · <?php renderEditableValue((string) ($entry['created_by'] ?? ''), ['related_tickets', $relIdx, 'journal', $journalIdx, 'created_by'], 'Journal author'); ?>
                                            </span>
                                            <div><?php renderEditableValue((string) ($entry['value'] ?? ''), ['related_tickets', $relIdx, 'journal', $journalIdx, 'value'], 'Journal entry', true); ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                        <div class="fields-wrap" data-fields>
                            <?php renderFieldGrid($relFields, false, '', ['related_tickets', $relIdx, 'fields']); ?>
                        </div>
                        <div class="fields-wrap fields-all hidden" data-fields-all>
                            <?php renderFieldGrid($relFields, true, '', ['related_tickets', $relIdx, 'fields']); ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($parsed['ddr']) && is_array($parsed['ddr'])): ?>
            <?php $ddr = $parsed['ddr']; $ddrFields = is_array($ddr['fields'] ?? null) ? $ddr['fields'] : []; ?>
            <section class="panel panel-tone-ddr" id="section-ddr">
                <div class="section-head">
                    <h2><?= kindEmoji('ddr') ?> Due Diligence
                        <?php if (!empty($ddr['number'])): ?>
                            <?php
                            $ticketMeta = $snMeta((string) $ddr['number'], 'ddr', (string) ($ddr['sys_id'] ?? ''), (string) ($ddr['table'] ?? $ddr['sys_class_name'] ?? ''));
                            renderServicenowTicketPill((string) $ddr['number'], [
                                'kind' => 'ddr',
                                'instance' => $snInstance,
                                'sys_id' => $ticketMeta['sys_id'],
                                'table' => $ticketMeta['table'],
                                'path' => ['ddr', 'number'],
                                'label' => 'DDR number',
                            ]);
                            ?>
                        <?php endif; ?>
                    </h2>
                    <?php if (!empty($ddr['state'])): ?>
                        <span class="pill gray">📌 <?php renderEditableValue((string) $ddr['state'], ['ddr', 'state'], 'DDR state'); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ddr['description'])): ?>
                    <div class="prose-block">
                        <h3>📝 Description</h3>
                        <p><?php renderEditableValue((string) $ddr['description'], ['ddr', 'description'], 'DDR description', true); ?></p>
                    </div>
                <?php endif; ?>
                <div class="fields-wrap" data-fields>
                    <?php renderFieldGrid($ddrFields, false, '', ['ddr', 'fields']); ?>
                </div>
                <div class="fields-wrap fields-all hidden" data-fields-all>
                    <?php renderFieldGrid($ddrFields, true, '', ['ddr', 'fields']); ?>
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
                    <?php renderFieldGrid($vendorFields, false, '', isset($parsed['vendor']['fields']) ? ['vendor', 'fields'] : ['vendor']); ?>
                </div>
                <div class="fields-wrap fields-all hidden" data-fields-all>
                    <?php renderFieldGrid($vendorFields, true, '', isset($parsed['vendor']['fields']) ? ['vendor', 'fields'] : ['vendor']); ?>
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
                    <?php foreach ($group as $assessmentIdx => $assessment): ?>
                        <?php
                        if (!is_array($assessment)) {
                            continue;
                        }
                        $assessmentGroup = $groupLabel === '🌐 External' ? 'external' : 'internal';
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
                                    <?php $assessmentNameKey = !empty($assessment['name']) ? 'name' : 'number'; ?>
                                    <strong>📋 <?php renderEditableValue((string) ($assessment[$assessmentNameKey] ?? ''), ['assessments', $assessmentGroup, $assessmentIdx, $assessmentNameKey], 'Assessment name'); ?></strong>
                                    <?php if (!empty($assessment['number'])): ?>
                                        <span class="pill teal"><?php renderEditableValue((string) $assessment['number'], ['assessments', $assessmentGroup, $assessmentIdx, 'number'], 'Assessment number'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($assessment['state'])): ?>
                                        <span class="pill gray"><?php renderEditableValue((string) $assessment['state'], ['assessments', $assessmentGroup, $assessmentIdx, 'state'], 'Assessment state'); ?></span>
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
                                    <?php renderFieldGrid($aFields, false, '', ['assessments', $assessmentGroup, $assessmentIdx, 'fields']); ?>
                                </div>
                                <div class="fields-wrap fields-all hidden" data-fields-all>
                                    <?php renderFieldGrid($aFields, true, '', ['assessments', $assessmentGroup, $assessmentIdx, 'fields']); ?>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($questionnaires as $questionnaireIdx => $questionnaire): ?>
                                <?php
                                $instances = is_array($questionnaire['instances'] ?? null) ? $questionnaire['instances'] : [];
                                foreach ($instances as $instanceIdx => $instance):
                                    $qaList = is_array($instance['qa'] ?? null) ? $instance['qa'] : [];
                                    if ($qaList === []) {
                                        continue;
                                    }
                                    ?>
                                    <div class="qa-block">
                                        <h4>🧾 <?php renderEditableValue((string) ($questionnaire['name'] ?? ''), ['assessments', $assessmentGroup, $assessmentIdx, 'questionnaires', $questionnaireIdx, 'name'], 'Questionnaire name'); ?></h4>
                                        <div class="qa-list">
                                            <?php foreach ($qaList as $qaIdx => $qaItem): ?>
                                                <?php
                                                $q = (string) ($qaItem['question'] ?? '');
                                                $a = (string) ($qaItem['answer'] ?? '');
                                                $answered = trim($a) !== '';
                                                ?>
                                                <article class="qa-item<?= $answered ? ' answered' : ' unanswered' ?>"
                                                         data-q="<?= e(strtolower($q . ' ' . $a)) ?>">
                                                    <p class="q"><?php renderEditableValue($q, ['assessments', $assessmentGroup, $assessmentIdx, 'questionnaires', $questionnaireIdx, 'instances', $instanceIdx, 'qa', $qaIdx, 'question'], 'Assessment question', true); ?></p>
                                                    <p class="a"><?php renderEditableValue($a, ['assessments', $assessmentGroup, $assessmentIdx, 'questionnaires', $questionnaireIdx, 'instances', $instanceIdx, 'qa', $qaIdx, 'answer'], 'Assessment answer', true); ?></p>
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
                <form method="post" action="file-actions.php" class="dossier-file-manager" id="dossier-file-manager">
                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                    <input type="hidden" name="project_id" value="<?= (int) $id ?>">
                    <div class="dossier-file-actions">
                        <label class="check">
                            <input type="checkbox" id="dossier-files-select-all">
                            Select all
                        </label>
                        <button type="submit" class="button button-small" name="file_action" value="reparse">
                            🔄 Reparse selected
                        </button>
                        <button
                            type="submit"
                            class="button ghost is-danger button-small"
                            name="file_action"
                            value="delete"
                            onclick="return confirm('Delete the selected files from this dossier? Parsed information already in the dossier will be retained.');"
                        >🗑️ Delete selected</button>
                    </div>
                    <p class="context-note">
                        Reparse ticket PDFs, the task packet JSON, or DDR JSON/text attachments to refresh dossier fields.
                        Deleting a file removes only the stored file; information already parsed into the dossier is retained.
                    </p>
                    <ul class="file-list file-list-manage">
                        <?php foreach ($files as $file): ?>
                            <?php
                            $fileName = (string) $file['original_name'];
                            $lowerFileName = strtolower($fileName);
                            $isReparsable = (string) $file['kind'] === 'packet'
                                || str_ends_with($lowerFileName, '.pdf')
                                || str_contains($lowerFileName, 'ddr');
                            ?>
                            <li>
                                <label class="dossier-file-select" title="Select <?= e($fileName) ?>">
                                    <input type="checkbox" name="file_ids[]" value="<?= (int) $file['id'] ?>">
                                    <span class="visually-hidden">Select</span>
                                </label>
                                <span class="<?= e(pillClassForKind((string) $file['kind'])) ?>"><?= kindEmoji((string) $file['kind']) ?> <?= e(kindLabel((string) $file['kind'])) ?></span>
                                <a href="download.php?project_id=<?= $id ?>&amp;file_id=<?= (int) $file['id'] ?>">
                                    ⬇️ <?= e($fileName) ?>
                                </a>
                                <span class="muted"><?= number_format((int) $file['size_bytes'] / 1024, 1) ?> KB</span>
                                <?php if ($isReparsable): ?>
                                    <span class="file-reparse-ready" title="This file can refresh dossier data">Reparsable</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <dialog class="field-editor-dialog" id="dossier-field-editor" aria-labelledby="field-editor-title">
        <form method="post" action="field-edit.php">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="project_id" value="<?= (int) $id ?>">
            <input type="hidden" name="field_path" id="field-editor-path" value="">
            <div class="field-editor-head">
                <div>
                    <p class="eyebrow">Correct parsed value</p>
                    <h2 id="field-editor-title">✏️ Edit field</h2>
                </div>
                <button type="button" class="field-editor-close" id="field-editor-cancel" aria-label="Cancel editing" title="Cancel">×</button>
            </div>
            <label class="field">
                <span id="field-editor-label">Dossier field</span>
                <textarea name="field_value" id="field-editor-value" maxlength="50000" rows="5"></textarea>
            </label>
            <p class="context-note">This changes the dossier copy only. Replacing or reparsing its source file may replace this correction.</p>
            <div class="field-editor-actions">
                <button type="button" class="button ghost" onclick="document.getElementById('field-editor-cancel').click()">Cancel</button>
                <button type="submit" class="button button-primary">💾 Save correction</button>
            </div>
        </form>
    </dialog>
    <?php require dirname(__DIR__) . '/includes/site-footer.php'; ?>
</div>
<script src="<?= e($auth->publicPrefix()) ?>assets/js/theme.js?v=<?= e(themeJsVersion()) ?>"></script>
<script src="<?= e($auth->publicPrefix()) ?>assets/js/fuzzy-search.js?v=<?= e(fuzzySearchJsVersion()) ?>"></script>
<script src="assets/js/floating-search.js?v=<?= e($floatingJsV) ?>"></script>
<script src="assets/js/app.js?v=<?= e($jsV) ?>"></script>
<script src="assets/js/servicenow-record-links.js?v=<?= e($jsV) ?>"></script>
<script src="assets/js/field-editor.js?v=<?= e($jsV) ?>"></script>
<script>
(() => {
    const all = document.getElementById('dossier-files-select-all');
    const form = document.getElementById('dossier-file-manager');
    if (!all || !form) return;
    const boxes = Array.from(form.querySelectorAll('input[name="file_ids[]"]'));
    all.addEventListener('change', () => boxes.forEach((box) => { box.checked = all.checked; }));
    boxes.forEach((box) => box.addEventListener('change', () => {
        all.checked = boxes.length > 0 && boxes.every((item) => item.checked);
        all.indeterminate = !all.checked && boxes.some((item) => item.checked);
    }));
})();
</script>
</body>
</html>
