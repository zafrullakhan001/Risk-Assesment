<?php

declare(strict_types=1);

use RiskAssessment\Auth;

$topbarMenuAuth = $topbarMenuAuth ?? Auth::instance();
$topbarMenuPrefix = $topbarMenuPrefix ?? $topbarMenuAuth->publicPrefix();
$topbarMenuJs = $topbarMenuJs ?? (dirname(__DIR__) . '/assets/js/topbar-menu.js');
?>
                </div>
            </div>
            <div class="topbar-menu-section topbar-menu-appearance">
                <p class="topbar-menu-section-label"><span class="topbar-menu-emoji" aria-hidden="true">🎨</span> Appearance</p>
                <?php require __DIR__ . '/theme-controls.php'; ?>
            </div>
            <div class="topbar-menu-section topbar-menu-toolbar">
                <p class="topbar-menu-section-label"><span class="topbar-menu-emoji" aria-hidden="true">🧰</span> Toolbar</p>
                <div class="topbar-menu-mode-toggle theme-controls" role="group" aria-label="Header layout">
                    <button type="button" class="theme-btn topbar-nav-mode-btn" data-nav-set="menu" aria-pressed="false">☰ Compact menu</button>
                    <button type="button" class="theme-btn topbar-nav-mode-btn" data-nav-set="bar" aria-pressed="false">▦ Show all buttons</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/auth-nav-chrome.php'; ?>
<?php if (!defined('RA_TOPBAR_MENU_SCRIPT')): ?>
    <?php define('RA_TOPBAR_MENU_SCRIPT', true); ?>
    <script src="<?= e($topbarMenuPrefix) ?>assets/js/topbar-menu.js?v=<?= is_file($topbarMenuJs) ? filemtime($topbarMenuJs) : time() ?>" defer></script>
<?php endif; ?>
