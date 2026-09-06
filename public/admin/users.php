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
/** @var array{
 *   profile: array{username: string, email: string, display_name: string, dn: string, groups: list<string>},
 *   status: array{
 *     enabled: bool,
 *     disabled: bool,
 *     locked: bool,
 *     password_expired: bool,
 *     must_change_password: bool,
 *     password_never_expires: bool,
 *     account_expired: bool,
 *     badges: list<array{label: string, tone: string}>,
 *     notes: list<string>
 *   },
 *   groups: list<array{cn: string, dn: string}>,
 *   fields: array<string, string>,
 *   timestamps: array<string, string>,
 *   attributes: array<string, string|list<string>>
 * }|null $ldapUserDetails */
$ldapUserDetails = null;
/** @var array{dn: string, name: string, members: list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>}|null $ldapGroupPreview */
$ldapGroupPreview = null;
$ldapGroupQuery = '';

/**
 * @param list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}> $members
 * @param list<string> $onlyUsernames
 * @return array{created: int, updated: int, skipped: int, failures: list<string>}
 */
function admin_provision_ldap_members(
    \RiskAssessment\Repositories\UserRepository $usersRepo,
    array $currentUser,
    array $group,
    array $members,
    array $onlyUsernames,
    bool $makeAdmin
): array {
    $allow = [];
    foreach ($onlyUsernames as $name) {
        $name = strtolower(trim((string) $name));
        if ($name !== '') {
            $allow[$name] = true;
        }
    }

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $failures = [];
    foreach ($members as $profile) {
        $username = (string) ($profile['username'] ?? '');
        if ($username === '') {
            $skipped++;
            continue;
        }
        if ($allow !== [] && !isset($allow[strtolower($username)])) {
            continue;
        }
        try {
            $existing = $usersRepo->findByUsernameOrEmail($username);
            if ($existing === null && ($profile['email'] ?? '') !== '') {
                $existing = $usersRepo->findByUsernameOrEmail((string) $profile['email']);
            }
            if ($existing !== null && ($existing['auth_source'] ?? '') === 'local') {
                $skipped++;
                $failures[] = $username . ' (local account exists)';
                continue;
            }
            $wasExisting = $existing !== null;
            $user = $usersRepo->upsertLdapUser(
                $profile,
                true,
                true,
                true,
                (int) $currentUser['id'],
                (string) $currentUser['username']
            );
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
                    'group_dn' => $group['dn'] ?? '',
                    'group_name' => $group['name'] ?? '',
                ]
            );
        } catch (Throwable $memberException) {
            $skipped++;
            $failures[] = $username . ' (' . $memberException->getMessage() . ')';
        }
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'failures' => $failures,
    ];
}

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
            $id = $usersRepo->createLocal(
                $username,
                $email,
                $hash,
                $makeAdmin,
                true,
                $displayName,
                '',
                (int) $currentUser['id'],
                (string) $currentUser['username']
            );
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
            $user = $usersRepo->upsertLdapUser(
                $profile,
                true,
                true,
                true,
                (int) $currentUser['id'],
                (string) $currentUser['username']
            );
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
        } elseif ($action === 'view_ldap_user') {
            if (!$ldapEnabled) {
                throw new RuntimeException('Enable LDAP under Authentication before inspecting directory users.');
            }
            $username = trim((string) ($_POST['ldap_username'] ?? ''));
            $ldapSearchQuery = trim((string) ($_POST['ldap_search_query'] ?? ''));
            if ($username === '') {
                throw new RuntimeException('Enter an LDAP username.');
            }
            $ldapUserDetails = $ldap->lookupUserDetails($username);
            $usersRepo->logAudit(
                'user.ldap_inspected',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                (string) ($ldapUserDetails['profile']['username'] ?? $username),
                ['dn' => (string) ($ldapUserDetails['profile']['dn'] ?? '')]
            );
            $flash = 'Directory profile loaded for “' . ($ldapUserDetails['profile']['username'] ?? $username) . '”.';
            if ($ldapSearchQuery !== '') {
                try {
                    $ldapSearchResults = $ldap->searchUsers($ldapSearchQuery, 25);
                } catch (Throwable) {
                    $ldapSearchResults = null;
                }
            }
        } elseif ($action === 'preview_ldap_group' || $action === 'create_ldap_group' || $action === 'import_ldap_group_selected') {
            if (!$ldapEnabled) {
                throw new RuntimeException('Enable LDAP under Authentication before importing directory groups.');
            }
            $groupRef = trim((string) ($_POST['ldap_group_dn'] ?? ''));
            $ldapGroupQuery = $groupRef;
            $makeAdmin = !empty($_POST['is_admin']);
            if ($groupRef === '') {
                throw new RuntimeException('Enter an LDAP group DN, CN, or sAMAccountName.');
            }
            $group = $ldap->lookupGroupMembers($groupRef);
            $ldapGroupPreview = $group;

            if ($action === 'preview_ldap_group') {
                $flash = count($group['members']) . ' member'
                    . (count($group['members']) === 1 ? '' : 's')
                    . ' found in “' . $group['name'] . '”. Select people to add, or import all.';
            } else {
                $only = [];
                if ($action === 'import_ldap_group_selected') {
                    $rawSelected = $_POST['ldap_usernames'] ?? [];
                    if (!is_array($rawSelected)) {
                        $rawSelected = [];
                    }
                    foreach ($rawSelected as $name) {
                        $name = trim((string) $name);
                        if ($name !== '') {
                            $only[] = $name;
                        }
                    }
                    if ($only === []) {
                        throw new RuntimeException('Select at least one group member to import.');
                    }
                }
                $result = admin_provision_ldap_members(
                    $usersRepo,
                    $currentUser,
                    $group,
                    $group['members'],
                    $only,
                    $makeAdmin
                );
                $usersRepo->logAudit(
                    'user.ldap_group_imported',
                    (int) $currentUser['id'],
                    (string) $currentUser['username'],
                    null,
                    $group['name'],
                    [
                        'group_dn' => $group['dn'],
                        'created' => $result['created'],
                        'updated' => $result['updated'],
                        'skipped' => $result['skipped'],
                        'admin' => $makeAdmin,
                        'selected' => $only,
                    ]
                );
                $flash = 'Imported LDAP group “' . $group['name'] . '”: '
                    . $result['created'] . ' added, '
                    . $result['updated'] . ' updated'
                    . ($result['skipped'] > 0 ? ', ' . $result['skipped'] . ' skipped' : '')
                    . '.';
                if ($result['failures'] !== [] && count($result['failures']) <= 8) {
                    $flash .= ' Notes: ' . implode('; ', $result['failures']) . '.';
                } elseif ($result['failures'] !== []) {
                    $flash .= ' Some members were skipped (see audit log details).';
                }
            }
        } elseif ($action === 'bulk_delete_users') {
            $rawIds = $_POST['user_ids'] ?? [];
            if (!is_array($rawIds)) {
                $rawIds = [];
            }
            $ids = [];
            foreach ($rawIds as $rawId) {
                $id = (int) $rawId;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $ids = array_values($ids);
            if ($ids === []) {
                throw new RuntimeException('Select at least one user to delete.');
            }
            $deleted = 0;
            $skipped = [];
            foreach ($ids as $id) {
                $row = $usersRepo->findById($id);
                if ($row === null) {
                    continue;
                }
                if ((int) $row['id'] === (int) $currentUser['id']) {
                    $skipped[] = (string) $row['username'] . ' (your account)';
                    continue;
                }
                if (!empty($row['is_admin']) && $usersRepo->countAdmins() <= 1) {
                    $skipped[] = (string) $row['username'] . ' (last administrator)';
                    continue;
                }
                $usersRepo->delete($id);
                $usersRepo->logAudit(
                    'user.deleted',
                    (int) $currentUser['id'],
                    (string) $currentUser['username'],
                    $id,
                    (string) $row['username'],
                    ['bulk' => true]
                );
                $deleted++;
            }
            $flash = $deleted . ' user' . ($deleted === 1 ? '' : 's') . ' deleted.';
            if ($skipped !== []) {
                $flash .= ' Skipped: ' . implode(', ', $skipped) . '.';
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

$allowedPerPage = [10, 25, 50, 100];
$userQuery = trim((string) ($_GET['uq'] ?? ''));
$userPerPage = \RiskAssessment\PaginationPreference::resolve(
    \RiskAssessment\PaginationPreference::KEY_USERS,
    isset($_GET['uper']) ? (int) $_GET['uper'] : null,
    25,
    $allowedPerPage
);
$userTotal = $usersRepo->countSearch($userQuery);
$userTotalPages = max(1, (int) ceil($userTotal / $userPerPage));
$userPage = max(1, min($userTotalPages, (int) ($_GET['upage'] ?? 1)));
$allUsers = $usersRepo->searchUsers($userQuery, $userPage, $userPerPage);

$auditQuery = trim((string) ($_GET['aq'] ?? ''));
$auditPerPage = \RiskAssessment\PaginationPreference::resolve(
    \RiskAssessment\PaginationPreference::KEY_AUDIT,
    isset($_GET['aper']) ? (int) $_GET['aper'] : null,
    25,
    $allowedPerPage
);
$auditTotal = $usersRepo->countAudit($auditQuery);
$auditTotalPages = max(1, (int) ceil($auditTotal / $auditPerPage));
$auditPage = max(1, min($auditTotalPages, (int) ($_GET['apage'] ?? 1)));
$audit = $usersRepo->searchAudit($auditQuery, $auditPage, $auditPerPage);

$usersPageUrl = static function (array $overrides = [], string $hash = '') use ($userQuery, $userPage, $userPerPage, $auditQuery, $auditPage, $auditPerPage): string {
    $params = array_merge(
        [
            'uq' => $userQuery,
            'upage' => $userPage,
            'uper' => $userPerPage,
            'aq' => $auditQuery,
            'apage' => $auditPage,
            'aper' => $auditPerPage,
        ],
        $overrides
    );
    if (trim((string) ($params['uq'] ?? '')) === '') {
        unset($params['uq']);
    }
    if ((int) ($params['upage'] ?? 1) <= 1) {
        unset($params['upage']);
    }
    if ((int) ($params['uper'] ?? 25) === 25) {
        unset($params['uper']);
    }
    if (trim((string) ($params['aq'] ?? '')) === '') {
        unset($params['aq']);
    }
    if ((int) ($params['apage'] ?? 1) <= 1) {
        unset($params['apage']);
    }
    if ((int) ($params['aper'] ?? 25) === 25) {
        unset($params['aper']);
    }
    $query = http_build_query($params);

    return 'users.php' . ($query !== '' ? '?' . $query : '') . ($hash !== '' ? $hash : '');
};

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
                    <p>Search the directory by name, username, or email, then add people from the results or open <strong>LDAP details</strong> for a full live profile (groups, lock status, and readable attributes). No user password is required.</p>
                    <form method="post" class="settings-form user-create-form" action="#add-ldap-user">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="search_ldap_users">
                        <fieldset class="settings-fieldset settings-tone-teal">
                            <legend><span class="settings-emoji" aria-hidden="true">🔎</span> Directory search</legend>
                            <p class="settings-hint">Start with the exact username (sAMAccountName) when you know it. Name and email searches use prefix / ANR matches so they stay fast against a large domain.</p>
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

                    <?php if ($ldapUserDetails !== null): ?>
                        <?php
                        $detailProfile = $ldapUserDetails['profile'];
                        $detailStatus = $ldapUserDetails['status'];
                        $detailGroups = $ldapUserDetails['groups'];
                        $detailFields = $ldapUserDetails['fields'];
                        $detailTimestamps = $ldapUserDetails['timestamps'];
                        $detailAttributes = $ldapUserDetails['attributes'];
                        $detailName = $detailProfile['display_name'] !== '' ? $detailProfile['display_name'] : $detailProfile['username'];
                        $detailInitial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $detailName) ?: 'U', 0, 1));
                        $ldapFieldIcons = [
                            'title' => '💼',
                            'department' => '🏢',
                            'company' => '🏛️',
                            'manager' => '👤',
                            'telephonenumber' => '📞',
                            'mobile' => '📱',
                            'homephone' => '☎️',
                            'pager' => '📟',
                            'facsimiletelephonenumber' => '📠',
                            'physicaldeliveryofficename' => '📍',
                            'streetaddress' => '🏠',
                            'l' => '🌆',
                            'st' => '🗺️',
                            'postalcode' => '🏷️',
                            'c' => '🌍',
                            'co' => '🌍',
                            'givenname' => '✏️',
                            'sn' => '✏️',
                            'initials' => '🔤',
                            'description' => '📝',
                            'employeeid' => '🪪',
                            'employeenumber' => '🔢',
                            'info' => 'ℹ️',
                            'wwwhomepage' => '🔗',
                            'url' => '🔗',
                        ];
                        $ldapBadgeEmoji = static function (string $label, string $tone): string {
                            $lower = strtolower($label);
                            return match (true) {
                                str_contains($lower, 'disabled') => '🚫',
                                str_contains($lower, 'locked') => '🔒',
                                str_contains($lower, 'expired') && str_contains($lower, 'password') => '⌛',
                                str_contains($lower, 'account expired') => '📅',
                                str_contains($lower, 'must change') => '🔄',
                                str_contains($lower, 'never expires') => '♾️',
                                str_contains($lower, 'enabled') => '✅',
                                $tone === 'danger' => '⚠️',
                                $tone === 'warn' => '⚡',
                                $tone === 'ok' => '✅',
                                default => 'ℹ️',
                            };
                        };
                        $ldapHumanLabel = static function (string $name): string {
                            $map = [
                                'telephonenumber' => 'Phone',
                                'physicaldeliveryofficename' => 'Office',
                                'streetaddress' => 'Street',
                                'postalcode' => 'Postal code',
                                'givenname' => 'First name',
                                'sn' => 'Last name',
                                'l' => 'City',
                                'st' => 'State',
                                'c' => 'Country code',
                                'co' => 'Country',
                                'wwwhomepage' => 'Website',
                                'facsimiletelephonenumber' => 'Fax',
                                'employeeid' => 'Employee ID',
                                'employeenumber' => 'Employee number',
                                'pwdlastset' => 'Password last set',
                                'lockouttime' => 'Lockout time',
                                'lastlogon' => 'Last logon',
                                'lastlogontimestamp' => 'Last logon timestamp',
                                'badpasswordtime' => 'Bad password time',
                                'lastlogoff' => 'Last logoff',
                                'accountexpires' => 'Account expires',
                                'whencreated' => 'When created',
                                'whenchanged' => 'When changed',
                                'createtimestamp' => 'Create timestamp',
                                'modifytimestamp' => 'Modify timestamp',
                                'pwdchangedtime' => 'Password changed',
                                'pwdaccountlockedtime' => 'Account locked time',
                                'msds-userpasswordexpirytimecomputed' => 'Password expiry (computed)',
                            ];
                            $key = strtolower($name);
                            if (isset($map[$key])) {
                                return $map[$key];
                            }

                            return preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name;
                        };
                        $orgFieldRows = [];
                        foreach ($detailFields as $fieldName => $fieldValue) {
                            if (in_array(strtolower($fieldName), ['samaccountname', 'uid', 'cn', 'mail', 'displayname', 'userprincipalname'], true)) {
                                continue;
                            }
                            $orgFieldRows[$fieldName] = $fieldValue;
                        }
                        ?>
                        <div class="ldap-details-card" id="ldap-user-details">
                            <div class="ldap-details-head">
                                <div class="ldap-details-identity">
                                    <span class="ldap-details-avatar" aria-hidden="true"><?= e($detailInitial) ?></span>
                                    <div>
                                        <p class="eyebrow"><span class="settings-emoji" aria-hidden="true">🗂️</span> Directory profile</p>
                                        <h3><?= e($detailName) ?></h3>
                                        <p class="ldap-details-dn"><span class="settings-emoji" aria-hidden="true">🧩</span> <?= e($detailProfile['dn']) ?></p>
                                    </div>
                                </div>
                                <div class="ldap-status-badges">
                                    <?php foreach ($detailStatus['badges'] as $badge): ?>
                                        <?php
                                        $tone = (string) ($badge['tone'] ?? 'info');
                                        $label = (string) ($badge['label'] ?? '');
                                        ?>
                                        <span class="ldap-status-badge tone-<?= e($tone) ?>">
                                            <span class="settings-emoji" aria-hidden="true"><?= e($ldapBadgeEmoji($label, $tone)) ?></span>
                                            <?= e($label) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="ldap-details-grid">
                                <div class="ldap-details-block tone-identity">
                                    <h4><span class="settings-emoji" aria-hidden="true">🧑</span> Identity</h4>
                                    <dl class="ldap-details-dl">
                                        <div><dt><span class="settings-emoji" aria-hidden="true">🔑</span> Username</dt><dd><code><?= e($detailProfile['username']) ?></code></dd></div>
                                        <div><dt><span class="settings-emoji" aria-hidden="true">📧</span> Email</dt><dd><?= e($detailProfile['email'] !== '' ? $detailProfile['email'] : '—') ?></dd></div>
                                        <div><dt><span class="settings-emoji" aria-hidden="true">🏷️</span> Display name</dt><dd><?= e($detailProfile['display_name'] !== '' ? $detailProfile['display_name'] : '—') ?></dd></div>
                                    </dl>
                                    <?php if ($detailStatus['notes'] !== []): ?>
                                        <ul class="ldap-details-notes">
                                            <?php foreach ($detailStatus['notes'] as $note): ?>
                                                <li><span class="settings-emoji" aria-hidden="true">📌</span> <?= e($note) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>

                                <?php if ($orgFieldRows !== []): ?>
                                    <div class="ldap-details-block tone-org">
                                        <h4><span class="settings-emoji" aria-hidden="true">🏢</span> Org &amp; contact</h4>
                                        <dl class="ldap-details-dl">
                                            <?php foreach ($orgFieldRows as $fieldName => $fieldValue): ?>
                                                <?php $icon = $ldapFieldIcons[strtolower($fieldName)] ?? '🔹'; ?>
                                                <div>
                                                    <dt><span class="settings-emoji" aria-hidden="true"><?= e($icon) ?></span> <?= e($ldapHumanLabel($fieldName)) ?></dt>
                                                    <dd><?= e($fieldValue) ?></dd>
                                                </div>
                                            <?php endforeach; ?>
                                        </dl>
                                    </div>
                                <?php endif; ?>

                                <?php if ($detailTimestamps !== []): ?>
                                    <div class="ldap-details-block tone-time">
                                        <h4><span class="settings-emoji" aria-hidden="true">⏱️</span> Timestamps</h4>
                                        <dl class="ldap-details-dl">
                                            <?php foreach ($detailTimestamps as $tsName => $tsValue): ?>
                                                <div>
                                                    <dt><span class="settings-emoji" aria-hidden="true">📅</span> <?= e($ldapHumanLabel($tsName)) ?></dt>
                                                    <dd><time><?= e($tsValue) ?></time></dd>
                                                </div>
                                            <?php endforeach; ?>
                                        </dl>
                                    </div>
                                <?php endif; ?>

                                <div class="ldap-details-block ldap-details-groups tone-groups">
                                    <h4><span class="settings-emoji" aria-hidden="true">👥</span> Groups <em><?= count($detailGroups) ?></em></h4>
                                    <?php if ($detailGroups === []): ?>
                                        <p class="settings-hint"><span class="settings-emoji" aria-hidden="true">🫥</span> No memberOf groups returned (or the bind account cannot read them).</p>
                                    <?php else: ?>
                                        <ul class="ldap-group-list">
                                            <?php foreach ($detailGroups as $group): ?>
                                                <li>
                                                    <span class="ldap-group-icon" aria-hidden="true">🛡️</span>
                                                    <span class="ldap-group-text">
                                                        <strong><?= e($group['cn']) ?></strong>
                                                        <span class="table-sub"><?= e($group['dn']) ?></span>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <details class="ldap-attributes-details" open>
                                <summary>
                                    <span class="settings-emoji" aria-hidden="true">🧾</span>
                                    All readable attributes
                                    <em><?= count($detailAttributes) ?></em>
                                </summary>
                                <div class="admin-table-wrap ldap-attr-table-wrap">
                                    <table class="admin-table ldap-attr-table">
                                        <thead>
                                            <tr>
                                                <th>Attribute</th>
                                                <th>Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($detailAttributes as $attrName => $attrValue): ?>
                                                <tr>
                                                    <td><code><?= e((string) $attrName) ?></code></td>
                                                    <td>
                                                        <?php if (is_array($attrValue)): ?>
                                                            <ul class="ldap-attr-values">
                                                                <?php foreach ($attrValue as $one): ?>
                                                                    <li><?= e((string) $one) ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php else: ?>
                                                            <?= e((string) $attrValue) ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>

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
                                            <th>Details</th>
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
                                                    <form method="post" class="user-actions" action="#ldap-user-details">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="view_ldap_user">
                                                        <input type="hidden" name="ldap_username" value="<?= e($hit['username']) ?>">
                                                        <input type="hidden" name="ldap_search_query" value="<?= e($ldapSearchQuery) ?>">
                                                        <button type="submit" class="button ghost">LDAP details</button>
                                                    </form>
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
                        <form method="post" class="settings-form user-create-form" action="#ldap-user-details" style="margin-top: 0.75rem;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="view_ldap_user">
                            <div class="settings-grid">
                                <label class="settings-field">
                                    <span>Inspect LDAP username</span>
                                    <input type="text" name="ldap_username" required maxlength="120" autocomplete="off" placeholder="jsmith">
                                </label>
                            </div>
                            <div class="settings-actions">
                                <button type="submit" class="button ghost">View LDAP details</button>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>
            </section>

            <section class="upload-card settings-card" id="add-ldap-group">
                <h2><span class="settings-emoji" aria-hidden="true">👥</span> Add LDAP group</h2>
                <?php if (!$ldapEnabled): ?>
                    <p class="settings-hint">LDAP is disabled. Configure and enable it under <a href="authentication.php">Authentication</a> first.</p>
                <?php else: ?>
                    <p>Look up a directory group by full DN, CN, or sAMAccountName. Preview the members, then import selected people or the whole group as approved LDAP accounts.</p>
                    <form method="post" class="settings-form user-create-form" action="#add-ldap-group">
                        <?= csrf_field() ?>
                        <fieldset class="settings-fieldset settings-tone-violet">
                            <legend><span class="settings-emoji" aria-hidden="true">🧾</span> Directory group</legend>
                            <p class="settings-hint">Examples: <code>Risk Register Users</code> or <code>CN=Risk Register Users,OU=Groups,DC=multihosp,DC=net</code></p>
                            <div class="settings-grid">
                                <label class="settings-field settings-span-all">
                                    <span><span class="settings-emoji" aria-hidden="true">🏷️</span> Group DN, CN, or sAMAccountName</span>
                                    <input type="text" name="ldap_group_dn" required maxlength="512" autocomplete="off" placeholder="CN=Group Name,OU=Groups,DC=example,DC=com" value="<?= e($ldapGroupQuery !== '' ? $ldapGroupQuery : (string) ($_POST['ldap_group_dn'] ?? '')) ?>">
                                </label>
                                <label class="remember-row settings-span-all">
                                    <input type="checkbox" name="is_admin" value="1" <?= !empty($_POST['is_admin']) ? 'checked' : '' ?>>
                                    <span><span class="settings-emoji" aria-hidden="true">🛡️</span> Make imported members administrators</span>
                                </label>
                            </div>
                        </fieldset>
                        <div class="settings-actions">
                            <button type="submit" class="button ghost" name="action" value="preview_ldap_group">🔎 Preview members</button>
                            <button type="submit" class="button button-primary" name="action" value="create_ldap_group">➕ Import all members</button>
                        </div>
                    </form>
                    <?php if (is_array($ldapGroupPreview)): ?>
                        <?php $groupMembers = $ldapGroupPreview['members']; ?>
                        <form method="post" class="settings-form" action="#add-ldap-group" id="ldap-group-import-form" style="margin-top: 1rem;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="import_ldap_group_selected">
                            <input type="hidden" name="ldap_group_dn" value="<?= e((string) $ldapGroupPreview['dn']) ?>">
                            <?php if (!empty($_POST['is_admin'])): ?>
                                <input type="hidden" name="is_admin" value="1">
                            <?php endif; ?>
                            <div class="bulk-response-bar user-bulk-bar">
                                <label class="bulk-select-all">
                                    <input type="checkbox" class="js-bulk-select-all" data-bulk-form="ldap-group-import-form" title="Select all listed members">
                                    <span>Select all</span>
                                </label>
                                <span class="bulk-selected-count" data-bulk-count="ldap-group-import-form">0 selected</span>
                                <button type="submit" class="button button-primary">➕ Add selected</button>
                            </div>
                            <p class="settings-hint">
                                Group <strong><?= e((string) $ldapGroupPreview['name']) ?></strong>
                                · <?= e((string) count($groupMembers)) ?> member<?= count($groupMembers) === 1 ? '' : 's' ?>
                                · <span class="table-sub"><?= e((string) $ldapGroupPreview['dn']) ?></span>
                            </p>
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead>
                                        <tr>
                                            <th class="col-select">Select</th>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($groupMembers as $member): ?>
                                            <?php
                                            $hitUser = $usersRepo->findByUsernameOrEmail($member['username']);
                                            if ($hitUser === null && $member['email'] !== '') {
                                                $hitUser = $usersRepo->findByUsernameOrEmail($member['email']);
                                            }
                                            $alreadyLdap = $hitUser !== null && ($hitUser['auth_source'] ?? '') === 'ldap';
                                            $alreadyLocal = $hitUser !== null && ($hitUser['auth_source'] ?? '') === 'local';
                                            ?>
                                            <tr>
                                                <td class="col-select">
                                                    <?php if ($alreadyLocal): ?>
                                                        <span class="settings-hint">—</span>
                                                    <?php else: ?>
                                                        <input type="checkbox" name="ldap_usernames[]" value="<?= e($member['username']) ?>" <?= $alreadyLdap ? '' : 'checked' ?>>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= e($member['display_name'] !== '' ? $member['display_name'] : $member['username']) ?></strong>
                                                    <?php if ($member['dn'] !== ''): ?>
                                                        <span class="table-sub"><?= e($member['dn']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= e($member['username']) ?></td>
                                                <td><?= e($member['email'] !== '' ? $member['email'] : '—') ?></td>
                                                <td>
                                                    <?php if ($alreadyLdap): ?>
                                                        <span class="token-ok">Already added</span>
                                                    <?php elseif ($alreadyLocal): ?>
                                                        <span class="token-needed">Local account exists</span>
                                                    <?php else: ?>
                                                        <span class="token-needed">Not in app</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="upload-card" id="all-users">
                <h2>All users</h2>
                <form method="get" class="settings-form" action="users.php#all-users" style="margin-bottom: 1rem;">
                    <?php if ($auditQuery !== ''): ?>
                        <input type="hidden" name="aq" value="<?= e($auditQuery) ?>">
                    <?php endif; ?>
                    <?php if ($auditPage > 1): ?>
                        <input type="hidden" name="apage" value="<?= (int) $auditPage ?>">
                    <?php endif; ?>
                    <?php if ($userPerPage !== 25): ?>
                        <input type="hidden" name="uper" value="<?= (int) $userPerPage ?>">
                    <?php endif; ?>
                    <?php if ($auditPerPage !== 25): ?>
                        <input type="hidden" name="aper" value="<?= (int) $auditPerPage ?>">
                    <?php endif; ?>
                    <div class="settings-grid">
                        <label class="settings-field settings-span-all">
                            <span>Search users</span>
                            <input type="search" name="uq" value="<?= e($userQuery) ?>" maxlength="120" placeholder="Username, email, name, added by, ldap/local, admin…" autocomplete="off">
                        </label>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="button button-primary">🔎 Search</button>
                        <?php if ($userQuery !== ''): ?>
                            <a class="button ghost" href="<?= e($usersPageUrl(['uq' => '', 'upage' => 1], '#all-users')) ?>">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($userTotal === 0): ?>
                    <p class="empty-results"><?= $userQuery !== '' ? 'No users matched your search.' : 'No users yet.' ?></p>
                <?php else: ?>
                    <p class="settings-hint">
                        Showing <?= e((string) ((($userPage - 1) * $userPerPage) + 1)) ?>–<?= e((string) min($userTotal, $userPage * $userPerPage)) ?>
                        of <?= e((string) $userTotal) ?>
                        <?= $userQuery !== '' ? ' matching' : '' ?> user<?= $userTotal === 1 ? '' : 's' ?>.
                    </p>
                    <form method="post" id="bulk-users-form" action="<?= e($usersPageUrl([], '#all-users')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="bulk_delete_users">
                    </form>
                    <div class="bulk-response-bar user-bulk-bar">
                        <label class="bulk-select-all">
                            <input type="checkbox" class="js-bulk-select-all" data-bulk-form="bulk-users-form" title="Select all listed users">
                            <span>Select all</span>
                        </label>
                        <span class="bulk-selected-count" data-bulk-count="bulk-users-form">0 selected</span>
                        <button type="submit" form="bulk-users-form" class="button ghost is-danger" onclick="return confirm('Delete the selected users permanently? This cannot be undone.');">🗑️ Delete selected</button>
                    </div>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th class="col-select">Select</th>
                                    <th>User</th>
                                    <th>Source</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Added</th>
                                    <th>Added by</th>
                                    <th>Last login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $user): ?>
                                    <tr>
                                        <td class="col-select">
                                            <?php if ((int) $user['id'] === (int) $currentUser['id']): ?>
                                                <span class="settings-hint" title="You cannot delete your own account">—</span>
                                            <?php else: ?>
                                                <input type="checkbox" form="bulk-users-form" name="user_ids[]" value="<?= (int) $user['id'] ?>">
                                            <?php endif; ?>
                                        </td>
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
                                        <td><?= e((string) ($user['created_at'] !== '' ? $user['created_at'] : '—')) ?></td>
                                        <td><?= e((string) (($user['created_by_username'] ?? '') !== '' ? $user['created_by_username'] : '—')) ?></td>
                                        <td><?= e((string) ($user['last_login'] ?: '—')) ?></td>
                                        <td>
                                            <div class="user-actions">
                                                <?php if (empty($user['is_approved'])): ?>
                                                    <form method="post">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="user-action-btn is-approve" title="Approve user" aria-label="Approve user">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="user-action-btn is-unapprove" title="Unapprove user" aria-label="Unapprove user">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                                <circle cx="12" cy="12" r="10"></circle>
                                                                <line x1="15" y1="9" x2="9" y2="15"></line>
                                                                <line x1="9" y1="9" x2="15" y2="15"></line>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (!empty($user['is_disabled'])): ?>
                                                    <form method="post">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                        <input type="hidden" name="action" value="enable">
                                                        <button type="submit" class="user-action-btn is-enable" title="Enable user" aria-label="Enable user">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                                                <path d="M7 11V7a5 5 0 0 1 9.9-1"></path>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                        <input type="hidden" name="action" value="disable">
                                                        <button type="submit" class="user-action-btn is-disable" title="Disable user" aria-label="Disable user">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (empty($user['is_admin'])): ?>
                                                    <form method="post">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                        <input type="hidden" name="action" value="promote">
                                                        <button type="submit" class="user-action-btn is-admin" title="Make administrator" aria-label="Make administrator">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                                                                <polyline points="9 12 11 14 15 10"></polyline>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                        <input type="hidden" name="action" value="demote">
                                                        <button type="submit" class="user-action-btn is-admin" title="Remove administrator" aria-label="Remove administrator">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                                <path d="M19.69 14a6.9 6.9 0 0 0 .31-2V5l-8-3-3.16 1.18"></path>
                                                                <path d="M4.73 4.73 4 5v7c0 6 8 10 8 10a33.4 33.4 0 0 0 5.94-2.82"></path>
                                                                <line x1="1" y1="1" x2="23" y2="23"></line>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if (($user['auth_source'] ?? '') === 'local'): ?>
                                                    <details class="inline-details user-reset-details">
                                                        <summary class="user-action-btn is-key" title="Reset password" aria-label="Reset password">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                                <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path>
                                                            </svg>
                                                        </summary>
                                                        <form method="post" class="updater-form updater-form-stack user-reset-panel">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                            <input type="hidden" name="action" value="reset_password">
                                                            <label class="file-input"><span>New password</span><input type="password" name="password" required minlength="8"></label>
                                                            <label class="file-input"><span>Confirm</span><input type="password" name="password_confirm" required minlength="8"></label>
                                                            <button type="submit" class="button button-primary">Save password</button>
                                                        </form>
                                                    </details>
                                                <?php elseif ($ldapEnabled && ($user['auth_source'] ?? '') === 'ldap'): ?>
                                                    <form method="post" action="#ldap-user-details">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="view_ldap_user">
                                                        <input type="hidden" name="ldap_username" value="<?= e((string) $user['username']) ?>">
                                                        <button type="submit" class="user-action-btn is-ldap-details" title="LDAP details" aria-label="LDAP details">
                                                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                                <circle cx="11" cy="11" r="8"></circle>
                                                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                            </svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" onsubmit="return confirm('Delete this user permanently?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="user-action-btn is-danger" title="Delete user" aria-label="Delete user">
                                                        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
                                                            <polyline points="3 6 5 6 21 6"></polyline>
                                                            <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"></path>
                                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                                        </svg>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <nav class="pagination" aria-label="User list pages">
                        <div class="pagination-controls">
                            <?php if ($userTotalPages > 1): ?>
                                <?php if ($userPage > 1): ?>
                                    <a class="button ghost" href="<?= e($usersPageUrl(['upage' => $userPage - 1], '#all-users')) ?>">← Previous</a>
                                <?php else: ?>
                                    <span class="button ghost is-disabled" aria-disabled="true">← Previous</span>
                                <?php endif; ?>
                                <span class="pagination-pages">
                                    <?php
                                    $windowStart = max(1, $userPage - 2);
                                    $windowEnd = min($userTotalPages, $userPage + 2);
                                    for ($pageNum = $windowStart; $pageNum <= $windowEnd; $pageNum++):
                                    ?>
                                        <?php if ($pageNum === $userPage): ?>
                                            <span class="pagination-page is-current" aria-current="page"><?= $pageNum ?></span>
                                        <?php else: ?>
                                            <a class="pagination-page" href="<?= e($usersPageUrl(['upage' => $pageNum], '#all-users')) ?>"><?= $pageNum ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </span>
                                <?php if ($userPage < $userTotalPages): ?>
                                    <a class="button ghost" href="<?= e($usersPageUrl(['upage' => $userPage + 1], '#all-users')) ?>">Next →</a>
                                <?php else: ?>
                                    <span class="button ghost is-disabled" aria-disabled="true">Next →</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <form method="get" class="pagination-per-page" action="users.php#all-users">
                            <?php if ($userQuery !== ''): ?>
                                <input type="hidden" name="uq" value="<?= e($userQuery) ?>">
                            <?php endif; ?>
                            <?php if ($auditQuery !== ''): ?>
                                <input type="hidden" name="aq" value="<?= e($auditQuery) ?>">
                            <?php endif; ?>
                            <?php if ($auditPage > 1): ?>
                                <input type="hidden" name="apage" value="<?= (int) $auditPage ?>">
                            <?php endif; ?>
                            <?php if ($auditPerPage !== 25): ?>
                                <input type="hidden" name="aper" value="<?= (int) $auditPerPage ?>">
                            <?php endif; ?>
                            <label>
                                <span>Rows per page</span>
                                <select name="uper" onchange="this.form.submit()">
                                    <?php foreach ($allowedPerPage as $size): ?>
                                        <option value="<?= (int) $size ?>"<?= $userPerPage === $size ? ' selected' : '' ?>><?= (int) $size ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </form>
                    </nav>
                <?php endif; ?>
            </section>

            <section class="upload-card" id="audit-log">
                <h2>Audit log</h2>
                <form method="get" class="settings-form" action="users.php#audit-log" style="margin-bottom: 1rem;">
                    <?php if ($userQuery !== ''): ?>
                        <input type="hidden" name="uq" value="<?= e($userQuery) ?>">
                    <?php endif; ?>
                    <?php if ($userPage > 1): ?>
                        <input type="hidden" name="upage" value="<?= (int) $userPage ?>">
                    <?php endif; ?>
                    <?php if ($userPerPage !== 25): ?>
                        <input type="hidden" name="uper" value="<?= (int) $userPerPage ?>">
                    <?php endif; ?>
                    <?php if ($auditPerPage !== 25): ?>
                        <input type="hidden" name="aper" value="<?= (int) $auditPerPage ?>">
                    <?php endif; ?>
                    <div class="settings-grid">
                        <label class="settings-field settings-span-all">
                            <span>Search events</span>
                            <input type="search" name="aq" value="<?= e($auditQuery) ?>" maxlength="120" placeholder="Event, actor, target, IP, or date" autocomplete="off">
                        </label>
                    </div>
                    <div class="settings-actions">
                        <button type="submit" class="button button-primary">🔎 Search</button>
                        <?php if ($auditQuery !== ''): ?>
                            <a class="button ghost" href="<?= e($usersPageUrl(['aq' => '', 'apage' => 1], '#audit-log')) ?>">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($auditTotal === 0): ?>
                    <p class="empty-results"><?= $auditQuery !== '' ? 'No audit events matched your search.' : 'No events yet.' ?></p>
                <?php else: ?>
                    <p class="settings-hint">
                        Showing <?= e((string) ((($auditPage - 1) * $auditPerPage) + 1)) ?>–<?= e((string) min($auditTotal, $auditPage * $auditPerPage)) ?>
                        of <?= e((string) $auditTotal) ?>
                        <?= $auditQuery !== '' ? ' matching' : '' ?> event<?= $auditTotal === 1 ? '' : 's' ?>.
                    </p>
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
                    <nav class="pagination" aria-label="Audit log pages">
                        <div class="pagination-controls">
                            <?php if ($auditTotalPages > 1): ?>
                                <?php if ($auditPage > 1): ?>
                                    <a class="button ghost" href="<?= e($usersPageUrl(['apage' => $auditPage - 1], '#audit-log')) ?>">← Previous</a>
                                <?php else: ?>
                                    <span class="button ghost is-disabled" aria-disabled="true">← Previous</span>
                                <?php endif; ?>
                                <span class="pagination-pages">
                                    <?php
                                    $windowStart = max(1, $auditPage - 2);
                                    $windowEnd = min($auditTotalPages, $auditPage + 2);
                                    for ($pageNum = $windowStart; $pageNum <= $windowEnd; $pageNum++):
                                    ?>
                                        <?php if ($pageNum === $auditPage): ?>
                                            <span class="pagination-page is-current" aria-current="page"><?= $pageNum ?></span>
                                        <?php else: ?>
                                            <a class="pagination-page" href="<?= e($usersPageUrl(['apage' => $pageNum], '#audit-log')) ?>"><?= $pageNum ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </span>
                                <?php if ($auditPage < $auditTotalPages): ?>
                                    <a class="button ghost" href="<?= e($usersPageUrl(['apage' => $auditPage + 1], '#audit-log')) ?>">Next →</a>
                                <?php else: ?>
                                    <span class="button ghost is-disabled" aria-disabled="true">Next →</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <form method="get" class="pagination-per-page" action="users.php#audit-log">
                            <?php if ($userQuery !== ''): ?>
                                <input type="hidden" name="uq" value="<?= e($userQuery) ?>">
                            <?php endif; ?>
                            <?php if ($userPage > 1): ?>
                                <input type="hidden" name="upage" value="<?= (int) $userPage ?>">
                            <?php endif; ?>
                            <?php if ($userPerPage !== 25): ?>
                                <input type="hidden" name="uper" value="<?= (int) $userPerPage ?>">
                            <?php endif; ?>
                            <?php if ($auditQuery !== ''): ?>
                                <input type="hidden" name="aq" value="<?= e($auditQuery) ?>">
                            <?php endif; ?>
                            <label>
                                <span>Rows per page</span>
                                <select name="aper" onchange="this.form.submit()">
                                    <?php foreach ($allowedPerPage as $size): ?>
                                        <option value="<?= (int) $size ?>"<?= $auditPerPage === $size ? ' selected' : '' ?>><?= (int) $size ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </form>
                    </nav>
                <?php endif; ?>
            </section>
<script>
(() => {
    const syncForm = (formId) => {
        const form = document.getElementById(formId);
        if (!form) {
            return;
        }
        const boxes = [...document.querySelectorAll('input[type="checkbox"][name]')]
            .filter((input) => (input.getAttribute('form') === formId || input.form === form) && input.name.endsWith('[]'));
        const selected = boxes.filter((input) => input.checked).length;
        document.querySelectorAll('[data-bulk-count="' + formId + '"]').forEach((label) => {
            label.textContent = selected + ' selected';
        });
        document.querySelectorAll('.js-bulk-select-all[data-bulk-form="' + formId + '"]').forEach((toggle) => {
            toggle.checked = boxes.length > 0 && selected === boxes.length;
            toggle.indeterminate = selected > 0 && selected < boxes.length;
        });
    };

    document.querySelectorAll('.js-bulk-select-all').forEach((toggle) => {
        toggle.addEventListener('change', () => {
            const formId = toggle.getAttribute('data-bulk-form') || '';
            const form = document.getElementById(formId);
            if (!form) {
                return;
            }
            document.querySelectorAll('input[type="checkbox"][name]').forEach((input) => {
                if ((input.getAttribute('form') === formId || input.form === form) && input.name.endsWith('[]') && !input.disabled) {
                    input.checked = toggle.checked;
                }
            });
            syncForm(formId);
        });
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox' || !target.name.endsWith('[]')) {
            return;
        }
        const form = target.form;
        if (form && form.id) {
            syncForm(form.id);
        }
    });

    document.querySelectorAll('.js-bulk-select-all').forEach((toggle) => {
        const formId = toggle.getAttribute('data-bulk-form');
        if (formId) {
            syncForm(formId);
        }
    });
})();
</script>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
