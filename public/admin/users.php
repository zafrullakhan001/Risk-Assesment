<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$usersRepo = $auth->users();
$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target = $targetId > 0 ? $usersRepo->findById($targetId) : null;

        if ($action === 'create_user') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirm'] ?? '');
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $makeAdmin = !empty($_POST['is_admin']);
            if (strlen($username) < 3) {
                throw new RuntimeException('Username must be at least 3 characters.');
            }
            $isLocalhost = str_ends_with(strtolower($email), '@localhost');
            if (!$isLocalhost && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Enter a valid email address.');
            }
            if ($password !== $confirm) {
                throw new RuntimeException('Password confirmation does not match.');
            }
            $strength = \RiskAssessment\Auth::validatePasswordStrength($password);
            if ($strength !== null) {
                throw new RuntimeException($strength);
            }
            if ($usersRepo->usernameExists($username)) {
                throw new RuntimeException('That username is already taken.');
            }
            if ($usersRepo->emailExists($email)) {
                throw new RuntimeException('That email is already registered.');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Unable to create the user.');
            }
            $id = $usersRepo->createLocal($username, $email, $hash, $makeAdmin, true, $displayName);
            $usersRepo->logAudit(
                'user.created',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                $id,
                $username,
                ['admin' => $makeAdmin]
            );
            $flash = 'Local user created and approved.';
        } elseif ($target === null) {
            throw new RuntimeException('Select a valid user.');
        } elseif ($action === 'approve') {
            $usersRepo->setApproved($targetId, true);
            $usersRepo->logAudit('user.approved', (int) $currentUser['id'], (string) $currentUser['username'], $targetId, (string) $target['username']);
            $flash = 'User approved.';
        } elseif ($action === 'reject') {
            if (!empty($target['is_admin']) && $usersRepo->countAdmins() <= 1) {
                throw new RuntimeException('Cannot reject the last administrator.');
            }
            $usersRepo->setApproved($targetId, false);
            $usersRepo->logAudit('user.unapproved', (int) $currentUser['id'], (string) $currentUser['username'], $targetId, (string) $target['username']);
            $flash = 'User set back to pending.';
        } elseif ($action === 'disable') {
            if ((int) $target['id'] === (int) $currentUser['id']) {
                throw new RuntimeException('You cannot disable your own account.');
            }
            if (!empty($target['is_admin']) && $usersRepo->countAdmins() <= 1) {
                throw new RuntimeException('Cannot disable the last administrator.');
            }
            $usersRepo->setDisabled($targetId, true);
            $usersRepo->logAudit('user.disabled', (int) $currentUser['id'], (string) $currentUser['username'], $targetId, (string) $target['username']);
            $flash = 'User disabled.';
        } elseif ($action === 'enable') {
            $usersRepo->setDisabled($targetId, false);
            $usersRepo->logAudit('user.enabled', (int) $currentUser['id'], (string) $currentUser['username'], $targetId, (string) $target['username']);
            $flash = 'User enabled.';
        } elseif ($action === 'promote') {
            $usersRepo->setAdmin($targetId, true);
            $usersRepo->setApproved($targetId, true);
            $usersRepo->logAudit('user.promoted', (int) $currentUser['id'], (string) $currentUser['username'], $targetId, (string) $target['username']);
            $flash = 'User is now an administrator.';
        } elseif ($action === 'demote') {
            if ((int) $target['id'] === (int) $currentUser['id']) {
                throw new RuntimeException('You cannot remove your own admin role.');
            }
            if ($usersRepo->countAdmins() <= 1) {
                throw new RuntimeException('Cannot demote the last administrator.');
            }
            $usersRepo->setAdmin($targetId, false);
            $usersRepo->logAudit('user.demoted', (int) $currentUser['id'], (string) $currentUser['username'], $targetId, (string) $target['username']);
            $flash = 'Administrator role removed.';
        } elseif ($action === 'reset_password') {
            if (($target['auth_source'] ?? '') !== 'local') {
                throw new RuntimeException('LDAP users reset their password in the directory.');
            }
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirm'] ?? '');
            if ($password !== $confirm) {
                throw new RuntimeException('Password confirmation does not match.');
            }
            $strength = \RiskAssessment\Auth::validatePasswordStrength($password);
            if ($strength !== null) {
                throw new RuntimeException($strength);
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Unable to reset the password.');
            }
            $usersRepo->setPassword($targetId, $hash);
            $usersRepo->logAudit('user.password_reset', (int) $currentUser['id'], (string) $currentUser['username'], $targetId, (string) $target['username']);
            $flash = 'Password reset.';
        } elseif ($action === 'delete') {
            if ((int) $target['id'] === (int) $currentUser['id']) {
                throw new RuntimeException('You cannot delete your own account.');
            }
            if (!empty($target['is_admin']) && $usersRepo->countAdmins() <= 1) {
                throw new RuntimeException('Cannot delete the last administrator.');
            }
            $usersRepo->delete($targetId);
            $usersRepo->logAudit('user.deleted', (int) $currentUser['id'], (string) $currentUser['username'], $targetId, (string) $target['username']);
            $flash = 'User deleted.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$allUsers = $usersRepo->listAll();
$audit = $usersRepo->recentAudit(40);

$adminTitle = 'Users';
$adminTab = 'users';
$adminEyebrow = 'Access';
$adminHeading = 'People on this <em>install</em>';
$adminIntro = 'Local accounts can sign in with a password. LDAP accounts are provisioned from the directory.';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card">
                <h2>Add local user</h2>
                <p>Created accounts are approved immediately. Share the password over a private channel.</p>
                <form method="post" class="updater-form updater-form-stack user-create-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_user">
                    <div class="admin-form-grid">
                        <label class="file-input">
                            <span>Username</span>
                            <input type="text" name="username" required minlength="3" maxlength="80" autocomplete="off">
                        </label>
                        <label class="file-input">
                            <span>Email</span>
                            <input type="email" name="email" required autocomplete="off">
                        </label>
                        <label class="file-input">
                            <span>Display name</span>
                            <input type="text" name="display_name" maxlength="120" autocomplete="off">
                        </label>
                        <label class="file-input">
                            <span>Password</span>
                            <input type="password" name="password" required minlength="8" autocomplete="new-password">
                        </label>
                        <label class="file-input">
                            <span>Confirm password</span>
                            <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
                        </label>
                        <label class="remember-row">
                            <input type="checkbox" name="is_admin" value="1">
                            <span>Administrator</span>
                        </label>
                    </div>
                    <button type="submit" class="button button-primary">Create user</button>
                </form>
            </section>

            <section class="upload-card">
                <h2>All users</h2>
                <?php if ($allUsers === []): ?>
                    <p class="empty-results">No users yet.</p>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Source</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $user): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e((string) $user['username']) ?></strong>
                                            <span class="table-sub"><?= e((string) $user['email']) ?></span>
                                            <?php if ((string) $user['display_name'] !== ''): ?>
                                                <span class="table-sub"><?= e((string) $user['display_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="auth-badge <?= $user['auth_source'] === 'ldap' ? 'is-ldap' : 'is-local' ?>"><?= e((string) $user['auth_source']) ?></span></td>
                                        <td><?= !empty($user['is_admin']) ? 'Admin' : 'User' ?></td>
                                        <td>
                                            <?php if (!empty($user['is_disabled'])): ?>
                                                <span class="token-needed">Disabled</span>
                                            <?php elseif (empty($user['is_approved'])): ?>
                                                <span class="token-needed">Pending</span>
                                            <?php else: ?>
                                                <span class="token-ok">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e((string) ($user['last_login'] ?: '—')) ?></td>
                                        <td>
                                            <div class="user-actions">
                                                <?php if (empty($user['is_approved'])): ?>
                                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="approve"><button type="submit" class="button ghost">Approve</button></form>
                                                <?php else: ?>
                                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="reject"><button type="submit" class="button ghost">Unapprove</button></form>
                                                <?php endif; ?>
                                                <?php if (!empty($user['is_disabled'])): ?>
                                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="enable"><button type="submit" class="button ghost">Enable</button></form>
                                                <?php else: ?>
                                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="disable"><button type="submit" class="button ghost">Disable</button></form>
                                                <?php endif; ?>
                                                <?php if (empty($user['is_admin'])): ?>
                                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="promote"><button type="submit" class="button ghost">Make admin</button></form>
                                                <?php else: ?>
                                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="demote"><button type="submit" class="button ghost">Remove admin</button></form>
                                                <?php endif; ?>
                                                <?php if (($user['auth_source'] ?? '') === 'local'): ?>
                                                    <details class="inline-details">
                                                        <summary>Reset password</summary>
                                                        <form method="post" class="updater-form updater-form-stack">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                            <input type="hidden" name="action" value="reset_password">
                                                            <label class="file-input"><span>New password</span><input type="password" name="password" required minlength="8"></label>
                                                            <label class="file-input"><span>Confirm</span><input type="password" name="password_confirm" required minlength="8"></label>
                                                            <button type="submit" class="button button-primary">Save password</button>
                                                        </form>
                                                    </details>
                                                <?php endif; ?>
                                                <form method="post" onsubmit="return confirm('Delete this user permanently?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="button danger-btn">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="upload-card">
                <h2>Audit log</h2>
                <?php if ($audit === []): ?>
                    <p class="empty-results">No events yet.</p>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Event</th>
                                    <th>Actor</th>
                                    <th>Target</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($audit as $row): ?>
                                    <tr>
                                        <td><?= e((string) ($row['created_at'] ?? '')) ?></td>
                                        <td><?= e((string) ($row['event'] ?? '')) ?></td>
                                        <td><?= e((string) ($row['actor_username'] ?? '—')) ?></td>
                                        <td><?= e((string) ($row['target_username'] ?? '—')) ?></td>
                                        <td><?= e((string) ($row['ip_address'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
