<?php

declare(strict_types=1);

/**
 * SQLite configuration healthcheck for RiskRegister.
 *
 * Compares expected enterprise PRAGMAs vs what is currently set, and reports
 * environment facts (SQLite version, memory_limit, WAL sidecars) with fix hints.
 *
 * Usage:
 *   php bin/check_sqlite_health.php
 *   php bin/check_sqlite_health.php --json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use RiskAssessment\Database\Database;
use RiskAssessment\Database\SqliteHealthcheck;

$asJson = in_array('--json', $argv ?? [], true);

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$path = (string) ($dbConfig['path'] ?? '');

try {
    $pdo = Database::connection($dbConfig);
    $result = SqliteHealthcheck::run($pdo, $path, true);

    if ($asJson) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo SqliteHealthcheck::formatCli($result);
    }

    exit(!empty($result['critical_ok']) ? 0 : 2);
} catch (Throwable $e) {
    if ($asJson) {
        fwrite(STDOUT, json_encode([
            'success' => false,
            'critical_ok' => false,
            'error' => $e->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
