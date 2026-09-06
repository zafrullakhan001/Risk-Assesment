<?php

declare(strict_types=1);

/**
 * One-off publisher: create a GitHub Release and upload a zip.
 * Usage: php bin/publish_github_release.php v1.1.0 dist/RiskRegister-v1.1.0.zip
 */

use RiskAssessment\Crypto;
use RiskAssessment\Database\Database;
use RiskAssessment\Repositories\SettingsRepository;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$tag = trim((string) ($argv[1] ?? ''));
$zipPath = trim((string) ($argv[2] ?? ''));
if ($tag === '' || $zipPath === '' || !is_file($zipPath)) {
    fwrite(STDERR, "Usage: php bin/publish_github_release.php <tag> <zip-path>\n");
    exit(1);
}

$dbConfig = require $root . '/config/database.php';
$pdo = Database::connection($dbConfig);
$settings = new SettingsRepository($pdo);
$crypto = new Crypto($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.encryption_key');

$token = '';
$stored = $settings->get('updater_github_token', '');
if ($stored !== '') {
    $token = $crypto->decrypt($stored);
}
if ($token === '') {
    $env = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: '';
    $token = is_string($env) ? trim($env) : '';
}
if ($token === '') {
    fwrite(STDERR, "No GitHub token available. Save a PAT under Admin → App updates first.\n");
    exit(1);
}

$repo = 'zafrullakhan001/Risk-Assesment';
$zipName = basename($zipPath);
$notes = <<<MD
## App updates without Git

This release can be installed from the packaged zip. The in-app updater (Admin → App updates) downloads this zip from GitHub Releases. Git is not required on the server.

- Database, uploads, and branding stay in place during updates
- Package future builds with `php bin/package_release.php vX.Y.Z`
- Attach `RiskRegister-vX.Y.Z.zip` to the GitHub Release

### Install

1. Extract the `RiskRegister` folder into your web root
2. PHP 8+ with sqlite, curl, zip, and openssl
3. Open the site and change the default admin password
MD;

function github_request(string $url, string $token, string $method = 'GET', ?string $body = null, string $contentType = 'application/json', bool $raw = false): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'User-Agent: RiskRegister-Release-Publisher',
        'X-GitHub-Api-Version: 2022-11-28',
        'Content-Type: ' . $contentType,
    ];
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to start GitHub request.');
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($handle, $options);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($response === false) {
        throw new RuntimeException('GitHub request failed: ' . $error);
    }
    $decoded = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $response) : (string) $response;
        throw new RuntimeException('GitHub HTTP ' . $status . ': ' . $message);
    }

    return is_array($decoded) ? $decoded : [];
}

$release = github_request(
    'https://api.github.com/repos/' . $repo . '/releases',
    $token,
    'POST',
    json_encode([
        'tag_name' => $tag,
        'target_commitish' => trim((string) shell_exec('git rev-parse --abbrev-ref HEAD')),
        'name' => $tag,
        'body' => $notes,
        'draft' => false,
        'prerelease' => false,
        'generate_release_notes' => true,
    ], JSON_THROW_ON_ERROR)
);

$uploadTemplate = (string) ($release['upload_url'] ?? '');
$uploadUrl = preg_replace('/\{.*\}$/', '', $uploadTemplate);
if (!is_string($uploadUrl) || $uploadUrl === '') {
    throw new RuntimeException('Release was created but no upload URL was returned.');
}

$zipBody = file_get_contents($zipPath);
if ($zipBody === false) {
    throw new RuntimeException('Unable to read ' . $zipPath);
}

$asset = github_request(
    $uploadUrl . '?name=' . rawurlencode($zipName) . '&label=' . rawurlencode('RiskRegister packaged install (includes vendor)'),
    $token,
    'POST',
    $zipBody,
    'application/zip',
    true
);

$htmlUrl = (string) ($release['html_url'] ?? '');
$zipUrl = (string) ($asset['browser_download_url'] ?? '');
fwrite(STDOUT, "RELEASE_URL={$htmlUrl}\n");
fwrite(STDOUT, "ZIP_URL={$zipUrl}\n");
exit(0);
