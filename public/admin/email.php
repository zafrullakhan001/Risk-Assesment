<?php

declare(strict_types=1);

use RiskAssessment\Mail\EmailTemplates;
use RiskAssessment\Mail\SmtpMailer;
use RiskAssessment\Mail\SmtpSettings;

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$error = '';
$flash = '';
$testResult = null;

$smtpSettings = new SmtpSettings($settings, $crypto);
$mailer = new SmtpMailer();
$templates = new EmailTemplates($branding);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_smtp') {
            $smtpSettings->save($_POST);
            $auth->users()->logAudit(
                'settings.smtp',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'enabled' => !empty($_POST['smtp_enabled']),
                    'provider' => SmtpSettings::normalizeProvider((string) ($_POST['smtp_provider'] ?? '')),
                    'host' => trim((string) ($_POST['smtp_host'] ?? '')),
                ]
            );
            $flash = 'Email (SMTP) settings saved. Password is encrypted at rest.';
        } elseif ($action === 'test_smtp') {
            $config = $smtpSettings->configFromInput($_POST);
            $testTo = trim((string) ($_POST['test_to'] ?? ''));
            if ($testTo === '') {
                $testTo = trim((string) ($currentUser['email'] ?? ''));
            }
            $recipients = SmtpSettings::normalizeRecipients($testTo);
            if ($recipients === []) {
                throw new RuntimeException('Enter a valid recipient email for the test message.');
            }
            $testTo = $recipients[0];
            $message = $templates->smtpTest($testTo);
            $result = $mailer->send(
                $testTo,
                $message['subject'],
                $message['text'],
                $config,
                [],
                [
                    'html' => $message['html'],
                    'text' => $message['text'],
                ]
            );
            $auth->users()->logAudit(
                'settings.smtp_test',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'to' => $testTo,
                    'ok' => $result === true,
                    'provider' => SmtpSettings::normalizeProvider((string) ($_POST['smtp_provider'] ?? '')),
                ]
            );
            if ($result === true) {
                $testResult = ['success' => true, 'message' => 'Test email sent successfully to ' . $testTo . '.'];
                $flash = $testResult['message'];
            } else {
                $testResult = ['success' => false, 'message' => (string) $result];
                $error = 'Test email failed: ' . (string) $result;
            }
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        if ($testResult === null && (string) ($_POST['action'] ?? '') === 'test_smtp') {
            $testResult = ['success' => false, 'message' => $error];
        }
    }
}

$values = $smtpSettings->all(false);
$formProvider = $values['provider'];
$formHost = $values['host'];
$formPort = $values['port'];
$formEncryption = $values['encryption'];
$formUsername = $values['username'];
$formFromEmail = $values['from_email'];
$formFromName = $values['from_name'] !== '' ? $values['from_name'] : $branding->brandTitle();
$formEnabled = $values['enabled'];
$hasPassword = $values['has_password'];
$defaultTestTo = trim((string) ($currentUser['email'] ?? ''));

