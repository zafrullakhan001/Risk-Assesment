<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        flashSet('error', 'Invalid security token.');
        redirect('index.php#find-projects');
    }
    $deleteId = (int) $_POST['delete_id'];
    if ($deleteId > 0) {
        try {
            ProjectRepository::delete($deleteId);
            flashSet('success', 'Project deleted.');
        } catch (Throwable $e) {
            flashSet('error', 'Could not delete project: ' . $e->getMessage());
        }
    }
    redirect('index.php#find-projects');
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchPage = max(1, (int) ($_GET['page'] ?? 1));
$allowedPerPage = [10, 25, 50, 100];
$searchPerPage = PaginationPreference::resolve(
    PaginationPreference::KEY_PROJECTS,
    isset($_GET['per']) ? (int) $_GET['per'] : null,
    10,
    $allowedPerPage
);
$allowedSorts = ['id', 'project', 'vendor', 'demand', 'story', 'task', 'ddr', 'updated'];
$searchSort = strtolower(trim((string) ($_GET['sort'] ?? 'updated')));
if (!in_array($searchSort, $allowedSorts, true)) {
    $searchSort = 'updated';
}
$searchDir = strtolower(trim((string) ($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
$searchFilters = [
    'id' => trim((string) ($_GET['f_id'] ?? '')),
    'project' => trim((string) ($_GET['f_project'] ?? '')),
    'vendor' => trim((string) ($_GET['f_vendor'] ?? '')),
    'demand' => trim((string) ($_GET['f_demand'] ?? '')),
    'story' => trim((string) ($_GET['f_story'] ?? '')),
    'task' => trim((string) ($_GET['f_task'] ?? '')),
    'ddr' => trim((string) ($_GET['f_ddr'] ?? '')),
    'updated' => trim((string) ($_GET['f_updated'] ?? '')),
];
$activeFilters = array_filter($searchFilters, static fn (string $value): bool => $value !== '');
$searchTotal = ProjectRepository::countSearch($searchQuery, $searchFilters);
$searchTotalPages = max(1, (int) ceil($searchTotal / $searchPerPage));
if ($searchPage > $searchTotalPages) {
    $searchPage = $searchTotalPages;
}
$projects = ProjectRepository::search(
    $searchQuery,
    $searchPage,
    $searchPerPage,
    $searchSort,
    $searchDir,
    $searchFilters
);
$searchFrom = $searchTotal === 0 ? 0 : (($searchPage - 1) * $searchPerPage) + 1;
$searchTo = min($searchTotal, $searchPage * $searchPerPage);

$projectListQueryParams = static function (
    array $overrides = []
) use (
    $searchQuery,
    $searchPage,
    $searchPerPage,
    $searchSort,
    $searchDir,
    $searchFilters
): array {
    $params = [
        'q' => $searchQuery,
        'page' => $searchPage,
        'per' => $searchPerPage,
        'sort' => $searchSort,
        'dir' => $searchDir,
    ];
    foreach ($searchFilters as $key => $value) {
        if ($value !== '') {
            $params['f_' . $key] = $value;
        }
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    if (($params['q'] ?? '') === '') {
        unset($params['q']);
    }
    if ((int) ($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }
    if ((int) ($params['per'] ?? 10) === 10) {
        unset($params['per']);
    }
    if (($params['sort'] ?? 'updated') === 'updated' && ($params['dir'] ?? 'desc') === 'desc') {
        unset($params['sort'], $params['dir']);
    }

    return $params;
};

$projectListUrl = static function (array $overrides = []) use ($projectListQueryParams): string {
    $params = $projectListQueryParams($overrides);
    $query = http_build_query($params);

    return 'index.php' . ($query !== '' ? '?' . $query : '') . '#find-projects';
};

$sortHeaderUrl = static function (string $column) use ($searchSort, $searchDir, $projectListUrl): string {
    $nextDir = ($searchSort === $column && $searchDir === 'asc') ? 'desc' : 'asc';
    if ($searchSort !== $column) {
        $nextDir = in_array($column, ['updated', 'id'], true) ? 'desc' : 'asc';
    }

    return $projectListUrl([
        'sort' => $column,
        'dir' => $nextDir,
        'page' => 1,
    ]);
};

$sortAria = static function (string $column) use ($searchSort, $searchDir): string {
    if ($searchSort !== $column) {
        return 'none';
    }

    return $searchDir === 'asc' ? 'ascending' : 'descending';
};

$sortClass = static function (string $column) use ($searchSort, $searchDir): string {
    if ($searchSort !== $column) {
        return 'is-sortable';
    }

    return 'is-sortable is-sorted is-sorted-' . $searchDir;
};

$flash = flashTake();
$token = csrfToken();
$cssV = cssVersion();
$jsV = jsVersion();

/**
 * @param array<string, mixed> $project
 * @return array{sources: array<string, bool>, present: int}
 */
$projectSourcesMeta = static function (array $project): array {
    $sources = json_decode((string) ($project['sources_json'] ?? ''), true);
    if (!is_array($sources)) {
        $sources = [];
    }
    $present = 0;
    foreach (TD_SOURCE_KINDS as $kind) {
        if (!empty($sources[$kind])) {
            $present++;
        }
    }

    return ['sources' => $sources, 'present' => $present];
};
?>
<!DOCTYPE html>
<html lang="en" data-theme="teal">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Dossier · <?= e($branding->documentTitle()) ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <?php require dirname(__DIR__) . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= e($cssV) ?>">
    <script>
    (function () {
        try {
            var view = window.localStorage.getItem('td-project-list-view-v1') || 'table';
            if (view === 'list') {
                view = 'strip';
            }
            if (view !== 'table' && view !== 'strip' && view !== 'cards') {
                view = 'table';
            }
            document.documentElement.setAttribute('data-project-list-view', view);
        } catch (error) {
            document.documentElement.setAttribute('data-project-list-view', 'table');
        }
    })();
    </script>
</head>
<body>
<div class="shell">
    <header class="topbar topbar-uplift">
        <a class="brand brand-link" href="../index.php#find-projects">
            <?php require dirname(__DIR__) . '/includes/brand-mark.php'; ?>
            <div class="brand-text">
                <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                <h1>Ticket Dossier</h1>
            </div>
        </a>
        <div class="topbar-actions">
            <?php require __DIR__ . '/includes/app-nav.php'; ?>
        </div>
    </header>

    <main>
        <section class="hero hero-compact">
            <div class="hero-main">
                <div class="hero-intro">
                    <p class="eyebrow">ServiceNow packet viewer</p>
                    <h2>Build a dossier from <em>any</em> export set</h2>
                    <p>Upload Demand, Story, Task, and/or DDR files. Missing pieces are fine — chapters appear only when data is present.</p>
                </div>
            </div>
        </section>

        <?php if ($flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>">
                <?= $flash['type'] === 'success' ? '✅ ' : ($flash['type'] === 'error' ? '⚠️ ' : 'ℹ️ ') ?>
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <section class="upload-card">
            <h2>🚀 New project</h2>
            <p class="context-note">Drop or choose any subset of Demand / Story / Task PDFs and DDR JSON. Types are detected from <strong>file contents</strong> first, then filename.</p>
            <form class="upload-form" id="upload-form" action="upload.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" id="csrf-token" value="<?= e($token) ?>">
                <label class="field">
                    <span>Project title <em>(optional)</em></span>
                    <input type="text" name="title" maxlength="200" placeholder="Leave blank to auto-detect from files">
                </label>

                <div class="dropzone" id="dropzone" tabindex="0" role="button" aria-label="Drop ServiceNow export files here">
                    <input type="file" id="file-input" name="files[]" accept=".pdf,.json,application/pdf,application/json" multiple hidden>
                    <div class="dropzone-inner">
                        <div class="dropzone-icon" aria-hidden="true">📂</div>
                        <p class="dropzone-title">Drag &amp; drop files here</p>
                        <p class="dropzone-hint">or <button type="button" class="linkish" id="browse-files">browse</button> · PDF / JSON · up to 10 files</p>
                    </div>
                </div>

                <div id="detect-status" class="detect-status hidden" aria-live="polite"></div>
                <ul id="file-preview" class="file-preview" aria-live="polite"></ul>

                <button type="submit" class="button button-primary" id="submit-upload" disabled>✨ Create dossier</button>
            </form>
        </section>

        <section class="panel" id="find-projects">
            <div class="section-head">
                <h2>📁 Projects</h2>
                <span class="pill gray"><?= (int) $searchTotal ?> total</span>
            </div>

            <form method="get" class="search-form" action="index.php#find-projects">
                <?php if ($searchPerPage !== 10): ?>
                    <input type="hidden" name="per" value="<?= (int) $searchPerPage ?>">
                <?php endif; ?>
                <?php if ($searchSort !== 'updated' || $searchDir !== 'desc'): ?>
                    <input type="hidden" name="sort" value="<?= e($searchSort) ?>">
                    <input type="hidden" name="dir" value="<?= e($searchDir) ?>">
                <?php endif; ?>
                <?php foreach ($searchFilters as $filterKey => $filterValue): ?>
                    <?php if ($filterValue !== ''): ?>
                        <input type="hidden" name="f_<?= e($filterKey) ?>" value="<?= e($filterValue) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="search-wrap search-wrap-wide">
                    <span>Find</span>
                    <input
                        type="search"
                        name="q"
                        value="<?= e($searchQuery) ?>"
                        placeholder="Title, vendor, demand, story, task, DDR…"
                        aria-label="Search projects"
                    >
                </div>
                <button type="submit" class="button button-primary">Find project</button>
            </form>

            <?php if ($searchTotal === 0 && $searchQuery === '' && $activeFilters === []): ?>
                <div class="empty-state">
                    <span class="empty-icon" aria-hidden="true">📭</span>
                    <h3>No projects yet</h3>
                    <p>Upload at least one ServiceNow export file to create your first dossier.</p>
                </div>
            <?php else: ?>
                <div class="project-list-toolbar">
                    <p class="search-result-meta">Showing <?= (int) $searchFrom ?>–<?= (int) $searchTo ?> of <?= (int) $searchTotal ?><?= $searchQuery !== '' ? ' matching “' . e($searchQuery) . '”' : '' ?><?= $activeFilters !== [] ? ' · filtered' : '' ?></p>
                    <div class="project-list-toolbar-actions">
                        <button
                            type="button"
                            class="button ghost project-filter-toggle<?= $activeFilters !== [] ? ' is-active' : '' ?>"
                            id="project-filter-toggle"
                            aria-controls="project-table-filters"
                            aria-expanded="<?= $activeFilters !== [] ? 'true' : 'false' ?>"
                        ><?= $activeFilters !== [] ? 'Hide filters' : 'Show filters' ?></button>
                        <div class="project-list-view-switcher" id="project-list-view-switcher" role="tablist" aria-label="Project list view">
                            <button type="button" class="project-list-view-btn" role="tab" aria-selected="false" data-project-view="cards">▦ Cards</button>
                            <button type="button" class="project-list-view-btn is-active" role="tab" aria-selected="true" data-project-view="table">⊞ Table</button>
                            <button type="button" class="project-list-view-btn" role="tab" aria-selected="false" data-project-view="strip">▬ Strip</button>
                        </div>
                    </div>
                </div>

                <div class="project-list" id="project-list" data-project-view="table">
                    <?php if ($projects === []): ?>
                        <p class="empty-results">No projects match<?= $searchQuery !== '' || $activeFilters !== [] ? ' these filters.' : '.' ?></p>
                    <?php endif; ?>
                    <?php foreach ($projects as $project): ?>
                        <?php
                        $meta = $projectSourcesMeta($project);
                        $sources = $meta['sources'];
                        $presentCount = $meta['present'];
                        ?>
                        <article class="project-card project-item">
                            <div class="card-top">
                                <h3><a href="project.php?id=<?= (int) $project['id'] ?>"><?= e((string) $project['title']) ?></a></h3>
                                <?php if ($project['vendor']): ?>
                                    <p class="vendor"><span aria-hidden="true">🏢</span> <?= e((string) $project['vendor']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="chip-row">
                                <?php foreach (['demand' => 'demand_number', 'story' => 'story_number', 'task' => 'task_number', 'ddr' => 'ddr_number'] as $kind => $col): ?>
                                    <?php if (!empty($project[$col])): ?>
                                        <span class="<?= e(pillClassForKind($kind)) ?>"><?= kindEmoji($kind) ?> <?= e((string) $project[$col]) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <div class="source-row">
                                <?php foreach (TD_SOURCE_KINDS as $kind): ?>
                                    <span class="source-pill <?= !empty($sources[$kind]) ? 'on' : 'off' ?>">
                                        <?= !empty($sources[$kind]) ? '✓' : '○' ?>
                                        <?= kindEmoji($kind) ?> <?= e(kindLabel($kind)) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <div class="card-meta">
                                <span>🗓️ Updated <?= e((string) $project['updated_at']) ?> UTC · <?= $presentCount ?>/4 sources</span>
                                <div class="card-actions">
                                    <a class="button button-primary button-small" href="project.php?id=<?= (int) $project['id'] ?>">Open →</a>
                                    <?php if ($presentCount < 4): ?>
                                        <a class="button ghost button-small" href="project.php?id=<?= (int) $project['id'] ?>#complete-dossier">🧩 Complete</a>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Delete this project?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                        <input type="hidden" name="delete_id" value="<?= (int) $project['id'] ?>">
                                        <button type="submit" class="button ghost is-danger button-small">🗑️ Delete</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="project-table-wrap table-scroll" id="project-table-wrap">
                    <form method="get" class="project-table-filter-form" action="index.php#find-projects">
                        <?php if ($searchQuery !== ''): ?>
                            <input type="hidden" name="q" value="<?= e($searchQuery) ?>">
                        <?php endif; ?>
                        <?php if ($searchPerPage !== 10): ?>
                            <input type="hidden" name="per" value="<?= (int) $searchPerPage ?>">
                        <?php endif; ?>
                        <input type="hidden" name="sort" value="<?= e($searchSort) ?>">
                        <input type="hidden" name="dir" value="<?= e($searchDir) ?>">
                        <table class="project-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="<?= e($sortClass('id')) ?>" aria-sort="<?= e($sortAria('id')) ?>">
                                        <a class="project-sort-link" href="<?= e($sortHeaderUrl('id')) ?>">ID</a>
                                    </th>
                                    <th scope="col" class="<?= e($sortClass('project')) ?>" aria-sort="<?= e($sortAria('project')) ?>">
                                        <a class="project-sort-link" href="<?= e($sortHeaderUrl('project')) ?>">Project</a>
                                    </th>
                                    <th scope="col" class="<?= e($sortClass('vendor')) ?>" aria-sort="<?= e($sortAria('vendor')) ?>">
                                        <a class="project-sort-link" href="<?= e($sortHeaderUrl('vendor')) ?>">Vendor</a>
                                    </th>
                                    <th scope="col" class="<?= e($sortClass('demand')) ?>" aria-sort="<?= e($sortAria('demand')) ?>">
                                        <a class="project-sort-link" href="<?= e($sortHeaderUrl('demand')) ?>">Demand</a>
                                    </th>
                                    <th scope="col" class="<?= e($sortClass('story')) ?>" aria-sort="<?= e($sortAria('story')) ?>">
                                        <a class="project-sort-link" href="<?= e($sortHeaderUrl('story')) ?>">Story</a>
                                    </th>
                                    <th scope="col" class="<?= e($sortClass('task')) ?>" aria-sort="<?= e($sortAria('task')) ?>">
                                        <a class="project-sort-link" href="<?= e($sortHeaderUrl('task')) ?>">Task</a>
                                    </th>
                                    <th scope="col" class="<?= e($sortClass('ddr')) ?>" aria-sort="<?= e($sortAria('ddr')) ?>">
                                        <a class="project-sort-link" href="<?= e($sortHeaderUrl('ddr')) ?>">DDR</a>
                                    </th>
                                    <th scope="col" class="<?= e($sortClass('updated')) ?>" aria-sort="<?= e($sortAria('updated')) ?>">
                                        <a class="project-sort-link" href="<?= e($sortHeaderUrl('updated')) ?>">Updated</a>
                                    </th>
                                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                                </tr>
                                <tr class="project-table-filters<?= $activeFilters === [] ? ' is-collapsed' : '' ?>" id="project-table-filters"<?= $activeFilters === [] ? ' hidden' : '' ?>>
                                    <th scope="col"><input type="search" name="f_id" value="<?= e($searchFilters['id']) ?>" placeholder="#" aria-label="Filter by ID"></th>
                                    <th scope="col"><input type="search" name="f_project" value="<?= e($searchFilters['project']) ?>" placeholder="Filter…" aria-label="Filter by project"></th>
                                    <th scope="col"><input type="search" name="f_vendor" value="<?= e($searchFilters['vendor']) ?>" placeholder="Filter…" aria-label="Filter by vendor"></th>
                                    <th scope="col"><input type="search" name="f_demand" value="<?= e($searchFilters['demand']) ?>" placeholder="Filter…" aria-label="Filter by demand"></th>
                                    <th scope="col"><input type="search" name="f_story" value="<?= e($searchFilters['story']) ?>" placeholder="Filter…" aria-label="Filter by story"></th>
                                    <th scope="col"><input type="search" name="f_task" value="<?= e($searchFilters['task']) ?>" placeholder="Filter…" aria-label="Filter by task"></th>
                                    <th scope="col"><input type="search" name="f_ddr" value="<?= e($searchFilters['ddr']) ?>" placeholder="Filter…" aria-label="Filter by DDR"></th>
                                    <th scope="col"><input type="search" name="f_updated" value="<?= e($searchFilters['updated']) ?>" placeholder="YYYY-MM-DD" aria-label="Filter by updated date"></th>
                                    <th scope="col" class="project-table-filter-actions">
                                        <button type="submit" class="button ghost project-filter-apply">Filter</button>
                                        <?php if ($activeFilters !== []): ?>
                                            <a class="button ghost project-filter-clear" href="<?= e($projectListUrl([
                                                'f_id' => null,
                                                'f_project' => null,
                                                'f_vendor' => null,
                                                'f_demand' => null,
                                                'f_story' => null,
                                                'f_task' => null,
                                                'f_ddr' => null,
                                                'f_updated' => null,
                                                'page' => 1,
                                            ])) ?>">Clear</a>
                                        <?php endif; ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($projects === []): ?>
                                    <tr class="project-table-empty">
                                        <td colspan="9">No projects match<?= $searchQuery !== '' || $activeFilters !== [] ? ' these filters.' : '.' ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($projects as $project): ?>
                                    <tr>
                                        <td class="project-table-id">#<?= (int) $project['id'] ?></td>
                                        <td class="project-table-name">
                                            <a href="project.php?id=<?= (int) $project['id'] ?>"><?= e((string) $project['title']) ?></a>
                                        </td>
                                        <td><?= e((string) (($project['vendor'] ?? '') !== '' ? $project['vendor'] : '—')) ?></td>
                                        <td class="project-table-ticket"><?= e((string) (($project['demand_number'] ?? '') !== '' ? $project['demand_number'] : '—')) ?></td>
                                        <td class="project-table-ticket"><?= e((string) (($project['story_number'] ?? '') !== '' ? $project['story_number'] : '—')) ?></td>
                                        <td class="project-table-ticket"><?= e((string) (($project['task_number'] ?? '') !== '' ? $project['task_number'] : '—')) ?></td>
                                        <td class="project-table-ticket"><?= e((string) (($project['ddr_number'] ?? '') !== '' ? $project['ddr_number'] : '—')) ?></td>
                                        <td class="project-table-date"><?= e((string) $project['updated_at']) ?></td>
                                        <td class="project-table-actions">
                                            <div class="project-table-action-row">
                                                <a class="button button-primary button-small" href="project.php?id=<?= (int) $project['id'] ?>">Open</a>
                                                <form method="post" class="project-delete-form" onsubmit="return confirm('Delete this project?');">
                                                    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                                                    <input type="hidden" name="delete_id" value="<?= (int) $project['id'] ?>">
                                                    <button type="submit" class="button ghost is-danger button-small" title="Delete project" aria-label="Delete project">🗑️</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>

                <?php if ($searchTotal > 0): ?>
                <nav class="pagination" aria-label="Project list pages">
                    <div class="pagination-controls">
                        <?php if ($searchTotalPages > 1): ?>
                            <?php if ($searchPage > 1): ?>
                                <a class="button ghost" href="<?= e($projectListUrl(['page' => $searchPage - 1])) ?>">← Previous</a>
                            <?php else: ?>
                                <span class="button ghost is-disabled" aria-disabled="true">← Previous</span>
                            <?php endif; ?>
                            <span class="pagination-pages">
                                <?php
                                $windowStart = max(1, $searchPage - 2);
                                $windowEnd = min($searchTotalPages, $searchPage + 2);
                                for ($pageNum = $windowStart; $pageNum <= $windowEnd; $pageNum++):
                                ?>
                                    <?php if ($pageNum === $searchPage): ?>
                                        <span class="pagination-page is-current" aria-current="page"><?= $pageNum ?></span>
                                    <?php else: ?>
                                        <a class="pagination-page" href="<?= e($projectListUrl(['page' => $pageNum])) ?>"><?= $pageNum ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </span>
                            <?php if ($searchPage < $searchTotalPages): ?>
                                <a class="button ghost" href="<?= e($projectListUrl(['page' => $searchPage + 1])) ?>">Next →</a>
                            <?php else: ?>
                                <span class="button ghost is-disabled" aria-disabled="true">Next →</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <form method="get" class="pagination-per-page" action="index.php#find-projects">
                        <?php if ($searchQuery !== ''): ?>
                            <input type="hidden" name="q" value="<?= e($searchQuery) ?>">
                        <?php endif; ?>
                        <?php if ($searchSort !== 'updated' || $searchDir !== 'desc'): ?>
                            <input type="hidden" name="sort" value="<?= e($searchSort) ?>">
                            <input type="hidden" name="dir" value="<?= e($searchDir) ?>">
                        <?php endif; ?>
                        <?php foreach ($searchFilters as $filterKey => $filterValue): ?>
                            <?php if ($filterValue !== ''): ?>
                                <input type="hidden" name="f_<?= e($filterKey) ?>" value="<?= e($filterValue) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <label>
                            <span>Rows per page</span>
                            <select name="per" onchange="this.form.submit()">
                                <?php foreach ($allowedPerPage as $size): ?>
                                    <option value="<?= (int) $size ?>"<?= $searchPerPage === $size ? ' selected' : '' ?>><?= (int) $size ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </form>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
    <?php require dirname(__DIR__) . '/includes/site-footer.php'; ?>
</div>
<script src="assets/js/app.js?v=<?= e($jsV) ?>"></script>
<script src="assets/js/project-list.js?v=<?= e($jsV) ?>"></script>
</body>
</html>
