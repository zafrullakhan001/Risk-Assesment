<?php

declare(strict_types=1);

/**
 * Signed-in topbar account controls.
 * Prefer wrapping with topbar-menu-start/end so Help/Admin/Sign out stay in the menu
 * and people/bell/user chip stay as chrome. This file keeps both for any leftover callers.
 */
require __DIR__ . '/auth-nav-menu.php';
require __DIR__ . '/auth-nav-chrome.php';
