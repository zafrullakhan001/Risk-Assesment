<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\Auth;
use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\UserRepository;

$username = trim((string) ($argv[1] ?? 'admin'));
$password = (string) ($argv[2] ?? '');

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php bin/reset_admin_password.php <username> <new-password>\n");
    exit(1);
}

$isDefaultStarter = $username === Auth::DEFAULT_ADMIN_USERNAME && $password === Auth::DEFAULT_ADMIN_PASSWORD;
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
$existing = $users->findByUsernameOrEmail($username);

if ($existing === null) {
    $email = str_contains($username, '@') ? $username : $username . '@localhost';
    $id = $users->createLocal($username, $email, $hash, true, true, $username, 'Recovered via CLI', null, 'CLI');
    $users->logAudit('user.cli_bootstrap', $id, $username, $id, $username, ['via' => 'reset_admin_password']);
    echo "Created administrator {$username} (id {$id})." . PHP_EOL;
    exit(0);
}

$id = (int) $existing['id'];
$users->setPassword($id, $hash);
$users->setAdmin($id, true);
$users->setApproved($id, true);
$users->setDisabled($id, false);
$users->logAudit('user.cli_password_reset', $id, $username, $id, (string) $existing['username'], ['via' => 'reset_admin_password']);
echo "Reset password and restored admin access for {$username} (id {$id})." . PHP_EOL;
