<?php

declare(strict_types=1);

/**
 * Exception Monitor worker — detect due/overdue exceptions, create in-app notices,
 * and queue SMTP messages into email_outbox (does not send mail).
 *
 * Scheduled by Windows Task Scheduler (see scripts/schedule-exception-workers.bat).
 *
 * Usage:
 *   C:\xampp\php\php.exe bin\exception_monitor.php
 */

require __DIR__ . '/exception_worker_bootstrap.php';

$boot = ra_exception_worker_bootstrap();
$findings = $boot['findings'];
$notifier = $boot['notifier'];
$outbox = $boot['outbox'];

$due = $findings->listDueForReminder(200);
$total = count($due);
$notifiedUsers = 0;
$emailsQueued = 0;
$marked = 0;

echo 'Exception monitor @ ' . date('c') . PHP_EOL;
echo 'Due items pending reminder: ' . $total . PHP_EOL;
echo 'Outbox pending before: ' . $outbox->countPending() . PHP_EOL;

foreach ($due as $item) {
    $result = $notifier->queueDueItem($item);
    $notifiedUsers += (int) ($result['notified_users'] ?? 0);
    $emailsQueued += (int) ($result['emails_queued'] ?? 0);
    if (!empty($result['marked'])) {
        $marked++;
    }
    echo sprintf(
        " - [%d/%s] %s → users=%d queued=%d marked=%s\n",
        (int) ($item['assessment_id'] ?? 0),
        (string) ($item['finding_id'] ?? ''),
        (string) ($item['expires_at'] ?? ''),
        (int) ($result['notified_users'] ?? 0),
        (int) ($result['emails_queued'] ?? 0),
        !empty($result['marked']) ? 'yes' : 'no'
    );
}

echo 'Outbox pending after: ' . $outbox->countPending() . PHP_EOL;
echo "Done. marked={$marked} notified_users={$notifiedUsers} emails_queued={$emailsQueued}" . PHP_EOL;
exit(0);
