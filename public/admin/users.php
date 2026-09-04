<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$usersRepo = $auth->users();
$ldap = $auth->ldap();
$ldapEnabled = $ldap->isEnabled();
$error = '';
$flash = '';
$ldapSearchQuery = '';
/** @var list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>|null $ldapSearchResults */
$ldapSearchResults = null;

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
        } elseif ($action === 'search_ldap_users') {
            if (!$ldapEnabled) {
                throw new RuntimeException('Enable LDAP under Authentication before searching the directory.');
            }
            $ldapSearchQuery = trim((string) ($_POST['ldap_search_query'] ?? ''));
            $ldapSearchResults = $ldap->searchUsers($ldapSearchQuery, 25);
            if ($ldapSearchResults === []) {
                $flash = 'No directory users matched “' . $ldapSearchQuery . '”.';
            } else {
                $n = count($ldapSearchResults);
                $flash = $n . ' directory user' . ($n === 1 ? '' : 's') . ' found.';
            }
        } elseif ($action === 'create_ldap_user') {
            if (!$ldapEnabled) {
                throw new RuntimeException('Enable LDAP under Authentication before adding directory users.');
            }
            $username = trim((string) ($_POST['ldap_username'] ?? ''));
            $makeAdmin = !empty($_POST['is_admin']);
            $ldapSearchQuery = trim((string) ($_POST['ldap_search_query'] ?? ''));
            if ($username === '') {
                throw new RuntimeException('Enter an LDAP username.');
            }
            $profile = $ldap->lookupUser($username);
            $existing = $usersRepo->findByUsernameOrEmail($profile['username']);
            if ($existing === null && $profile['email'] !== '') {
                $existing = $usersRepo->findByUsernameOrEmail($profile['email']);
            }
            if ($existing !== null && ($existing['auth_source'] ?? '') === 'local') {
                throw new RuntimeException('A local user already uses that username or email. Choose a different account or rename the local user.');
            }
            $wasExisting = $existing !== null;
            $user = $usersRepo->upsertLdapUser($profile, true, true, true);
            if ($makeAdmin) {
                $usersRepo->setAdmin((int) $user['id'], true);
                $usersRepo->setApproved((int) $user['id'], true);
            }
            $usersRepo->logAudit(
                $wasExisting ? 'user.ldap_refreshed' : 'user.ldap_provisioned',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                (int) $user['id'],
                (string) $user['username'],
                ['admin' => $makeAdmin, 'dn' => $profile['dn'] ?? '']
            );
            $flash = $wasExisting
                ? 'LDAP user “' . $user['username'] . '” was already present and has been refreshed.'
                : 'LDAP user “' . $user['username'] . '” added and approved.';
            if ($ldapSearchQuery !== '') {
                try {
                    $ldapSearchResults = $ldap->searchUsers($ldapSearchQuery, 25);
                } catch (Throwable) {
                    $ldapSearchResults = null;
                }
            }
        } elseif ($action === 'create_ldap_group') {
            if (!$ldapEnabled) {
                throw new RuntimeException('Enable LDAP under Authentication before importing directory groups.');
            }
            $groupDn = trim((string) ($_POST['ldap_group_dn'] ?? ''));
            $makeAdmin = !empty($_POST['is_admin']);
            if ($groupDn === '') {
                throw new RuntimeException('Enter the full LDAP group DN.');
            }
            if (!preg_match('/[=,]/', $groupDn)) {
                throw new RuntimeException('Enter the complete group DN, for example CN=RiskUsers,OU=Groups,DC=example,DC=com.');
            }
            $group = $ldap->lookupGroupMembers($groupDn);
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $failures = [];
            foreach ($group['members'] as $profile) {
                $username = (string) ($profile['username'] ?? '');
                if ($username === '') {
                    $skipped++;
                    continue;
                }
                try {
                    $existing = $usersRepo->findByUsernameOrEmail($username);
                    if ($existing !== null && ($existing['auth_source'] ?? '') === 'local') {
                        $skipped++;
                        $failures[] = $username . ' (local account exists)';
                        continue;
                    }
                    $wasExisting = $existing !== null;
                    $user = $usersRepo->upsertLdapUser($profile, true, true, true);
                    if ($makeAdmin) {
                        $usersRepo->setAdmin((int) $user['id'], true);
                        $usersRepo->setApproved((int) $user['id'], true);
                    }
                    if ($wasExisting) {
                        $updated++;
                    } else {
                        $created++;
                    }
                    $usersRepo->logAudit(
                        $wasExisting ? 'user.ldap_refreshed' : 'user.ldap_provisioned',
                        (int) $currentUser['id'],
                        (string) $currentUser['username'],
                        (int) $user['id'],
                        (string) $user['username'],
                        [
                            'admin' => $makeAdmin,
                            'group_dn' => $group['dn'],
                            'group_name' => $group['name'],
                        ]
                    );
                } catch (Throwable $memberException) {
                    $skipped++;
                    $failures[] = $username . ' (' . $memberException->getMessage() . ')';
                }
            }
            $usersRepo->logAudit(
                'user.ldap_group_imported',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                $group['name'],
                [
                    'group_dn' => $group['dn'],
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'admin' => $makeAdmin,
                ]
            );
            $flash = 'Imported LDAP group “' . $group['name'] . '”: '
                . $created . ' added, '
                . $updated . ' updated'
                . ($skipped > 0 ? ', ' . $skipped . ' skipped' : '')
                . '.';
            if ($failures !== [] && count($failures) <= 8) {
                $flash .= ' Notes: ' . implode('; ', $failures) . '.';
            } elseif ($failures !== []) {
                $flash .= ' Some members were skipped (see audit log details).';
            }
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
$adminIntro = 'Local accounts can sign in with a password. LDAP accounts can be added by username or by importing a group DN.';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card settings-card">
                <h2><span class="settings-emoji" aria-hidden="true">👤</span> Add local user</h2>
                <p>Created accounts are approved immediately. Share the password over a private channel.</p>
                <form method="post" class="settings-form user-create-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_user">
                    <fieldset class="settings-fieldset settings-tone-teal">
                        <legend><span class="settings-emoji" aria-hidden="true">🪪</span> Account</legend>
                        <p class="settings-hint">Local username and contact details for this install.</p>
                        <div class="settings-grid">
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🧑</span> Username</span>
                                <input type="text" name="username" required minlength="3" maxlength="80" autocomplete="off">
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">✉️</span> Email</span>
                                <input type="email" name="email" required autocomplete="off">
                            </label>
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">🏷️</span> Display name</span>
                                <input type="text" name="display_name" maxlength="120" autocomplete="off">
                            </label>
                        </div>
                    </fieldset>
                    <fieldset class="settings-fieldset settings-tone-violet">
                        <legend><span class="settings-emoji" aria-hidden="true">🔑</span> Password</legend>
                        <p class="settings-hint">At least 8 characters. The person signs in with this until they change it.</p>
                        <div class="settings-grid">
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🔒</span> Password</span>
                                <input type="password" name="password" required minlength="8" autocomplete="new-password">
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">✅</span> Confirm password</span>
                                <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
                            </label>
                            <label class="remember-row settings-span-all">
                                <input type="checkbox" name="is_admin" value="1">
                                <span><span class="settings-emoji" aria-hidden="true">🛡️</span> Administrator</span>
                            </label>
                        </div>
                    </fieldset>
                    <div class="settings-actions">
                        <button type="submit" class="button button-primary">➕ Create user</button>
                    </div>
                </form>
            </section>

            <section class="upload-card settings-card" id="add-ldap-user">
                <h2><span class="settings-emoji" aria-hidden="true">🗂️</span> Add LDAP user</h2>
                <?php if (!$ldapEnabled): ?>
                    <p class="settings-hint">LDAP is disabled. Configure and enable it under <a href="authentication.php">Authentication</a> first.</p>
                <?php else: ?>
                    <p>Search the directory by name, username, or email, then add people from the results. No user password is required.</p>
                    <form method="post" class="settings-form user-create-form" action="#add-ldap-user">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="search_ldap_users">
                        <fieldset class="settings-fieldset settings-tone-teal">
                            <legend><span class="settings-emoji" aria-hidden="true">🔎</span> Directory search</legend>
                            <p class="settings-hint">Matches display name, username (sAMAccountName / uid), email, and common name. At least 2 characters.</p>
                            <div class="settings-grid">
                                <label class="settings-field settings-span-all">
                                    <span><span class="settings-emoji" aria-hidden="true">🧑</span> Search</span>
                                    <input type="search" name="ldap_search_query" required minlength="2" maxlength="120" autocomplete="off" placeholder="Jane Smith or jsmith" value="<?= e($ldapSearchQuery) ?>">
                                </label>
                            </div>
                        </fieldset>
                        <div class="settings-actions">
                            <button type="submit" class="button button-primary">🔎 Search directory</button>
                        </div>
                    </form>

                    <?php if ($ldapSearchResults !== null): ?>
                        <div class="admin-table-wrap" style="margin-top: 1rem;">
                            <?php if ($ldapSearchResults === []): ?>
                                <p class="empty-results">No matches for “<?= e($ldapSearchQuery) ?>”.</p>
                            <?php else: ?>
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Status</th>
                                            <th>Add</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ldapSearchResults as $hit): ?>
                                            <?php
                                            $hitUser = $usersRepo->findByUsernameOrEmail($hit['username']);
                                            if ($hitUser === null && $hit['email'] !== '') {
                                                $hitUser = $usersRepo->findByUsernameOrEmail($hit['email']);
                                            }
                                            $alreadyLdap = $hitUser !== null && ($hitUser['auth_source'] ?? '') === 'ldap';
                                            $alreadyLocal = $hitUser !== null && ($hitUser['auth_source'] ?? '') === 'local';
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?= e($hit['display_name'] !== '' ? $hit['display_name'] : $hit['username']) ?></strong>
                                                    <?php if ($hit['dn'] !== ''): ?>
                                                        <span class="table-sub"><?= e($hit['dn']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= e($hit['username']) ?></td>
                                                <td><?= e($hit['email'] !== '' ? $hit['email'] : '—') ?></td>
                                                <td>
                                                    <?php if ($alreadyLdap): ?>
                                                        <span class="token-ok">Already added</span>
                                                    <?php elseif ($alreadyLocal): ?>
                                                        <span class="token-needed">Local account exists</span>
                                                    <?php else: ?>
                                                        <span class="token-needed">Not in app</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($alreadyLocal): ?>
                                                        <span class="settings-hint">Unavailable</span>
                                                    <?php else: ?>
                                                        <form method="post" class="user-actions" action="#add-ldap-user">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="create_ldap_user">
                                                            <input type="hidden" name="ldap_username" value="<?= e($hit['username']) ?>">
                                                            <input type="hidden" name="ldap_search_query" value="<?= e($ldapSearchQuery) ?>">
                                                            <label class="remember-row" style="margin: 0 0 0.35rem;">
                                                                <input type="checkbox" name="is_admin" value="1">
                                                                <span>Admin</span>
                                                            </label>
                                                            <button type="submit" class="button button-primary">
                                                                <?= $alreadyLdap ? 'Refresh' : '➕ Add' ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <details class="inline-details" style="margin-top: 1rem;">
                        <summary>Add by exact LDAP username</summary>
                        <form method="post" class="settings-form user-create-form" action="#add-ldap-user">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_ldap_user">
                            <div class="settings-grid" style="margin-top: 0.75rem;">
                                <label class="settings-field">
                                    <span>LDAP username</span>
                                    <input type="text" name="ldap_username" required maxlength="120" autocomplete="off" placeholder="jsmith">
                                </label>
                                <label class="remember-row settings-span-all">
                                    <input type="checkbox" name="is_admin" value="1">
                                    <span>Administrator</span>
                                </label>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="button button-primary">➕ Add LDAP user</button>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>
            </section>

            <section class="upload-card settings-card">
                <h2><span class="settings-emoji" aria-hidden="true">👥</span> Add LDAP group</h2>
                <?php if (!$ldapEnabled): ?>
                    <p class="settings-hint">LDAP is disabled. Configure and enable it under <a href="authentication.php">Authentication</a> first.</p>
                <?php else: ?>
                    <p>Paste the group’s full distinguished name. Every user member (and one level of nested group members) is provisioned as an approved LDAP account.</p>
                    <form method="post" class="settings-form user-create-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_ldap_group">
                        <fieldset class="settings-fieldset settings-tone-violet">
                            <legend><span class="settings-emoji" aria-hidden="true">🧾</span> Group DN</legend>
                            <p class="settings-hint">Example: <code>CN=Risk Register Users,OU=Security Groups,DC=contoso,DC=com</code></p>
                            <div class="settings-grid">
                                <label class="settings-field settings-span-all">
                                    <span><span class="settings-emoji" aria-hidden="true">🏷️</span> Complete group DN</span>
                                    <input type="text" name="ldap_group_dn" required maxlength="512" autocomplete="off" placeholder="CN=Group Name,OU=Groups,DC=example,DC=com">
                                </label>
                                <label class="remember-row settings-span-all">
                                    <input type="checkbox" name="is_admin" value="1">
                                    <span><span class="settings-emoji" aria-hidden="true">🛡️</span> Make all imported members administrators</span>
                                </label>
                            </div>
                        </fieldset>
                        <div class="settings-actions">
                            <button type="submit" class="button button-primary">➕ Import group members</button>
                        </div>
                    </form>
                <?php endif; ?>
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
