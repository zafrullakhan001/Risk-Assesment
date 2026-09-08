<?php
declare(strict_types=1);

/**
 * Shared chrome matching RiskRegister (Architecture Risk) branding.
 */

function brandMarkSvg(): string
{
    return <<<'SVG'
<span class="brand-mark" aria-hidden="true">
<svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect width="56" height="56" rx="12" fill="var(--primary-soft)"/>
  <rect x="4" y="4" width="48" height="48" rx="10" fill="var(--card)" stroke="var(--primary)" stroke-width="1.5"/>
  <path d="M18 36V22.5L28 16l10 6.5V36h-7.5V28h-5v8H18z" fill="var(--primary)"/>
  <circle cx="28" cy="24" r="2.2" fill="var(--hero-accent, #67e8f9)"/>
</svg>
</span>
SVG;
}

function cssVersion(): string
{
    $path = TD_ROOT . '/assets/css/app.css';

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
