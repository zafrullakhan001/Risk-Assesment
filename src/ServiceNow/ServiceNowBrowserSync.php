<?php

declare(strict_types=1);

namespace RiskAssessment\ServiceNow;

use PDO;
use RuntimeException;

/**
 * Short-lived token so a signed-in ServiceNow browser tab can POST a task packet.
 */
final class ServiceNowBrowserSync
{
    public const TOKEN_TTL_SECONDS = 1800;
    public const MAX_RELATED_TICKETS = 250;
    public const MAX_ATTACHMENTS = 100;
    public const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;
    public const PACKET_FORMAT = 'architecture-risk.servicenow-task-packet.v1';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Ensure the token table exists (Ticket Dossier SQLite).
     */
    public function ensureSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS servicenow_browser_sync (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                instance_origin TEXT NOT NULL,
                task_number TEXT NOT NULL,
                owner_user_id INTEGER,
                owner_username TEXT NOT NULL DEFAULT \'\',
                owner_display_name TEXT NOT NULL DEFAULT \'\',
                owner_auth_source TEXT NOT NULL DEFAULT \'\',
                project_id INTEGER,
                expires_at INTEGER NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_sn_browser_sync_expires
             ON servicenow_browser_sync(expires_at)'
        );
    }

    /**
     * @param array{
     *   owner_user_id?: int|null,
     *   owner_username?: string,
     *   owner_display_name?: string,
     *   owner_auth_source?: string
     * } $owner
     * @return array{
     *   token: string,
     *   expires_at: int,
     *   instance_origin: string,
     *   task_number: string,
     *   import_url: string,
     *   attachment_url: string,
     *   complete_url: string,
     *   max_related: int,
     *   max_attachments: int,
     *   max_attachment_bytes: int
     * }
     */
    public function prepare(
        string $instanceOrigin,
        string $taskNumber,
        string $importUrl,
        string $attachmentUrl,
        string $completeUrl,
        array $owner = []
    ): array {
        $this->ensureSchema();
        $this->purgeExpired();

        $origin = self::normalizeInstanceOrigin($instanceOrigin);
        $task = self::normalizeTaskNumber($taskNumber);

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = time() + self::TOKEN_TTL_SECONDS;
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO servicenow_browser_sync (
                token_hash, instance_origin, task_number,
                owner_user_id, owner_username, owner_display_name, owner_auth_source,
                project_id, expires_at, created_at
            ) VALUES (
                :hash, :origin, :task,
                :owner_user_id, :owner_username, :owner_display_name, :owner_auth_source,
                NULL, :expires, :created
            )'
        );

        $ownerUserId = (int) ($owner['owner_user_id'] ?? 0);
        $stmt->execute([
            ':hash' => $hash,
            ':origin' => $origin,
            ':task' => $task,
            ':owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
            ':owner_username' => (string) ($owner['owner_username'] ?? ''),
            ':owner_display_name' => (string) ($owner['owner_display_name'] ?? ''),
            ':owner_auth_source' => (string) ($owner['owner_auth_source'] ?? ''),
            ':expires' => $expiresAt,
            ':created' => $now,
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'instance_origin' => $origin,
            'task_number' => $task,
            'import_url' => $importUrl,
            'attachment_url' => $attachmentUrl,
            'complete_url' => $completeUrl,
            'max_related' => self::MAX_RELATED_TICKETS,
            'max_attachments' => self::MAX_ATTACHMENTS,
            'max_attachment_bytes' => self::MAX_ATTACHMENT_BYTES,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assertValidToken(string $token): array
    {
        $this->ensureSchema();
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            throw new RuntimeException('Missing or invalid sync token.');
        }

        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM servicenow_browser_sync WHERE token_hash = :hash LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Sync token is invalid or already used.');
        }

        $expires = (int) ($row['expires_at'] ?? 0);
        if ($expires < time()) {
            $this->deleteByHash($hash);
            throw new RuntimeException('Sync token has expired. Prepare again from Ticket Dossier.');
        }

        return $row;
    }

    public function bindProject(string $token, int $projectId): void
    {
        $row = $this->assertValidToken($token);
        $hash = (string) ($row['token_hash'] ?? '');
        if ($hash === '' || $projectId <= 0) {
            throw new RuntimeException('Cannot bind project to sync token.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE servicenow_browser_sync SET project_id = :pid WHERE token_hash = :hash'
        );
        $stmt->execute([
            ':pid' => $projectId,
            ':hash' => $hash,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function assertTokenForProject(string $token, int $projectId): array
    {
        $row = $this->assertValidToken($token);
        $bound = (int) ($row['project_id'] ?? 0);
        if ($bound !== $projectId || $projectId <= 0) {
            throw new RuntimeException('Sync token is not bound to this project.');
        }

        return $row;
    }

    public function clearToken(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $this->deleteByHash(hash('sha256', $token));
    }

    public function purgeExpired(): void
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'DELETE FROM servicenow_browser_sync WHERE expires_at < :now'
        );
        $stmt->execute([':now' => time()]);
    }

    public function applyCorsHeaders(string $origin, string $allowedOrigin): void
    {
        $origin = trim($origin);
        $allowed = rtrim(strtolower(trim($allowedOrigin)), '/');
        $incoming = rtrim(strtolower($origin), '/');
        if ($origin !== '' && $incoming === $allowed) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Sync-Token, X-Project-Id');
            header('Access-Control-Max-Age: 600');
            header('Vary: Origin');
        }
    }

    public static function normalizeInstanceOrigin(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('ServiceNow instance URL is required.');
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Invalid ServiceNow instance URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new RuntimeException('ServiceNow instance URL must be http(s).');
        }

        // Prefer HTTPS for production instances.
        if ($scheme === 'http' && !in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1'], true)) {
            $scheme = 'https';
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * Normalize a ServiceNow ticket number (TASK, DMND, STRY, DDR, PRJ, etc.).
     * Kept as normalizeTaskNumber for backward compatibility with callers/tests.
     */
    public static function normalizeTaskNumber(string $taskNumber): string
    {
        return self::normalizeTicketNumber($taskNumber);
    }

    public static function normalizeTicketNumber(string $ticketNumber): string
    {
        $ticket = strtoupper(trim($ticketNumber));
        if (!preg_match('/^[A-Z]+\d+$/', $ticket)) {
            throw new RuntimeException(
                'Ticket number must look like TASK0123456, DMND…, STRY…, DDR…, or PRJ….'
            );
        }

        return $ticket;
    }

    /**
     * ServiceNow form table for opening a ticket by number prefix.
     */
    public static function tableForTicketNumber(string $ticketNumber): string
    {
        $ticket = strtoupper(trim($ticketNumber));
        if (str_starts_with($ticket, 'DMND')) {
            return 'dmn_demand';
        }
        if (str_starts_with($ticket, 'STRY')) {
            return 'rm_story';
        }
        if (str_starts_with($ticket, 'DDR')) {
            return 'sn_tprm_dd_request';
        }
        if (str_starts_with($ticket, 'PRJ')) {
            return 'pm_project';
        }

        return 'task';
    }

    public static function sanitizeStoredFilename(string $name): string
    {
        $name = str_replace("\0", '', $name);
        $name = str_replace(['\\', '/'], '/', $name);
        $name = basename($name);
        $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $name) ?? $name;
        $name = trim($name, '._ ');

        return $name !== '' ? $name : 'attachment.bin';
    }

    private function deleteByHash(string $hash): void
    {
        if ($hash === '') {
            return;
        }
        $stmt = $this->pdo->prepare(
            'DELETE FROM servicenow_browser_sync WHERE token_hash = :hash'
        );
        $stmt->execute([':hash' => $hash]);
    }
}
