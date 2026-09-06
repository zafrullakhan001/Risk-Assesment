<?php

declare(strict_types=1);

/**
 * @var \RiskAssessment\Branding $branding
 * @var string $shareUnavailableMessage
 */
$shareUnavailableMessage = (string) ($shareUnavailableMessage ?? 'This public link is invalid, expired, or has been revoked.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Share link unavailable · <?= e($branding->brandTitle()) ?></title>
    <?php require __DIR__ . '/theme-head.php'; ?>
    <?php require __DIR__ . '/head-branding.php'; ?>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
    <style>
        .share-unavailable { max-width: 28rem; margin: 4rem auto; padding: 1.5rem; text-align: center; }
        .share-unavailable h1 { margin: 0 0 0.75rem; font-size: 1.35rem; }
        .share-unavailable p { margin: 0 0 1rem; color: var(--muted, #64748b); }
    </style>
</head>
<body>
    <main class="share-unavailable">
        <h1>Share link unavailable</h1>
        <p><?= e($shareUnavailableMessage) ?></p>
        <p><a class="button button-primary" href="login.php">Sign in</a></p>
    </main>
</body>
</html>
