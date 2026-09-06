<?php

declare(strict_types=1);

namespace RiskAssessment;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RiskAssessment\Repositories\SettingsRepository;
use RuntimeException;
use ZipArchive;

final class GitHubUpdater
{
    public const DEFAULT_REPO = 'zafrullakhan001/Risk-Assesment';
    public const DEFAULT_BRANCH = 'main';
    public const PAT_CREATE_URL = 'https://github.com/settings/tokens/new?scopes=repo&description=Risk%20Assessment%20Updater';
    public const PAT_MANAGE_URL = 'https://github.com/settings/tokens';
    private const VERSION_FILE = 'VERSION.json';
    private const STAGING_DIR = 'database/update-staging';

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
     *   zipAvailable: bool,
     *   curlAvailable: bool,
     *   installedSha: string,
     *   installedShort: string,
     *   installedTag: string,
     *   installedVersion: string,
     *   currentBranch: string,
     *   dirty: bool,
     *   lastAppliedAt: string,
     *   updateMethod: string
     * }
     */
    public function status(): array
    {
        $caps = $this->capabilities();
        $manifest = $this->installedManifest();
        $sha = (string) ($manifest['sha'] ?? '');
        if ($sha === '' && $caps['gitAvailable']) {
            $sha = $this->installedCommitFromGit();
        }
        $tag = (string) ($manifest['tag'] ?? '');
        $version = (string) ($manifest['version'] ?? '');

        return [
            'repo' => $this->repoSlug(),
            'branch' => $this->trackBranch(),
            'hasToken' => $this->hasToken(),
            'gitAvailable' => $caps['gitAvailable'],
            'zipAvailable' => $caps['zipAvailable'],
            'curlAvailable' => $caps['curlAvailable'],
            'installedSha' => $sha,
            'installedShort' => $sha !== '' ? substr($sha, 0, 7) : ($tag !== '' ? $tag : ($version !== '' ? $version : '')),
            'installedTag' => $tag,
            'installedVersion' => $version !== '' ? $version : $tag,
            'currentBranch' => $caps['gitAvailable'] ? $this->currentBranch() : '',
            'dirty' => $caps['gitAvailable'] && $this->workingTreeDirty(),
            'lastAppliedAt' => $this->settings->get('updater_last_applied_at', ''),
            'updateMethod' => 'github-zip',
        ];
    }

    /**
     * @return array{
     *   aheadBy: int,
     *   headSha: string,
     *   mode: string,
     *   commits: list<array<string, string>>
     * }
     */
    public function check(bool $forceFetch = true): array
    {
        unset($forceFetch);
        $this->assertCanTalkToGithub();

        $releases = $this->githubReleases();
        $releaseCheck = $releases !== []
            ? $this->checkFromReleases($releases)
            : ['aheadBy' => 0, 'headSha' => '', 'mode' => 'releases', 'commits' => [], 'branch' => ''];

        $commitCheck = $this->checkFromCommits($releases);
        $items = [];
        $seen = [];
        foreach (array_merge($releaseCheck['commits'], $commitCheck['commits']) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = strtolower((string) ($item['sha'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
        }

        $mode = 'commits';
        if ($releaseCheck['commits'] !== [] && $commitCheck['commits'] !== []) {
            $mode = 'mixed';
        } elseif ($releaseCheck['commits'] !== []) {
            $mode = 'releases';
        } elseif ($releases !== [] && $items === []) {
            $mode = 'releases';
        }

        return [
            'aheadBy' => count($items),
            'headSha' => $items[0]['sha'] ?? '',
            'mode' => $mode,
            'commits' => $items,
            'branch' => (string) ($commitCheck['branch'] ?? $this->trackBranch()),
        ];
    }

    public function apply(string $ref = ''): array
    {
        $this->assertCanApply();

        $check = $this->check(false);
        $target = trim($ref);
        if ($target === '') {
            $target = $check['headSha'] !== '' ? $check['headSha'] : $this->trackBranch();
        }
        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $target)) {
            throw new RuntimeException('Invalid update target.');
        }

        $selected = $this->findUpdate($check['commits'], $target);
        $download = $this->resolveDownload($target, $selected);

        $lock = $this->acquireLock();
        $staging = $this->stagingPath();
        try {
            $this->resetDirectory($staging);
            $zipFile = $staging . DIRECTORY_SEPARATOR . 'package.zip';
            $extractDir = $staging . DIRECTORY_SEPARATOR . 'extract';
            $this->httpDownload($download['url'], $zipFile, $download['headers']);
            $payloadRoot = $this->extractPackage($zipFile, $extractDir);
            $this->overlayFiles($payloadRoot);
            $this->maybeComposerInstall();

            $kind = (string) ($selected['kind'] ?? '');
            $isCommit = $kind === 'commit' || preg_match('/^[a-f0-9]{7,40}$/i', $target) === 1;
            if ($isCommit) {
                $sha = strtolower($target);
                $tag = $this->installedTagOrVersion();
                if (preg_match('/^[a-f0-9]{7,40}$/i', $tag) === 1) {
                    $tag = '';
                }
            } else {
                $sha = (string) ($selected['commitSha'] ?? '');
                $tag = $target;
            }
            $this->recordApplied($tag, $sha);

            $label = $tag !== '' ? $tag : ($sha !== '' ? substr($sha, 0, 7) : $target);

            return [
                'ok' => true,
                'sha' => $sha !== '' ? $sha : $target,
                'short' => $label,
                'message' => 'Updated to ' . $label . '. Reload the page (Ctrl+F5). Database and uploads were kept in place.',
            ];
        } finally {
            $this->deleteDirectory($staging);
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

    /**
     * @param list<array<string, mixed>> $releases
     * @return array{aheadBy: int, headSha: string, mode: string, commits: list<array<string, string>>}
     */
    private function checkFromReleases(array $releases): array
    {
        $installed = $this->installedTagOrVersion();
        $updates = [];
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            if (!empty($release['prerelease']) && !$this->installedIsPrerelease()) {
                continue;
            }
            $mapped = $this->mapRelease($release);
            if ($mapped === null) {
                continue;
            }
            if ($installed !== '' && $this->sameVersion($mapped['sha'], $installed)) {
                break;
            }
            $updates[] = $mapped;
        }

        if ($installed === '' && $updates === []) {
            foreach ($releases as $release) {
                if (!is_array($release) || !empty($release['prerelease'])) {
                    continue;
                }
                $mapped = $this->mapRelease($release);
                if ($mapped !== null) {
                    $updates[] = $mapped;
                }
            }
        }

        return [
            'aheadBy' => count($updates),
            'headSha' => $updates[0]['sha'] ?? '',
            'mode' => 'releases',
            'commits' => $updates,
            'branch' => '',
        ];
    }

    /**
     * @param list<array<string, mixed>> $releases
     * @return array{aheadBy: int, headSha: string, mode: string, commits: list<array<string, string>>, branch: string}
     */
    private function checkFromCommits(array $releases = []): array
    {
        $empty = [
            'aheadBy' => 0,
            'headSha' => '',
            'mode' => 'commits',
            'commits' => [],
            'branch' => $this->trackBranch(),
        ];

        $base = $this->installedCompareBase($releases);
        $branches = [];
        $current = $this->capabilities()['gitAvailable'] ? $this->currentBranch() : '';
        if ($current !== '' && $current !== 'HEAD') {
            $branches[] = $current;
        }
        $track = $this->trackBranch();
        if (!in_array($track, $branches, true)) {
            $branches[] = $track;
        }
        if ($branches !== []) {
            $empty['branch'] = $branches[0];
        }

        foreach ($branches as $branch) {
            if ($base === '') {
                $commits = $this->githubBranchCommits($branch);
                if ($commits !== []) {
                    return [
                        'aheadBy' => count($commits),
                        'headSha' => $commits[0]['sha'] ?? '',
                        'mode' => 'commits',
                        'commits' => $commits,
                        'branch' => $branch,
                    ];
                }
                continue;
            }

            $ahead = $this->commitsAheadOfBase($base, $branch);
            if ($ahead['commits'] !== []) {
                return [
                    'aheadBy' => $ahead['aheadBy'],
                    'headSha' => $ahead['commits'][0]['sha'] ?? '',
                    'mode' => 'commits',
                    'commits' => $ahead['commits'],
                    'branch' => $branch,
                ];
            }
        }

        return $empty;
    }

    /**
     * @param list<array<string, mixed>> $releases
     */
    private function installedCompareBase(array $releases): string
    {
        $manifest = $this->installedManifest();
        if ($manifest['sha'] !== '') {
            return $manifest['sha'];
        }
        $tag = $this->installedTagOrVersion();
        if ($tag !== '') {
            return $tag;
        }
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
                continue;
            }
            $name = trim((string) ($release['tag_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * @return array{aheadBy: int, commits: list<array<string, string>>}
     */
    private function commitsAheadOfBase(string $base, string $head): array
    {
        try {
            $payload = $this->githubCompare($base, $head);
            $commits = $this->mapCompareCommits($payload, $base);
            return [
                'aheadBy' => (int) ($payload['ahead_by'] ?? count($commits)),
                'commits' => $commits,
            ];
        } catch (RuntimeException $exception) {
            error_log('updater: compare ' . $base . '...' . $head . ' failed: ' . $exception->getMessage());
            return ['aheadBy' => 0, 'commits' => []];
        }
    }

    /**
     * @param list<array<string, string>> $items
     * @return array<string, string>
     */
    private function findUpdate(array $items, string $target): array
    {
        foreach ($items as $item) {
            $sha = (string) ($item['sha'] ?? '');
            if ($sha === $target || str_starts_with($sha, $target) || $this->sameVersion($sha, $target)) {
                return $item;
            }
        }

        return ['sha' => $target, 'short' => $target];
    }

    /**
     * @param array<string, string> $selected
     * @return array{url: string, headers: list<string>}
     */
    private function resolveDownload(string $target, array $selected): array
    {
        $assetId = trim((string) ($selected['assetId'] ?? ''));
        if ($assetId !== '' && ctype_digit($assetId)) {
            return [
                'url' => 'https://api.github.com/repos/' . $this->repoSlug() . '/releases/assets/' . $assetId,
                'headers' => ['Accept: application/octet-stream'],
            ];
        }

        return [
            'url' => 'https://api.github.com/repos/' . $this->repoSlug() . '/zipball/' . rawurlencode($target),
            'headers' => ['Accept: application/vnd.github+json'],
        ];
    }

    private function extractPackage(string $zipFile, string $extractDir): string
    {
        if (!is_file($zipFile) || filesize($zipFile) < 64) {
            throw new RuntimeException('The downloaded update file is empty or incomplete.');
        }
        $magic = (string) file_get_contents($zipFile, false, null, 0, 4);
        if (!str_starts_with($magic, 'PK')) {
            throw new RuntimeException('GitHub did not return a zip file. Check the token and repository name.');
        }

        if (!is_dir($extractDir) && !mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            throw new RuntimeException('Unable to create the update extract folder.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipFile);
        if ($opened !== true) {
            throw new RuntimeException('Unable to open the update zip (code ' . (string) $opened . ').');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_contains($name, '..')) {
                $zip->close();
                throw new RuntimeException('The update zip contains an unsafe path and was rejected.');
            }
        }

        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new RuntimeException('Unable to extract the update zip.');
        }
        $zip->close();

        return $this->payloadRoot($extractDir);
    }

    private function payloadRoot(string $extractDir): string
    {
        $entries = scandir($extractDir);
        if (!is_array($entries)) {
            throw new RuntimeException('Unable to read the extracted update.');
        }
        $useful = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $useful[] = $entry;
        }
        if (count($useful) === 1 && is_dir($extractDir . DIRECTORY_SEPARATOR . $useful[0])) {
            return $extractDir . DIRECTORY_SEPARATOR . $useful[0];
        }

        return $extractDir;
    }

    private function overlayFiles(string $sourceRoot): void
    {
        if (!is_dir($sourceRoot)) {
            throw new RuntimeException('The update package has no files to copy.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $copied = 0;
        foreach ($iterator as $file) {
            $absolute = $file->getPathname();
            $relative = substr($absolute, strlen($sourceRoot) + 1);
            if ($relative === false || $relative === '') {
                continue;
            }
            $relativeUnix = str_replace('\\', '/', $relative);
            if ($this->shouldPreserve($relativeUnix)) {
                continue;
            }

            $destination = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeUnix);
            if ($file->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                    throw new RuntimeException('Unable to create folder: ' . $relativeUnix);
                }
                continue;
            }

            $directory = dirname($destination);
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create folder: ' . dirname($relativeUnix));
            }
            if (!@copy($absolute, $destination)) {
                usleep(120000);
                if (!@copy($absolute, $destination)) {
                    throw new RuntimeException('Unable to replace ' . $relativeUnix . '. Close programs using that file and try again.');
                }
            }
            $copied++;
        }

        if ($copied < 5) {
            throw new RuntimeException('The update package did not contain enough application files.');
        }
    }

    private function shouldPreserve(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $base = basename($relative);

        if ($relative === '.git' || str_starts_with($relative, '.git/')) {
            return true;
        }
        if ($relative === self::STAGING_DIR || str_starts_with($relative, self::STAGING_DIR . '/')) {
            return true;
        }
        if ($relative === 'dist' || str_starts_with($relative, 'dist/')) {
            return true;
        }
        if ($relative === 'uploads' || str_starts_with($relative, 'uploads/')) {
            return !in_array($base, ['.gitkeep', '.htaccess'], true);
        }
        if (str_starts_with($relative, 'public/assets/branding/')) {
            return $base !== '.gitkeep';
        }
        if ($relative === 'database' || str_starts_with($relative, 'database/')) {
            return !in_array($base, ['schema.sql', 'schema.sqlite.sql', '.gitkeep', '.htaccess'], true);
        }

        return false;
    }

    private function recordApplied(string $tag, string $sha): void
    {
        $version = $tag !== '' ? $this->normalizeVersion($tag) : '';
        $manifest = [
            'name' => 'RiskRegister',
            'version' => $version !== '' ? $version : (string) ($this->installedManifest()['version'] ?? ''),
            'tag' => $tag,
            'sha' => $sha,
            'built_at' => gmdate('c'),
            'repo' => $this->repoSlug(),
        ];
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . self::VERSION_FILE;
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Updated files were copied, but VERSION.json could not be written.');
        }

        $this->settings->set('updater_last_applied_at', date('Y-m-d H:i:s'));
        if ($sha !== '') {
            $this->settings->set('updater_last_applied_sha', $sha);
        }
        if ($tag !== '') {
            $this->settings->set('updater_last_applied_tag', $tag);
        }
    }

    /**
     * @return array{version?: string, tag?: string, sha?: string}
     */
    private function installedManifest(): array
    {
        $file = $this->readVersionFile();
        $tag = trim($this->settings->get('updater_last_applied_tag', ''));
        $sha = trim($this->settings->get('updater_last_applied_sha', ''));
        if ($tag === '' && isset($file['tag'])) {
            $tag = trim((string) $file['tag']);
        }
        if ($sha === '' && isset($file['sha'])) {
            $sha = trim((string) $file['sha']);
        }
        $version = isset($file['version']) ? trim((string) $file['version']) : '';
        if ($version === '' && $tag !== '') {
            $version = $this->normalizeVersion($tag);
        }

        return [
            'version' => $version,
            'tag' => $tag,
            'sha' => $sha,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readVersionFile(): ?array
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . self::VERSION_FILE;
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function installedTagOrVersion(): string
    {
        $manifest = $this->installedManifest();
        if ($manifest['tag'] !== '') {
            return $manifest['tag'];
        }

        return $manifest['version'];
    }

    private function installedIsPrerelease(): bool
    {
        $value = strtolower($this->installedTagOrVersion());
        return str_contains($value, 'alpha') || str_contains($value, 'beta') || str_contains($value, 'rc');
    }

    private function sameVersion(string $left, string $right): bool
    {
        return strcasecmp($this->normalizeVersion($left), $this->normalizeVersion($right)) === 0;
    }

    private function normalizeVersion(string $value): string
    {
        $value = trim($value);
        if (str_starts_with(strtolower($value), 'v') && preg_match('/^v\d/i', $value) === 1) {
            return substr($value, 1);
        }

        return $value;
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
     * @return array{gitAvailable: bool, zipAvailable: bool, curlAvailable: bool}
     */
    private function capabilities(): array
    {
        $hasGitDir = is_dir($this->projectRoot . DIRECTORY_SEPARATOR . '.git');
        return [
            'gitAvailable' => $hasGitDir && $this->gitBinary() !== '',
            'zipAvailable' => class_exists(ZipArchive::class),
            'curlAvailable' => function_exists('curl_init'),
        ];
    }

    private function assertCanTalkToGithub(): void
    {
        if (!$this->capabilities()['curlAvailable']) {
            throw new RuntimeException('PHP cURL is required to check GitHub for updates. Enable extension=curl in php.ini and restart Apache.');
        }
    }

    private function assertCanApply(): void
    {
        $this->assertCanTalkToGithub();
        if (!$this->capabilities()['zipAvailable']) {
            throw new RuntimeException('PHP zip is required to apply updates. Enable extension=zip in php.ini and restart Apache.');
        }
    }

    private function installedCommitFromGit(): string
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
     * @return list<array<string, mixed>>
     */
    private function githubReleases(): array
    {
        $url = 'https://api.github.com/repos/' . $this->repoSlug() . '/releases?per_page=20';
        $payload = $this->httpJson($url);
        $releases = [];
        foreach ($payload as $release) {
            if (!is_array($release) || !empty($release['draft'])) {
                continue;
            }
            $releases[] = $release;
        }

        return $releases;
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, string>|null
     */
    private function mapRelease(array $release): ?array
    {
        $tag = trim((string) ($release['tag_name'] ?? ''));
        if ($tag === '' || preg_match('#^[A-Za-z0-9._/-]+$#', $tag) !== 1) {
            return null;
        }
        $name = trim((string) ($release['name'] ?? ''));
        if ($name === '') {
            $name = $tag;
        }
        $asset = $this->preferredReleaseAsset($release);
        $published = (string) ($release['published_at'] ?? $release['created_at'] ?? '');
        $when = $published !== '' ? substr($published, 0, 10) : '';

        return [
            'sha' => $tag,
            'short' => $tag,
            'message' => $name,
            'author' => $when,
            'date' => $published,
            'url' => (string) ($release['html_url'] ?? ''),
            'assetId' => $asset !== null ? (string) $asset['id'] : '',
            'assetName' => $asset !== null ? (string) $asset['name'] : '',
            'commitSha' => '',
            'kind' => 'release',
        ];
    }

    /**
     * @param array<string, mixed> $release
     * @return array{id: int, name: string}|null
     */
    private function preferredReleaseAsset(array $release): ?array
    {
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $zipAssets = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $id = (int) ($asset['id'] ?? 0);
            if ($id <= 0 || preg_match('/\.zip$/i', $name) !== 1) {
                continue;
            }
            $zipAssets[] = ['id' => $id, 'name' => $name];
        }
        foreach ($zipAssets as $asset) {
            if (preg_match('/RiskRegister|Risk-Assesment|RiskAssessment/i', $asset['name']) === 1) {
                return $asset;
            }
        }

        return $zipAssets[0] ?? null;
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
            'assetId' => '',
            'assetName' => '',
            'commitSha' => $sha,
            'kind' => 'commit',
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function httpJson(string $url): array
    {
        $text = $this->httpRequest($url, ['Accept: application/vnd.github+json']);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from GitHub.');
        }

        return $decoded;
    }

    /**
     * @param list<string> $extraHeaders
     */
    private function httpDownload(string $url, string $destination, array $extraHeaders = []): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the update download folder.');
        }

        $handle = fopen($destination, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to write the update zip.');
        }

        try {
            $this->httpRequest($url, $extraHeaders, $handle, 300);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string> $extraHeaders
     * @param resource|null $fileHandle
     */
    private function httpRequest(string $url, array $extraHeaders = [], $fileHandle = null, int $timeout = 30): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required to talk to GitHub.');
        }

        $headers = [
            'User-Agent: RiskAssessment-Updater',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }
        $token = $this->githubToken();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to start a GitHub request.');
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
        if (is_resource($fileHandle)) {
            $options[CURLOPT_FILE] = $fileHandle;
            $options[CURLOPT_RETURNTRANSFER] = false;
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('GitHub request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        if ($status < 200 || $status >= 300) {
            $text = is_string($body) ? $body : '';
            if ($text === '' && is_resource($fileHandle)) {
                fflush($fileHandle);
            }
            throw new RuntimeException($this->formatGithubHttpError($status, $text));
        }

        return is_string($body) ? $body : '';
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

    private function maybeComposerInstall(): void
    {
        $autoload = $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoload)) {
            return;
        }
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
        $env['COMPOSER_DISABLE_XDEBUG_WARN'] = '1';
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

    private function stagingPath(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::STAGING_DIR);
    }

    private function resetDirectory(string $path): void
    {
        if (is_dir($path)) {
            $this->deleteDirectory($path);
        }
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create the updater staging folder.');
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($path);
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
