<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$error = '';
$flash = '';
$testResult = null;
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
                                    <option value="ldap" <?= ($server['protocol'] ?? '') === 'ldap' ? 'selected' : '' ?>>ldap://</option>
                                    <option value="ldaps" <?= ($server['protocol'] ?? '') === 'ldaps' ? 'selected' : '' ?>>ldaps://</option>
                                </select>
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
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
