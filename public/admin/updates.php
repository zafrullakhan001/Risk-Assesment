<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use RiskAssessment\GitHubUpdater;

$currentUser = $auth->requireAdmin();
$updater = new GitHubUpdater(
    $settings,
    $crypto,
    dirname(__DIR__, 2),
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'updater.lock'
);

$error = '';
$flash = '';
$checkResult = null;

if (!empty($_SESSION['updater_flash']) && is_string($_SESSION['updater_flash'])) {
    $flash = $_SESSION['updater_flash'];
    unset($_SESSION['updater_flash']);
}
if (!empty($_SESSION['updater_error']) && is_string($_SESSION['updater_error'])) {
    $error = $_SESSION['updater_error'];
    unset($_SESSION['updater_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            $payload = [
                'updater_repo' => (string) ($_POST['updater_repo'] ?? ''),
                'updater_track_branch' => (string) ($_POST['updater_track_branch'] ?? ''),
            ];
            $token = trim((string) ($_POST['updater_github_token'] ?? ''));
            if ($token !== '') {
                $payload['updater_github_token'] = $token;
            }
            $updater->saveSettings($payload);
            $auth->users()->logAudit(
                'settings.updater',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['repo' => $payload['updater_repo']]
            );
            $flash = 'Updater settings saved.';
            $checkResult = $updater->check(true);
        } elseif ($action === 'clear_token') {
            $updater->clearToken();
            $auth->users()->logAudit('settings.updater_token_cleared', (int) $currentUser['id'], (string) $currentUser['username']);
            $flash = 'GitHub token removed.';
        } elseif ($action === 'check') {
            $checkResult = $updater->check(true);
            $flash = ((int) $checkResult['aheadBy']) > 0
                ? $checkResult['aheadBy'] . ' update' . ($checkResult['aheadBy'] === 1 ? '' : 's') . ' available.'
                : 'This install is up to date with GitHub.';
        } elseif ($action === 'apply') {
            @set_time_limit(0);
            ignore_user_abort(true);
            @ini_set('memory_limit', '512M');
            @ini_set('display_errors', '0');
            $ref = trim((string) ($_POST['target_ref'] ?? ''));
            $applied = $updater->apply($ref);
            try {
                $auth->users()->logAudit(
                    'updater.apply',
                    (int) $currentUser['id'],
                    (string) $currentUser['username'],
                    null,
                    null,
                    ['ref' => $ref]
                );
            } catch (Throwable) {
                // Update already applied; audit logging is best-effort.
            }
            $flash = (string) $applied['message'];
            $checkResult = $updater->check(false);
            if ((int) $checkResult['aheadBy'] <= 0) {
                $flash .= ' This install is up to date with GitHub.';
            } else {
                $flash .= ' ' . $checkResult['aheadBy'] . ' further update'
                    . ($checkResult['aheadBy'] === 1 ? '' : 's')
                    . ' remain after this apply.';
            }
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$logged = $updater->consumeLastLog();
if ($error === '' && $logged !== '') {
    $error = $logged;
}

$status = $updater->status();
$patUrl = GitHubUpdater::PAT_CREATE_URL;
$patManageUrl = GitHubUpdater::PAT_MANAGE_URL;
$checkMode = is_array($checkResult) ? (string) ($checkResult['mode'] ?? 'releases') : 'releases';
$checkBranch = is_array($checkResult) && ($checkResult['branch'] ?? '') !== ''
    ? (string) $checkResult['branch']
    : $status['branch'];
$installedLabel = $status['installedTag'] !== ''
    ? $status['installedTag']
    : ($status['installedVersion'] !== '' ? $status['installedVersion'] : ($status['installedShort'] !== '' ? $status['installedShort'] : 'unknown'));

$adminTitle = 'App updates';
$adminTab = 'updates';
$adminEyebrow = 'GitHub updater';
$adminHeading = 'Update this install from <em>GitHub</em>';
$adminIntro = 'Check ' . $status['repo'] . ' for newer Releases and commits. Git is not required on this server.';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card pat-help" id="enable-pat">
                <h2>Enable a GitHub Personal Access Token</h2>
                <p>Private repos need a <strong>classic PAT</strong> with the <code>repo</code> scope. A token also avoids GitHub API rate limits on public repos.</p>
                <ol class="format-list pat-steps">
                    <li>
                        Open
                        <a href="<?= e($patUrl) ?>" target="_blank" rel="noopener noreferrer">Create a classic PAT</a>
                        while signed in to GitHub
                        (<code>github.com/settings/tokens/new?scopes=repo</code>).
                    </li>
                    <li>Note: <strong>Risk Assessment Updater</strong> (already filled by the link).</li>
                    <li>Expiration: 90 days or longer.</li>
                    <li>Scopes: leave <strong>repo</strong> checked (full control of private repositories).</li>
                    <li>Generate the token, copy it (starts with <code>ghp_</code>), paste it below, then Save settings.</li>
                </ol>
                <p class="pat-links">
                    <a class="button button-primary" href="<?= e($patUrl) ?>" target="_blank" rel="noopener noreferrer">Create classic PAT (repo scope)</a>
                    <a class="button ghost" href="<?= e($patManageUrl) ?>" target="_blank" rel="noopener noreferrer">Manage existing tokens</a>
                </p>
                <p class="pat-fineprint">Fine-grained tokens also work if they grant this repository <strong>Contents: Read</strong> and <strong>Metadata: Read</strong>.</p>
            </section>

            <section class="upload-card">
                <h2>Installed status</h2>
                <div class="updater-status">
                    <div><span>Repository</span><strong><?= e($status['repo']) ?></strong></div>
                    <div><span>Track branch</span><strong><?= e($status['branch']) ?></strong></div>
                    <?php if ($status['currentBranch'] !== ''): ?>
                        <div><span>Local branch</span><strong><?= e($status['currentBranch']) ?></strong></div>
                    <?php endif; ?>
                    <div><span>Installed version</span><strong><?= e($installedLabel) ?></strong></div>
                    <div>
                        <span>GitHub token</span>
                        <strong class="<?= $status['hasToken'] ? 'token-ok' : 'token-needed' ?>">
                            <?= $status['hasToken'] ? 'saved' : 'token needed' ?>
                        </strong>
                    </div>
                    <div>
                        <span>Apply method</span>
                        <strong class="<?= $status['zipAvailable'] && $status['curlAvailable'] ? 'token-ok' : 'token-needed' ?>">
                            <?= $status['zipAvailable'] && $status['curlAvailable'] ? 'GitHub zip' : 'missing PHP zip/curl' ?>
                        </strong>
                    </div>
                    <div><span>Git on server</span><strong><?= $status['gitAvailable'] ? 'available (optional)' : 'not required' ?></strong></div>
                </div>
                <?php if (!$status['zipAvailable']): ?>
                    <p class="updater-warning">PHP zip is not enabled. Turn on <code>extension=zip</code> in php.ini and restart Apache so updates can be extracted.</p>
                <?php endif; ?>
                <?php if (!$status['curlAvailable']): ?>
                    <p class="updater-warning">PHP cURL is not enabled. Turn on <code>extension=curl</code> in php.ini and restart Apache.</p>
                <?php endif; ?>
                <?php if ($status['dirty']): ?>
                    <p class="updater-warning">This working tree has local git changes. Applying a release zip overwrites application files (database and uploads stay).</p>
                <?php endif; ?>
                <?php if ($status['currentBranch'] !== '' && $status['currentBranch'] !== $status['branch']): ?>
                    <p class="empty-results">This folder is on <code><?= e($status['currentBranch']) ?></code>. Check for updates looks at that branch first, then <code><?= e($status['branch']) ?></code>. Save settings to track <code><?= e($status['currentBranch']) ?></code> if that should be the default.</p>
                <?php endif; ?>
                <?php if ($status['lastAppliedAt'] !== ''): ?>
                    <p class="empty-results">Last applied <?= e($status['lastAppliedAt']) ?>.</p>
                <?php endif; ?>
                <div class="updater-actions">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="check">
                        <button type="submit" class="button button-primary">Check for updates</button>
                    </form>
                </div>
            </section>

            <?php if (is_array($checkResult)): ?>
                <section class="upload-card">
                    <h2>Available updates</h2>
                    <?php if ((int) $checkResult['aheadBy'] <= 0): ?>
                        <p>This install matches the latest GitHub Release<?= $installedLabel !== 'unknown' ? ' (' . e($installedLabel) . ')' : '' ?> and has no new commits on <code><?= e($checkBranch) ?></code>.</p>
                    <?php else: ?>
                        <?php if ($checkMode === 'releases'): ?>
                            <p><?= (int) $checkResult['aheadBy'] ?> newer GitHub Release<?= (int) $checkResult['aheadBy'] === 1 ? '' : 's' ?> available. The packaged <code>RiskRegister-*.zip</code> asset is used when present.</p>
                        <?php elseif ($checkMode === 'mixed'): ?>
                            <p><?= (int) $checkResult['aheadBy'] ?> update<?= (int) $checkResult['aheadBy'] === 1 ? '' : 's' ?> available (newer Releases and commits on <code><?= e($checkBranch) ?></code>).</p>
                        <?php else: ?>
                            <p><?= (int) $checkResult['aheadBy'] ?> commit<?= (int) $checkResult['aheadBy'] === 1 ? '' : 's' ?> ahead on <code><?= e($checkBranch) ?></code> since <?= e($installedLabel) ?>. Applying downloads the GitHub source zip for that commit.</p>
                        <?php endif; ?>
                        <form method="post" class="updater-apply" onsubmit="return confirm('Apply this GitHub update now? Keep this tab open until it finishes. The database, uploads, and branding stay in place.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="apply">
                            <div class="commit-list">
                                <?php foreach ($checkResult['commits'] as $index => $commit): ?>
                                    <label class="commit-row">
                                        <input
                                            type="radio"
                                            name="target_ref"
                                            value="<?= e((string) $commit['sha']) ?>"
                                            <?= $index === 0 ? 'checked' : '' ?>
                                        >
                                        <span class="commit-sha"><?= e((string) $commit['short']) ?></span>
                                        <span class="commit-msg"><?= e((string) $commit['message']) ?></span>
                                        <span class="commit-meta"><?= e((string) (($commit['kind'] ?? '') === 'release' ? 'Release' : $commit['author'])) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="button button-primary">Download and apply</button>
                            <p class="pat-fineprint">The page stays on this screen and then shows success or the error. Wait for it to finish; do not close the tab.</p>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="upload-card">
                <h2>Publish a release zip</h2>
                <p>GitHub’s automatic “Source code (zip)” does not include <code>vendor/</code> and is not a git checkout. Package this app first, then attach the zip to the GitHub Release:</p>
                <pre class="updater-code">php bin/package_release.php v1.1.0</pre>
                <p class="pat-fineprint">That writes <code>dist/RiskRegister-v1.1.0.zip</code>. Upload it as a release asset. Creating a GitHub Release also runs the packaging workflow, which attaches the same zip automatically.</p>
            </section>

            <section class="upload-card">
                <h2>Updater settings</h2>
                <p>Leave the token field blank to keep the saved PAT. Token is encrypted at rest and never shown again.</p>
                <form id="updater-settings-form" method="post" class="updater-form updater-form-stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_settings">
                    <label class="file-input">
                        <span>GitHub repo (owner/name)</span>
                        <input type="text" name="updater_repo" value="<?= e($status['repo']) ?>" placeholder="zafrullakhan001/Risk-Assesment" required>
                    </label>
                    <label class="file-input">
                        <span>Track branch (commits after the latest Release)</span>
                        <input type="text" name="updater_track_branch" value="<?= e($status['branch']) ?>" placeholder="main" required>
                    </label>
                    <label class="file-input">
                        <span>GitHub token<?= $status['hasToken'] ? ' (leave blank to keep)' : '' ?></span>
                        <input
                            type="password"
                            name="updater_github_token"
                            autocomplete="off"
                            placeholder="<?= $status['hasToken'] ? '•••••••• (saved)' : 'ghp_…' ?>"
                        >
                    </label>
                </form>
                <div class="updater-actions">
                    <button type="submit" form="updater-settings-form" class="button button-primary">Save settings</button>
                    <?php if ($status['hasToken']): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('Remove the saved GitHub token?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="clear_token">
                            <button type="submit" class="button ghost">Remove token</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
