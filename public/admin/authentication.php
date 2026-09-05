<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$error = '';
$flash = '';
$testResult = null;
$userTestResult = null;
$ldap = $auth->ldap();

function admin_bool_flag(array $post, string $key): string
{
    return !empty($post[$key]) ? '1' : '0';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_methods') {
            $localOn = !empty($_POST['local_auth_enabled']);
            $ldapOn = !empty($_POST['ldap_enabled']);
            if (!$localOn && !$ldapOn) {
                throw new RuntimeException('Keep at least one sign-in method enabled.');
            }
            $settings->set('local_auth_enabled', $localOn ? '1' : '0');
            $settings->set('local_registration_enabled', !empty($_POST['local_registration_enabled']) ? '1' : '0');
            $settings->set('ldap_enabled', $ldapOn ? '1' : '0');
            $settings->set('ldap_auto_create_users', !empty($_POST['ldap_auto_create_users']) ? '1' : '0');
            $settings->set('ldap_auto_update_users', !empty($_POST['ldap_auto_update_users']) ? '1' : '0');
            $settings->set('ldap_auto_approve', !empty($_POST['ldap_auto_approve']) ? '1' : '0');
            $auth->users()->logAudit(
                'settings.auth_methods',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['local' => $localOn, 'ldap' => $ldapOn]
            );
            $flash = 'Authentication methods saved.';
        } elseif ($action === 'save_ldap' || $action === 'test_ldap') {
            $server = array_merge(\RiskAssessment\LdapAuth::defaultServer(), [
                'name' => trim((string) ($_POST['name'] ?? 'Primary')),
                'server' => trim((string) ($_POST['server'] ?? '')),
                'port' => (int) ($_POST['port'] ?? 389),
                'protocol' => (string) ($_POST['protocol'] ?? 'ldap'),
                'tls' => admin_bool_flag($_POST, 'tls'),
                'timeout' => (int) ($_POST['timeout'] ?? 30),
                'bind_dn' => trim((string) ($_POST['bind_dn'] ?? '')),
                'bind_password' => (string) ($_POST['bind_password'] ?? ''),
                'user_search_base' => trim((string) ($_POST['user_search_base'] ?? '')),
                'user_filter' => trim((string) ($_POST['user_filter'] ?? '(sAMAccountName={username})')),
                'user_attributes' => trim((string) ($_POST['user_attributes'] ?? 'sAMAccountName,mail,displayName,memberOf')),
                'email_attribute' => trim((string) ($_POST['email_attribute'] ?? 'mail')),
                'display_name_attribute' => trim((string) ($_POST['display_name_attribute'] ?? 'displayName')),
                'login_domain' => trim((string) ($_POST['login_domain'] ?? '')),
                'user_dn_template' => trim((string) ($_POST['user_dn_template'] ?? '')),
                'search_scope' => (string) ($_POST['search_scope'] ?? 'sub'),
                'require_group_membership' => admin_bool_flag($_POST, 'require_group_membership'),
                'required_groups' => (string) ($_POST['required_groups'] ?? ''),
                'denied_groups' => (string) ($_POST['denied_groups'] ?? ''),
                'ssl_verify' => admin_bool_flag($_POST, 'ssl_verify'),
                'referrals' => admin_bool_flag($_POST, 'referrals'),
            ]);

            // Common AD misconfig: ldaps:// on port 389 (plain LDAP). Auto-correct.
            if (($server['protocol'] ?? '') === 'ldaps' && (int) $server['port'] === 389) {
                $server['protocol'] = 'ldap';
                $server['tls'] = '0';
            }

            if ($action === 'save_ldap') {
                if ($server['server'] === '') {
                    throw new RuntimeException('LDAP server host is required.');
                }
                $ldap->saveServers([$server]);
                $auth->users()->logAudit(
                    'settings.ldap',
                    (int) $currentUser['id'],
                    (string) $currentUser['username'],
                    null,
                    null,
                    ['server' => $server['server'], 'port' => $server['port']]
                );
                $flash = 'LDAP server saved. Bind password is encrypted at rest.';
            } else {
                $testResult = $ldap->testConnection($server);
                $flash = $testResult['success'] ? $testResult['message'] : '';
                if (!$testResult['success']) {
                    $error = $testResult['message'];
                }
            }
        } elseif ($action === 'test_ldap_user') {
            $testUsername = trim((string) ($_POST['test_username'] ?? ''));
            $testPassword = (string) ($_POST['test_password'] ?? '');
            if ($testUsername === '' || $testPassword === '') {
                throw new RuntimeException('Enter an LDAP username and password to test.');
            }

            $servers = $ldap->servers();
            if ($servers === []) {
                throw new RuntimeException('Save an LDAP server configuration before testing a user login.');
            }
            $server = $servers[0];
            if (trim((string) ($server['server'] ?? '')) === '') {
                throw new RuntimeException('LDAP server host is not configured. Save the LDAP server first.');
            }

            $profile = $ldap->authenticateAgainstServer($server, $testUsername, $testPassword);
            $provision = !empty($_POST['test_provision']);
            $provisionNote = 'Local account was not created (provision option was off).';
            if ($provision) {
                $user = $auth->users()->upsertLdapUser(
                    $profile,
                    $settings->get('ldap_auto_create_users', '1') === '1',
                    $settings->get('ldap_auto_update_users', '1') === '1',
                    $settings->get('ldap_auto_approve', '1') === '1',
                    (int) $currentUser['id'],
                    (string) $currentUser['username']
                );
                $status = (string) ($user['status'] ?? 'unknown');
                $provisionNote = 'Local account ready: '
                    . (string) $user['username']
                    . ' (id ' . (int) $user['id'] . ', status ' . $status . ').';
            }

            $auth->users()->logAudit(
                'settings.ldap_user_test',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'username' => $profile['username'],
                    'provisioned' => $provision,
                ]
            );

            $userTestResult = [
                'success' => true,
                'username' => $profile['username'],
                'email' => $profile['email'],
                'display_name' => $profile['display_name'],
                'dn' => $profile['dn'],
                'groups' => $profile['groups'],
                'provision_note' => $provisionNote,
            ];
            $flash = 'LDAP user login succeeded for ' . $profile['username'] . '. ' . $provisionNote;
        } elseif ($action === 'export_ldap') {
            $includeSecrets = !empty($_POST['include_secrets']);
            $payload = $ldap->exportSettings($includeSecrets);
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Unable to encode LDAP settings as JSON.');
            }
            $filename = 'ldap-settings-' . gmdate('Ymd-His') . '.json';
            $auth->users()->logAudit(
                'settings.ldap_export',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['secrets' => $includeSecrets, 'servers' => count($payload['servers'])]
            );
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
            header('Content-Length: ' . (string) strlen($json));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            echo $json;
            exit;
        } elseif ($action === 'import_ldap') {
            $file = $_FILES['ldap_json'] ?? null;
            if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a JSON file to import.');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException('Uploaded LDAP settings file is not valid.');
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size < 2 || $size > 1_048_576) {
                throw new RuntimeException('LDAP settings file must be between 2 bytes and 1 MB.');
            }
            $name = (string) ($file['name'] ?? '');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '' && $ext !== 'json') {
                throw new RuntimeException('Import file must be a .json file.');
            }
            $raw = file_get_contents($tmp);
            if ($raw === false || trim($raw) === '') {
                throw new RuntimeException('Unable to read the uploaded JSON file.');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('LDAP import file is not valid JSON.');
            }
            $ldap->importSettings($decoded);
            $auth->users()->logAudit(
                'settings.ldap_import',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['servers' => is_array($decoded['servers'] ?? null) ? count($decoded['servers']) : 0]
            );
            $flash = 'LDAP settings imported from JSON. Re-enter the bind password if the file omitted secrets.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$servers = $ldap->servers();
