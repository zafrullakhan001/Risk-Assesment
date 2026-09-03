<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\SettingsRepository;
use RuntimeException;

final class GitHubUpdater
{
    public const DEFAULT_REPO = 'zafrullakhan001/Risk-Assesment';
    public const DEFAULT_BRANCH = 'main';
    public const PAT_CREATE_URL = 'https://github.com/settings/tokens/new?scopes=repo&description=Risk%20Assessment%20Updater';
    public const PAT_MANAGE_URL = 'https://github.com/settings/tokens';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Crypto $crypto,
        private readonly string $projectRoot,
        private readonly string $lockFile,
    ) {
    }

    public function repoSlug(): string
    {
        $repo = trim($this->settings->get('updater_repo', ''));
        if ($repo === '') {
            $repo = $this->detectRepoFromGit() ?? self::DEFAULT_REPO;
        }
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return self::DEFAULT_REPO;
        }

        return $repo;
    }

    public function trackBranch(): string
    {
        $branch = trim($this->settings->get('updater_track_branch', self::DEFAULT_BRANCH));
        if ($branch === '' || !preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
            return self::DEFAULT_BRANCH;
        }

        return $branch;
    }

    public function hasToken(): bool
    {
        return $this->githubToken() !== '';
    }

    /**
     * @return array{
     *   repo: string,
     *   branch: string,
     *   hasToken: bool,
     *   gitAvailable: bool,
     *   installedSha: string,
     *   installedShort: string,
     *   currentBranch: string,
     *   dirty: bool,
     *   lastAppliedAt: string
     * }
     */
    public function status(): array
    {
        $caps = $this->capabilities();
        $sha = $caps['gitAvailable'] ? $this->installedCommit() : '';

        return [
            'repo' => $this->repoSlug(),
            'branch' => $this->trackBranch(),
            'hasToken' => $this->hasToken(),
            'gitAvailable' => $caps['gitAvailable'],
            'installedSha' => $sha,
            'installedShort' => $sha !== '' ? substr($sha, 0, 7) : '',
            'currentBranch' => $caps['gitAvailable'] ? $this->currentBranch() : '',
            'dirty' => $caps['gitAvailable'] && $this->workingTreeDirty(),
            'lastAppliedAt' => $this->settings->get('updater_last_applied_at', ''),
        ];
    }

    /**
     * @return array{aheadBy: int, headSha: string, commits: list<array<string, string>>}
     */
    public function check(bool $forceFetch = true): array
    {
        $caps = $this->capabilities();
        if (!$caps['gitAvailable']) {
            throw new RuntimeException('Git is not available in this install. Install Git for Windows and ensure this folder is a git checkout.');
        }

        $branch = $this->trackBranch();
        $base = $this->installedCommit();
        if ($forceFetch && $this->hasToken()) {
            $this->ensureGitSafeDirectory();
            $this->runCommand('git fetch origin ' . escapeshellarg($branch) . ' --prune', 120);
        }

        if ($base === '') {
            $commits = $this->githubBranchCommits($branch);
            return [
                'aheadBy' => count($commits),
                'headSha' => $commits[0]['sha'] ?? '',
                'commits' => $commits,
            ];
        }

        $payload = $this->githubCompare($base, $branch);
        $commits = $this->mapCompareCommits($payload, $base);

        return [
            'aheadBy' => (int) ($payload['ahead_by'] ?? 0),
            'headSha' => $commits[0]['sha'] ?? '',
            'commits' => $commits,
        ];
    }

    public function apply(string $ref = ''): array
    {
        $caps = $this->capabilities();
        if (!$caps['gitAvailable']) {
            throw new RuntimeException('Git is not available in this install.');
        }

        $branch = $this->trackBranch();
        $target = trim($ref);
        if ($target === '') {
            $check = $this->check(true);
            $target = $check['headSha'] !== '' ? $check['headSha'] : $branch;
        }
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $target)) {
            throw new RuntimeException('Invalid update target.');
        }

        $lock = $this->acquireLock();
        try {
            if ($this->workingTreeDirty()) {
                // Force checkout matches LinkNest: local uncommitted files are overwritten.
            }

            $this->ensureGitSafeDirectory();
            $fetch = $this->runCommand('git fetch --tags --force origin', 180);
            if (!$fetch['ok']) {
                throw new RuntimeException($this->commandFailure('git fetch failed: ', $fetch));
            }
            $this->runCommand('git fetch origin ' . escapeshellarg($branch), 120);

            $checkout = $this->runCommand('git checkout --force ' . escapeshellarg($target), 120);
            if (!$checkout['ok']) {
                throw new RuntimeException($this->commandFailure('git checkout failed: ', $checkout));
            }

            $head = $this->runCommand('git rev-parse HEAD', 15);
            $sha = $head['ok'] ? strtolower($head['stdout']) : $target;
            $this->maybeComposerInstall();
            $this->settings->set('updater_last_applied_at', date('Y-m-d H:i:s'));
            $this->settings->set('updater_last_applied_sha', $sha);

            return [
                'ok' => true,
                'sha' => $sha,
                'short' => substr($sha, 0, 7),
                'message' => 'Updated to ' . substr($sha, 0, 7) . '. Reload the page (Ctrl+F5).',
            ];
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @param array{updater_repo?: string, updater_track_branch?: string, updater_github_token?: string|null} $input
     */
    public function saveSettings(array $input): void
    {
        $repo = trim((string) ($input['updater_repo'] ?? ''));
        if ($repo === '') {
            $repo = $this->detectRepoFromGit() ?? self::DEFAULT_REPO;
        }
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new RuntimeException('GitHub repo must be in owner/name format.');
        }
        $this->settings->set('updater_repo', $repo);

        $branch = trim((string) ($input['updater_track_branch'] ?? self::DEFAULT_BRANCH));
        if ($branch === '') {
            $branch = self::DEFAULT_BRANCH;
        }
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
            throw new RuntimeException('Track branch contains invalid characters.');
        }
        $this->settings->set('updater_track_branch', $branch);

        if (!array_key_exists('updater_github_token', $input)) {
            return;
        }

        $token = is_string($input['updater_github_token']) ? trim($input['updater_github_token']) : '';
        if ($token === '') {
            return;
        }
        if ($this->crypto->isMaskedPlaceholder($token)) {
            throw new RuntimeException('GitHub token looks masked (asterisks). Paste the real token and save again.');
        }
        if (str_starts_with($token, 'github_pat_') === false && str_starts_with($token, 'ghp_') === false) {
            // Allow other token prefixes (gho_, ghu_) but warn via exception only for obvious junk.
            if (strlen($token) < 20) {
                throw new RuntimeException('That does not look like a GitHub personal access token.');
            }
        }

        $this->settings->set('updater_github_token', $this->crypto->encrypt($token));
    }

    public function clearToken(): void
    {
        $this->settings->delete('updater_github_token');
    }

    private function githubToken(): string
    {
        $stored = $this->settings->get('updater_github_token', '');
        if ($stored === '') {
            $env = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: '';
            return is_string($env) ? trim($env) : '';
        }

        try {
            return $this->crypto->decrypt($stored);
        } catch (\Throwable $exception) {
            error_log('updater: failed to decrypt github token: ' . $exception->getMessage());
            return '';
        }
    }

    /**
     * @return array{gitAvailable: bool}
     */
    private function capabilities(): array
    {
        $hasGitDir = is_dir($this->projectRoot . DIRECTORY_SEPARATOR . '.git');
        return [
            'gitAvailable' => $hasGitDir && $this->gitBinary() !== '',
        ];
    }

    private function installedCommit(): string
    {
        $result = $this->runCommand('git rev-parse HEAD', 15);
        if ($result['ok'] && preg_match('/^[a-f0-9]{7,40}$/i', $result['stdout']) === 1) {
            return strtolower($result['stdout']);
        }

        return '';
    }

    private function currentBranch(): string
    {
        $result = $this->runCommand('git rev-parse --abbrev-ref HEAD', 15);
        return $result['ok'] ? $result['stdout'] : '';
    }

    private function workingTreeDirty(): bool
    {
        $result = $this->runCommand('git status --porcelain', 15);
        return $result['ok'] && $result['stdout'] !== '';
    }

    private function detectRepoFromGit(): ?string
    {
        if (!is_dir($this->projectRoot . DIRECTORY_SEPARATOR . '.git')) {
            return null;
        }
        $result = $this->runCommand('git remote get-url origin', 15);
        if (!$result['ok'] || $result['stdout'] === '') {
            return null;
        }
        if (preg_match('#github\.com[:/]([^/]+)/([^/\s]+?)(?:\.git)?$#i', $result['stdout'], $matches) !== 1) {
            return null;
        }

        return $matches[1] . '/' . preg_replace('/\.git$/i', '', $matches[2]);
    }

    /**
     * @return array<string, mixed>
     */
    private function githubCompare(string $base, string $head): array
    {
        $url = 'https://api.github.com/repos/' . $this->repoSlug()
            . '/compare/' . rawurlencode($base) . '...' . rawurlencode($head);
        return $this->httpJson($url);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{sha: string, short: string, message: string, author: string, date: string, url: string}>
     */
    private function mapCompareCommits(array $payload, string $base): array
    {
        $raw = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];
        $commits = [];
        foreach (array_reverse($raw) as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $mapped = $this->mapCommit($commit);
            if ($mapped === null) {
                continue;
            }
            if ($base !== '' && str_starts_with($mapped['sha'], substr($base, 0, 7))) {
                continue;
            }
            $commits[] = $mapped;
        }

        return $commits;
    }

    /**
     * @return list<array{sha: string, short: string, message: string, author: string, date: string, url: string}>
     */
    private function githubBranchCommits(string $branch): array
    {
        $url = 'https://api.github.com/repos/' . $this->repoSlug()
            . '/commits?sha=' . rawurlencode($branch) . '&per_page=20';
        $payload = $this->httpJson($url);
        $commits = [];
        foreach ($payload as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $mapped = $this->mapCommit($commit);
            if ($mapped !== null) {
                $commits[] = $mapped;
            }
        }

        return $commits;
    }

    /**
     * @param array<string, mixed> $commit
     * @return array{sha: string, short: string, message: string, author: string, date: string, url: string}|null
     */
    private function mapCommit(array $commit): ?array
    {
        $sha = strtolower((string) ($commit['sha'] ?? ''));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            return null;
        }
        $message = (string) ($commit['commit']['message'] ?? '');
        $subject = trim(explode("\n", $message)[0]);
        $author = (string) ($commit['commit']['author']['name'] ?? ($commit['author']['login'] ?? ''));
        $date = (string) ($commit['commit']['author']['date'] ?? '');

        return [
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'message' => $subject,
            'author' => $author,
            'date' => $date,
            'url' => (string) ($commit['html_url'] ?? ''),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function httpJson(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required to talk to GitHub.');
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: RiskAssessment-Updater',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $token = $this->githubToken();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to start a GitHub request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('GitHub request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        $text = is_string($body) ? $body : '';
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->formatGithubHttpError($status, $text));
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from GitHub.');
        }

        return $decoded;
    }

    private function formatGithubHttpError(int $status, string $body): string
    {
        $apiMessage = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded) && !empty($decoded['message'])) {
            $apiMessage = (string) $decoded['message'];
        }
        $repo = $this->repoSlug();

        if ($status === 404) {
            if (!$this->hasToken()) {
                return 'GitHub returned 404 for repo "' . $repo . '". '
                    . 'Private repositories require a classic Personal Access Token with the "repo" scope. '
                    . 'Create one at ' . self::PAT_CREATE_URL . ', paste it under Updater settings, Save, then Check again. '
                    . 'If the repo is public, confirm the owner/name is correct.';
            }
            return 'GitHub returned 404 for repo "' . $repo . '". '
                . 'Check that the repo name is correct and the token can access it '
                . '(classic: repo scope; fine-grained: Contents + Metadata).';
        }
        if ($status === 401) {
            return 'GitHub authentication failed (401). The saved token is invalid or expired — generate a new PAT and save it again.';
        }
        if ($status === 403) {
            return 'GitHub access denied (403) for "' . $repo . '". '
                . ($apiMessage !== '' ? $apiMessage . ' ' : '')
                . 'Ensure the token can read the repository, or wait if you hit a rate limit.';
        }

        $message = 'GitHub API HTTP ' . $status;
        if ($apiMessage !== '') {
            $message .= ': ' . $apiMessage;
        }
        return $message;
    }

    private function ensureGitSafeDirectory(): void
    {
        $safe = str_replace('\\', '/', $this->projectRoot);
        $this->runCommand('git config --global --add safe.directory ' . escapeshellarg($safe), 15);
    }

    private function maybeComposerInstall(): void
    {
        $composerJson = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerJson)) {
            return;
        }

        $phar = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.phar';
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        if (is_file($phar)) {
            $this->runCommand(escapeshellarg($php) . ' ' . escapeshellarg($phar) . ' install --no-dev --no-interaction', 180);
            return;
        }

        if ($this->commandExists('composer')) {
            $this->runCommand('composer install --no-dev --no-interaction', 180);
        }
    }

    /**
     * @param array{ok: bool, code: int, stdout: string, stderr: string} $result
     */
    private function commandFailure(string $prefix, array $result): string
    {
        $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
        if ($detail === '') {
            $detail = 'exit code ' . $result['code'] . ' (no output)';
        }
        if (!$this->hasToken()) {
            $detail .= '. Private repositories need a classic PAT with the repo scope. Create one at '
                . self::PAT_CREATE_URL . ', paste it under Updater settings, then try again.';
        }

        return $prefix . $detail;
    }

    /**
     * @return array{ok: bool, code: int, stdout: string, stderr: string}
     */
    private function runCommand(string $command, int $timeoutSec = 180): array
    {
        $command = $this->augmentGitCommand($command);
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $this->commandEnvironment();

        $process = @proc_open($command, $descriptor, $pipes, $this->projectRoot, $env);
        if (!is_resource($process)) {
            return ['ok' => false, 'code' => 1, 'stdout' => '', 'stderr' => 'Unable to start: ' . $command];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = time();
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ($timeoutSec > 0 && (time() - $started) >= $timeoutSec) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return [
                    'ok' => false,
                    'code' => 1,
                    'stdout' => trim($stdout),
                    'stderr' => trim($stderr . "\nCommand timed out after {$timeoutSec}s"),
                ];
            }
            usleep(80000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'ok' => $code === 0,
            'code' => $code,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ];
    }

    private function augmentGitCommand(string $command): string
    {
        $git = $this->gitBinary();
        if ($git === '') {
            return $command;
        }

        if (preg_match('/^\s*git(\s|$)/i', $command) !== 1) {
            return $command;
        }

        $safe = escapeshellarg(str_replace('\\', '/', $this->projectRoot));
        $inject = ' -c safe.directory=' . $safe . ' -c safe.directory=*';
        $token = $this->githubToken();
        if ($token !== '') {
            $basic = base64_encode('x-access-token:' . $token);
            $inject .= ' -c credential.helper=';
            $inject .= ' -c http.extraHeader=' . escapeshellarg('Authorization: Basic ' . $basic);
        }

        $binary = str_contains($git, ' ') ? escapeshellarg($git) : $git;
        return (string) preg_replace('/^\s*git\b/i', $binary . $inject, $command, 1);
    }

    /**
     * @return array<string, string>
     */
    private function commandEnvironment(): array
    {
        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
                $env[$key] = $value;
            }
        }
        foreach (['Path', 'PATH', 'PATHEXT', 'SystemRoot', 'TEMP', 'TMP', 'USERPROFILE', 'HOME'] as $keep) {
            $value = getenv($keep);
            if (is_string($value) && $value !== '') {
                $env[$keep] = $value;
            }
        }
        $env['GIT_TERMINAL_PROMPT'] = '0';
        $env['GCM_INTERACTIVE'] = 'never';
        $env['GIT_ASKPASS'] = '';
        return $env;
    }

    private function gitBinary(): string
    {
        static $cached = null;
        if (is_string($cached)) {
            return $cached;
        }

        $candidates = ['git'];
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = 'C:\\Program Files\\Git\\cmd\\git.exe';
            $candidates[] = 'C:\\Program Files\\Git\\bin\\git.exe';
            $candidates[] = 'C:\\Program Files (x86)\\Git\\cmd\\git.exe';
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== 'git' && is_file($candidate)) {
                $cached = $candidate;
                return $cached;
            }
        }
        $cached = $this->commandExists('git') ? 'git' : '';
        return $cached;
    }

    private function commandExists(string $command): bool
    {
        $check = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($command)
            : 'command -v ' . escapeshellarg($command);
        $output = [];
        $code = 1;
        @exec($check . ' 2>&1', $output, $code);
        return $code === 0;
    }

    /** @return resource|false */
    private function acquireLock()
    {
        $directory = dirname($this->lockFile);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the updater lock directory.');
        }

        $handle = fopen($this->lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the updater lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('An update is already in progress. Wait a moment and try again.');
        }

        return $handle;
    }

    /** @param resource|false $handle */
    private function releaseLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
