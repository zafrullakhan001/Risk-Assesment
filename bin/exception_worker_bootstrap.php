<?php

declare(strict_types=1);

/**
 * Shared bootstrap for exception worker CLIs (monitor / email sender / reminders).
 *
 * @return array{
 *   pdo: PDO,
 *   findings: \RiskAssessment\Repositories\FindingStatusRepository,
 *   notifier: \RiskAssessment\ExceptionNotifier,
 *   outbox: \RiskAssessment\Repositories\EmailOutboxRepository,
 *   smtp: \RiskAssessment\Mail\SmtpSettings
 * }
 */
function ra_exception_worker_bootstrap(): array
{
    require dirname(__DIR__) . '/vendor/autoload.php';

    $config = require dirname(__DIR__) . '/config/config.php';
    $db = require dirname(__DIR__) . '/config/database.php';
    $pdo = \RiskAssessment\Database\Database::connection($db);

    $settings = new \RiskAssessment\Repositories\SettingsRepository($pdo);
    $crypto = new \RiskAssessment\Crypto(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.encryption_key'
    );
    $findings = new \RiskAssessment\Repositories\FindingStatusRepository($pdo);
    $notifications = new \RiskAssessment\Repositories\UserNotificationRepository($pdo);
    $access = new \RiskAssessment\Repositories\AssessmentAccessRepository($pdo);
    $users = new \RiskAssessment\Repositories\UserRepository($pdo);
    $outbox = new \RiskAssessment\Repositories\EmailOutboxRepository($pdo);
    $smtp = new \RiskAssessment\Mail\SmtpSettings($settings, $crypto);
    $branding = new \RiskAssessment\Branding(
        $settings,
        (string) $config['branding_dir'],
        (int) $config['branding_max_bytes']
    );
    $notifier = new \RiskAssessment\ExceptionNotifier(
        $findings,
        $notifications,
        $access,
        $users,
        $outbox,
        $branding
    );

    return [
        'pdo' => $pdo,
        'findings' => $findings,
        'notifier' => $notifier,
        'outbox' => $outbox,
        'smtp' => $smtp,
    ];
}