$server = $servers[0] ?? \RiskAssessment\LdapAuth::defaultServer();
$hasBindPassword = trim((string) ($server['bind_password'] ?? '')) !== '';

$adminTitle = 'Authentication';
$adminTab = 'authentication';
$adminEyebrow = 'Directory and local';
$adminHeading = 'Local and <em>LDAP</em> sign-in';
$adminIntro = 'Same model as LinkNest: local password accounts plus optional Active Directory / LDAP bind.';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card">
                <h2>Sign-in methods</h2>
                <p>At least one method must stay on. Auto login tries LDAP first, then local (unless the username contains <code>@localhost</code>).</p>
                <form method="post" class="updater-form updater-form-stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_methods">
                    <label class="remember-row">
                        <input type="checkbox" name="local_auth_enabled" value="1" <?= $auth->localEnabled() ? 'checked' : '' ?>>
                        <span>Enable local username/password</span>
                    </label>
                    <label class="remember-row">
                        <input type="checkbox" name="local_registration_enabled" value="1" <?= $auth->registrationEnabled() ? 'checked' : '' ?>>
                        <span>Allow people to register local accounts (pending approval)</span>
                    </label>
                    <label class="remember-row">
                        <input type="checkbox" name="ldap_enabled" value="1" <?= $auth->ldapEnabled() ? 'checked' : '' ?>>
                        <span>Enable LDAP / Active Directory</span>
                    </label>
                    <label class="remember-row">
                        <input type="checkbox" name="ldap_auto_create_users" value="1" <?= $settings->get('ldap_auto_create_users', '1') === '1' ? 'checked' : '' ?>>
                        <span>Auto-create users on first successful LDAP login</span>
                    </label>
                    <label class="remember-row">
                        <input type="checkbox" name="ldap_auto_update_users" value="1" <?= $settings->get('ldap_auto_update_users', '1') === '1' ? 'checked' : '' ?>>
                        <span>Update email and display name from LDAP on each login</span>
                    </label>
                    <label class="remember-row">
                        <input type="checkbox" name="ldap_auto_approve" value="1" <?= $settings->get('ldap_auto_approve', '1') === '1' ? 'checked' : '' ?>>
                        <span>Auto-approve newly provisioned LDAP users</span>
                    </label>
                    <button type="submit" class="button button-primary">Save methods</button>
                </form>
            </section>

            <section class="upload-card settings-card ldap-card">
                <h2><span class="settings-emoji" aria-hidden="true">🛰️</span> LDAP server</h2>
                <p>Use a service account bind, then search for the user and bind as that user to verify the password. User passwords are never stored.</p>
                <?php if (!extension_loaded('ldap')): ?>
                    <p class="updater-warning">PHP LDAP extension is not loaded. Enable <code>extension=ldap</code> in <code>php.ini</code> and restart Apache.</p>
                <?php endif; ?>
                <form method="post" class="settings-form ldap-form">
                    <?= csrf_field() ?>

                    <fieldset class="settings-fieldset settings-tone-teal">
                        <legend><span class="settings-emoji" aria-hidden="true">🔌</span> Connection</legend>
                        <p class="settings-hint">How this app reaches the directory.</p>
                        <div class="settings-grid settings-grid-connection">
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🏷️</span> Display name</span>
                                <input type="text" name="name" value="<?= e((string) $server['name']) ?>" maxlength="120" autocomplete="off">
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🖥️</span> Server host</span>
                                <input type="text" name="server" value="<?= e((string) $server['server']) ?>" placeholder="dc1.corp.example.com" spellcheck="false" autocomplete="off">
                            </label>
                        </div>
                        <div class="settings-grid settings-grid-compact">
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🔢</span> Port</span>
                                <input type="number" name="port" value="<?= (int) $server['port'] ?>" min="1" max="65535">
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🔗</span> Protocol</span>
                                <select name="protocol">
                                    <option value="ldap" <?= ($server['protocol'] ?? '') === 'ldap' ? 'selected' : '' ?>>ldap:// (port 389)</option>
                                    <option value="ldaps" <?= ($server['protocol'] ?? '') === 'ldaps' ? 'selected' : '' ?>>ldaps:// (port 636)</option>
                                </select>
                                <small class="settings-help">For most Windows AD setups use ldap:// with port 389. ldaps:// needs a working certificate on 636.</small>
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">⏱️</span> Timeout</span>
                                <input type="number" name="timeout" value="<?= (int) $server['timeout'] ?>" min="1" max="60">
                                <small class="settings-help">Seconds</small>
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">📂</span> Search scope</span>
                                <select name="search_scope">
                                    <option value="sub" <?= ($server['search_scope'] ?? '') === 'sub' ? 'selected' : '' ?>>Subtree</option>
                                    <option value="one" <?= ($server['search_scope'] ?? '') === 'one' ? 'selected' : '' ?>>One level</option>
                                    <option value="base" <?= ($server['search_scope'] ?? '') === 'base' ? 'selected' : '' ?>>Base</option>
                                </select>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-violet">
                        <legend><span class="settings-emoji" aria-hidden="true">🔐</span> Service account</legend>
                        <p class="settings-hint">Used only to search for the user. The user’s password is checked with a second bind and is never saved.</p>
                        <div class="settings-grid">
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">🪪</span> Bind DN</span>
                                <input type="text" name="bind_dn" value="<?= e((string) $server['bind_dn']) ?>" placeholder="CN=svc,OU=Service,DC=corp,DC=com" spellcheck="false" autocomplete="off">
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🔑</span> Bind password</span>
                                <input type="password" name="bind_password" autocomplete="new-password" placeholder="<?= $hasBindPassword ? 'Saved — leave blank to keep' : 'Service account password' ?>">
                                <?php if ($hasBindPassword): ?>
                                    <small class="settings-help settings-help-ok">Encrypted password is already stored.</small>
                                <?php endif; ?>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-sky">
                        <legend><span class="settings-emoji" aria-hidden="true">🔎</span> User lookup</legend>
                        <p class="settings-hint">Find the account after the service bind. <code>{username}</code> is replaced with the sign-in name.</p>
                        <div class="settings-grid">
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">🌳</span> User search base</span>
                                <input type="text" name="user_search_base" value="<?= e((string) $server['user_search_base']) ?>" placeholder="OU=Users,DC=corp,DC=com" spellcheck="false" autocomplete="off">
                            </label>
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">🧪</span> User filter</span>
                                <input type="text" name="user_filter" value="<?= e((string) $server['user_filter']) ?>" spellcheck="false" autocomplete="off">
                            </label>
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">🧩</span> User DN template <em>optional</em></span>
                                <input type="text" name="user_dn_template" value="<?= e((string) $server['user_dn_template']) ?>" placeholder="CN={username},OU=Users,DC=corp,DC=com" spellcheck="false" autocomplete="off">
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🌐</span> Login domain <em>optional</em></span>
                                <input type="text" name="login_domain" value="<?= e((string) $server['login_domain']) ?>" placeholder="corp.example.com" spellcheck="false" autocomplete="off">
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-mint">
                        <legend><span class="settings-emoji" aria-hidden="true">🏷️</span> Attributes</legend>
                        <p class="settings-hint">Directory fields copied onto the local user record after a successful bind.</p>
                        <div class="settings-grid">
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">📋</span> User attributes</span>
                                <input type="text" name="user_attributes" value="<?= e((string) $server['user_attributes']) ?>" spellcheck="false" autocomplete="off">
                                <small class="settings-help">Comma-separated list requested from the directory.</small>
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">📧</span> Email attribute</span>
                                <input type="text" name="email_attribute" value="<?= e((string) $server['email_attribute']) ?>" spellcheck="false" autocomplete="off">
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🙂</span> Display name attribute</span>
                                <input type="text" name="display_name_attribute" value="<?= e((string) $server['display_name_attribute']) ?>" spellcheck="false" autocomplete="off">
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-amber">
                        <legend><span class="settings-emoji" aria-hidden="true">👥</span> Group access</legend>
                        <p class="settings-hint">Optional allow and deny lists. Use one group DN or name per line.</p>
                        <div class="settings-grid">
                            <label class="settings-field settings-field-allow">
                                <span><span class="settings-emoji" aria-hidden="true">✅</span> Required groups</span>
                                <textarea name="required_groups" rows="4" spellcheck="false"><?= e((string) $server['required_groups']) ?></textarea>
                            </label>
                            <label class="settings-field settings-field-deny">
                                <span><span class="settings-emoji" aria-hidden="true">🚫</span> Denied groups</span>
                                <textarea name="denied_groups" rows="4" spellcheck="false"><?= e((string) $server['denied_groups']) ?></textarea>
                            </label>
                            <label class="remember-row settings-span-all">
                                <input type="checkbox" name="require_group_membership" value="1" <?= ($server['require_group_membership'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span>Require membership in at least one required group</span>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-rose">
                        <legend><span class="settings-emoji" aria-hidden="true">🛡️</span> Security options</legend>
                        <div class="settings-checks">
                            <label class="remember-row">
                                <input type="checkbox" name="tls" value="1" <?= ($server['tls'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span>🔒 StartTLS (for ldap://, not ldaps://)</span>
                            </label>
                            <label class="remember-row">
                                <input type="checkbox" name="ssl_verify" value="1" <?= ($server['ssl_verify'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span>📜 Verify TLS certificate</span>
                            </label>
                            <label class="remember-row">
                                <input type="checkbox" name="referrals" value="1" <?= ($server['referrals'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span>↪️ Follow LDAP referrals</span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="settings-actions">
                        <button type="submit" class="button button-primary" name="action" value="save_ldap">💾 Save LDAP server</button>
                        <button type="submit" class="button ghost" name="action" value="test_ldap">🧪 Test connection</button>
                    </div>
                </form>
            </section>

            <section class="upload-card settings-card ldap-card">
                <h2><span class="settings-emoji" aria-hidden="true">👤</span> Test LDAP user login</h2>
                <p>Uses the <strong>saved</strong> LDAP server settings (same path as the login page). Enter a directory username and password to confirm that account can authenticate.</p>
                <form method="post" class="settings-form ldap-form" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test_ldap_user">
                    <fieldset class="settings-fieldset settings-tone-sky">
                        <legend><span class="settings-emoji" aria-hidden="true">🔑</span> Directory credentials</legend>
                        <div class="settings-grid">
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">👤</span> Username</span>
                                <input type="text" name="test_username" value="<?= e((string) ($_POST['test_username'] ?? '')) ?>" placeholder="sAMAccountName" spellcheck="false" autocomplete="off" required>
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🔒</span> Password</span>
                                <input type="password" name="test_password" autocomplete="new-password" required>
                            </label>
                            <label class="remember-row settings-span-all">
                                <input type="checkbox" name="test_provision" value="1" <?= (!isset($_POST['action']) || (string) ($_POST['action'] ?? '') !== 'test_ldap_user' || !empty($_POST['test_provision'])) ? 'checked' : '' ?>>
                                <span>Provision / refresh the local user record on success (same as a real LDAP login)</span>
                            </label>
                        </div>
                        <div class="settings-actions">
                            <button type="submit" class="button button-primary">🧪 Test user login</button>
                        </div>
                    </fieldset>
                </form>
                <?php if (is_array($userTestResult) && !empty($userTestResult['success'])): ?>
                    <div class="alert alert-success" style="margin-top:14px">
                        <p><strong>Login path OK</strong> for <?= e((string) $userTestResult['username']) ?>.</p>
                        <ul class="settings-help" style="margin:8px 0 0;padding-left:18px">
                            <li>Display name: <?= e((string) $userTestResult['display_name']) ?></li>
                            <li>Email: <?= e((string) $userTestResult['email']) ?></li>
                            <li>DN: <?= e((string) $userTestResult['dn']) ?></li>
                            <li><?= e((string) $userTestResult['provision_note']) ?></li>
                            <?php if (!empty($userTestResult['groups']) && is_array($userTestResult['groups'])): ?>
                                <li>Groups found: <?= e((string) count($userTestResult['groups'])) ?></li>
                            <?php endif; ?>
                        </ul>
                        <p class="settings-help" style="margin-top:10px">You can now sign in on the login page with this username and choose LDAP (or Auto).</p>
                    </div>
                <?php endif; ?>
            </section>

            <section class="upload-card settings-card ldap-card">
                <h2><span class="settings-emoji" aria-hidden="true">📦</span> Export / import LDAP</h2>
                <p>Download current LDAP methods and server settings as JSON, or restore them from a previously exported file. Empty bind passwords in an import keep the password already stored on this server.</p>
                <div class="settings-grid settings-grid-connection">
                    <form method="post" class="settings-form ldap-transfer-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="export_ldap">
                        <fieldset class="settings-fieldset settings-tone-teal">
                            <legend><span class="settings-emoji" aria-hidden="true">⬇️</span> Export</legend>
                            <p class="settings-hint">Creates a downloadable JSON backup of LDAP flags and server fields.</p>
                            <label class="remember-row">
                                <input type="checkbox" name="include_secrets" value="1" checked>
                                <span>Include bind password in plaintext</span>
                            </label>
                            <p class="settings-help">Treat exported files as secrets when this option is on.</p>
                            <div class="settings-actions">
                                <button type="submit" class="button button-primary">⬇️ Download JSON</button>
                            </div>
                        </fieldset>
                    </form>
                    <form method="post" enctype="multipart/form-data" class="settings-form ldap-transfer-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="import_ldap">
                        <fieldset class="settings-fieldset settings-tone-violet">
                            <legend><span class="settings-emoji" aria-hidden="true">⬆️</span> Import</legend>
                            <p class="settings-hint">Replaces the LDAP server list and LDAP method flags from the file.</p>
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">📄</span> JSON file</span>
                                <input type="file" name="ldap_json" accept=".json,application/json" required>
                            </label>
                            <div class="settings-actions">
                                <button type="submit" class="button button-primary">⬆️ Import JSON</button>
                            </div>
                        </fieldset>
                    </form>
                </div>
            </section>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
