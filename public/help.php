<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$currentUser = $auth->requireAuth();

require __DIR__ . '/includes/help-topics.php';

$helpGroups = help_topic_groups();
$helpTopics = help_flatten_topics($helpGroups);
$helpCount = count($helpTopics);
$helpFirstId = (string) ($helpTopics[0]['id'] ?? 'about-app');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help &amp; About · <?= e($branding->documentTitle()) ?></title>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <?php require __DIR__ . '/includes/head-branding.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
</head>
<body>
    <div class="shell upload-page help-page">
        <header class="topbar topbar-uplift">
            <a class="brand brand-link" href="index.php#find-projects" title="Find projects by name">
                <?php require __DIR__ . '/includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1>Help &amp; About</h1>
                </div>
            </a>
            <div class="topbar-actions">
                <?php require __DIR__ . '/includes/topbar-menu-start.php'; ?>
                <a class="button ghost home-link" data-menu-tone="sky" href="index.php#find-projects"><span class="topbar-menu-emoji" aria-hidden="true">🔎</span>Find projects</a>
                <a class="button ghost home-link" data-menu-tone="mint" href="index.php#upload"><span class="topbar-menu-emoji" aria-hidden="true">📤</span>Upload</a>
                <a class="button ghost home-link" data-menu-tone="lavender" href="templates.php"><span class="topbar-menu-emoji" aria-hidden="true">📚</span>Templates</a>
                <a class="button ghost home-link" data-menu-tone="peach" href="sharepoint.php"><span class="topbar-menu-emoji" aria-hidden="true">📁</span>SharePoint</a>
                <?php require __DIR__ . '/includes/catalog-nav-link.php'; ?>
                <?php require __DIR__ . '/includes/ticket-dossier-nav-link.php'; ?>
                <?php require __DIR__ . '/includes/updates-nav.php'; ?>
                <?php require __DIR__ . '/includes/topbar-menu-end.php'; ?>
                <div class="updated"><?= (int) $helpCount ?> topic<?= $helpCount === 1 ? '' : 's' ?></div>
            </div>
        </header>

        <main>
            <section class="hero hero-compact">
                <div class="hero-main">
                    <div class="hero-head">
                        <div class="hero-intro">
                            <div class="eyebrow">Help &amp; About</div>
                            <h2>How this <em>register</em> works</h2>
                            <p>Pick a topic on the left. The right pane explains assessments, Ticket Dossier, SharePoint, and the rest of the app — with links into each screen.</p>
                        </div>
                        <?php require __DIR__ . '/includes/hero-medallion.php'; renderHeroMedallion((int) $helpCount, 'help topics'); ?>
                    </div>
                </div>
            </section>

            <div class="help-layout" id="help-layout" data-default-topic="<?= e($helpFirstId) ?>">
                <nav class="help-toc upload-card" aria-label="Help topics">
                    <h2 class="help-toc-title">Topics</h2>
                    <?php foreach ($helpGroups as $group): ?>
                        <div class="help-toc-group">
                            <h3 class="help-toc-group-label"><?= e((string) $group['label']) ?></h3>
                            <ul class="help-toc-list">
                                <?php foreach ($group['topics'] as $topic): ?>
                                    <?php $isFirst = $topic['id'] === $helpFirstId; ?>
                                    <li>
                                        <a
                                            class="help-toc-link<?= $isFirst ? ' is-active' : '' ?>"
                                            href="#<?= e((string) $topic['id']) ?>"
                                            data-help-topic="<?= e((string) $topic['id']) ?>"
                                            <?= $isFirst ? ' aria-current="true"' : '' ?>
                                        ><?= e((string) $topic['title']) ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </nav>

                <div class="help-detail" id="help-detail">
                    <?php foreach ($helpTopics as $topic): ?>
                        <?php $isFirst = $topic['id'] === $helpFirstId; ?>
                        <article
                            class="help-article upload-card<?= $isFirst ? ' is-active' : '' ?>"
                            id="<?= e((string) $topic['id']) ?>"
                            data-help-article="<?= e((string) $topic['id']) ?>"
                            <?= $isFirst ? '' : ' hidden' ?>
                        >
                            <p class="help-article-kicker"><?= e((string) $topic['group']) ?></p>
                            <h2><?= e((string) $topic['title']) ?></h2>
                            <div class="help-article-body">
                                <?= help_allowed_html((string) $topic['html']) ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
    </div>
    <script src="assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="assets/js/help.js?v=<?= filemtime(__DIR__ . '/assets/js/help.js') ?>"></script>
</body>
</html>
