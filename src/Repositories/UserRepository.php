<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;
use RuntimeException;

final class UserRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, email, password_hash, is_admin, is_approved, is_disabled,
                    auth_source, display_name, notes, last_login, created_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByUsernameOrEmail(string $identifier): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, email, password_hash, is_admin, is_approved, is_disabled,
                    auth_source, display_name, notes, last_login, created_at
             FROM users
             WHERE LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email)
             LIMIT 1'
        );
        $statement->execute([
            ':username' => $identifier,
            ':email' => $identifier,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, username, email, password_hash, is_admin, is_approved, is_disabled,
                    auth_source, display_name, notes, last_login, created_at
             FROM users
             ORDER BY is_admin DESC, username COLLATE NOCASE ASC'
        );
        if ($statement === false) {
            return [];
        }

        $users = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $users[] = $this->normalize($row);
            }
        }

        return $users;
    }

    public function count(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM users');

        return $value === false ? 0 : (int) $value->fetchColumn();
    }

    public function countAdmins(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_disabled = 0');

        return $value === false ? 0 : (int) $value->fetchColumn();
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE LOWER(username) = LOWER(:username)';
        $params = [':username' => $username];
        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE LOWER(email) = LOWER(:email)';
        $params = [':email' => $email];
        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function createLocal(
        string $username,
        string $email,
        string $passwordHash,
        bool $isAdmin,
        bool $isApproved,
        string $displayName = '',
        string $notes = ''
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash, is_admin, is_approved, is_disabled,
                                auth_source, display_name, notes, created_at)
             VALUES (:username, :email, :password_hash, :is_admin, :is_approved, 0,
                     \'local\', :display_name, :notes, datetime(\'now\'))'
        );
        $statement->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':is_admin' => $isAdmin ? 1 : 0,
            ':is_approved' => $isApproved ? 1 : 0,
            ':display_name' => $displayName,
            ':notes' => $notes,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{username: string, email: string, display_name?: string} $ldapUser
     * @return array<string, mixed>
     */
    public function upsertLdapUser(array $ldapUser, bool $autoCreate, bool $autoUpdate, bool $autoApprove): array
    {
        $existing = $this->findByUsernameOrEmail($ldapUser['username']);
        if ($existing === null && $ldapUser['email'] !== '') {
            $existing = $this->findByUsernameOrEmail($ldapUser['email']);
        }

        if ($existing !== null) {
            if ($autoUpdate) {
                $statement = $this->pdo->prepare(
                    'UPDATE users
                     SET email = :email, display_name = :display_name
                     WHERE id = :id'
                );
                $email = $this->uniqueEmail((string) $ldapUser['email'], (int) $existing['id']);
                $statement->execute([
                    ':email' => $email,
                    ':display_name' => (string) ($ldapUser['display_name'] ?? $existing['display_name']),
                    ':id' => $existing['id'],
                ]);
                $existing = $this->findById((int) $existing['id']);
            }

            if (!is_array($existing)) {
                throw new RuntimeException('Unable to refresh the LDAP user record.');
            }

            return $existing;
        }

        if (!$autoCreate) {
            throw new RuntimeException('LDAP user is not provisioned. Ask an administrator to import the account.');
        }

        $placeholder = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        if ($placeholder === false) {
            throw new RuntimeException('Unable to provision the LDAP user.');
        }

        $email = $this->uniqueEmail((string) $ldapUser['email']);
        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash, is_admin, is_approved, is_disabled,
                                auth_source, display_name, notes, created_at)
             VALUES (:username, :email, :password_hash, 0, :is_approved, 0,
                     \'ldap\', :display_name, \'\', datetime(\'now\'))'
        );
        $statement->execute([
            ':username' => $ldapUser['username'],
            ':email' => $email,
            ':password_hash' => $placeholder,
            ':is_approved' => $autoApprove ? 1 : 0,
            ':display_name' => (string) ($ldapUser['display_name'] ?? ''),
        ]);

        $created = $this->findById((int) $this->pdo->lastInsertId());
        if ($created === null) {
            throw new RuntimeException('Unable to load the provisioned LDAP user.');
        }

        return $created;
    }

    public function markLogin(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET last_login = datetime(\'now\') WHERE id = :id'
        );
        $statement->execute([':id' => $id]);
    }

    public function setApproved(int $id, bool $approved): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET is_approved = :value WHERE id = :id');
        $statement->execute([':value' => $approved ? 1 : 0, ':id' => $id]);
    }

    public function setDisabled(int $id, bool $disabled): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET is_disabled = :value WHERE id = :id');
        $statement->execute([':value' => $disabled ? 1 : 0, ':id' => $id]);
    }

    public function setAdmin(int $id, bool $isAdmin): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET is_admin = :value WHERE id = :id');
        $statement->execute([':value' => $isAdmin ? 1 : 0, ':id' => $id]);
    }

    public function setPassword(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $statement->execute([':hash' => $passwordHash, ':id' => $id]);
    }

    public function updateProfile(int $id, string $email, string $displayName, string $notes): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET email = :email, display_name = :display_name, notes = :notes WHERE id = :id'
        );
        $statement->execute([
            ':email' => $email,
            ':display_name' => $displayName,
            ':notes' => $notes,
            ':id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute([':id' => $id]);
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public function logAudit(
        string $event,
        ?int $actorId,
        ?string $actorUsername,
        ?int $targetId = null,
        ?string $targetUsername = null,
        ?array $details = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_audit_log (event, actor_id, actor_username, target_user_id, target_username, details, ip_address, created_at)
             VALUES (:event, :actor_id, :actor_username, :target_id, :target_username, :details, :ip, datetime(\'now\'))'
        );
        $statement->execute([
            ':event' => $event,
            ':actor_id' => $actorId,
            ':actor_username' => $actorUsername,
            ':target_id' => $targetId,
            ':target_username' => $targetUsername,
            ':details' => $details === null ? '' : (string) json_encode($details, JSON_UNESCAPED_UNICODE),
            ':ip' => $this->clientIp(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function recentAudit(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->pdo->query(
            'SELECT id, event, actor_id, actor_username, target_user_id, target_username, details, ip_address, created_at
             FROM user_audit_log
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        if ($statement === false) {
            return [];
        }

        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function uniqueEmail(string $email, ?int $exceptId = null): string
    {
        $email = trim($email);
        if ($email === '') {
            $email = 'user-' . bin2hex(random_bytes(4)) . '@ldap.local';
        }
        if (!$this->emailExists($email, $exceptId)) {
            return $email;
        }

        $at = strrpos($email, '@');
        $local = $at === false ? $email : substr($email, 0, $at);
        $domain = $at === false ? 'ldap.local' : substr($email, $at + 1);
        $candidate = $local . '+' . substr(bin2hex(random_bytes(3)), 0, 6) . '@' . $domain;

        return $this->emailExists($candidate, $exceptId) ? $this->uniqueEmail($candidate, $exceptId) : $candidate;
    }

    /** @param array<string, mixed> $row */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_admin'] = !empty($row['is_admin']);
        $row['is_approved'] = !empty($row['is_approved']);
        $row['is_disabled'] = !empty($row['is_disabled']);
        $row['username'] = (string) $row['username'];
        $row['email'] = (string) $row['email'];
        $row['password_hash'] = (string) ($row['password_hash'] ?? '');
        $row['auth_source'] = (string) ($row['auth_source'] ?? 'local');
        $row['display_name'] = (string) ($row['display_name'] ?? '');
        $row['notes'] = (string) ($row['notes'] ?? '');

        return $row;
    }

    private function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}
