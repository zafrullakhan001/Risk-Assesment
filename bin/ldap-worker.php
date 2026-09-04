<?php

declare(strict_types=1);

/**
 * CLI LDAP worker for environments where Apache's php_ldap cannot create a session
 * handle ("Local error") but CLI PHP can. Invoked only by LdapAuth via stdin JSON.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require dirname(__DIR__) . '/public/bootstrap.php';

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    fwrite(STDERR, "Missing JSON payload on stdin.\n");
    exit(2);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid JSON payload.\n");
    exit(2);
}

$action = (string) ($payload['action'] ?? '');
$ldap = $auth->ldap();

try {
    if ($action === 'test') {
        $server = $payload['server'] ?? null;
        if (!is_array($server)) {
            throw new RuntimeException('server object is required.');
        }
        $result = $ldap->testConnection($server);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit($result['success'] ? 0 : 3);
    }

    if ($action === 'authenticate') {
        $server = $payload['server'] ?? null;
        $username = (string) ($payload['username'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        if (!is_array($server) || $username === '' || $password === '') {
            throw new RuntimeException('server, username, and password are required.');
        }
        $profile = $ldap->authenticateAgainstServer($server, $username, $password);
        echo json_encode(['success' => true, 'profile' => $profile], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $exception) {
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(3);
}
