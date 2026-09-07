<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RiskAssessment\AppUrl;
use RiskAssessment\Crypto;

/**
 * Public read-only share links for SharePoint catalog cards and project-owner cards.
 */
final class CatalogShareRepository
{
    public const TOKEN_BYTES = 32;
    public const KIND_CATALOG = 'catalog';
    public const KIND_OWNERS = 'owners';
    public const HISTORY_PER_PAGE = 8;
    public const MAX_ACTIVE = 10;
    public const LABEL_MAX_LENGTH = 60;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
    ) {
    }

    public static function normalizeKind(string $kind): string
    {
        return $kind === self::KIND_OWNERS ? self::KIND_OWNERS : self::KIND_CATALOG;
    }

    public static function publicFile(string $kind): string
    {
        return self::normalizeKind($kind) === self::KIND_OWNERS ? 'owners-share.php' : 'catalog-share.php';
    }

    public static function normalizeLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        $label = strip_tags($label);
        $label = preg_replace('/[\x00-\x1F\x7F]/', '', $label) ?? '';
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return trim(mb_substr($label, 0, self::LABEL_MAX_LENGTH));
        }

        return trim(substr($label, 0, self::LABEL_MAX_LENGTH));
    }

    /**
     * Create a new public share. Existing active links of the same kind stay valid (up to MAX_ACTIVE).
     *
     * @param list<string> $sourceKeys Empty list means every catalog at view time.
     * @param array{id?: int, username?: string}|null $actor
     * @return array{token: string, id: int, created_at: string, url_path: string, source_keys: list<string>, kind: string, label: string}
     */
    public function create(
        array $sourceKeys = [],
        ?array $actor = null,
        string $kind = self::KIND_CATALOG,
        string $label = '',
    ): array {
        $kind = self::normalizeKind($kind);
        $label = self::normalizeLabel($label);
        if ($label === '') {
            throw new \RuntimeException('Add a tag so you can tell this public link apart from others.');
        }

        $normalized = $this->normalizeSourceKeys($sourceKeys);
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = $this->hashToken($token);
        $tokenSecret = $this->crypto->encrypt($token);
        $userId = isset($actor['id']) ? (int) $actor['id'] : null;
        $username = trim((string) ($actor['username'] ?? ''));
        $sourceJson = $normalized === [] ? '' : json_encode($normalized, JSON_UNESCAPED_UNICODE);

        $this->pdo->beginTransaction();
        try {
            if ($this->countActive($kind) >= self::MAX_ACTIVE) {
                throw new \RuntimeException(
                    'You already have ' . self::MAX_ACTIVE . ' active public links. Revoke one before creating another.'
                );
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO catalog_share_links (
                    token_hash, token_secret, kind, label, source_keys, created_by_user_id, created_by_username, created_at
                 ) VALUES (
                    :token_hash, :token_secret, :kind, :label, :source_keys, :created_by_user_id, :created_by_username, datetime(\'now\')
                 )'
            );
            $insert->execute([
                ':token_hash' => $tokenHash,
                ':token_secret' => $tokenSecret,
                ':kind' => $kind,
                ':label' => $label,
                ':source_keys' => is_string($sourceJson) ? $sourceJson : '',
                ':created_by_user_id' => $userId > 0 ? $userId : null,
                ':created_by_username' => $username,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $createdAt = (string) ($this->pdo->query(
            'SELECT created_at FROM catalog_share_links WHERE id = ' . $id
        )->fetchColumn() ?: gmdate('Y-m-d H:i:s'));

        return [
            'token' => $token,
            'id' => $id,
            'created_at' => $createdAt,
            'url_path' => self::publicFile($kind) . '?t=' . rawurlencode($token),
            'source_keys' => $normalized,
            'kind' => $kind,
            'label' => $label,
        ];
    }

    public function revokeById(int $shareId, string $kind = ''): bool
    {
        if ($shareId <= 0) {
            return false;
        }

        $sql = "UPDATE catalog_share_links
                SET revoked_at = datetime('now')
                WHERE id = :id
                  AND revoked_at IS NULL";
        $params = [':id' => $shareId];
        if ($kind !== '') {
            $sql .= ' AND kind = :kind';
            $params[':kind'] = self::normalizeKind($kind);
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function revokeAll(string $kind = self::KIND_CATALOG): int
    {
        $kind = self::normalizeKind($kind);
        $statement = $this->pdo->prepare(
            "UPDATE catalog_share_links
             SET revoked_at = datetime('now')
             WHERE revoked_at IS NULL
               AND kind = :kind"
        );
        $statement->execute([':kind' => $kind]);

        return $statement->rowCount();
    }

    /**
     * Permanently delete revoked and expired rows. Active links are kept.
     */
    public function purgeHistory(string $kind = self::KIND_CATALOG): int
    {
        $kind = self::normalizeKind($kind);
        $statement = $this->pdo->prepare(
            "DELETE FROM catalog_share_links
             WHERE kind = :kind
               AND (
                    (revoked_at IS NOT NULL AND revoked_at != '')
                    OR (
                        expires_at IS NOT NULL
                        AND expires_at != ''
                        AND expires_at < datetime('now')
                    )
               )"
        );
        $statement->execute([':kind' => $kind]);

        return $statement->rowCount();
    }

    public function countHistory(string $kind = self::KIND_CATALOG): int
    {
        $kind = self::normalizeKind($kind);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM catalog_share_links WHERE kind = :kind');
        $statement->execute([':kind' => $kind]);

        return (int) $statement->fetchColumn();
    }

    public function countActive(string $kind = self::KIND_CATALOG): int
    {
        $kind = self::normalizeKind($kind);
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM catalog_share_links
             WHERE kind = :kind
               AND (revoked_at IS NULL OR revoked_at = '')
               AND (
                    expires_at IS NULL
                    OR expires_at = ''
                    OR expires_at >= datetime('now')
               )"
        );
        $statement->execute([':kind' => $kind]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{
     *   id: int,
     *   kind: string,
     *   source_keys: list<string>,
     *   created_at: string,
     *   created_by_username: string,
     *   expires_at: ?string
     * }|null
     */
    public function findActiveByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, kind, label, source_keys, created_at, created_by_username, expires_at, revoked_at
             FROM catalog_share_links
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $statement->execute([':token_hash' => $this->hashToken($token)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        if (($row['revoked_at'] ?? null) !== null && (string) $row['revoked_at'] !== '') {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt !== null && (string) $expiresAt !== '') {
            $expiresTs = strtotime((string) $expiresAt);
            if ($expiresTs !== false && $expiresTs < time()) {
                return null;
            }
        }

        $touch = $this->pdo->prepare(
            "UPDATE catalog_share_links
             SET last_accessed_at = datetime('now')
             WHERE id = :id"
        );
        $touch->execute([':id' => (int) $row['id']]);

        return [
            'id' => (int) $row['id'],
            'kind' => self::normalizeKind((string) ($row['kind'] ?? self::KIND_CATALOG)),
            'label' => self::normalizeLabel((string) ($row['label'] ?? '')),
            'source_keys' => $this->decodeSourceKeys((string) ($row['source_keys'] ?? '')),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_by_username' => (string) ($row['created_by_username'] ?? ''),
            'expires_at' => $expiresAt !== null && (string) $expiresAt !== '' ? (string) $expiresAt : null,
        ];
    }

    /**
     * @return list<array{
     *   id: int,
     *   label: string,
     *   source_keys: list<string>,
     *   created_at: string,
     *   created_by_username: string,
     *   expires_at: ?string,
     *   last_accessed_at: ?string,
     *   is_active: bool,
     *   url: string,
     *   can_copy: bool
     * }>
     */
    public function listPage(string $kind = self::KIND_CATALOG, int $page = 1, int $perPage = self::HISTORY_PER_PAGE): array
    {
        $kind = self::normalizeKind($kind);
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            'SELECT id, label, source_keys, created_at, created_by_username, expires_at, last_accessed_at, revoked_at, token_secret
             FROM catalog_share_links
             WHERE kind = :kind
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':kind', $kind);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll() ?: [];

        return $this->mapHistoryRows($rows, $kind);
    }

    /**
     * @return array{
     *   id: int,
     *   label: string,
     *   source_keys: list<string>,
     *   created_at: string,
     *   created_by_username: string,
     *   expires_at: ?string,
     *   last_accessed_at: ?string,
     *   is_active: bool,
     *   url: string,
     *   can_copy: bool
     * }|null
     */
    public function findActive(string $kind = self::KIND_CATALOG): ?array
    {
        $kind = self::normalizeKind($kind);
        $statement = $this->pdo->prepare(
            "SELECT id, label, source_keys, created_at, created_by_username, expires_at, last_accessed_at, revoked_at, token_secret
             FROM catalog_share_links
             WHERE kind = :kind
               AND (revoked_at IS NULL OR revoked_at = '')
               AND (
                    expires_at IS NULL
                    OR expires_at = ''
                    OR expires_at >= datetime('now')
               )
             ORDER BY id DESC
             LIMIT 1"
        );
        $statement->execute([':kind' => $kind]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $mapped = $this->mapHistoryRows([$row], $kind);

        return $mapped[0] ?? null;
    }

    /**
     * @return list<array{
     *   id: int,
     *   label: string,
     *   source_keys: list<string>,
     *   created_at: string,
     *   created_by_username: string,
     *   expires_at: ?string,
     *   last_accessed_at: ?string,
     *   is_active: bool,
     *   url: string,
     *   can_copy: bool
     * }>
     */
    public function listRecent(int $limit = 20, string $kind = self::KIND_CATALOG): array
    {
        return $this->listPage($kind, 1, $limit);
    }

    /**
     * Filter registered sources down to those allowed by a share.
     *
     * @param list<array<string, mixed>> $allSources
     * @param list<string> $allowedKeys Empty means all catalogs.
     * @return list<array<string, mixed>>
     */
    public function filterSources(array $allSources, array $allowedKeys): array
    {
        if ($allowedKeys === []) {
            return array_values($allSources);
        }

        $wanted = array_fill_keys($allowedKeys, true);
        $out = [];
        foreach ($allSources as $src) {
            $key = (string) ($src['source_key'] ?? '');
            if ($key !== '' && isset($wanted[$key])) {
                $out[] = $src;
            }
        }

        return $out;
    }

    public static function absoluteUrl(string $token, string $kind = self::KIND_CATALOG): string
    {
        return AppUrl::absolute(self::publicFile($kind) . '?t=' . rawurlencode($token));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{
     *   id: int,
     *   label: string,
     *   source_keys: list<string>,
     *   created_at: string,
     *   created_by_username: string,
     *   expires_at: ?string,
     *   last_accessed_at: ?string,
     *   is_active: bool,
     *   url: string,
     *   can_copy: bool
     * }>
     */
    private function mapHistoryRows(array $rows, string $kind = self::KIND_CATALOG): array
    {
        $kind = self::normalizeKind($kind);
        $now = time();
        $links = [];
        foreach ($rows as $row) {
            $revoked = ($row['revoked_at'] ?? null) !== null && (string) $row['revoked_at'] !== '';
            $expiresAt = $row['expires_at'] ?? null;
            $expired = false;
            if ($expiresAt !== null && (string) $expiresAt !== '') {
                $expiresTs = strtotime((string) $expiresAt);
                $expired = $expiresTs !== false && $expiresTs < $now;
            }
            $isActive = !$revoked && !$expired;
            $url = '';
            $canCopy = false;
            if ($isActive) {
                $token = $this->decryptToken((string) ($row['token_secret'] ?? ''));
                if ($token !== null) {
                    $url = self::absoluteUrl($token, $kind);
                    $canCopy = true;
                }
            }
            $links[] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => self::normalizeLabel((string) ($row['label'] ?? '')),
                'source_keys' => $this->decodeSourceKeys((string) ($row['source_keys'] ?? '')),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'created_by_username' => (string) ($row['created_by_username'] ?? ''),
                'expires_at' => $expiresAt !== null && (string) $expiresAt !== '' ? (string) $expiresAt : null,
                'last_accessed_at' => ($row['last_accessed_at'] ?? null) !== null && (string) $row['last_accessed_at'] !== ''
                    ? (string) $row['last_accessed_at']
                    : null,
                'is_active' => $isActive,
                'url' => $url,
                'can_copy' => $canCopy,
            ];
        }

        return $links;
    }

    private function decryptToken(string $tokenSecret): ?string
    {
        $tokenSecret = trim($tokenSecret);
        if ($tokenSecret === '') {
            return null;
        }

        try {
            $token = trim($this->crypto->decrypt($tokenSecret));
        } catch (\Throwable) {
            return null;
        }

        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return $token;
    }

    /**
     * @param list<mixed> $sourceKeys
     * @return list<string>
     */
    private function normalizeSourceKeys(array $sourceKeys): array
    {
        $out = [];
        foreach ($sourceKeys as $key) {
            $value = trim((string) $key);
            if ($value === '' || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/', $value)) {
                continue;
            }
            $out[$value] = $value;
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private function decodeSourceKeys(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeSourceKeys($decoded);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
