<?php

declare(strict_types=1);

/**
 * Closed <dialog> elements stay hidden unless display is scoped to [open].
 *
 * Usage: php bin/verify_portfolio_dialog_css.php
 */

$cssPath = dirname(__DIR__) . '/public/assets/css/dashboard.css';
$css = file_get_contents($cssPath);
if ($css === false) {
    fwrite(STDERR, "Could not read {$cssPath}\n");
    exit(1);
}

$failed = 0;
$passed = 0;
$assert = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    if ($ok) {
        $passed++;
        echo "ok  {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL  {$label}\n";
};

$block = static function (string $css, string $selector): string {
    $quoted = preg_quote($selector, '/');
    if (!preg_match('/' . $quoted . '\s*\{([^}]*)\}/', $css, $match)) {
        return '';
    }
    return $match[1];
};

$closed = $block($css, '.sp-portfolio-map-dialog');
$open = $block($css, '.sp-portfolio-map-dialog[open]');

$assert($closed !== '', 'closed dialog rule exists');
$assert($open !== '', 'open dialog rule exists');
$assert(
    !preg_match('/display\s*:\s*flex/i', $closed),
    'closed .sp-portfolio-map-dialog does not set display:flex'
);
$assert(
    (bool) preg_match('/display\s*:\s*flex/i', $open),
    'open .sp-portfolio-map-dialog[open] sets display:flex'
);

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
