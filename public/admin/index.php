<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'change_password') {
            $auth->changePassword(
                (int) $currentUser['id'],
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_password_confirm'] ?? '')
            );
            $flash = 'Your password was updated.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$userCount = $auth->users()->count();
$adminCount = $auth->users()->countAdmins();
$pending = 0;
foreach ($auth->users()->listAll() as $user) {
    if (!$user['is_approved'] && !$user['is_disabled']) {
        $pending++;
    }
}

$adminTitle = 'Admin';
$adminTab = 'home';
$adminEyebrow = 'Control room';
$adminHeading = 'Install <em>administration</em>';
$adminIntro = 'User access, branding, email (SMTP), local/LDAP sign-in, SQLite backups, and GitHub updates live here.';
$smtpEnabled = $settings->get('smtp_enabled', '0') === '1';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="admin-grid">
                <a class="upload-card admin-tile" href="users.php">
                    <h2>Users</h2>
                    <p>Approve, disable, and create local accounts. <?= (int) $userCount ?> user<?= $userCount === 1 ? '' : 's' ?>, <?= (int) $adminCount ?> admin<?= $adminCount === 1 ? '' : 's' ?>.</p>
                    <?php if ($pending > 0): ?>
                        <strong class="token-needed"><?= (int) $pending ?> pending approval</strong>
                    <?php endif; ?>
                </a>
                <a class="upload-card admin-tile" href="authentication.php">
                    <h2>Authentication</h2>
                    <p>Turn local and LDAP sign-in on or off. Configure directory servers and test the bind.</p>
                    <div class="auth-source-row">
                        <span class="auth-badge <?= $auth->localEnabled() ? 'is-local' : 'is-off' ?>">Local <?= $auth->localEnabled() ? 'on' : 'off' ?></span>
                        <span class="auth-badge <?= $auth->ldapEnabled() ? 'is-ldap' : 'is-off' ?>">LDAP <?= $auth->ldapEnabled() ? 'on' : 'off' ?></span>
                    </div>
                </a>
                <a class="upload-card admin-tile" href="branding.php">
                    <h2>Branding</h2>
                    <p>Personalize the brand name, logo, home hero, footer text, and favicon.</p>
                </a>
                <a class="upload-card admin-tile" href="email.php">
                    <h2>✉️ Email</h2>
                    <p>Configure Custom or Office 365 SMTP, send a test message, and email public share links.</p>
                    <div class="auth-source-row">
                        <span class="auth-badge <?= $smtpEnabled ? 'is-local' : 'is-off' ?>">SMTP <?= $smtpEnabled ? 'on' : 'off' ?></span>
                    </div>
                </a>
                <a class="upload-card admin-tile" href="maintenance.php">
                    <h2>🗄️ SQLite</h2>
                    <p>Integrity check, VACUUM, and backup or restore from database snapshots.</p>
                </a>
                <a class="upload-card admin-tile" href="updates.php">
                    <h2>App updates</h2>
                    <p>Save a GitHub PAT, check <code>origin</code>, and apply commits to this install.</p>
                </a>
            </section>

            <?php if (\RiskAssessment\Auth::usesDefaultPassword($currentUser)): ?>
                <div class="updater-warning">This install still uses the default <code>admin</code> / <code>admin123</code> password. Change it below before exposing the app on a network.</div>
            <?php endif; ?>

            <?php if (($currentUser['auth_source'] ?? '') === 'local'): ?>
                <section class="upload-card">
                    <h2>Your password</h2>
                    <p>Signed in as <strong><?= e((string) $currentUser['username']) ?></strong>. Directory users change passwords in LDAP.</p>
                    <form method="post" class="updater-form updater-form-stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_password">
                        <label class="file-input">
                            <span>Current password</span>
                            <input type="password" name="current_password" required autocomplete="current-password">
                        </label>
                        <label class="file-input">
                            <span>New password</span>
                            <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                        </label>
                        <label class="file-input">
                            <span>Confirm new password</span>
                            <input type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password">
                        </label>
                        <button type="submit" class="button button-primary">Update password</button>
                    </form>
                </section>
            <?php endif; ?>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
