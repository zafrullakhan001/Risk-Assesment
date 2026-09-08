<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use RiskAssessment\AppModules;
use RiskAssessment\Auth;

$currentUser = $auth->requireAdmin();
$error = '';
$flash = '';
$apps = AppModules::instance();
$isSuperAdmin = Auth::isSuperAdmin($currentUser);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        if (!$isSuperAdmin) {
            throw new RuntimeException('Only the local superadmin can change which apps are enabled.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_apps') {
            $flags = [
                AppModules::RISK => !empty($_POST['app_risk_register_enabled']),
                AppModules::SHAREPOINT => !empty($_POST['app_sharepoint_enabled']),
                AppModules::TICKET => !empty($_POST['app_ticket_dossier_enabled']),
            ];
            foreach ($flags as $app => $on) {
                $settings->set(AppModules::settingKey($app), $on ? '1' : '0');
            }
            $auth->users()->logAudit(
                'settings.app_modules',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                [
                    'risk' => $flags[AppModules::RISK],
                    'sharepoint' => $flags[AppModules::SHAREPOINT],
                    'ticket' => $flags[AppModules::TICKET],
                ]
            );
            $flash = 'App availability saved. Disabled apps stay hidden from everyone except the local superadmin.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$riskOn = $apps->isEnabled(AppModules::RISK);
$sharepointOn = $apps->isEnabled(AppModules::SHAREPOINT);
$ticketOn = $apps->isEnabled(AppModules::TICKET);

$adminTitle = 'Apps';
$adminTab = 'apps';
$adminEyebrow = 'Modules';
$adminHeading = 'Enable or <em>disable</em> apps';
$adminIntro = 'Control whether Risk Register, SharePoint, and Ticket Dossier appear for signed-in users. The local superadmin always keeps access.';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card">
                <h2>Signed-in apps</h2>
                <p>When an app is off, its menu section is hidden and its pages return unavailable for everyone except the local superadmin. Public share links are not affected.</p>
                <?php if (!$isSuperAdmin): ?>
                    <p class="updater-warning">You can view these settings. Only the local superadmin can change them.</p>
                <?php endif; ?>
                <form method="post" class="updater-form updater-form-stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_apps">
                    <label class="remember-row">
                        <input type="checkbox" name="app_risk_register_enabled" value="1" <?= $riskOn ? 'checked' : '' ?> <?= $isSuperAdmin ? '' : 'disabled' ?>>
                        <span><strong>Risk Register</strong> — Find projects, Upload, Templates</span>
                    </label>
                    <label class="remember-row">
                        <input type="checkbox" name="app_sharepoint_enabled" value="1" <?= $sharepointOn ? 'checked' : '' ?> <?= $isSuperAdmin ? '' : 'disabled' ?>>
                        <span><strong>SharePoint</strong> — SharePoint, catalogs, Owners</span>
                    </label>
                    <label class="remember-row">
                        <input type="checkbox" name="app_ticket_dossier_enabled" value="1" <?= $ticketOn ? 'checked' : '' ?> <?= $isSuperAdmin ? '' : 'disabled' ?>>
                        <span><strong>Ticket Dossier</strong> — Ticket Analysis / Ticket Dossier</span>
                    </label>
                    <?php if ($isSuperAdmin): ?>
                        <button type="submit" class="button button-primary">Save apps</button>
                    <?php endif; ?>
                </form>
                <div class="auth-source-row" style="margin-top:1rem;">
                    <span class="auth-badge <?= $riskOn ? 'is-local' : 'is-off' ?>">Risk <?= $riskOn ? 'on' : 'off' ?></span>
                    <span class="auth-badge <?= $sharepointOn ? 'is-local' : 'is-off' ?>">SharePoint <?= $sharepointOn ? 'on' : 'off' ?></span>
                    <span class="auth-badge <?= $ticketOn ? 'is-local' : 'is-off' ?>">Ticket Dossier <?= $ticketOn ? 'on' : 'off' ?></span>
                </div>
            </section>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
