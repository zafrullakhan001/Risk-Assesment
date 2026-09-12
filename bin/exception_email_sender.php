<?php

declare(strict_types=1);

/**
 * Exception Email Sender worker — drain email_outbox via SMTP.
 *
 * Requires Admin → Email SMTP to be enabled. Safe to re-run; failed rows backoff.
 *
 * Usage:
 *   C:\xampp\php\php.exe bin\exception_email_sender.php
 *   C:\xampp\php\php.exe bin\exception_email_sender.php 50
 */

require __DIR__ . '/exception_worker_bootstrap.php';

$limit = isset($argv[1]) ? (int) $argv[1] : 25;
$limit = max(1, min(200, $limit));

$boot = ra_exception_worker_bootstrap();
$notifier = $boot['notifier'];
$outbox = $boot['outbox'];
$smtp = $boot['smtp'];

echo 'Exception email sender @ ' . date('c') . PHP_EOL;
echo 'SMTP enabled: ' . ($smtp->isEnabled() ? 'yes' : 'no') . PHP_EOL;
echo 'Pending outbox: ' . $outbox->countPending() . PHP_EOL;
echo 'Limit: ' . $limit . PHP_EOL;

$result = $notifier->sendPendingEmails($smtp, $limit);

if (!empty($result['skipped_smtp'])) {
    echo 'Skipped: SMTP is disabled in Admin → Email.' . PHP_EOL;
    exit(0);
}

echo sprintf(
    "Done. attempted=%d sent=%d failed=%d\n",
    (int) ($result['attempted'] ?? 0),
    (int) ($result['sent'] ?? 0),
    (int) ($result['failed'] ?? 0)
);
exit(0);
