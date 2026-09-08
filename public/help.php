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
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&family=Lexend:wght@400;500;600;700&family=Nunito:ital,wght@0,400;0,600;0,700;1,400&family=Source+Serif+4:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <script>
    (function () {
        try {
            var font = localStorage.getItem('ra-help-font') || 'app';
            var allowed = ['app', 'serif', 'clear', 'lexend', 'rounded'];
            if (allowed.indexOf(font) === -1) {
                font = 'app';
            }
            document.documentElement.setAttribute('data-help-font', font);
        } catch (e) { /* ignore */ }
    })();
    </script>
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
                <a class="button ghost home-link" data-menu-group="risk" data-menu-tone="sky" href="index.php#find-projects" title="Search and open saved risk assessments by name, vendor, owner, and more"><span class="topbar-menu-emoji" aria-hidden="true">🔎</span>Find projects</a>
                <a class="button ghost home-link" data-menu-group="risk" data-menu-tone="mint" href="index.php#upload" title="Upload an Architecture Risk Assessment workbook (.xlsx) to generate a dashboard"><span class="topbar-menu-emoji" aria-hidden="true">📤</span>Upload</a>
                <a class="button ghost home-link" data-menu-group="risk" data-menu-tone="lavender" href="templates.php" title="Browse and manage assessment workbook templates"><span class="topbar-menu-emoji" aria-hidden="true">📚</span>Templates</a>
                <a class="button ghost home-link" data-menu-group="sharepoint" data-menu-tone="peach" href="sharepoint.php" title="Browse SharePoint folders, sync projects, and search architecture work"><span class="topbar-menu-emoji" aria-hidden="true">📁</span>SharePoint</a>
                <?php require __DIR__ . '/includes/catalog-nav-link.php'; ?>
                <?php require __DIR__ . '/includes/owners-nav-link.php'; ?>
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
                            <p>Pick a topic on the left. The right pane explains assessments, Ticket Dossier, SharePoint, and the rest of the app — with links into each screen. Choose a reading font and use Read aloud when you want the browser to speak the topic.</p>
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
                    <div class="help-reader-toolbar upload-card" id="help-reader-toolbar" role="region" aria-label="Reading options">
                        <div class="help-reader-row">
                            <div class="theme-controls help-font-controls" aria-label="Help reading font">
                                <span class="theme-controls-label">Font</span>
                                <button type="button" class="theme-btn" data-help-font-set="app" title="App — Source Sans 3, matches the rest of the register" aria-pressed="false">App</button>
                                <button type="button" class="theme-btn" data-help-font-set="serif" title="Serif — Source Serif 4 for long-form reading" aria-pressed="false">Serif</button>
                                <button type="button" class="theme-btn" data-help-font-set="clear" title="Clear — Atkinson Hyperlegible for high legibility" aria-pressed="false">Clear</button>
                                <button type="button" class="theme-btn" data-help-font-set="lexend" title="Lexend — easier word decoding" aria-pressed="false">Lexend</button>
                                <button type="button" class="theme-btn" data-help-font-set="rounded" title="Rounded — Nunito, softer and friendlier" aria-pressed="false">Rounded</button>
                            </div>
                        </div>
                        <div class="help-reader-row help-reader-speak">
                            <label class="help-voice-label" for="help-voice-select">
                                <span class="theme-controls-label">Voice</span>
                                <select id="help-voice-select" class="help-voice-select" aria-describedby="help-reader-status">
                                    <option value="">Loading voices…</option>
                                </select>
                            </label>
                            <div class="help-reader-actions" role="group" aria-label="Read aloud">
                                <button type="button" class="button ghost help-reader-btn" id="help-reader-play" title="Read the current topic aloud">Play</button>
                                <button type="button" class="button ghost help-reader-btn" id="help-reader-pause" title="Pause or resume reading" disabled>Pause</button>
                                <button type="button" class="button ghost help-reader-btn" id="help-reader-stop" title="Stop reading" disabled>Stop</button>
                            </div>
                            <p class="help-reader-status" id="help-reader-status" aria-live="polite"></p>
                        </div>
                    </div>

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
    <script src="assets/js/help-reader.js?v=<?= filemtime(__DIR__ . '/assets/js/help-reader.js') ?>"></script>
</body>
</html>
