<?php

declare(strict_types=1);

/**
 * Recover a forgotten local superadmin password from the server CLI.
 *
 * This is not a web page. Run it in a terminal on the machine that hosts the app
 * (the folder that contains bin/ and public/). Do not expose it over HTTP.
 *
 * Usage:
 *   php bin/reset_admin_password.php <new-password>
 *   php bin/reset_admin_password.php <username> <new-password>
 *
 * One argument: reset the current superadmin (creates local admin if none exists).
 * Two arguments: reset or create that local username and restore admin access.
 *
 * Examples:
 *   php bin/reset_admin_password.php "NewPass!1"
 *   php bin/reset_admin_password.php admin "NewPass!1"
 *
 * XAMPP on Windows (from the app folder):
 *   C:\xampp\php\php.exe bin\reset_admin_password.php "NewPass!1"
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\Auth;
use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\UserRepository;

/**
 * @param array<int, string> $argv
 */
function reset_admin_print_usage(): void
{
    fwrite(STDERR, <<<'TXT'
Reset a forgotten local superadmin (or named admin) password from the server CLI.

Usage:
  php bin/reset_admin_password.php <new-password>
  php bin/reset_admin_password.php <username> <new-password>

The one-argument form finds the current superadmin. The two-argument form resets
a named local account (and creates it as an administrator if it does not exist).

The new password must be at least 8 characters, with one uppercase letter, one
number, and one special character. Quote the password if it contains ! or spaces.

This script is not a web page. Run it from a terminal in the application folder.

TXT);
}

$arg1 = trim((string) ($argv[1] ?? ''));
$arg2 = (string) ($argv[2] ?? '');

if ($arg1 === '' || in_array(strtolower($arg1), ['-h', '--help', 'help'], true)) {
    reset_admin_print_usage();
    exit($arg1 === '' ? 1 : 0);
}

if ($arg2 === '') {
    $requestedUsername = '';
    $password = $arg1;
} else {
    $requestedUsername = $arg1;
    $password = $arg2;
}

$isDefaultStarter = $requestedUsername === Auth::DEFAULT_ADMIN_USERNAME && $password === Auth::DEFAULT_ADMIN_PASSWORD;
$strength = $isDefaultStarter ? null : Auth::validatePasswordStrength($password);
if ($strength !== null) {
    fwrite(STDERR, $strength . PHP_EOL);
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Unable to hash the password.\n");
    exit(1);
}

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$users = new UserRepository(Database::connection($dbConfig));

$existing = null;
if ($requestedUsername !== '') {
    $existing = $users->findByUsernameOrEmail($requestedUsername);
} else {
    $existing = $users->findSuperAdmin();
    if ($existing === null) {
        $existing = $users->findByUsernameOrEmail(Auth::DEFAULT_ADMIN_USERNAME);
    }
}

if ($existing === null) {
    $username = $requestedUsername !== '' ? $requestedUsername : Auth::DEFAULT_ADMIN_USERNAME;
    $email = str_contains($username, '@') ? $username : $username . '@localhost';
    $asSuperAdmin = strcasecmp($username, Auth::DEFAULT_ADMIN_USERNAME) === 0
        || $users->findSuperAdmin() === null;
    $id = $users->createLocal($username, $email, $hash, true, true, $username, 'Recovered via CLI', null, 'CLI', $asSuperAdmin);
    $users->logAudit('user.cli_bootstrap', $id, $username, $id, $username, ['via' => 'reset_admin_password']);
    $role = $asSuperAdmin ? 'superadmin' : 'administrator';
    echo "Created {$role} {$username} (id {$id}). Sign in with that username and the password you just set." . PHP_EOL;
    exit(0);
}

if (($existing['auth_source'] ?? '') === 'ldap') {
    fwrite(STDERR, "Account " . (string) $existing['username'] . " uses LDAP. Reset the password in the directory, or pass a different local username.\n");
    exit(1);
}

$id = (int) $existing['id'];
$username = (string) $existing['username'];
$users->setPassword($id, $hash);
$users->setAdmin($id, true);
$users->setApproved($id, true);
$users->setDisabled($id, false);

$restoreSuperAdmin = !empty($existing['is_superadmin'])
    || (
        strcasecmp($username, Auth::DEFAULT_ADMIN_USERNAME) === 0
        && ($existing['auth_source'] ?? '') === 'local'
    );
if ($restoreSuperAdmin) {
    $users->setSuperAdmin($id, true);
}

$users->logAudit('user.cli_password_reset', $id, $username, $id, $username, ['via' => 'reset_admin_password']);
$role = $restoreSuperAdmin ? 'superadmin' : 'administrator';
echo "Reset password and restored {$role} access for {$username} (id {$id}). Sign in with that username." . PHP_EOL;