$adminTitle = 'Email';
$adminTab = 'email';
$adminEyebrow = 'Outbound mail';
$adminHeading = 'SMTP <em>email</em>';
$adminIntro = 'Configure Custom or Office 365 SMTP so you can send a test message and email public share links.';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card settings-card">
                <h2><span class="settings-emoji" aria-hidden="true">✉️</span> SMTP settings</h2>
                <p>Use <strong>Office 365</strong> for Microsoft mailboxes (same STARTTLS path as LinkNest) or <strong>Custom</strong> for any other SMTP server. Enable email, save, then send a test.</p>

                <form method="post" class="settings-form" id="smtp-settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_smtp" id="smtp-form-action">

                    <fieldset class="settings-fieldset settings-tone-violet">
                        <legend><span class="settings-emoji" aria-hidden="true">🔌</span> Provider</legend>
                        <p class="settings-hint">Office 365 fills host, port, and encryption. Custom lets you edit them.</p>
                        <div class="settings-grid">
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">🏷️</span> Provider</span>
                                <select name="smtp_provider" id="smtp_provider">
                                    <option value="custom"<?= $formProvider === 'custom' ? ' selected' : '' ?>>Custom SMTP</option>
                                    <option value="office365"<?= $formProvider === 'office365' ? ' selected' : '' ?>>Office 365 / Microsoft 365</option>
                                </select>
                            </label>
                        </div>
                        <div class="smtp-provider-help" id="smtp-help-custom"<?= $formProvider === 'office365' ? ' hidden' : '' ?>>
                            <p class="settings-hint">Enter your mail server host and port. For internal relays on <strong>port 25</strong>, set Encryption to <strong>None</strong>. Username and password are optional — leave blank for open relays. If tests hang or show 421/empty replies, disable antivirus <strong>Mail Shield</strong> outbound SMTP scanning (Avast/AVG/etc.) or exclude your SMTP host.</p>
                        </div>
                        <div class="smtp-provider-help smtp-provider-help-o365" id="smtp-help-office365"<?= $formProvider === 'office365' ? '' : ' hidden' ?>>
                            <p><strong>Office 365:</strong> Uses <code>smtp.office365.com</code> on port <code>587</code> with STARTTLS. Enable Authenticated SMTP for the mailbox in the Microsoft 365 admin center. Username must be the full mailbox email. If MFA is on, use an app password. From address should match that mailbox (or an allowed send-as address).</p>
                        </div>
                        <div class="smtp-enable-box">
                            <label class="remember-row">
                                <input type="checkbox" name="smtp_enabled" value="1"<?= $formEnabled ? ' checked' : '' ?>>
                                <span><strong>Enable outbound email</strong> — required before share panels show “Email a public link”</span>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-sky">
                        <legend><span class="settings-emoji" aria-hidden="true">🖥️</span> Server</legend>
                        <div class="settings-grid">
                            <label class="settings-field">
                                <span>SMTP host</span>
                                <input type="text" name="smtp_host" id="smtp_host" value="<?= e($formHost) ?>" maxlength="200" placeholder="smtp.office365.com" autocomplete="off">
                            </label>
                            <label class="settings-field">
                                <span>Port</span>
                                <input type="number" name="smtp_port" id="smtp_port" value="<?= (int) $formPort ?>" min="1" max="65535" placeholder="587">
                            </label>
                            <label class="settings-field settings-span-all">
                                <span>Encryption</span>
                                <select name="smtp_encryption" id="smtp_encryption">
                                    <option value="none"<?= $formEncryption === 'none' ? ' selected' : '' ?>>None</option>
                                    <option value="ssl"<?= $formEncryption === 'ssl' ? ' selected' : '' ?>>SSL</option>
                                    <option value="tls"<?= $formEncryption === 'tls' ? ' selected' : '' ?>>TLS (STARTTLS)</option>
                                </select>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-amber">
                        <legend><span class="settings-emoji" aria-hidden="true">🔑</span> Authentication</legend>
                        <p class="settings-hint" id="smtp-auth-hint-custom"<?= $formProvider === 'office365' ? ' hidden' : '' ?>>Optional for Custom SMTP. Leave blank if your server only needs host and port.</p>
                        <p class="settings-hint" id="smtp-auth-hint-office365"<?= $formProvider === 'office365' ? '' : ' hidden' ?>>Required for Office 365. Use the full mailbox email and password (or app password).</p>
                        <div class="settings-grid">
                            <label class="settings-field">
                                <span>Username <em class="settings-optional">(optional for Custom)</em></span>
                                <input type="text" name="smtp_username" id="smtp_username" value="<?= e($formUsername) ?>" maxlength="200" placeholder="user@yourdomain.com" autocomplete="off">
                            </label>
                            <label class="settings-field">
                                <span>Password <em class="settings-optional">(optional for Custom)</em></span>
                                <input type="password" name="smtp_password" id="smtp_password" value="" maxlength="500" placeholder="<?= $hasPassword ? 'Leave blank to keep current' : 'SMTP password or app password' ?>" autocomplete="new-password">
                                <?php if ($hasPassword): ?>
                                    <small class="settings-help">A password is already stored (encrypted). Leave blank to keep it. Clear the username and save without a password only if you no longer need auth.</small>
                                <?php endif; ?>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-mint">
                        <legend><span class="settings-emoji" aria-hidden="true">📤</span> Sender</legend>
                        <div class="settings-grid">
                            <label class="settings-field">
                                <span>From email</span>
                                <input type="text" name="smtp_from_email" id="smtp_from_email" value="<?= e($formFromEmail) ?>" maxlength="200" placeholder="user@yourdomain.com or user@localhost" inputmode="email" autocomplete="off">
                            </label>
                            <label class="settings-field">
                                <span>From name</span>
                                <input type="text" name="smtp_from_name" id="smtp_from_name" value="<?= e($formFromName) ?>" maxlength="120" placeholder="<?= e($branding->brandTitle()) ?>">
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-teal">
                        <legend><span class="settings-emoji" aria-hidden="true">🧪</span> Send test email</legend>
                        <p class="settings-hint">Sends a branded HTML test using the values in this form (including an unsaved password). Defaults to your account email.</p>
                        <div class="settings-grid">
                            <label class="settings-field settings-span-all">
                                <span>Send test to</span>
                                <input type="text" name="test_to" id="smtp_test_to" value="<?= e($defaultTestTo) ?>" maxlength="200" placeholder="you@example.com" inputmode="email" autocomplete="email" form="smtp-settings-form">
                            </label>
                        </div>
                        <?php if (is_array($testResult)): ?>
                            <div class="alert <?= !empty($testResult['success']) ? 'alert-success' : 'alert-error' ?>" style="margin-top:12px;">
                                <?= e((string) ($testResult['message'] ?? '')) ?>
                            </div>
                        <?php endif; ?>
                    </fieldset>

                    <div class="settings-actions">
                        <button type="submit" class="button button-primary" id="smtp-save-btn">💾 Save SMTP settings</button>
                        <button type="submit" class="button ghost-light" id="smtp-test-btn" formaction="" onclick="document.getElementById('smtp-form-action').value='test_smtp';">🧪 Send test email</button>
                    </div>
                </form>
            </section>

            <script>
            (function () {
                var provider = document.getElementById('smtp_provider');
                var host = document.getElementById('smtp_host');
                var port = document.getElementById('smtp_port');
                var encryption = document.getElementById('smtp_encryption');
                var helpCustom = document.getElementById('smtp-help-custom');
                var helpO365 = document.getElementById('smtp-help-office365');
                var authHintCustom = document.getElementById('smtp-auth-hint-custom');
                var authHintO365 = document.getElementById('smtp-auth-hint-office365');
                var form = document.getElementById('smtp-settings-form');
                var actionInput = document.getElementById('smtp-form-action');
                var saveBtn = document.getElementById('smtp-save-btn');

                function suggestEncryptionForPort() {
                    if (!port || !encryption || encryption.disabled) return;
                    var p = parseInt(port.value, 10);
                    if (p === 25 && encryption.value === 'tls') {
                        encryption.value = 'none';
                    } else if (p === 465 && encryption.value === 'none') {
                        encryption.value = 'ssl';
                    } else if (p === 587 && encryption.value === 'none') {
                        encryption.value = 'tls';
                    }
                }

                function applyProvider() {
                    var isO365 = provider && provider.value === 'office365';
                    if (helpCustom) helpCustom.hidden = !!isO365;
                    if (helpO365) helpO365.hidden = !isO365;
                    if (authHintCustom) authHintCustom.hidden = !!isO365;
                    if (authHintO365) authHintO365.hidden = !isO365;
                    if (isO365) {
                        if (host) { host.value = 'smtp.office365.com'; host.readOnly = true; }
                        if (port) { port.value = '587'; port.readOnly = true; }
                        if (encryption) { encryption.value = 'tls'; encryption.disabled = true; }
                    } else {
                        if (host) host.readOnly = false;
                        if (port) port.readOnly = false;
                        if (encryption) encryption.disabled = false;
                        suggestEncryptionForPort();
                    }
                }

                if (provider) {
                    provider.addEventListener('change', applyProvider);
                    applyProvider();
                }
                if (port) {
                    port.addEventListener('change', suggestEncryptionForPort);
                    port.addEventListener('blur', suggestEncryptionForPort);
                }

                if (form && actionInput) {
                    form.addEventListener('submit', function () {
                        if (encryption && encryption.disabled) {
                            encryption.disabled = false;
                        }
                    });
                    if (saveBtn) {
                        saveBtn.addEventListener('click', function () {
                            actionInput.value = 'save_smtp';
                        });
                    }
                }
            })();
            </script>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
