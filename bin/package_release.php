<?php

declare(strict_types=1);

/**
 * Build a GitHub Release zip that installs without Git.
 *
 * Usage:
 *   php bin/package_release.php
 *   php bin/package_release.php v1.2.0
 *
 * Output:
 *   dist/RiskRegister-<version>.zip
 *
 * The zip includes vendor/ when present, VERSION.json, and a README.
 * Database files, uploads, branding, and secrets are excluded.
 */

$root = dirname(__DIR__);
$versionArg = trim((string) ($argv[1] ?? ''));
$manifestPath = $root . DIRECTORY_SEPARATOR . 'VERSION.json';
$manifest = [];
if (is_file($manifestPath)) {
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    if (is_array($decoded)) {
        $manifest = $decoded;
    }
}

$rawVersion = $versionArg !== '' ? $versionArg : (string) ($manifest['tag'] ?? $manifest['version'] ?? '1.0.0');
$rawVersion = trim($rawVersion);
if ($rawVersion === '') {
    $rawVersion = '1.0.0';
}
$version = preg_match('/^v\d/i', $rawVersion) === 1 ? substr($rawVersion, 1) : $rawVersion;
$tag = preg_match('/^v\d/i', $rawVersion) === 1 ? $rawVersion : 'v' . $rawVersion;
if (!preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
    fwrite(STDERR, "Invalid version: {$rawVersion}\n");
    exit(1);
}

$sha = '';
$gitHead = [];
$code = 1;
@exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>&1', $gitHead, $code);
if ($code === 0 && isset($gitHead[0]) && preg_match('/^[a-f0-9]{7,40}$/i', trim($gitHead[0])) === 1) {
    $sha = strtolower(trim($gitHead[0]));
}

$autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    fwrite(STDOUT, "vendor/ is missing. Run composer install --no-dev before packaging so the zip runs on XAMPP without Composer.\n");
}

$builtAt = gmdate('c');
$manifest = [
    'name' => 'RiskRegister',
    'version' => $version,
    'tag' => $tag,
    'sha' => $sha,
    'built_at' => $builtAt,
    'repo' => (string) ($manifest['repo'] ?? 'zafrullakhan001/Risk-Assesment'),
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($manifestPath, $json . "\n") === false) {
    fwrite(STDERR, "Unable to write VERSION.json\n");
    exit(1);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP zip extension is required. Enable extension=zip in php.ini.\n");
    exit(1);
}

$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
if (!is_dir($distDir) && !mkdir($distDir, 0755, true) && !is_dir($distDir)) {
    fwrite(STDERR, "Unable to create dist/\n");
    exit(1);
}

$zipName = 'RiskRegister-' . $tag . '.zip';
$zipPath = $distDir . DIRECTORY_SEPARATOR . $zipName;
if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to create {$zipPath}\n");
    exit(1);
}

$folder = 'RiskRegister';
$skipDirNames = [
    '.git' => true,
    '.github' => true,
    '.cursor' => true,
    '.idea' => true,
    'dist' => true,
    'node_modules' => true,
    'agent-transcripts' => true,
    'update-staging' => true,
    'sessions' => true,
];
$skipFiles = [
    '.encryption_key' => true,
    'updater.lock' => true,
    'composer.phar' => true,
    'cacert.pem' => true,
    'Thumbs.db' => true,
    '.DS_Store' => true,
];

$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filtered = new RecursiveCallbackFilterIterator($directory, static function ($current) use ($skipDirNames): bool {
    $name = $current->getFilename();
    return !($current->isDir() && isset($skipDirNames[$name]));
});
$iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST);

$added = 0;
foreach ($iterator as $file) {
    $absolute = $file->getPathname();
    $relative = substr($absolute, strlen($root) + 1);
    if ($relative === false || $relative === '') {
        continue;
    }
    $relativeUnix = str_replace('\\', '/', $relative);
    $parts = explode('/', $relativeUnix);
    if (isset($skipDirNames[$parts[0]])) {
        continue;
    }
    if ($parts[0] === 'database' && isset($parts[1]) && $parts[1] === 'snapshots' && ($parts[2] ?? '') !== '.gitkeep') {
        continue;
    }
    // Keep Ticket Dossier runtime DB/files off the release (same idea as uploads/).
    if ($parts[0] === 'database' && ($parts[1] ?? '') === 'ticket-dossier-storage') {
        continue;
    }
    if ($parts[0] === 'public' && ($parts[1] ?? '') === 'ticket-dossier' && in_array($parts[2] ?? '', ['data', 'storage'], true)) {
        if (!in_array(basename($relativeUnix), ['.htaccess', '.gitignore', '.gitkeep'], true)) {
            continue;
        }
    }
    if ($parts[0] === 'uploads' && isset($parts[1]) && !in_array($parts[1], ['.gitkeep', '.htaccess'], true) && count($parts) > 1) {
        continue;
    }
    if ($parts[0] === 'public' && ($parts[1] ?? '') === 'assets' && ($parts[2] ?? '') === 'branding' && ($parts[3] ?? '') !== '.gitkeep' && isset($parts[3])) {
        continue;
    }

    $base = basename($relativeUnix);
    if (isset($skipFiles[$base])) {
        continue;
    }
    if (preg_match('/\.sqlite($|-journal$)/i', $base) === 1 || str_contains($base, '.sqlite.retired-')) {
        continue;
    }
    if (str_starts_with($base, 'sess_')) {
        continue;
    }

    $localName = $folder . '/' . $relativeUnix;
    if ($file->isDir()) {
        $zip->addEmptyDir($localName);
        continue;
    }
    if (!$zip->addFile($absolute, $localName)) {
        $zip->close();
        fwrite(STDERR, "Unable to add {$relativeUnix}\n");
        exit(1);
    }
    $added++;
}

$readme = <<<TEXT
Architecture Risk Assessment
Version: {$tag}

Install
1. Extract the RiskRegister folder into your web root (for XAMPP: C:\\xampp\\htdocs\\RiskRegister).
2. PHP 8+ with sqlite, curl, zip, and openssl.
3. Open the site in a browser.
4. Sign in as admin / admin123 and change that password immediately.

This zip does not include Git. In-app updates (Admin → App updates) download the next GitHub Release zip. Your SQLite database, uploads, and branding stay on the server.

Do not commit or copy database/*.sqlite or database/.encryption_key into a release.
TEXT;
$zip->addFromString($folder . '/README.txt', $readme);
$added++;

$zip->close();

if ($added < 20) {
    fwrite(STDERR, "Package looks empty ({$added} files). Aborting.\n");
    @unlink($zipPath);
    exit(1);
}

$size = is_file($zipPath) ? filesize($zipPath) : 0;
fwrite(STDOUT, "Wrote {$zipPath} (" . number_format((int) $size) . " bytes, {$added} files, {$tag})\n");
fwrite(STDOUT, "Attach this file to the GitHub Release. The in-app updater prefers RiskRegister-*.zip over the auto source zip.\n");
exit(0);
