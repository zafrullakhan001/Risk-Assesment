<?php

declare(strict_types=1);

$src = $argv[1] ?? 'C:/Users/zafru/.cursor/browser-logs/cdp-response-Runtime.evaluate-2026-09-05T20-43-04-431Z.json';
$out = dirname(__DIR__) . '/uploads/sharepoint-catalog-import.csv';

$raw = file_get_contents($src);
if ($raw === false) {
    fwrite(STDERR, "Cannot read CDP log: $src\n");
    exit(1);
}

$j = json_decode($raw, true);
if (!is_array($j)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}

$walk = static function ($node) use (&$walk): ?string {
    if (is_string($node) && (str_starts_with($node, 'Name,Path,Type,URL') || str_contains($node, "Name,Path,Type,URL"))) {
        return $node;
    }
    if (!is_array($node)) {
        return null;
    }
    foreach ($node as $child) {
        $found = $walk($child);
        if (is_string($found)) {
            return $found;
        }
    }

    return null;
};

$csv = $walk($j);
if (!is_string($csv) || $csv === '') {
    fwrite(STDERR, "CSV payload not found\n");
    exit(1);
}

file_put_contents($out, $csv);
echo 'wrote ' . strlen($csv) . ' bytes to ' . $out . PHP_EOL;
echo 'header=' . strtok($csv, "\r\n") . PHP_EOL;
