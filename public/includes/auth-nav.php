<?php

declare(strict_types=1);

/**
 * Signed-in topbar account controls.
 * Prefer wrapping with topbar-menu-start/end so Admin/Sign out stay at the top,
 * Help is a compact ? icon in the menu header, and people/bell/user chip stay as chrome.
 */
require __DIR__ . '/auth-nav-account.php';
require __DIR__ . '/auth-nav-help.php';
require __DIR__ . '/auth-nav-chrome.php';
