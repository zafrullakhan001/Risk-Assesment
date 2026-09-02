<?php

declare(strict_types=1);

namespace RiskAssessment\Database;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    /** @param array{driver?: string, path?: string} $config */
    public static function connection(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $path = (string) ($config['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('SQLite database path is not configured.');
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the database directory.');
        }

        self::$connection = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        self::$connection->exec('PRAGMA foreign_keys = ON');
        self::migrate(self::$connection);

        return self::$connection;
    }

    private static function migrate(PDO $pdo): void
    {
        $schemaPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sqlite.sql';
        if (!is_readable($schemaPath)) {
            throw new \RuntimeException('SQLite schema file is missing.');
        }

        $pdo->exec((string) file_get_contents($schemaPath));
    }
}
