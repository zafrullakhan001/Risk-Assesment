<?php

declare(strict_types=1);

use RiskAssessment\Auth;

$topbarMenuAuth = Auth::instance();
$topbarMenuPrefix = $topbarMenuAuth->publicPrefix();
$topbarMenuJs = dirname(__DIR__) . '/assets/js/topbar-menu.js';
?>
<div class="topbar-menu" id="topbar-menu-root">
    <button
        type="button"
        class="button ghost update-bell-btn topbar-menu-btn"
        id="topbar-menu-btn"
        aria-label="Open menu"
        aria-expanded="false"
        aria-haspopup="true"
        aria-controls="topbar-menu-panel"
        title="Menu"
    >
        <svg class="update-bell-icon topbar-menu-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M4 6.5h16a1.25 1.25 0 0 0 0-2.5H4a1.25 1.25 0 0 0 0 2.5Zm0 6.5h16a1.25 1.25 0 0 0 0-2.5H4a1.25 1.25 0 0 0 0 2.5Zm0 6.5h16a1.25 1.25 0 0 0 0-2.5H4a1.25 1.25 0 0 0 0 2.5Z"/>
        </svg>
    </button>
    <div class="topbar-menu-panel" id="topbar-menu-panel" hidden role="menu" aria-labelledby="topbar-menu-heading">
        <div class="topbar-menu-panel-head">
            <h3 id="topbar-menu-heading"><span class="topbar-menu-emoji" aria-hidden="true">✨</span> Menu</h3>
            <div class="topbar-menu-panel-actions">
                <?php require __DIR__ . '/auth-nav-help.php'; ?>
                <button type="button" class="update-bell-close" id="topbar-menu-close" aria-label="Close menu">×</button>
            </div>
        </div>
        <div class="topbar-menu-body">
            <?php require __DIR__ . '/auth-nav-account.php'; ?>
            <div class="topbar-menu-groups" data-topbar-menu-groups>
                <div class="topbar-menu-section topbar-menu-group" data-menu-group-panel="risk" hidden>
                    <p class="topbar-menu-section-label"><span class="topbar-menu-emoji" aria-hidden="true">📋</span> Risk Register</p>
                    <div class="topbar-menu-nav-items" data-menu-group-items="risk"></div>
                </div>
                <div class="topbar-menu-section topbar-menu-group" data-menu-group-panel="sharepoint" hidden>
                    <p class="topbar-menu-section-label"><span class="topbar-menu-emoji" aria-hidden="true">📁</span> SharePoint</p>
                    <div class="topbar-menu-nav-items" data-menu-group-items="sharepoint"></div>
                </div>
                <div class="topbar-menu-section topbar-menu-group" data-menu-group-panel="storage" hidden>
                    <p class="topbar-menu-section-label"><span class="topbar-menu-emoji" aria-hidden="true">🗺️</span> Storage</p>
                    <div class="topbar-menu-nav-items" data-menu-group-items="storage"></div>
                </div>
                <div class="topbar-menu-section topbar-menu-group" data-menu-group-panel="ticket" hidden>
                    <p class="topbar-menu-section-label"><span class="topbar-menu-emoji" aria-hidden="true">🎫</span> Ticket Analysis</p>
                    <div class="topbar-menu-nav-items" data-menu-group-items="ticket"></div>
                </div>
                <div class="topbar-menu-section topbar-menu-group" data-menu-group-panel="misc" hidden>
                    <p class="topbar-menu-section-label"><span class="topbar-menu-emoji" aria-hidden="true">🧩</span> Misc</p>
                    <div class="topbar-menu-nav-items" data-menu-group-items="misc"></div>
                </div>
            </div>
            <div class="topbar-menu-section topbar-menu-nav topbar-menu-nav-source">
                <div class="topbar-menu-nav-items" data-topbar-menu-source>
