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
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&family=Caveat:wght@400;600;700&family=Dancing+Script:wght@400;600;700&family=Figtree:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Great+Vibes&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;0,7..72,700;1,7..72,400&family=Merriweather:ital,opsz,wght@0,18..144,400;0,18..144,700;1,18..144,400&family=Nunito:ital,wght@0,400;0,600;0,700;1,400&family=Patrick+Hand&family=Lexend:wght@400;500;600;700&family=Source+Serif+4:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <script>
    (function () {
        try {
            var font = localStorage.getItem('ra-help-font') || 'app';
            var allowed = [
                'app', 'clear', 'lexend', 'rounded', 'figtree', 'arial', 'verdana', 'tahoma', 'trebuchet', 'impact',
                'serif', 'literata', 'merriweather', 'times', 'georgia',
                'courier',
                'dancing', 'caveat', 'great-vibes', 'patrick', 'comic'
            ];
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
                        <div class="help-reader-row help-reader-controls">
                            <div class="help-control-label help-font-picker" id="help-font-picker">
                                <span class="theme-controls-label" id="help-font-label">Font</span>
                                <button
                                    type="button"
                                    class="help-control-select help-font-picker-trigger"
                                    id="help-font-picker-trigger"
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                    aria-labelledby="help-font-label help-font-picker-trigger"
                                    title="Choose a reading font for Help &amp; About"
                                >App (Source Sans 3)</button>
                                <div class="help-font-picker-menu" id="help-font-picker-menu" role="listbox" aria-labelledby="help-font-label" hidden>
                                    <div class="help-font-picker-group" role="presentation">Sans</div>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="app" style="font-family: 'Source Sans 3', sans-serif;">App (Source Sans 3)</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="clear" style="font-family: 'Atkinson Hyperlegible', Tahoma, sans-serif;">Clear (Atkinson)</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="lexend" style="font-family: Lexend, sans-serif;">Lexend</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="rounded" style="font-family: Nunito, sans-serif;">Rounded (Nunito)</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="figtree" style="font-family: Figtree, sans-serif;">Figtree</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="arial" style="font-family: Arial, Helvetica, sans-serif;">Arial</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="verdana" style="font-family: Verdana, Geneva, sans-serif;">Verdana</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="tahoma" style="font-family: Tahoma, 'Segoe UI', sans-serif;">Tahoma</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="trebuchet" style="font-family: 'Trebuchet MS', 'Segoe UI', sans-serif;">Trebuchet MS</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="impact" style="font-family: Impact, Haettenschweiler, sans-serif;">Impact</button>
                                    <div class="help-font-picker-group" role="presentation">Serif</div>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="serif" style="font-family: 'Source Serif 4', Georgia, serif;">Source Serif 4</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="literata" style="font-family: Literata, Georgia, serif;">Literata</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="merriweather" style="font-family: Merriweather, Georgia, serif;">Merriweather</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="times" style="font-family: 'Times New Roman', Times, serif;">Times New Roman</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="georgia" style="font-family: Georgia, 'Times New Roman', serif;">Georgia</button>
                                    <div class="help-font-picker-group" role="presentation">Monospace</div>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="courier" style="font-family: 'Courier New', Courier, monospace;">Courier New</button>
                                    <div class="help-font-picker-group" role="presentation">Cursive</div>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="dancing" style="font-family: 'Dancing Script', cursive;">Dancing Script</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="caveat" style="font-family: Caveat, cursive;">Caveat</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="great-vibes" style="font-family: 'Great Vibes', cursive;">Great Vibes</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="patrick" style="font-family: 'Patrick Hand', cursive;">Patrick Hand</button>
                                    <button type="button" role="option" class="help-font-picker-option" data-help-font="comic" style="font-family: 'Comic Sans MS', cursive;">Comic Sans MS</button>
                                </div>
                                <select id="help-font-select" class="help-font-select-native" tabindex="-1" aria-hidden="true">
                                    <option value="app">App (Source Sans 3)</option>
                                    <option value="clear">Clear (Atkinson)</option>
                                    <option value="lexend">Lexend</option>
                                    <option value="rounded">Rounded (Nunito)</option>
                                    <option value="figtree">Figtree</option>
                                    <option value="arial">Arial</option>
                                    <option value="verdana">Verdana</option>
                                    <option value="tahoma">Tahoma</option>
                                    <option value="trebuchet">Trebuchet MS</option>
                                    <option value="impact">Impact</option>
                                    <option value="serif">Source Serif 4</option>
                                    <option value="literata">Literata</option>
                                    <option value="merriweather">Merriweather</option>
                                    <option value="times">Times New Roman</option>
                                    <option value="georgia">Georgia</option>
                                    <option value="courier">Courier New</option>
                                    <option value="dancing">Dancing Script</option>
                                    <option value="caveat">Caveat</option>
                                    <option value="great-vibes">Great Vibes</option>
                                    <option value="patrick">Patrick Hand</option>
                                    <option value="comic">Comic Sans MS</option>
                                </select>
                            </div>
                            <label class="help-control-label" for="help-voice-select">
                                <span class="theme-controls-label">Voice</span>
                                <select id="help-voice-select" class="help-control-select" aria-describedby="help-reader-status">
                                    <option value="">Loading voices…</option>
                                </select>
                            </label>
                            <div class="help-reader-actions" role="group" aria-label="Read aloud">
                                <button type="button" class="help-reader-icon-btn" id="help-reader-play" title="Play — read the current topic aloud" aria-label="Play">
                                    <svg class="help-reader-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M8 5.5v13l11-6.5L8 5.5z" fill="currentColor"/>
                                    </svg>
                                </button>
                                <button type="button" class="help-reader-icon-btn" id="help-reader-pause" title="Pause reading" aria-label="Pause" disabled>
                                    <svg class="help-reader-icon help-reader-icon-pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M7 5h3.5v14H7V5zm6.5 0H17v14h-3.5V5z" fill="currentColor"/>
                                    </svg>
                                    <svg class="help-reader-icon help-reader-icon-resume" viewBox="0 0 24 24" aria-hidden="true" focusable="false" hidden>
                                        <path d="M8 5.5v13l11-6.5L8 5.5z" fill="currentColor"/>
                                    </svg>
                                </button>
                                <button type="button" class="help-reader-icon-btn" id="help-reader-stop" title="Stop reading" aria-label="Stop" disabled>
                                    <svg class="help-reader-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M6.5 6.5h11v11h-11z" fill="currentColor"/>
                                    </svg>
                                </button>
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
