<?php

declare(strict_types=1);

use RiskAssessment\Auth;
use RiskAssessment\Branding;
use RiskAssessment\Crypto;
use RiskAssessment\Database\Database;
use RiskAssessment\ErrorHandler;
use RiskAssessment\LdapAuth;
use RiskAssessment\Repositories\SettingsRepository;
use RiskAssessment\Repositories\UserRepository;
use RiskAssessment\Security;
use RiskAssessment\Session;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Initialize security settings
Security::initialize();
Security::sendSecurityHeaders();

// Register error handler
ErrorHandler::register();

$config = require dirname(__DIR__) . '/config/config.php';
$dbConfig = require dirname(__DIR__) . '/config/database.php';
$pdo = Database::connection($dbConfig);
$settings = new SettingsRepository($pdo);
$users = new UserRepository($pdo);
$crypto = new Crypto(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.encryption_key');
$ldap = new LdapAuth($settings, $crypto);
Session::start();
$auth = new Auth($users, $settings, $ldap);
$branding = new Branding(
    $settings,
    (string) $config['branding_dir'],
    (int) $config['branding_max_bytes']
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$publicPrefix = $auth->publicPrefix();
$csrf = htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $token = htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }
}

if (!function_exists('require_valid_csrf')) {
    function require_valid_csrf(): void
    {
        if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid form submission. Please refresh and try again.');
        }
    }
}
