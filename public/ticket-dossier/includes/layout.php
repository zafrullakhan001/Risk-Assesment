<?php

declare(strict_types=1);

/**
 * Shared layout helpers for Ticket Dossier (brand chrome comes from RiskRegister Branding).
 */

use RiskAssessment\Branding;

function brandMarkSvg(): string
{
    return Branding::current()->renderMark();
}

function dashboardCssVersion(): string
{
    $path = dirname(__DIR__, 2) . '/assets/css/dashboard.css';

    return is_file($path) ? (string) filemtime($path) : '1';
}

function cssVersion(): string
{
    $path = TD_ROOT . '/assets/css/app.css';

    return is_file($path) ? (string) filemtime($path) : '1';
}

function themeJsVersion(): string
{
    $path = dirname(__DIR__, 2) . '/assets/js/theme.js';

    return is_file($path) ? (string) filemtime($path) : '1';
}

function jsVersion(): string
{
    $paths = [
        TD_ROOT . '/assets/js/app.js',
        TD_ROOT . '/assets/js/project-list.js',
    ];
    $mtime = 0;
    foreach ($paths as $path) {
        if (is_file($path)) {
            $mtime = max($mtime, (int) filemtime($path));
        }
    }

    return $mtime > 0 ? (string) $mtime : '1';
}

/**
 * @param 'demand'|'story'|'task'|'ddr'|string $kind
 */
function pillClassForKind(string $kind): string
{
    return match ($kind) {
        'demand' => 'pill teal',
        'story' => 'pill amber',
        'task' => 'pill gray',
        'ddr' => 'pill teal',
        'vendor' => 'pill gray',
        default => 'pill gray',
    };
}
