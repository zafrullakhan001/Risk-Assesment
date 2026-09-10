<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RiskAssessment\AppModules;
use RiskAssessment\Auth;

$appModules = AppModules::instance();
$rawNext = trim((string) ($_GET['next'] ?? $_POST['next'] ?? ''));

if ($auth->currentUser() !== null && !$auth->needsSetup()) {
    $user = $auth->currentUser();
    $target = $rawNext !== ''
        ? $appModules->resolveNext($rawNext, $user)
        : $appModules->resumeOrHome($user);
    header('Location: ' . $target);
    exit;
}

$error = '';
$flash = '';
$mode = (string) ($_GET['mode'] ?? 'login');
$next = $rawNext !== '' ? $auth->safeNext($rawNext) : '';
$ldapServers = $auth->ldap()->servers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? 'login');
        if ($action === 'setup') {
            $auth->createFirstAdmin(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirm'] ?? '')
            );
            header('Location: admin/index.php');
            exit;
        }
        if ($action === 'register') {
            $auth->register(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirm'] ?? '')
            );
            $flash = 'Registration received. An administrator must approve the account before you can sign in.';
            $mode = 'login';
        } elseif ($action === 'login') {
            $user = $auth->login(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['auth_method'] ?? Auth::METHOD_AUTO),
                !empty($_POST['remember_me']),
                (int) ($_POST['ldap_server_index'] ?? 0)
            );
            $target = $rawNext !== ''
                ? $appModules->resolveNext($rawNext, $user)
                : $appModules->resumeOrHome($user);
            header('Location: ' . $target);
            exit;
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $mode = (string) ($_POST['action'] ?? $mode);
        if ($mode === 'setup' && !$auth->needsSetup()) {
            $mode = 'login';
        }
    }
}

