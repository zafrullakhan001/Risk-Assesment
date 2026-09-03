<?php

declare(strict_types=1);

namespace RiskAssessment\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function get(string $key, string $default = ''): string
    {
        $statement = $this->pdo->prepare('SELECT value FROM app_settings WHERE key = :key LIMIT 1');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (key, value, updated_at)
             VALUES (:key, :value, datetime(\'now\'))
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }

    public function delete(string $key): void
    {
        $statement = $this->pdo->prepare('DELETE FROM app_settings WHERE key = :key');
        $statement->execute([':key' => $key]);
    }
}
