<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RiskAssessment\SharePoint\SharePointFolderUrl;
use RuntimeException;

/**
 * Registered SharePoint folder catalogs (each has its own source_key + MFA sync).
 */
final class SharePointSourceRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT * FROM sharepoint_sources ORDER BY sort_order ASC, id ASC'
        );

        return array_map(
            [$this, 'mapRow'],
            $statement ? ($statement->fetchAll() ?: []) : []
        );
    }

    public function findByKey(string $sourceKey): ?array
    {
        $sourceKey = $this->normalizeKey($sourceKey);
        if ($sourceKey === '') {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM sharepoint_sources WHERE source_key = :k LIMIT 1');
        $statement->execute([':k' => $sourceKey]);
        $row = $statement->fetch();

        return $row ? $this->mapRow($row) : null;
    }

    public function requireByKey(string $sourceKey): array
    {
        $source = $this->findByKey($sourceKey);
        if ($source === null) {
            throw new RuntimeException('SharePoint source not found.');
        }

        return $source;
    }

    /**
     * Create a source from a display title + SharePoint folder URL.
     *
     * @return array<string, mixed>
     */
    public function createFromUrl(string $title, string $folderUrl): array
    {
        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('Please enter a display name for this SharePoint link.');
        }
        $parsed = SharePointFolderUrl::parse($folderUrl);
        $sourceKey = $this->allocateUniqueKey($title);

        $sort = (int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM sharepoint_sources')->fetchColumn();
        $insert = $this->pdo->prepare(
            'INSERT INTO sharepoint_sources (
                source_key, title, folder_url, site_host, site_path, folder_path,
                sort_order, created_at, updated_at
             ) VALUES (
                :source_key, :title, :folder_url, :site_host, :site_path, :folder_path,
                :sort_order, datetime(\'now\'), datetime(\'now\')
             )'
        );
        $insert->execute([
            ':source_key' => $sourceKey,
            ':title' => mb_substr($title, 0, 200),
            ':folder_url' => $parsed['folder_url'],
            ':site_host' => $parsed['site_host'],
            ':site_path' => $parsed['site_path'],
            ':folder_path' => $parsed['folder_path'],
            ':sort_order' => $sort + 1,
        ]);

        return $this->requireByKey($sourceKey);
    }

    /**
     * @param array{title?: string, folder_url?: string} $input
     * @return array<string, mixed>
     */
    public function update(string $sourceKey, array $input): array
    {
        $source = $this->requireByKey($sourceKey);
        $title = array_key_exists('title', $input) ? trim((string) $input['title']) : (string) $source['title'];
        if ($title === '') {
            throw new RuntimeException('Display name is required.');
        }

        $folderUrl = array_key_exists('folder_url', $input)
            ? trim((string) $input['folder_url'])
            : (string) $source['folder_url'];
        $parsed = SharePointFolderUrl::parse($folderUrl);

        $statement = $this->pdo->prepare(
            'UPDATE sharepoint_sources
             SET title = :title,
                 folder_url = :folder_url,
                 site_host = :site_host,
                 site_path = :site_path,
                 folder_path = :folder_path,
                 updated_at = datetime(\'now\')
             WHERE source_key = :source_key'
        );
        $statement->execute([
            ':title' => mb_substr($title, 0, 200),
            ':folder_url' => $parsed['folder_url'],
            ':site_host' => $parsed['site_host'],
            ':site_path' => $parsed['site_path'],
            ':folder_path' => $parsed['folder_path'],
            ':source_key' => $source['source_key'],
        ]);

        return $this->requireByKey((string) $source['source_key']);
    }

    public function delete(string $sourceKey, SharePointCatalogRepository $catalog): void
    {
        $source = $this->requireByKey($sourceKey);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM sharepoint_sources')->fetchColumn();
        if ($count <= 1) {
            throw new RuntimeException('Keep at least one SharePoint source.');
        }
        $catalog->replaceForSource((string) $source['source_key'], []);
        $delete = $this->pdo->prepare('DELETE FROM sharepoint_sources WHERE source_key = :k');
        $delete->execute([':k' => $source['source_key']]);
    }

    public function markSynced(string $sourceKey, string $status, int $itemCount, string $error = ''): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sharepoint_sources
             SET last_synced_at = datetime(\'now\'),
                 last_sync_status = :status,
                 last_sync_error = :error,
                 last_item_count = :count,
                 updated_at = datetime(\'now\')
             WHERE source_key = :source_key'
        );
        $statement->execute([
            ':status' => mb_substr($status, 0, 40),
            ':error' => mb_substr($error, 0, 1000),
            ':count' => max(0, $itemCount),
            ':source_key' => $this->normalizeKey($sourceKey),
        ]);
    }

    /**
     * @return array{token: string, expires_at: int}
     */
    public function issueSyncToken(string $sourceKey, int $ttlSeconds = 1800): array
    {
        $source = $this->requireByKey($sourceKey);
        $token = bin2hex(random_bytes(24));
        $expiresAt = time() + max(60, $ttlSeconds);
        $statement = $this->pdo->prepare(
            'UPDATE sharepoint_sources
             SET sync_token_hash = :hash,
                 sync_token_expires = :expires,
                 updated_at = datetime(\'now\')
             WHERE source_key = :source_key'
        );
        $statement->execute([
            ':hash' => hash('sha256', $token),
            ':expires' => (string) $expiresAt,
            ':source_key' => $source['source_key'],
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function assertSyncToken(string $sourceKey, string $token): void
    {
        $source = $this->requireByKey($sourceKey);
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            throw new RuntimeException('Missing or invalid sync token. Click Prepare MFA sync again.');
        }
        $hash = (string) ($source['sync_token_hash'] ?? '');
        $expires = (int) ($source['sync_token_expires'] ?? 0);
        if ($hash === '' || $expires < time()) {
            throw new RuntimeException('Sync token expired. Prepare MFA sync again for this source.');
        }
        if (!hash_equals($hash, hash('sha256', $token))) {
            throw new RuntimeException('Sync token does not match this SharePoint source.');
        }
    }

    public function clearSyncToken(string $sourceKey): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sharepoint_sources
             SET sync_token_hash = \'\',
                 sync_token_expires = \'0\',
                 updated_at = datetime(\'now\')
             WHERE source_key = :source_key'
        );
        $statement->execute([':source_key' => $this->normalizeKey($sourceKey)]);
    }

    /**
     * Ensure the legacy "default" Architectural Projects source exists (migrates global settings).
     *
     * @param array{folder_url?: string, site_host?: string, site_path?: string, folder_path?: string, last_synced_at?: string, last_sync_status?: string, last_item_count?: int} $legacy
     */
    public function ensureDefaultSource(array $legacy = []): array
    {
        $existing = $this->findByKey(SharePointCatalogRepository::SOURCE_DEFAULT);
        if ($existing !== null) {
            return $existing;
        }

        $host = trim((string) ($legacy['site_host'] ?? '')) ?: 'ahsonline.sharepoint.com';
        $sitePath = trim((string) ($legacy['site_path'] ?? '')) ?: '/teams/AITTechnologyEngagement';
        $folderPath = trim((string) ($legacy['folder_path'] ?? '')) ?: 'Architectural Projects [Public]';
        $folderUrl = trim((string) ($legacy['folder_url'] ?? ''));
        if ($folderUrl === '') {
            $folderUrl = SharePointFolderUrl::buildBrowseUrl($host, $sitePath, $folderPath);
        } else {
            try {
                $parsed = SharePointFolderUrl::parse($folderUrl);
                $host = $parsed['site_host'];
                $sitePath = $parsed['site_path'];
                $folderPath = $parsed['folder_path'];
                $folderUrl = $parsed['folder_url'];
            } catch (RuntimeException) {
                $folderUrl = SharePointFolderUrl::buildBrowseUrl($host, $sitePath, $folderPath);
            }
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO sharepoint_sources (
                source_key, title, folder_url, site_host, site_path, folder_path,
                last_synced_at, last_sync_status, last_item_count, sort_order, created_at, updated_at
             ) VALUES (
                :source_key, :title, :folder_url, :site_host, :site_path, :folder_path,
                :last_synced_at, :last_sync_status, :last_item_count, 1, datetime(\'now\'), datetime(\'now\')
             )'
        );
        $insert->execute([
            ':source_key' => SharePointCatalogRepository::SOURCE_DEFAULT,
            ':title' => 'Architectural Projects [Public]',
            ':folder_url' => $folderUrl,
            ':site_host' => $host,
            ':site_path' => $sitePath,
            ':folder_path' => $folderPath,
            ':last_synced_at' => (string) ($legacy['last_synced_at'] ?? ''),
            ':last_sync_status' => (string) ($legacy['last_sync_status'] ?? ''),
            ':last_item_count' => (int) ($legacy['last_item_count'] ?? 0),
        ]);

        return $this->requireByKey(SharePointCatalogRepository::SOURCE_DEFAULT);
    }

    private function allocateUniqueKey(string $title): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? 'source';
        $base = trim($base, '-');
        if ($base === '' || $base === 'default') {
            $base = 'source';
        }
        $base = mb_substr($base, 0, 40);
        $candidate = $base;
        $n = 2;
        while ($this->findByKey($candidate) !== null) {
            $candidate = mb_substr($base, 0, 36) . '-' . $n;
            $n++;
            if ($n > 200) {
                $candidate = 'source-' . bin2hex(random_bytes(4));
                break;
            }
        }

        return $candidate;
    }

    private function normalizeKey(string $sourceKey): string
    {
        $sourceKey = strtolower(trim($sourceKey));
        $sourceKey = preg_replace('/[^a-z0-9\-]+/', '', $sourceKey) ?? '';

        return mb_substr($sourceKey, 0, 64);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'source_key' => (string) ($row['source_key'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'folder_url' => (string) ($row['folder_url'] ?? ''),
            'site_host' => (string) ($row['site_host'] ?? ''),
            'site_path' => (string) ($row['site_path'] ?? ''),
            'folder_path' => (string) ($row['folder_path'] ?? ''),
            'last_synced_at' => (string) ($row['last_synced_at'] ?? ''),
            'last_sync_status' => (string) ($row['last_sync_status'] ?? ''),
            'last_sync_error' => (string) ($row['last_sync_error'] ?? ''),
            'last_item_count' => (int) ($row['last_item_count'] ?? 0),
            'sync_token_hash' => (string) ($row['sync_token_hash'] ?? ''),
            'sync_token_expires' => (string) ($row['sync_token_expires'] ?? '0'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }
}