$needsSetup = $auth->needsSetup();
$showRegister = !$needsSetup && $auth->registrationEnabled() && $mode === 'register';
$localOn = $auth->localEnabled();
$ldapOn = $auth->ldapEnabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $needsSetup ? 'Create administrator' : 'Sign in' ?> · <?= e(\RiskAssessment\Branding::current()->documentTitle()) ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="shell upload-page login-page">
        <header class="topbar topbar-uplift">
            <div class="brand">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e(\RiskAssessment\Branding::current()->brandTitle()) ?></div>
                    <h1><?= e(\RiskAssessment\Branding::current()->brandSubtitle()) ?></h1>
                </div>
            </div>
            <div class="topbar-actions">
                <?php require __DIR__ . '/includes/topbar-menu-start.php'; ?>
                <?php require __DIR__ . '/includes/topbar-menu-end.php'; ?>
            </div>
        </header>

        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow"><?= $needsSetup ? 'Administrator' : 'Authentication' ?></div>
                            <h2><?= $needsSetup ? 'Create the first <em>admin</em>' : 'Sign in to continue' ?></h2>
                            <p>
                                <?php if ($needsSetup): ?>
                                    Local and LDAP sign-in are available after this account exists. Choose a strong password.
                                <?php elseif ($showRegister): ?>
                                    Local accounts stay pending until an administrator approves them.
                                <?php else: ?>
                                    Use a local account or your directory credentials<?= $ldapOn ? ' (LDAP)' : '' ?>.
                                <?php endif; ?>
                            </p>
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

            <?php if ($needsSetup): ?>
                <section class="upload-card login-card">
                    <h2>Create administrator</h2>
                    <p>This is the first account on this install. It receives full admin access.</p>
                    <form method="post" class="updater-form updater-form-stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="setup">
                        <label class="file-input">
                            <span>Username</span>
                            <input type="text" name="username" required minlength="3" maxlength="80" autocomplete="username" value="<?= e((string) ($_POST['username'] ?? 'admin')) ?>">
                        </label>
                        <label class="file-input">
                            <span>Email</span>
                            <input type="email" name="email" required autocomplete="email" value="<?= e((string) ($_POST['email'] ?? 'admin@localhost')) ?>">
                        </label>
                        <label class="file-input">
                            <span>Password</span>
                            <input type="password" name="password" required minlength="8" autocomplete="new-password">
                        </label>
                        <label class="file-input">
                            <span>Confirm password</span>
                            <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
                        </label>
                        <p class="pat-fineprint">At least 8 characters, with an uppercase letter, a number, and a special character.</p>
                        <button type="submit" class="button button-primary">Create admin and continue</button>
                    </form>
                </section>
            <?php elseif ($showRegister): ?>
                <section class="upload-card login-card">
                    <h2>Register a local account</h2>
                    <form method="post" class="updater-form updater-form-stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="next" value="<?= e($next) ?>">
                        <label class="file-input">
                            <span>Username</span>
                            <input type="text" name="username" required minlength="3" maxlength="80" autocomplete="username" value="<?= e((string) ($_POST['username'] ?? '')) ?>">
                        </label>
                        <label class="file-input">
                            <span>Email</span>
                            <input type="email" name="email" required autocomplete="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>">
                        </label>
                        <label class="file-input">
                            <span>Password</span>
                            <input type="password" name="password" required minlength="8" autocomplete="new-password">
                        </label>
                        <label class="file-input">
                            <span>Confirm password</span>
                            <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
                        </label>
                        <button type="submit" class="button button-primary">Request access</button>
                    </form>
                    <p class="empty-results"><a href="login.php?mode=login">Back to sign in</a></p>
                </section>
            <?php else: ?>
                <section class="upload-card login-card">
                    <h2>Sign in</h2>
                    <?php if (!$localOn && !$ldapOn): ?>
                        <p class="updater-warning">No sign-in method is enabled. Use the first-run admin account or ask an administrator to turn local or LDAP authentication back on.</p>
                    <?php endif; ?>
                    <form method="post" class="updater-form updater-form-stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="next" value="<?= e($next) ?>">
                        <label class="file-input">
                            <span>Username or email</span>
                            <input type="text" name="username" required autocomplete="username" value="<?= e((string) ($_POST['username'] ?? '')) ?>">
                        </label>
                        <label class="file-input">
                            <span>Password</span>
                            <input type="password" name="password" required autocomplete="current-password">
                        </label>
                        <?php if ($localOn && $ldapOn): ?>
                            <label class="file-input">
                                <span>Method</span>
                                <select name="auth_method">
                                    <option value="auto" selected>Auto (LDAP, then local)</option>
                                    <option value="ldap">LDAP directory</option>
                                    <option value="local">Local account</option>
                                </select>
                            </label>
                        <?php elseif ($ldapOn): ?>
                            <input type="hidden" name="auth_method" value="ldap">
                        <?php else: ?>
                            <input type="hidden" name="auth_method" value="local">
                        <?php endif; ?>
                        <?php if ($ldapOn && count($ldapServers) > 1): ?>
                            <label class="file-input">
                                <span>LDAP server</span>
                                <select name="ldap_server_index">
                                    <?php foreach ($ldapServers as $index => $server): ?>
                                        <option value="<?= (int) $index ?>"><?= e((string) ($server['name'] ?: $server['server'] ?: 'Server ' . ($index + 1))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label class="remember-row">
                            <input type="checkbox" name="remember_me" value="1">
                            <span>Remember me for 30 days</span>
                        </label>
                        <button type="submit" class="button button-primary">Sign in</button>
                    </form>
                    <?php
                    $starter = $auth->users()->findByUsernameOrEmail(Auth::DEFAULT_ADMIN_USERNAME);
                    if (is_array($starter) && Auth::usesDefaultPassword($starter)):
                    ?>
                        <p class="pat-fineprint">Starter local account: <code>admin</code> / <code>admin123</code>. Change it in Admin after you sign in.</p>
                    <?php endif; ?>
                    <?php if ($auth->registrationEnabled()): ?>
                        <p class="empty-results"><a href="login.php?mode=register">Register a local account</a></p>
                    <?php endif; ?>
                    <div class="auth-source-row">
                        <span class="auth-badge <?= $localOn ? 'is-local' : 'is-off' ?>">Local <?= $localOn ? 'on' : 'off' ?></span>
                        <span class="auth-badge <?= $ldapOn ? 'is-ldap' : 'is-off' ?>">LDAP <?= $ldapOn ? 'on' : 'off' ?></span>
                    </div>
                </section>
            <?php endif; ?>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
</body>
</html>
