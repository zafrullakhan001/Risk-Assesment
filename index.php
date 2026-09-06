<?php

declare(strict_types=1);

/**
 * Fallback when mod_rewrite is unavailable: send browsers into public/.
 * Prefer the root .htaccess rewrite so the address bar stays on the folder URL.
 */
$target = 'public/';
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
