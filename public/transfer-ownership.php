<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\AccessNotifier;
use RiskAssessment\Actor;
use RiskAssessment\Repositories\AssessmentAccessRepository;
use RiskAssessment\Repositories\UserNotificationRepository;
use RiskAssessment\Mail\SmtpSettings;

$currentUser = $auth->requireAuth();
$accessRepository = new AssessmentAccessRepository($pdo);
$currentUserId = (int) ($currentUser['id'] ?? 0);

$error = '';
$flash = '';
$ownedProjects = $accessRepository->listOwnedProjects($currentUserId);
$recipients = [];
foreach ($auth->users()->listApprovedActive() as $candidate) {
    $candidateId = (int) ($candidate['id'] ?? 0);
    if ($candidateId <= 0 || $candidateId === $currentUserId) {
        continue;
    }
    $recipients[] = $candidate;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action !== 'transfer_owned_projects') {
            throw new RuntimeException('Invalid form submission.');
        }

        $newOwnerId = filter_var($_POST['new_owner_user_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $keepFormer = !isset($_POST['keep_former_editor']) || (string) $_POST['keep_former_editor'] === '1';
        $mode = (string) ($_POST['transfer_mode'] ?? 'selected');
        $selectedIds = null;
        if ($mode !== 'all') {
            $rawIds = $_POST['project_ids'] ?? [];
            if (!is_array($rawIds)) {
                $rawIds = [];
            }
            $selectedIds = [];
            foreach ($rawIds as $rawId) {
                $id = filter_var($rawId, FILTER_VALIDATE_INT) ?: 0;
                if ($id > 0) {
                    $selectedIds[] = $id;
                }
            }
        }

        $namesBefore = [];
        $firstId = 0;
        $selectedLookup = null;
        if ($selectedIds !== null) {
            $selectedLookup = [];
            foreach ($selectedIds as $id) {
                $selectedLookup[(int) $id] = true;
            }
        }
        foreach ($ownedProjects as $project) {
            $id = (int) ($project['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($selectedLookup !== null && !isset($selectedLookup[$id])) {
                continue;
            }
            $name = trim((string) ($project['solution_name'] ?? ''));
            if ($name !== '') {
                $namesBefore[] = $name;
            }
            if ($firstId <= 0) {
                $firstId = $id;
            }
        }

        $result = $accessRepository->transferOwnedProjects(
            $currentUserId,
            ['id' => $newOwnerId],
            $selectedIds,
            $keepFormer
        );

        $projectCount = (int) ($result['projects'] ?? 0);
        $versionCount = (int) ($result['versions'] ?? 0);

        $summary = $projectCount === 1
            ? ($namesBefore[0] ?? '1 project')
            : $projectCount . ' projects';
        $notifier = new AccessNotifier(
            new UserNotificationRepository($pdo),
            $auth->users(),
            new SmtpSettings($settings, $crypto),
            $branding
        );
        $notifyResult = $notifier->notifyOwnershipTransfer(
            $projectCount === 1 ? $firstId : 0,
            $summary,
            $currentUser,
            $newOwnerId,
            $namesBefore,
            $projectCount
        );

        $mailQs = '';
        if ($notifyResult['email_sent']) {
            $mailQs = '&mail=1';
        } elseif ($notifyResult['email_attempted']) {
            $mailQs = '&mail=0';
        }

        header('Location: transfer-ownership.php?ok=1&projects=' . $projectCount . '&versions=' . $versionCount . $mailQs);
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $ownedProjects = $accessRepository->listOwnedProjects($currentUserId);
    }
}

if (isset($_GET['ok']) && $flash === '') {
    $projectCount = max(0, (int) ($_GET['projects'] ?? 0));
    $versionCount = max(0, (int) ($_GET['versions'] ?? 0));
    $flash = $projectCount === 1
        ? 'Ownership of 1 project transferred (' . $versionCount . ' version' . ($versionCount === 1 ? '' : 's') . ').'
        : 'Ownership of ' . $projectCount . ' projects transferred (' . $versionCount . ' versions).';
    if ((string) ($_GET['mail'] ?? '') === '1') {
        $flash .= ' Email sent to you and the new owner.';
    } elseif ((string) ($_GET['mail'] ?? '') === '0') {
        $flash .= ' Email could not be sent.';
    }
    $ownedProjects = $accessRepository->listOwnedProjects($currentUserId);
}

$ownerLabel = Actor::formatLabel(
    trim((string) ($currentUser['display_name'] ?? '')),
    trim((string) ($currentUser['username'] ?? '')),
    trim((string) ($currentUser['auth_source'] ?? ''))
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer ownership · <?= e($branding->documentTitle()) ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="shell upload-page transfer-ownership-page">
        <header class="topbar topbar-uplift">
            <a class="brand brand-link" href="index.php#find-projects" title="Find projects by name">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1>Transfer ownership</h1>
                </div>
            </a>
            <div class="topbar-actions">
                <?php require __DIR__ . '/includes/topbar-menu-start.php'; ?>
                <?php require __DIR__ . '/includes/app-nav-links.php'; ?>
                <?php require __DIR__ . '/includes/updates-nav.php'; ?>
                <?php require __DIR__ . '/includes/topbar-menu-end.php'; ?>
            </div>
        </header>

        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">Leaving the team</div>
                            <h2>Hand off your risk projects</h2>
                            <p>Transfer ownership of selected projects—or everything you own—to another approved user. They become the rightful owner of those projects and all saved versions.</p>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>
            <?php if ($flash !== ''): ?>
                <div class="alert alert-success"><?= e($flash) ?></div>
            <?php endif; ?>

            <section class="upload-card transfer-ownership-card">
                <div class="card-heading">
                    <div>
                        <div class="eyebrow">Signed in as</div>
                        <h2><?= e($ownerLabel) ?></h2>
                    </div>
                    <span class="result-count result-count-badge"><?= count($ownedProjects) ?> project<?= count($ownedProjects) === 1 ? '' : 's' ?></span>
                </div>

                <?php if ($ownedProjects === []): ?>
                    <p class="empty-panel">You do not own any projects right now. When you create or receive ownership of projects, they will appear here.</p>
                    <p><a class="button button-primary" href="index.php#find-projects">Back to Find projects</a></p>
                <?php elseif ($recipients === []): ?>
                    <p class="empty-panel">There are no other approved users available to receive ownership.</p>
                <?php else: ?>
                    <form method="post" class="transfer-ownership-form" id="transfer-ownership-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="transfer_owned_projects">

                        <div class="transfer-ownership-controls">
                            <label>
                                <span>New owner</span>
                                <select name="new_owner_user_id" required>
                                    <option value="">Choose a user…</option>
                                    <?php foreach ($recipients as $user): ?>
                                        <?php
                                        $label = Actor::formatLabel(
                                            trim((string) ($user['display_name'] ?? '')),
                                            trim((string) ($user['username'] ?? '')),
                                            trim((string) ($user['auth_source'] ?? ''))
                                        );
                                        ?>
                                        <option value="<?= (int) $user['id'] ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <fieldset class="transfer-ownership-mode">
                                <legend>What to transfer</legend>
                                <label class="transfer-mode-option">
                                    <input type="radio" name="transfer_mode" value="selected" checked data-transfer-mode="selected">
                                    <span>Selected projects only</span>
                                </label>
                                <label class="transfer-mode-option">
                                    <input type="radio" name="transfer_mode" value="all" data-transfer-mode="all">
                                    <span>All <?= count($ownedProjects) ?> of my projects</span>
                                </label>
                            </fieldset>

                            <label class="project-access-keep-editor">
                                <input type="checkbox" name="keep_former_editor" value="1" checked>
                                <span>Keep me as an editor on transferred projects</span>
                            </label>
                        </div>

                        <div class="transfer-ownership-toolbar">
                            <button type="button" class="button ghost" id="transfer-select-all">Select all</button>
                            <button type="button" class="button ghost" id="transfer-select-none">Clear</button>
                            <input
                                type="search"
                                id="transfer-project-search"
                                class="shared-access-search"
                                placeholder="Search by project name or vendor…"
                                autocomplete="off"
                            >
                        </div>

                        <ul class="transfer-ownership-list" id="transfer-ownership-list">
                            <?php foreach ($ownedProjects as $project): ?>
                                <?php
                                $projectId = (int) ($project['id'] ?? 0);
                                $name = (string) ($project['solution_name'] ?? 'Untitled project');
                                $vendor = (string) ($project['vendor'] ?? '');
                                $versions = (int) ($project['version_count'] ?? 1);
                                $locked = ((int) ($project['is_locked'] ?? 0)) === 1;
                                $searchBlob = mb_strtolower(trim($name . ' ' . $vendor . ' #' . $projectId));
                                ?>
                                <li class="transfer-ownership-item" data-search="<?= e($searchBlob) ?>">
                                    <label>
                                        <input type="checkbox" name="project_ids[]" value="<?= $projectId ?>" class="transfer-project-check" checked>
                                        <span class="transfer-ownership-item-body">
                                            <strong>
                                                <?= e($name) ?>
                                                <?php if ($locked): ?>
                                                    <span class="project-lock-badge" title="Locked">🔒</span>
                                                <?php endif; ?>
                                            </strong>
                                            <span>
                                                #<?= $projectId ?>
                                                · <?= $versions ?> version<?= $versions === 1 ? '' : 's' ?>
                                                <?php if ($vendor !== ''): ?>
                                                    · <?= e($vendor) ?>
                                                <?php endif; ?>
                                                <?php if (($project['uploaded_at'] ?? '') !== ''): ?>
                                                    · latest <?= e((string) $project['uploaded_at']) ?>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="shared-access-empty" id="transfer-search-empty" hidden>No owned projects match that search.</p>

                        <div class="transfer-ownership-actions">
                            <button type="submit" class="button button-primary" id="transfer-submit-btn">Transfer ownership</button>
                            <a class="button ghost" href="index.php#find-projects">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="assets/js/transfer-ownership.js?v=<?= filemtime(__DIR__ . '/assets/js/transfer-ownership.js') ?>" defer></script>
</body>
</html>
