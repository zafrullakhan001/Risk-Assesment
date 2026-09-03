<?php

declare(strict_types=1);

$adminTitle = $adminTitle ?? 'Admin';
$adminTab = $adminTab ?? 'home';
$adminEyebrow = $adminEyebrow ?? 'Administration';
$adminHeading = $adminHeading ?? $adminTitle;
$adminIntro = $adminIntro ?? 'Manage this Risk Assessment install.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($adminTitle) ?> · <?= e(\RiskAssessment\Branding::current()->documentTitle()) ?></title>
    <?php require dirname(__DIR__) . '/includes/theme-head.php'; ?>
    <?php require dirname(__DIR__) . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="shell upload-page admin-page">
        <header class="topbar">
            <a class="brand brand-link" href="../index.php#find-projects" title="Find projects by name">
                <?php require dirname(__DIR__) . '/includes/brand-mark.php'; ?>
                <div>
                    <div class="brand-title"><?= e(\RiskAssessment\Branding::current()->brandTitle()) ?></div>
                    <h1>Admin</h1>
                </div>
            </a>
            <div class="topbar-actions">
                <a class="button ghost home-link" href="../index.php#find-projects">← Find projects</a>
                <?php require dirname(__DIR__) . '/includes/auth-nav.php'; ?>
                <?php require dirname(__DIR__) . '/includes/theme-controls.php'; ?>
            </div>
        </header>
        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow"><?= e($adminEyebrow) ?></div>
                            <h2><?= $adminHeading ?></h2>
                            <p><?= e($adminIntro) ?></p>
                        </div>
                    </div>
                </div>
            </section>
            <?php require __DIR__ . '/admin-nav.php'; ?>
            <?php if (($error ?? '') !== ''): ?>
                <div class="alert alert-error"><?= e((string) $error) ?></div>
            <?php endif; ?>
            <?php if (($flash ?? '') !== ''): ?>
                <div class="alert alert-success"><?= e((string) $flash) ?></div>
            <?php endif; ?>
