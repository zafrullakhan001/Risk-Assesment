<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\SettingsRepository;
use RuntimeException;
use Throwable;

final class LdapAuth
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Crypto $crypto,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('ldap_enabled', '0') === '1';
    }

    /** @return list<array<string, mixed>> */
    public function servers(): array
    {
        $raw = $this->settings->get('ldap_servers', '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $servers = [];
        foreach ($decoded as $server) {
            if (is_array($server)) {
                $servers[] = array_merge(self::defaultServer(), $server);
            }
        }

        return $servers;
    }

    /** @return array<string, mixed> */
    public static function defaultServer(): array
    {
        return [
            'name' => 'Primary',
            'server' => '',
            'port' => 389,
            'protocol' => 'ldap',
            'tls' => '0',
            'timeout' => 30,
            'bind_dn' => '',
            'bind_password' => '',
            'user_search_base' => '',
            'user_filter' => '(sAMAccountName={username})',
            'user_attributes' => 'sAMAccountName,mail,displayName,memberOf',
            'email_attribute' => 'mail',
            'display_name_attribute' => 'displayName',
            'login_domain' => '',
            'user_dn_template' => '',
            'search_scope' => 'sub',
            'require_group_membership' => '0',
            'required_groups' => '',
            'denied_groups' => '',
            'ssl_verify' => '0',
            'referrals' => '0',
        ];
    }

    /**
     * @param list<array<string, mixed>> $servers
     */
    public function saveServers(array $servers): void
    {
        $clean = [];
        foreach ($servers as $index => $server) {
            if (!is_array($server)) {
                continue;
            }
            $merged = array_merge(self::defaultServer(), $server);
            $merged['port'] = max(1, min(65535, (int) $merged['port']));
            $merged['timeout'] = max(1, min(60, (int) $merged['timeout']));
            $merged['protocol'] = $merged['protocol'] === 'ldaps' ? 'ldaps' : 'ldap';
            $merged['search_scope'] = in_array($merged['search_scope'], ['base', 'one', 'sub'], true)
                ? $merged['search_scope']
                : 'sub';
            $password = (string) ($merged['bind_password'] ?? '');
            if ($this->crypto->isMaskedPlaceholder($password) || $password === '') {
                $existing = $this->servers()[$index]['bind_password'] ?? '';
                $merged['bind_password'] = is_string($existing) ? $existing : '';
            } else {
                $merged['bind_password'] = $this->crypto->encrypt($password);
            }
            $clean[] = $merged;
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to store LDAP servers.');
        }
        $this->settings->set('ldap_servers', $json);
    }

    /**
     * Build a portable LDAP settings payload for JSON export.
     *
     * @return array{
     *   version: int,
     *   exported_at: string,
     *   ldap_enabled: string,
     *   ldap_auto_create_users: string,
     *   ldap_auto_update_users: string,
     *   ldap_auto_approve: string,
     *   servers: list<array<string, mixed>>,
     *   secrets_included: bool
     * }
     */
    public function exportSettings(bool $includeSecrets = true): array
    {
        $servers = [];
        foreach ($this->servers() as $server) {
            $row = $server;
            $stored = (string) ($row['bind_password'] ?? '');
            if ($includeSecrets) {
                $row['bind_password'] = $this->decryptSecret($stored);
            } else {
                $row['bind_password'] = '';
                $row['bind_password_set'] = $stored !== '';
            }
            $servers[] = $row;
        }

        return [
            'version' => 1,
            'exported_at' => gmdate('c'),
            'ldap_enabled' => $this->settings->get('ldap_enabled', '0'),
            'ldap_auto_create_users' => $this->settings->get('ldap_auto_create_users', '1'),
            'ldap_auto_update_users' => $this->settings->get('ldap_auto_update_users', '1'),
            'ldap_auto_approve' => $this->settings->get('ldap_auto_approve', '1'),
            'servers' => $servers,
            'secrets_included' => $includeSecrets,
        ];
    }

    /**
     * Apply LDAP settings from an exported JSON payload.
     *
     * @param array<string, mixed> $payload
     */
    public function importSettings(array $payload): void
    {
        $version = (int) ($payload['version'] ?? 1);
        if ($version < 1 || $version > 1) {
            throw new RuntimeException('Unsupported LDAP settings export version.');
        }

        $serversRaw = $payload['servers'] ?? null;
        if (!is_array($serversRaw)) {
            throw new RuntimeException('LDAP import file must include a servers array.');
        }

        $servers = [];
        foreach ($serversRaw as $server) {
            if (!is_array($server)) {
                continue;
            }
            unset($server['bind_password_set']);
            $merged = array_merge(self::defaultServer(), $server);
            if (trim((string) ($merged['server'] ?? '')) === '') {
                throw new RuntimeException('Each LDAP server in the import file needs a host.');
            }
            $servers[] = $merged;
        }

        if ($servers === []) {
            throw new RuntimeException('LDAP import file does not contain any servers.');
        }

        $ldapEnabled = $this->normalizeFlag($payload['ldap_enabled'] ?? $this->settings->get('ldap_enabled', '0'));
        $localEnabled = $this->settings->get('local_auth_enabled', '1') === '1';
        if ($ldapEnabled !== '1' && !$localEnabled) {
            throw new RuntimeException('Import would disable LDAP while local sign-in is off. Enable local auth first, or keep LDAP enabled in the file.');
        }

        $this->saveServers($servers);
        $this->settings->set('ldap_enabled', $ldapEnabled);
        $this->settings->set(
            'ldap_auto_create_users',
            $this->normalizeFlag($payload['ldap_auto_create_users'] ?? $this->settings->get('ldap_auto_create_users', '1'))
        );
        $this->settings->set(
            'ldap_auto_update_users',
            $this->normalizeFlag($payload['ldap_auto_update_users'] ?? $this->settings->get('ldap_auto_update_users', '1'))
        );
        $this->settings->set(
            'ldap_auto_approve',
            $this->normalizeFlag($payload['ldap_auto_approve'] ?? $this->settings->get('ldap_auto_approve', '1'))
        );
    }

    private function normalizeFlag(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $raw = strtolower(trim((string) $value));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    /**
     * @return array{username: string, email: string, display_name: string, dn: string, groups: list<string>}
     */
    public function authenticate(string $username, string $password, int $serverIndex = 0, bool $fastFail = false): array
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('PHP LDAP extension is not loaded. Enable it in php.ini.');
        }
        if ($username === '' || $password === '') {
            throw new RuntimeException('Username and password are required.');
        }

        $servers = $this->servers();
        if ($servers === []) {
            throw new RuntimeException('No LDAP server is configured.');
        }

        if (!isset($servers[$serverIndex])) {
            $serverIndex = 0;
        }

        $server = $servers[$serverIndex];
        if ($fastFail) {
            $server['timeout'] = min(5, (int) ($server['timeout'] ?: 5));
        }

        return $this->authenticateAgainst($server, $username, $password);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $server): array
    {
        if (!extension_loaded('ldap')) {
            return [
                'success' => false,
                'message' => 'PHP LDAP extension is not loaded. Enable extension=ldap in php.ini and restart Apache.',
            ];
        }

        $server = array_merge(self::defaultServer(), $server);
        $password = (string) ($server['bind_password'] ?? '');
        if ($this->crypto->isMaskedPlaceholder($password) || $password === '') {
            $stored = $this->servers()[0]['bind_password'] ?? '';
            $password = is_string($stored) ? $this->decryptSecret($stored) : '';
        } elseif ($this->crypto->isEncrypted($password)) {
            $password = $this->decryptSecret($password);
        }
        $server['bind_password'] = $password;

        if (!$this->nativeLdapUsable()) {
            return $this->runCliWorker('test', ['server' => $server]);
        }

        $connection = null;
        try {
            $connection = $this->connect($server);
            $bindDn = trim((string) ($server['bind_dn'] ?? ''));
            if (!@ldap_bind($connection, $bindDn !== '' ? $bindDn : null, $bindDn !== '' ? $password : null)) {
                $message = $this->explainBindFailure($connection, 'Service bind failed');
                $protocol = ($server['protocol'] ?? 'ldap') === 'ldaps' ? 'ldaps' : 'ldap';
                $port = (int) ($server['port'] ?? 389);
                if (stripos($message, "Can't contact LDAP server") !== false) {
                    if ($protocol === 'ldaps') {
                        $message .= ' Tip: ldaps:// needs SSL on the directory (usually port 636). For plain AD LDAP use protocol ldap:// and port 389.';
                    } elseif ($port === 636) {
                        $message .= ' Tip: port 636 is for ldaps://. For plain LDAP use port 389 with protocol ldap://.';
                    }
                }

                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

            $base = trim((string) ($server['user_search_base'] ?? ''));
            $detail = 'Connected and bound successfully.';
            if ($base !== '') {
                $result = @ldap_read($connection, $base, '(objectClass=*)', ['dn']);
                if ($result === false) {
                    $detail .= ' Search base could not be read: ' . ldap_error($connection);
                } else {
                    $detail .= ' Search base is reachable.';
                }
            }

            return ['success' => true, 'message' => $detail];
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } finally {
            if (is_resource($connection) || $connection instanceof \LDAP\Connection) {
                @ldap_unbind($connection);
            }
        }
    }

    /**
     * @param array<string, mixed> $server
     * @return array{username: string, email: string, display_name: string, dn: string, groups: list<string>}
     */
    public function authenticateAgainstServer(array $server, string $username, string $password): array
    {
        return $this->authenticateAgainst(array_merge(self::defaultServer(), $server), $username, $password);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{username: string, email: string, display_name: string, dn: string, groups: list<string>}
     */
    private function authenticateAgainst(array $server, string $username, string $password): array
    {
        $host = trim((string) ($server['server'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('LDAP server host is not configured.');
        }

        if (!$this->nativeLdapUsable()) {
            $result = $this->runCliWorker('authenticate', [
                'server' => $server,
                'username' => $username,
                'password' => $password,
            ]);
            if (empty($result['success']) || !is_array($result['profile'] ?? null)) {
                throw new RuntimeException((string) ($result['message'] ?? 'LDAP authentication failed.'));
            }

            /** @var array{username: string, email: string, display_name: string, dn: string, groups: list<string>} $profile */
            $profile = $result['profile'];

            return $profile;
        }

        $connection = $this->connect($server);
        try {
            $bindDn = trim((string) ($server['bind_dn'] ?? ''));
            $bindPassword = $this->decryptSecret((string) ($server['bind_password'] ?? ''));
            if (!@ldap_bind($connection, $bindDn !== '' ? $bindDn : null, $bindDn !== '' ? $bindPassword : null)) {
                throw new RuntimeException($this->explainBindFailure($connection, 'LDAP service bind failed'));
            }

            $template = trim((string) ($server['user_dn_template'] ?? ''));
            $userEntry = null;
            $userDn = '';

            if ($template !== '') {
                $userDn = str_replace('{username}', $this->escapeDn($username), $template);
                if (!@ldap_bind($connection, $userDn, $password)) {
                    throw new RuntimeException('LDAP user bind failed.');
                }
                $attributes = $this->attributeList($server);
                $result = @ldap_read($connection, $userDn, '(objectClass=*)', $attributes);
                if ($result !== false) {
                    $entries = @ldap_get_entries($connection, $result);
                    if (is_array($entries) && (int) ($entries['count'] ?? 0) > 0) {
                        $userEntry = $entries[0];
                    }
                }
            } else {
                $searchBase = trim((string) ($server['user_search_base'] ?? ''));
                if ($searchBase === '') {
                    throw new RuntimeException('LDAP user search base is not configured.');
                }

                $filterTemplate = trim((string) ($server['user_filter'] ?? '(sAMAccountName={username})'));
                $filter = str_replace('{username}', $this->escapeFilter($username), $filterTemplate);
                $attributes = $this->attributeList($server);
                $scope = strtolower((string) ($server['search_scope'] ?? 'sub'));
                $result = match ($scope) {
                    'base' => @ldap_read($connection, $searchBase, $filter, $attributes),
                    'one' => @ldap_list($connection, $searchBase, $filter, $attributes),
                    default => @ldap_search($connection, $searchBase, $filter, $attributes),
                };
                if ($result === false) {
                    throw new RuntimeException('LDAP search failed: ' . ldap_error($connection));
                }

                $entries = @ldap_get_entries($connection, $result);
                if (!is_array($entries) || (int) ($entries['count'] ?? 0) < 1) {
                    throw new RuntimeException('User not found in the directory.');
                }

                $userEntry = $entries[0];
                $userDn = (string) ($userEntry['dn'] ?? '');
                if ($userDn === '' || !@ldap_bind($connection, $userDn, $password)) {
                    throw new RuntimeException('Invalid LDAP username or password.');
                }
            }

            $profile = $this->extractProfile($server, $username, is_array($userEntry) ? $userEntry : [], $userDn);
            $this->enforceGroups($server, $profile['groups']);

            return $profile;
        } finally {
            if (is_resource($connection) || $connection instanceof \LDAP\Connection) {
                @ldap_unbind($connection);
            }
        }
    }

    /**
     * @param array<string, mixed> $server
     * @return \LDAP\Connection|resource
     */
    private function connect(array $server)
    {
        $host = trim((string) ($server['server'] ?? ''));
        $port = (int) ($server['port'] ?? 389);
        $protocol = ($server['protocol'] ?? 'ldap') === 'ldaps' ? 'ldaps' : 'ldap';
        $timeout = max(1, min(60, (int) ($server['timeout'] ?? 30)));
        $uri = $protocol . '://' . $host . ':' . $port;

        if (($server['ssl_verify'] ?? '0') !== '1') {
            @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        }

        $connection = @ldap_connect($uri);
        if ($connection === false) {
            $detail = error_get_last()['message'] ?? '';
            if (stripos($detail, 'Local error') !== false || stripos($detail, 'session handle') !== false) {
                throw new RuntimeException(
                    'Unable to connect to the LDAP server (Apache PHP LDAP session error). '
                    . 'The app will retry through CLI PHP automatically when available.'
                );
            }
            throw new RuntimeException('Unable to connect to the LDAP server.');
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($connection, LDAP_OPT_TIMELIMIT, $timeout);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, ($server['referrals'] ?? '0') === '1' ? 1 : 0);

        if (($server['tls'] ?? '0') === '1' && $protocol !== 'ldaps' && !@ldap_start_tls($connection)) {
            throw new RuntimeException('LDAP StartTLS failed: ' . ldap_error($connection));
        }

        return $connection;
    }

    /**
     * Apache + PHP 8.5 on Windows often fails ldap_connect with "Local error"
     * while the same php.exe CLI works. Detect that and fall back to CLI.
     */
    private function nativeLdapUsable(): bool
    {
        static $usable = null;
        if ($usable !== null) {
            return $usable;
        }
        if (!extension_loaded('ldap')) {
            return $usable = false;
        }
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            return $usable = true;
        }

        $previous = error_get_last();
        $connection = @ldap_connect('ldap://127.0.0.1:1');
        if ($connection !== false) {
            if (is_resource($connection) || $connection instanceof \LDAP\Connection) {
                @ldap_unbind($connection);
            }
            return $usable = true;
        }

        $last = error_get_last();
        if ($last !== null && $last !== $previous) {
            $message = (string) ($last['message'] ?? '');
            if (stripos($message, 'session handle') !== false || stripos($message, 'Local error') !== false) {
                return $usable = false;
            }
        }

        // Other connect failures (network, etc.) still mean the extension can create handles.
        return $usable = true;
    }

    private function cliPhpBinary(): string
    {
        $extensionDir = str_replace('\\', '/', (string) ini_get('extension_dir'));
        $candidates = [];
        if ($extensionDir !== '') {
            $candidates[] = dirname($extensionDir) . DIRECTORY_SEPARATOR . 'php.exe';
            $candidates[] = dirname($extensionDir) . DIRECTORY_SEPARATOR . 'php';
        }
        $candidates[] = 'C:\\xampp\\php\\php.exe';
        $candidates[] = 'php';

        foreach ($candidates as $candidate) {
            if ($candidate === 'php') {
                return $candidate;
            }
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to locate php.exe for the LDAP CLI worker.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function runCliWorker(string $action, array $payload): array
    {
        $worker = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ldap-worker.php';
        if (!is_file($worker)) {
            throw new RuntimeException('LDAP CLI worker is missing.');
        }

        $php = $this->cliPhpBinary();
        $command = escapeshellarg($php) . ' ' . escapeshellarg($worker);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the LDAP CLI worker.');
        }

        $json = json_encode(array_merge(['action' => $action], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            proc_close($process);
            throw new RuntimeException('Unable to encode LDAP worker payload.');
        }

        fwrite($pipes[0], $json);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $decoded = is_string($stdout) ? json_decode($stdout, true) : null;
        if (!is_array($decoded)) {
            $hint = trim((string) $stderr);
            throw new RuntimeException(
                'LDAP CLI worker returned an invalid response'
                . ($hint !== '' ? ': ' . $hint : '')
                . ($exitCode !== 0 ? " (exit {$exitCode})" : '')
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $entry
     * @return array{username: string, email: string, display_name: string, dn: string, groups: list<string>}
     */
    private function extractProfile(array $server, string $username, array $entry, string $userDn): array
    {
        $emailAttr = strtolower(trim((string) ($server['email_attribute'] ?? 'mail')));
        $nameAttr = strtolower(trim((string) ($server['display_name_attribute'] ?? 'displayname')));
        $email = $this->firstAttribute($entry, $emailAttr !== '' ? $emailAttr : 'mail');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = $this->firstAttribute($entry, 'mail');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $upn = $this->firstAttribute($entry, 'userprincipalname');
            $email = filter_var($upn, FILTER_VALIDATE_EMAIL) ? $upn : '';
        }
        if ($email === '') {
            $domain = trim((string) ($server['login_domain'] ?? ''));
            if ($domain === '') {
                $domain = $this->domainFromDn((string) ($server['user_search_base'] ?? ''))
                    ?: $this->domainFromHost((string) ($server['server'] ?? ''))
                    ?: 'ldap.local';
            }
            $email = $username . '@' . strtolower($domain);
        }

        $displayName = $this->firstAttribute($entry, $nameAttr !== '' ? $nameAttr : 'displayname');
        if ($displayName === '') {
            $displayName = $this->firstAttribute($entry, 'displayname')
                ?: $this->firstAttribute($entry, 'cn')
                ?: $username;
        }

        return [
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'dn' => $userDn,
            'groups' => $this->collectGroups($entry),
        ];
    }

    /**
     * @param array<string, mixed> $server
     * @param list<string> $groups
     */
    private function enforceGroups(array $server, array $groups): void
    {
        $denied = $this->parseGroupList((string) ($server['denied_groups'] ?? ''));
        if ($denied !== [] && $this->matchesAnyGroup($groups, $denied)) {
            throw new RuntimeException('Access denied: account is in a denied LDAP group.');
        }

        $required = $this->parseGroupList((string) ($server['required_groups'] ?? ''));
        if (($server['require_group_membership'] ?? '0') === '1' && $required !== [] && !$this->matchesAnyGroup($groups, $required)) {
            throw new RuntimeException('Access denied: account must belong to an allowed LDAP group.');
        }
    }

    /**
     * @param array<string, mixed> $server
     * @return list<string>
     */
    private function attributeList(array $server): array
    {
        $raw = (string) ($server['user_attributes'] ?? 'sAMAccountName,mail,displayName,memberOf');
        $attributes = array_values(array_filter(array_map('trim', explode(',', $raw))));
        foreach (['mail', 'displayName', 'memberOf', 'userPrincipalName', 'cn'] as $required) {
            if (!in_array($required, $attributes, true) && !in_array(strtolower($required), array_map('strtolower', $attributes), true)) {
                $attributes[] = $required;
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function collectGroups(array $entry): array
    {
        $groups = [];
        foreach (['memberof', 'memberOf'] as $key) {
            if (!isset($entry[$key]) || !is_array($entry[$key])) {
                continue;
            }
            foreach ($entry[$key] as $index => $value) {
                if ($index === 'count' || !is_string($value) || $value === '') {
                    continue;
                }
                $groups[] = $value;
            }
        }

        return array_values(array_unique($groups));
    }

    /** @return list<string> */
    private function parseGroupList(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $groups = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $groups[] = $part;
            }
        }

        return $groups;
    }

    /**
     * @param list<string> $userGroups
     * @param list<string> $needles
     */
    private function matchesAnyGroup(array $userGroups, array $needles): bool
    {
        $normalized = array_map(static fn (string $value): string => strtolower($value), $userGroups);
        foreach ($needles as $needle) {
            $needleLower = strtolower($needle);
            foreach ($normalized as $group) {
                if ($group === $needleLower || str_contains($group, $needleLower)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function firstAttribute(array $entry, string $name): string
    {
        $name = strtolower($name);
        foreach ($entry as $key => $value) {
            if (!is_string($key) || strtolower($key) !== $name || !is_array($value)) {
                continue;
            }
            $first = $value[0] ?? '';

            return is_string($first) ? $first : '';
        }

        return '';
    }

    private function domainFromDn(string $dn): string
    {
        if (preg_match_all('/DC=([^,]+)/i', $dn, $matches) !== false && $matches[1] !== []) {
            return strtolower(implode('.', $matches[1]));
        }

        return '';
    }

    private function domainFromHost(string $host): string
    {
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return strtolower(implode('.', array_slice($parts, 1)));
        }

        return '';
    }

    /**
     * @param \LDAP\Connection|resource $connection
     */
    private function explainBindFailure($connection, string $prefix): string
    {
        $error = ldap_error($connection);
        $diagnostic = '';
        if (defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
            @ldap_get_option($connection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);
        }
        $hint = '';
        if (is_string($diagnostic) && preg_match('/\bdata\s+([0-9a-fA-F]+)\b/', $diagnostic, $matches) === 1) {
            $hint = match (strtolower($matches[1])) {
                '525' => ' User not found (wrong bind DN).',
                '52e' => ' Invalid credentials.',
                '530' => ' Logon hours restriction.',
                '532' => ' Password expired.',
                '533' => ' Account disabled in Active Directory.',
                '701' => ' Account expired in Active Directory.',
                '773' => ' Password must be reset in Active Directory.',
                '775' => ' Account is locked out in Active Directory.',
                default => '',
            };
        }

        return $prefix . ': ' . $error . $hint;
    }

    private function decryptSecret(string $value): string
    {
        if ($value === '' || $this->crypto->isMaskedPlaceholder($value)) {
            return '';
        }
        try {
            return $this->crypto->decrypt($value);
        } catch (Throwable) {
            return $value;
        }
    }

    private function escapeFilter(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
        }

        return str_replace(
            ['\\', '*', '(', ')', "\x00"],
            ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
            $value
        );
    }

    private function escapeDn(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_DN);
        }

        return str_replace(
            ['\\', ',', '=', '+', '<', '>', ';', '"', '#'],
            ['\\\\', '\\,', '\\=', '\\+', '\\<', '\\>', '\\;', '\\"', '\\#'],
            $value
        );
    }
}
