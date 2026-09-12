<?php

declare(strict_types=1);

/**
 * Daily (or on-demand) reminder job for due/overdue governance exceptions.
 *
 * Usage (XAMPP / Windows Task Scheduler example):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\RiskRegister\bin\exception_reminders.php
 *
 * Schedule once per day. Safe to re-run: each exception is marked reminded_at
 * after the first successful notify for the current expires_at.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\Branding;
use RiskAssessment\Crypto;
use RiskAssessment\Database\Database;
use RiskAssessment\ExceptionNotifier;
use RiskAssessment\Mail\SmtpSettings;
use RiskAssessment\Repositories\AssessmentAccessRepository;
use RiskAssessment\Repositories\FindingStatusRepository;
use RiskAssessment\Repositories\SettingsRepository;
use RiskAssessment\Repositories\UserNotificationRepository;
use RiskAssessment\Repositories\UserRepository;

$config = require dirname(__DIR__) . '/config/config.php';
$db = require dirname(__DIR__) . '/config/database.php';
$pdo = Database::connection($db);

$settings = new SettingsRepository($pdo);
$crypto = new Crypto(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.encryption_key');
$findings = new FindingStatusRepository($pdo);
$notifications = new UserNotificationRepository($pdo);
$access = new AssessmentAccessRepository($pdo);
$users = new UserRepository($pdo);
$smtp = new SmtpSettings($settings, $crypto);
$branding = new Branding(
    $settings,
    (string) $config['branding_dir'],
    (int) $config['branding_max_bytes']
);

$notifier = new ExceptionNotifier(
    $findings,
    $notifications,
    $access,
    $users,
    $smtp,
    $branding
);

$due = $findings->listDueForReminder(200);
$total = count($due);
$notifiedUsers = 0;
$emailSent = 0;
$marked = 0;

echo 'Exception reminders @ ' . date('c') . PHP_EOL;
echo 'Due items pending reminder: ' . $total . PHP_EOL;
echo 'SMTP enabled: ' . ($smtp->isEnabled() ? 'yes' : 'no') . PHP_EOL;

foreach ($due as $item) {
    $result = $notifier->notifyDueItem($item);
    $notifiedUsers += (int) ($result['notified_users'] ?? 0);
    $emailSent += (int) ($result['email_sent'] ?? 0);
    if (!empty($result['marked'])) {
        $marked++;
    }
    echo sprintf(
        " - [%d/%s] %s → users=%d email=%d marked=%s\n",
        (int) ($item['assessment_id'] ?? 0),
        (string) ($item['finding_id'] ?? ''),
        (string) ($item['expires_at'] ?? ''),
        (int) ($result['notified_users'] ?? 0),
        (int) ($result['email_sent'] ?? 0),
        !empty($result['marked']) ? 'yes' : 'no'
    );
}

echo "Done. marked={$marked} notified_users={$notifiedUsers} emails_sent={$emailSent}" . PHP_EOL;
exit(0);
