<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_branding') {
            $branding->save($_POST, $_FILES);
            $auth->users()->logAudit(
                'settings.branding',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['changed' => 'identity']
            );
            $flash = 'Branding saved. Open the home page to see the full look.';
        } elseif ($action === 'remove_logo') {
            $branding->removeLogo();
            $auth->users()->logAudit(
                'settings.branding',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['changed' => 'logo_removed']
            );
            $flash = 'Custom logo removed. The default mark is back.';
        } elseif ($action === 'remove_favicon') {
            $branding->removeFavicon();
            $auth->users()->logAudit(
                'settings.branding',
                (int) $currentUser['id'],
                (string) $currentUser['username'],
                null,
                null,
                ['changed' => 'favicon_removed']
            );
            $flash = 'Custom favicon removed.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$values = $branding->all();
$hasLogo = $branding->hasCustomLogo();
$hasFavicon = $branding->hasCustomFavicon();

$adminTitle = 'Branding';
$adminTab = 'branding';
$adminEyebrow = 'Look and feel';
$adminHeading = 'Make this install <em>yours</em>';
$adminIntro = 'Set the brand name, logo, home hero, footer, and browser icon used across the app.';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
            <section class="upload-card branding-preview-card">
                <h2>Live preview</h2>
                <p>This is how the home header and hero will read. Wrap a word in <code>*asterisks*</code> to get the teal accent.</p>
                <div class="branding-preview" id="branding-preview">
                    <div class="branding-preview-bar">
                        <?= $branding->renderMark() ?>
                        <div>
                            <div class="brand-title" data-preview="brand_title"><?= e($values['brand_title']) ?></div>
                            <strong data-preview="brand_subtitle"><?= e($values['brand_subtitle']) ?></strong>
                        </div>
                    </div>
                    <div class="branding-preview-hero">
                        <div class="eyebrow" data-preview="brand_hero_eyebrow"><?= e($values['brand_hero_eyebrow']) ?></div>
                        <h2 data-preview-emphasis="brand_hero_heading"><?= $branding->heroHeadingHtml() ?></h2>
                        <p data-preview="brand_hero_intro"><?= e($values['brand_hero_intro']) ?></p>
                    </div>
                    <p class="branding-preview-footer<?= $values['brand_footer_text'] === '' ? ' is-empty' : '' ?>" data-preview="brand_footer_text" data-empty="Footer appears here when you add text."><?= $values['brand_footer_text'] !== '' ? e($values['brand_footer_text']) : 'Footer appears here when you add text.' ?></p>
                </div>
            </section>

            <section class="upload-card settings-card">
                <h2><span class="settings-emoji" aria-hidden="true">🎨</span> Branding</h2>
                <p>These values replace the default Architecture Risk look on the home page, sign-in, admin, and assessment desk.</p>
                <form method="post" class="settings-form" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_branding">

                    <fieldset class="settings-fieldset settings-tone-teal">
                        <legend><span class="settings-emoji" aria-hidden="true">🏷️</span> Identity</legend>
                        <p class="settings-hint">Shown in the top-left brand lockup and the browser tab.</p>
                        <div class="settings-grid">
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">✨</span> Brand name</span>
                                <input type="text" name="brand_title" id="brand_title" value="<?= e($values['brand_title']) ?>" maxlength="80" required>
                                <small class="settings-help">Small teal line, e.g. Architecture Risk</small>
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">📝</span> Brand tagline</span>
                                <input type="text" name="brand_subtitle" id="brand_subtitle" value="<?= e($values['brand_subtitle']) ?>" maxlength="80">
                                <small class="settings-help">Larger line under the name on the home page</small>
                            </label>
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">🌐</span> Browser title</span>
                                <input type="text" name="brand_document_title" id="brand_document_title" value="<?= e($values['brand_document_title']) ?>" maxlength="120" required>
                                <small class="settings-help">Used in the tab title across most pages</small>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-sky">
                        <legend><span class="settings-emoji" aria-hidden="true">🖼️</span> Home hero</legend>
                        <p class="settings-hint">The navy banner on the project register. Use <code>*word*</code> in the headline for emphasis.</p>
                        <div class="settings-grid">
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">📌</span> Eyebrow label</span>
                                <input type="text" name="brand_hero_eyebrow" id="brand_hero_eyebrow" value="<?= e($values['brand_hero_eyebrow']) ?>" maxlength="80">
                            </label>
                            <label class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">📣</span> Headline</span>
                                <input type="text" name="brand_hero_heading" id="brand_hero_heading" value="<?= e($values['brand_hero_heading']) ?>" maxlength="160" required>
                            </label>
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">💬</span> Supporting text</span>
                                <textarea name="brand_hero_intro" id="brand_hero_intro" maxlength="280" rows="3"><?= e($values['brand_hero_intro']) ?></textarea>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-violet">
                        <legend><span class="settings-emoji" aria-hidden="true">📄</span> Footer</legend>
                        <p class="settings-hint">Optional line under every page. Leave blank to hide the footer.</p>
                        <div class="settings-grid">
                            <label class="settings-field settings-span-all">
                                <span><span class="settings-emoji" aria-hidden="true">©️</span> Footer text</span>
                                <textarea name="brand_footer_text" id="brand_footer_text" maxlength="280" rows="3" placeholder="© 2026 Your organization · Confidential"><?= e($values['brand_footer_text']) ?></textarea>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="settings-fieldset settings-tone-mint">
                        <legend><span class="settings-emoji" aria-hidden="true">🏠</span> Logo and favicon</legend>
                        <p class="settings-hint">PNG, SVG, JPEG, WebP, or GIF for the logo. ICO, PNG, SVG, or WebP for the tab icon. 1 MB max. SVGs are shown as images, never inlined.</p>
                        <div class="settings-grid">
                            <label class="settings-field settings-span-all branding-size-field">
                                <span><span class="settings-emoji" aria-hidden="true">↔️</span> Logo size <em id="brand_logo_size_value"><?= (int) $branding->logoSize() ?>px</em></span>
                                <input type="range" name="brand_logo_size" id="brand_logo_size" min="<?= (int) \RiskAssessment\Branding::LOGO_SIZE_MIN ?>" max="<?= (int) \RiskAssessment\Branding::LOGO_SIZE_MAX ?>" step="2" value="<?= (int) $branding->logoSize() ?>">
                                <small class="settings-help">Drag to enlarge the header logo. Applies to the default mark and any uploaded logo.</small>
                            </label>
                            <div class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🖼️</span> Logo</span>
                                <div class="branding-asset">
                                    <div class="branding-asset-preview"><?= $branding->renderMark() ?></div>
                                    <label class="branding-file">
                                        <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.gif,.svg,image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                                        <small class="settings-help"><?= $hasLogo ? 'Replace the current custom logo' : 'Upload a square mark for the best fit' ?></small>
                                    </label>
                                </div>
                            </div>
                            <div class="settings-field">
                                <span><span class="settings-emoji" aria-hidden="true">🔖</span> Favicon</span>
                                <div class="branding-asset">
                                    <div class="branding-asset-preview branding-asset-favicon">
                                        <?php if ($hasFavicon): ?>
                                            <img src="<?= e($branding->faviconUrl()) ?>" alt="Current favicon" width="32" height="32">
                                        <?php else: ?>
                                            <span class="branding-favicon-fallback">Tab</span>
                                        <?php endif; ?>
                                    </div>
                                    <label class="branding-file">
                                        <input type="file" name="favicon" accept=".ico,.png,.svg,.webp,image/x-icon,image/png,image/svg+xml,image/webp">
                                        <small class="settings-help"><?= $hasFavicon ? 'Replace the current favicon' : 'If empty, a PNG/SVG logo is used as the tab icon' ?></small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <div class="settings-actions">
                        <button type="submit" class="button button-primary">Save branding</button>
                    </div>
                </form>

                <?php if ($hasLogo || $hasFavicon): ?>
                    <div class="branding-remove-row">
                        <?php if ($hasLogo): ?>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_logo">
                                <button type="submit" class="button ghost">Remove logo</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($hasFavicon): ?>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_favicon">
                                <button type="submit" class="button ghost">Remove favicon</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
            <script>
            (function () {
                var preview = document.getElementById('branding-preview');
                if (!preview) {
                    return;
                }
                function escapeText(value) {
                    return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;');
                }
                function applyEmphasis(value) {
                    return escapeText(value).replace(/\*([^*]+)\*/g, '<em>$1</em>');
                }
                function bind(id, attr, html) {
                    var input = document.getElementById(id);
                    var target = preview.querySelector(html ? '[data-preview-emphasis="' + id + '"]' : '[data-preview="' + id + '"]');
                    if (!input || !target) {
                        return;
                    }
                    var empty = target.getAttribute('data-empty') || '';
                    function sync() {
                        var value = input.value;
                        if (html) {
                            target.innerHTML = applyEmphasis(value);
                            return;
                        }
                        if (empty) {
                            target.classList.toggle('is-empty', value.trim() === '');
                            target.textContent = value.trim() === '' ? empty : value;
                            return;
                        }
                        target.textContent = value;
                    }
                    input.addEventListener('input', sync);
                }
                bind('brand_title');
                bind('brand_subtitle');
                bind('brand_hero_eyebrow');
                bind('brand_hero_heading', null, true);
                bind('brand_hero_intro');
                bind('brand_footer_text');
                var sizeInput = document.getElementById('brand_logo_size');
                var sizeLabel = document.getElementById('brand_logo_size_value');
                function applyLogoSize(px) {
                    document.documentElement.style.setProperty('--brand-mark-size', px + 'px');
                    if (sizeLabel) {
                        sizeLabel.textContent = px + 'px';
                    }
                }
                if (sizeInput) {
                    applyLogoSize(sizeInput.value);
                    sizeInput.addEventListener('input', function () {
                        applyLogoSize(sizeInput.value);
                    });
                }
            })();
            </script>
<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
