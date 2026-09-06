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
                $message = $this->formatLdapFailure(
                    'Service bind failed.',
                    $connection,
                    array_merge(
                        ['Bind DN: ' . ($bindDn !== '' ? $bindDn : '(anonymous)')],
                        $this->connectionContext($server)
                    )
                );
                $protocol = ($server['protocol'] ?? 'ldap') === 'ldaps' ? 'ldaps' : 'ldap';
                $port = (int) ($server['port'] ?? 389);
                if (stripos($message, "Can't contact LDAP server") !== false) {
                    if ($protocol === 'ldaps') {
                        $message .= "\nTip: ldaps:// needs SSL on the directory (usually port 636). For plain AD LDAP use protocol ldap:// and port 389.";
                    } elseif ($port === 636) {
                        $message .= "\nTip: port 636 is for ldaps://. For plain LDAP use port 389 with protocol ldap://.";
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
                    $detail .= "\n" . $this->formatLdapFailure(
                        'Search base could not be read.',
                        $connection,
                        ['Search base: ' . $base]
                    );
                } else {
                    $detail .= ' Search base is reachable.';
                }
            }

            return ['success' => true, 'message' => $detail];
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $previous = $exception->getPrevious();
            if ($previous instanceof Throwable) {
                $prior = trim($previous->getMessage());
                if ($prior !== '' && !str_contains($message, $prior)) {
                    $message .= "\n" . $prior;
                }
            }

            return ['success' => false, 'message' => $message];
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
     * Look up a directory user with the service account (no user password).
     *
     * @return array{username: string, email: string, display_name: string, dn: string, groups: list<string>}
     */
    public function lookupUser(string $username, int $serverIndex = 0): array
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('PHP LDAP extension is not loaded. Enable it in php.ini.');
        }
        $username = trim($username);
        if ($username === '') {
            throw new RuntimeException('LDAP username is required.');
        }

        $server = $this->serverAt($serverIndex);

        return $this->lookupUserAgainst($server, $username);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{username: string, email: string, display_name: string, dn: string, groups: list<string>}
     */
    public function lookupUserAgainstServer(array $server, string $username): array
    {
        return $this->lookupUserAgainst(array_merge(self::defaultServer(), $server), trim($username));
    }

    /**
     * Full live directory profile for admin inspection (groups, lock status, all readable attrs).
     * Passwords and other secrets are never returned.
     *
     * @return array{
     *   profile: array{username: string, email: string, display_name: string, dn: string, groups: list<string>},
     *   status: array{
     *     enabled: bool,
     *     disabled: bool,
     *     locked: bool,
     *     password_expired: bool,
     *     must_change_password: bool,
     *     password_never_expires: bool,
     *     account_expired: bool,
     *     badges: list<array{label: string, tone: string}>,
     *     notes: list<string>
     *   },
     *   groups: list<array{cn: string, dn: string}>,
     *   fields: array<string, string>,
     *   timestamps: array<string, string>,
     *   attributes: array<string, string|list<string>>
     * }
     */
    public function lookupUserDetails(string $username, int $serverIndex = 0): array
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('PHP LDAP extension is not loaded. Enable it in php.ini.');
        }
        $username = trim($username);
        if ($username === '') {
            throw new RuntimeException('LDAP username is required.');
        }

        $server = $this->serverAt($serverIndex);

        return $this->lookupUserDetailsAgainst($server, $username);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{
     *   profile: array{username: string, email: string, display_name: string, dn: string, groups: list<string>},
     *   status: array{
     *     enabled: bool,
     *     disabled: bool,
     *     locked: bool,
     *     password_expired: bool,
     *     must_change_password: bool,
     *     password_never_expires: bool,
     *     account_expired: bool,
     *     badges: list<array{label: string, tone: string}>,
     *     notes: list<string>
     *   },
     *   groups: list<array{cn: string, dn: string}>,
     *   fields: array<string, string>,
     *   timestamps: array<string, string>,
     *   attributes: array<string, string|list<string>>
     * }
     */
    public function lookupUserDetailsAgainstServer(array $server, string $username): array
    {
        return $this->lookupUserDetailsAgainst(array_merge(self::defaultServer(), $server), trim($username));
    }

    /**
     * Search directory users by username, display name, email, or common name (substring match).
     *
     * @return list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>
     */
    public function searchUsers(string $query, int $limit = 25, int $serverIndex = 0): array
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('PHP LDAP extension is not loaded. Enable it in php.ini.');
        }
        $query = trim($query);
        if (strlen($query) < 2) {
            throw new RuntimeException('Enter at least 2 characters to search the directory.');
        }
        $limit = max(1, min(100, $limit));
        $server = $this->serverAt($serverIndex);

        return $this->searchUsersAgainst($server, $query, $limit);
    }

    /**
     * @param array<string, mixed> $server
     * @return list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>
     */
    public function searchUsersAgainstServer(array $server, string $query, int $limit = 25): array
    {
        return $this->searchUsersAgainst(
            array_merge(self::defaultServer(), $server),
            trim($query),
            max(1, min(100, $limit))
        );
    }

    /**
     * Resolve an LDAP group DN and return member user profiles (direct members; nested groups expanded once).
     *
     * @return array{dn: string, name: string, members: list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>}
     */
    public function lookupGroupMembers(string $groupDn, int $serverIndex = 0): array
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('PHP LDAP extension is not loaded. Enable it in php.ini.');
        }
        $groupDn = trim($groupDn);
        if ($groupDn === '') {
            throw new RuntimeException('LDAP group DN is required.');
        }

        $server = $this->serverAt($serverIndex);

        return $this->lookupGroupMembersAgainst($server, $groupDn);
    }

    /**
     * @param array<string, mixed> $server
     * @return array{dn: string, name: string, members: list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>}
     */
    public function lookupGroupMembersAgainstServer(array $server, string $groupDn): array
    {
        return $this->lookupGroupMembersAgainst(array_merge(self::defaultServer(), $server), trim($groupDn));
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
            $this->serviceBind($connection, $server);

            $template = trim((string) ($server['user_dn_template'] ?? ''));
            $userEntry = null;
            $userDn = '';

            if ($template !== '') {
                $userDn = str_replace('{username}', $this->escapeDn($username), $template);
                if (!@ldap_bind($connection, $userDn, $password)) {
                    throw new RuntimeException($this->formatLdapFailure(
                        'LDAP user bind failed.',
                        $connection,
                        array_merge(
                            [
                                'Username: ' . $username,
                                'Bind DN: ' . $userDn,
                                'User DN template: ' . $template,
                            ],
                            $this->connectionContext($server)
                        )
                    ));
                }
                // Re-bind as service account to read attributes if needed
                $this->serviceBind($connection, $server);
                $found = $this->readEntry($connection, $server, $userDn);
                if ($found !== null) {
                    $userEntry = $found['entry'];
                    $userDn = $found['dn'];
                }
            } else {
                $found = $this->searchUserEntry($connection, $server, $username);
                $userEntry = $found['entry'];
                $userDn = $found['dn'];
                if ($userDn === '') {
                    throw new RuntimeException($this->formatLdapFailure(
                        'LDAP user DN was empty after search.',
                        $connection,
                        [
                            'Username: ' . $username,
                            'Search base: ' . trim((string) ($server['user_search_base'] ?? '')),
                            'Filter: ' . $this->userFilterFor($server, $username),
                        ]
                    ));
                }
                if (!@ldap_bind($connection, $userDn, $password)) {
                    throw new RuntimeException($this->formatLdapFailure(
                        'LDAP user bind failed.',
                        $connection,
                        array_merge(
                            [
                                'Username: ' . $username,
                                'Bind DN: ' . $userDn,
                            ],
                            $this->connectionContext($server)
                        )
                    ));
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
     * @return array{username: string, email: string, display_name: string, dn: string, groups: list<string>}
     */
    private function lookupUserAgainst(array $server, string $username): array
    {
        $host = trim((string) ($server['server'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('LDAP server host is not configured.');
        }
        if ($username === '') {
            throw new RuntimeException('LDAP username is required.');
        }

        if (!$this->nativeLdapUsable()) {
            $result = $this->runCliWorker('lookup', [
                'server' => $server,
                'username' => $username,
            ]);
            if (empty($result['success']) || !is_array($result['profile'] ?? null)) {
                throw new RuntimeException((string) ($result['message'] ?? 'LDAP user lookup failed.'));
            }

            /** @var array{username: string, email: string, display_name: string, dn: string, groups: list<string>} $profile */
            $profile = $result['profile'];

            return $profile;
        }

        $connection = $this->connect($server);
        try {
            $this->serviceBind($connection, $server);
            $found = $this->findUserByUsername($connection, $server, $username);
            $loginName = $this->usernameFromEntry($found['entry'], $username);

            return $this->extractProfile($server, $loginName, $found['entry'], $found['dn']);
        } finally {
            if (is_resource($connection) || $connection instanceof \LDAP\Connection) {
                @ldap_unbind($connection);
            }
        }
    }

    /**
     * @param array<string, mixed> $server
     * @return array{
     *   profile: array{username: string, email: string, display_name: string, dn: string, groups: list<string>},
     *   status: array{
     *     enabled: bool,
     *     disabled: bool,
     *     locked: bool,
     *     password_expired: bool,
     *     must_change_password: bool,
     *     password_never_expires: bool,
     *     account_expired: bool,
     *     badges: list<array{label: string, tone: string}>,
     *     notes: list<string>
     *   },
     *   groups: list<array{cn: string, dn: string}>,
     *   fields: array<string, string>,
     *   timestamps: array<string, string>,
     *   attributes: array<string, string|list<string>>
     * }
     */
    private function lookupUserDetailsAgainst(array $server, string $username): array
    {
        $host = trim((string) ($server['server'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('LDAP server host is not configured.');
        }
        if ($username === '') {
            throw new RuntimeException('LDAP username is required.');
        }

        if (!$this->nativeLdapUsable()) {
            $result = $this->runCliWorker('lookup_details', [
                'server' => $server,
                'username' => $username,
            ]);
            if (empty($result['success']) || !is_array($result['details'] ?? null)) {
                throw new RuntimeException((string) ($result['message'] ?? 'LDAP user details lookup failed.'));
            }

            /** @var array{
             *   profile: array{username: string, email: string, display_name: string, dn: string, groups: list<string>},
             *   status: array{
             *     enabled: bool,
             *     disabled: bool,
             *     locked: bool,
             *     password_expired: bool,
             *     must_change_password: bool,
             *     password_never_expires: bool,
             *     account_expired: bool,
             *     badges: list<array{label: string, tone: string}>,
             *     notes: list<string>
             *   },
             *   groups: list<array{cn: string, dn: string}>,
             *   fields: array<string, string>,
             *   timestamps: array<string, string>,
             *   attributes: array<string, string|list<string>>
             * } $details */
            $details = $result['details'];

            return $details;
        }

        $connection = $this->connect($server);
        try {
            $this->serviceBind($connection, $server);
            $found = $this->findUserByUsername($connection, $server, $username);
            $full = $this->readEntry($connection, $server, $found['dn'], ['*', '+']);
            $entry = is_array($full['entry'] ?? null) ? $full['entry'] : $found['entry'];
            $dn = (string) ($full['dn'] ?? $found['dn']);
            $loginName = $this->usernameFromEntry($entry, $username);

            return $this->buildUserDetails($server, $loginName, $entry, $dn);
        } finally {
            if (is_resource($connection) || $connection instanceof \LDAP\Connection) {
                @ldap_unbind($connection);
            }
        }
    }

    /**
     * @param array<string, mixed> $server
     * @return list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>
     */
    private function searchUsersAgainst(array $server, string $query, int $limit): array
    {
        $host = trim((string) ($server['server'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('LDAP server host is not configured.');
        }
        if (strlen($query) < 2) {
            throw new RuntimeException('Enter at least 2 characters to search the directory.');
        }

        if (!$this->nativeLdapUsable()) {
            $result = $this->runCliWorker('search', [
                'server' => $server,
                'query' => $query,
                'limit' => $limit,
            ]);
            if (empty($result['success']) || !is_array($result['results'] ?? null)) {
                throw new RuntimeException((string) ($result['message'] ?? 'LDAP user search failed.'));
            }

            /** @var list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}> $results */
            $results = [];
            foreach ($result['results'] as $row) {
                if (is_array($row) && isset($row['username'])) {
                    $results[] = [
                        'username' => (string) $row['username'],
                        'email' => (string) ($row['email'] ?? ''),
                        'display_name' => (string) ($row['display_name'] ?? ''),
                        'dn' => (string) ($row['dn'] ?? ''),
                        'groups' => is_array($row['groups'] ?? null)
                            ? array_values(array_filter($row['groups'], 'is_string'))
                            : [],
                    ];
                }
            }

            return $results;
        }

        $searchBase = trim((string) ($server['user_search_base'] ?? ''));
        if ($searchBase === '') {
            throw new RuntimeException('LDAP user search base is not configured.');
        }

        $connection = $this->connect($server);
        try {
            $this->serviceBind($connection, $server);
            $attributes = $this->attributeList($server);
            $scope = strtolower((string) ($server['search_scope'] ?? 'sub'));
            $profiles = [];
            $seen = [];
            $exactUsernameHit = false;

            // Same indexed lookup the login path uses, so an exact username always works
            // even when a domain-wide wildcard search would time out.
            foreach ($this->searchLookupCandidates($query) as $candidate) {
                try {
                    $found = $this->findUserByUsername($connection, $server, $candidate);
                    $entry = $found['entry'];
                    if (($entry['dn'] ?? '') === '' && $found['dn'] !== '') {
                        $entry['dn'] = $found['dn'];
                    }
                    $this->appendProfilesFromEntries(
                        $server,
                        ['count' => 1, 0 => $entry],
                        $profiles,
                        $seen,
                        $limit
                    );
                    $loginName = $this->usernameFromEntry($entry, $candidate);
                    if (strcasecmp($loginName, $candidate) === 0) {
                        $exactUsernameHit = true;
                    }
                } catch (Throwable) {
                    // Not an exact username match; continue with directory search filters.
                }
                if (count($profiles) >= $limit) {
                    break;
                }
            }

            $hardError = null;
            $timedOutWithNoHits = false;
            foreach ($exactUsernameHit ? [] : $this->userDirectorySearchFilters($query) as $filter) {
                if (count($profiles) >= $limit) {
                    break;
                }
                $outcome = $this->directorySearch(
                    $connection,
                    $searchBase,
                    $filter,
                    $attributes,
                    $scope,
                    $limit,
                    12
                );
                if ($outcome['error'] !== null) {
                    $hardError = $outcome['error'];
                    continue;
                }
                $before = count($profiles);
                $this->appendProfilesFromEntries($server, $outcome['entries'], $profiles, $seen, $limit);
                if ($outcome['timed_out'] && count($profiles) === $before) {
                    $timedOutWithNoHits = true;
                }
            }

            if ($profiles === [] && $hardError !== null && !$timedOutWithNoHits) {
                throw new RuntimeException($hardError);
            }
            if ($profiles === [] && $timedOutWithNoHits) {
                throw new RuntimeException(
                    'LDAP search timed out before any users were returned. '
                    . 'Search with the exact username (sAMAccountName), or set a narrower User search base than '
                    . $searchBase . '.'
                );
            }

            usort(
                $profiles,
                static function (array $a, array $b): int {
                    $nameCmp = strcasecmp((string) $a['display_name'], (string) $b['display_name']);
                    if ($nameCmp !== 0) {
                        return $nameCmp;
                    }

                    return strcasecmp((string) $a['username'], (string) $b['username']);
                }
            );

            return $profiles;
        } finally {
            if (is_resource($connection) || $connection instanceof \LDAP\Connection) {
                @ldap_unbind($connection);
            }
        }
    }

    /** @return list<string> */
    private function searchLookupCandidates(string $query): array
    {
        $candidates = [trim($query)];
        if (str_contains($query, '@')) {
            $local = trim((string) strstr($query, '@', true));
            if ($local !== '') {
                $candidates[] = $local;
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));
    }

    /**
     * Cheap, index-friendly filters first. Leading-wildcard substring searches against a
     * domain root are skipped because Active Directory typically exceeds its time limit.
     *
     * @return list<string>
     */
    private function userDirectorySearchFilters(string $query): array
    {
        $escaped = $this->escapeFilter($query);
        $local = $query;
        if (str_contains($query, '@')) {
            $local = (string) strstr($query, '@', true);
        }
        $escapedLocal = $this->escapeFilter($local !== '' ? $local : $query);

        $adUser = '(&(objectCategory=person)(objectClass=user)(!(objectClass=computer))';
        $genericUser = '(&(|(objectClass=user)(objectClass=inetOrgPerson)(objectClass=person))(!(objectClass=computer))';
        $exact = '(|(sAMAccountName=' . $escapedLocal . ')(uid=' . $escapedLocal . ')'
            . '(userPrincipalName=' . $escaped . ')(mail=' . $escaped . ')(cn=' . $escaped . '))';
        $prefix = '(|(sAMAccountName=' . $escapedLocal . '*)(uid=' . $escapedLocal . '*)'
            . '(cn=' . $escaped . '*)(mail=' . $escaped . '*)(displayName=' . $escaped . '*)'
            . '(givenName=' . $escaped . '*)(sn=' . $escaped . '*)(userPrincipalName=' . $escaped . '*))';

        $filters = [
            $adUser . $exact . ')',
            $genericUser . $exact . ')',
            $adUser . '(anr=' . $escaped . '))',
            $adUser . $prefix . ')',
            $genericUser . $prefix . ')',
        ];
        if ($escapedLocal !== $escaped) {
            $filters[] = $adUser . '(anr=' . $escapedLocal . '))';
        }

        return $filters;
    }

    /**
     * @param \LDAP\Connection|resource $connection
     * @param list<string> $attributes
     * @return array{entries: array<string, mixed>, timed_out: bool, error: ?string}
     */
    private function directorySearch(
        $connection,
        string $base,
        string $filter,
        array $attributes,
        string $scope,
        int $sizeLimit,
        int $timeLimit
    ): array {
        $result = match ($scope) {
            'base' => @ldap_read($connection, $base, $filter, $attributes, 0, $sizeLimit, $timeLimit),
            'one' => @ldap_list($connection, $base, $filter, $attributes, 0, $sizeLimit, $timeLimit),
            default => @ldap_search($connection, $base, $filter, $attributes, 0, $sizeLimit, $timeLimit),
        };
        $errno = (int) @ldap_errno($connection);
        $timeLimitCode = defined('LDAP_TIMELIMIT_EXCEEDED') ? LDAP_TIMELIMIT_EXCEEDED : 3;
        $sizeLimitCode = defined('LDAP_SIZELIMIT_EXCEEDED') ? LDAP_SIZELIMIT_EXCEEDED : 4;
        $adminLimitCode = defined('LDAP_ADMINLIMIT_EXCEEDED') ? LDAP_ADMINLIMIT_EXCEEDED : 11;
        $timedOut = in_array($errno, [$timeLimitCode, $adminLimitCode], true);
        $sizeLimited = $errno === $sizeLimitCode;
        $unsupported = in_array($errno, [16, 17, 18, 21], true);

        if ($result === false) {
            if ($timedOut || $sizeLimited || $unsupported) {
                return ['entries' => ['count' => 0], 'timed_out' => $timedOut, 'error' => null];
            }

            return [
                'entries' => ['count' => 0],
                'timed_out' => false,
                'error' => $this->formatLdapFailure('LDAP search failed.', $connection, [
                    'Search base: ' . $base,
                    'Filter: ' . $filter,
                    'Scope: ' . $scope,
                ]),
            ];
        }

        $entries = @ldap_get_entries($connection, $result);
        if ($result instanceof \LDAP\Result || is_resource($result)) {
            @ldap_free_result($result);
        }

        return [
            'entries' => is_array($entries) ? $entries : ['count' => 0],
            'timed_out' => $timedOut,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $entries
     * @param list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}> $profiles
     * @param array<string, true> $seen
     */
    private function appendProfilesFromEntries(
        array $server,
        array $entries,
        array &$profiles,
        array &$seen,
        int $limit
    ): void {
        $count = (int) ($entries['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            if (count($profiles) >= $limit) {
                return;
            }
            $entry = $entries[$i] ?? null;
            if (!is_array($entry) || !$this->isLikelyUserEntry($entry)) {
                continue;
            }
            $dn = (string) ($entry['dn'] ?? '');
            $loginName = $this->usernameFromEntry($entry, '');
            if ($loginName === '') {
                continue;
            }
            $key = strtolower($loginName);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $profiles[] = $this->extractProfile($server, $loginName, $entry, $dn);
        }
    }

    /**
     * @param array<string, mixed> $server
     * @return array{dn: string, name: string, members: list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>}
     */
    private function lookupGroupMembersAgainst(array $server, string $groupDn): array
    {
        $host = trim((string) ($server['server'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('LDAP server host is not configured.');
        }
        if ($groupDn === '') {
            throw new RuntimeException('LDAP group DN is required.');
        }

        if (!$this->nativeLdapUsable()) {
            $result = $this->runCliWorker('lookup_group', [
                'server' => $server,
                'group_dn' => $groupDn,
            ]);
            if (empty($result['success']) || !is_array($result['group'] ?? null)) {
                throw new RuntimeException((string) ($result['message'] ?? 'LDAP group lookup failed.'));
            }

            /** @var array{dn: string, name: string, members: list<array{username: string, email: string, display_name: string, dn: string, groups: list<string>}>} $group */
            $group = $result['group'];

            return $group;
        }

        $connection = $this->connect($server);
        try {
            $this->serviceBind($connection, $server);

            $groupEntry = $this->readEntry($connection, $server, $groupDn, ['cn', 'name', 'member', 'objectClass', 'distinguishedName']);
            if ($groupEntry === null) {
                throw new RuntimeException('LDAP group DN was not found: ' . $groupDn);
            }
            if (!$this->entryHasObjectClass($groupEntry['entry'], ['group', 'groupOfNames', 'groupOfUniqueNames', 'posixGroup'])) {
                // Still allow if it has member attributes (some directories omit objectClass in returned attrs).
                $members = $this->attributeValues($groupEntry['entry'], 'member');
                $uniqueMembers = $this->attributeValues($groupEntry['entry'], 'uniqueMember');
                $memberUid = $this->attributeValues($groupEntry['entry'], 'memberUid');
                if ($members === [] && $uniqueMembers === [] && $memberUid === []) {
                    throw new RuntimeException('The DN does not look like a group and has no members: ' . $groupDn);
                }
            }

            $groupName = $this->firstAttribute($groupEntry['entry'], 'cn')
                ?: $this->firstAttribute($groupEntry['entry'], 'name')
                ?: $groupDn;

            $memberDns = array_values(array_unique(array_merge(
                $this->attributeValues($groupEntry['entry'], 'member'),
                $this->attributeValues($groupEntry['entry'], 'uniqueMember')
            )));
            $memberUids = $this->attributeValues($groupEntry['entry'], 'memberUid');

            // Nested groups: expand one level of group members into user DNs.
            $resolvedDns = [];
            foreach ($memberDns as $memberDn) {
                $nested = $this->readEntry($connection, $server, $memberDn, ['objectClass', 'member', 'uniqueMember', 'sAMAccountName', 'uid', 'cn', 'mail', 'displayName', 'memberOf', 'userPrincipalName']);
                if ($nested === null) {
                    continue;
                }
                if ($this->entryHasObjectClass($nested['entry'], ['group', 'groupOfNames', 'groupOfUniqueNames', 'posixGroup'])
                    && !$this->isLikelyUserEntry($nested['entry'])) {
                    foreach (array_merge(
                        $this->attributeValues($nested['entry'], 'member'),
                        $this->attributeValues($nested['entry'], 'uniqueMember')
                    ) as $nestedDn) {
                        $resolvedDns[] = $nestedDn;
                    }
                    continue;
                }
                $resolvedDns[] = $memberDn;
            }

            // Also find users that list this group in memberOf (AD / some directories).
            $searchBase = trim((string) ($server['user_search_base'] ?? ''));
            if ($searchBase !== '') {
                $escapedGroupDn = $this->escapeFilter($groupEntry['dn']);
                $attributes = $this->attributeList($server);
                foreach ([
                    '(memberOf=' . $escapedGroupDn . ')',
                    // Active Directory nested-group matching rule (ignored on non-AD directories).
                    '(memberOf:1.2.840.113556.1.4.1941:=' . $escapedGroupDn . ')',
                ] as $filter) {
                    $result = @ldap_search($connection, $searchBase, $filter, $attributes);
                    if ($result === false) {
                        continue;
                    }
                    $entries = @ldap_get_entries($connection, $result);
                    if (!is_array($entries)) {
                        continue;
                    }
                    $count = (int) ($entries['count'] ?? 0);
                    for ($i = 0; $i < $count; $i++) {
                        $dn = (string) ($entries[$i]['dn'] ?? '');
                        if ($dn !== '') {
                            $resolvedDns[] = $dn;
                        }
                    }
                }
            }

            $profiles = [];
            $seenUsernames = [];

            foreach (array_values(array_unique($resolvedDns)) as $dn) {
                $entry = $this->readEntry($connection, $server, $dn);
                if ($entry === null || !$this->isLikelyUserEntry($entry['entry'])) {
                    continue;
                }
                $loginName = $this->usernameFromEntry($entry['entry'], '');
                if ($loginName === '') {
                    continue;
                }
                $key = strtolower($loginName);
                if (isset($seenUsernames[$key])) {
                    continue;
                }
                $seenUsernames[$key] = true;
                $profiles[] = $this->extractProfile($server, $loginName, $entry['entry'], $entry['dn']);
            }

            foreach ($memberUids as $uid) {
                $uid = trim($uid);
                if ($uid === '') {
                    continue;
                }
                $key = strtolower($uid);
                if (isset($seenUsernames[$key])) {
                    continue;
                }
                try {
                    $found = $this->findUserByUsername($connection, $server, $uid);
                    $loginName = $this->usernameFromEntry($found['entry'], $uid);
                    $seenUsernames[strtolower($loginName)] = true;
                    $profiles[] = $this->extractProfile($server, $loginName, $found['entry'], $found['dn']);
                } catch (Throwable) {
                    // Skip unresolved posix memberUid values.
                }
            }

            if ($profiles === []) {
                throw new RuntimeException('No user members were found for that LDAP group.');
            }

            return [
                'dn' => $groupEntry['dn'],
                'name' => $groupName,
                'members' => $profiles,
            ];
        } finally {
            if (is_resource($connection) || $connection instanceof \LDAP\Connection) {
                @ldap_unbind($connection);
            }
        }
    }

    /** @return array<string, mixed> */
    private function serverAt(int $serverIndex): array
    {
        $servers = $this->servers();
        if ($servers === []) {
            throw new RuntimeException('No LDAP server is configured.');
        }
        if (!isset($servers[$serverIndex])) {
            $serverIndex = 0;
        }

        return $servers[$serverIndex];
    }

    /**
     * @param \LDAP\Connection|resource $connection
     * @param array<string, mixed> $server
     */
    private function serviceBind($connection, array $server): void
    {
        $bindDn = trim((string) ($server['bind_dn'] ?? ''));
        $bindPassword = $this->decryptSecret((string) ($server['bind_password'] ?? ''));
        if (!@ldap_bind($connection, $bindDn !== '' ? $bindDn : null, $bindDn !== '' ? $bindPassword : null)) {
            throw new RuntimeException($this->formatLdapFailure(
                'LDAP service bind failed.',
                $connection,
                ['Bind DN: ' . ($bindDn !== '' ? $bindDn : '(anonymous)')]
            ));
        }
    }

    /**
     * @param \LDAP\Connection|resource $connection
     * @param array<string, mixed> $server
     * @return array{entry: array<string, mixed>, dn: string}
     */
    private function findUserByUsername($connection, array $server, string $username): array
    {
        $template = trim((string) ($server['user_dn_template'] ?? ''));
        if ($template !== '') {
            $userDn = str_replace('{username}', $this->escapeDn($username), $template);
            $found = $this->readEntry($connection, $server, $userDn);
            if ($found === null) {
                throw new RuntimeException($this->formatLdapFailure(
                    'User not found in the directory.',
                    $connection,
                    [
                        'Username: ' . $username,
                        'User DN: ' . $userDn,
                    ]
                ));
            }

            return $found;
        }

        return $this->searchUserEntry($connection, $server, $username);
    }

    /**
     * @param \LDAP\Connection|resource $connection
     * @param array<string, mixed> $server
     * @return array{entry: array<string, mixed>, dn: string}
     */
    private function searchUserEntry($connection, array $server, string $username): array
    {
        $searchBase = trim((string) ($server['user_search_base'] ?? ''));
        if ($searchBase === '') {
            throw new RuntimeException('LDAP user search base is not configured.');
        }

        $filter = $this->userFilterFor($server, $username);
        $attributes = $this->attributeList($server);
        $scope = strtolower((string) ($server['search_scope'] ?? 'sub'));
        $result = match ($scope) {
            'base' => @ldap_read($connection, $searchBase, $filter, $attributes),
            'one' => @ldap_list($connection, $searchBase, $filter, $attributes),
            default => @ldap_search($connection, $searchBase, $filter, $attributes),
        };
        if ($result === false) {
            throw new RuntimeException($this->formatLdapFailure(
                'LDAP search failed.',
                $connection,
                [
                    'Username: ' . $username,
                    'Search base: ' . $searchBase,
                    'Filter: ' . $filter,
                    'Scope: ' . $scope,
                ]
            ));
        }

        $entries = @ldap_get_entries($connection, $result);
        $entryCount = is_array($entries) ? (int) ($entries['count'] ?? 0) : 0;
        if (!is_array($entries) || $entryCount < 1) {
            throw new RuntimeException($this->formatLdapFailure(
                'User not found in the directory.',
                $connection,
                [
                    'Username: ' . $username,
                    'Search base: ' . $searchBase,
                    'Filter: ' . $filter,
                    'Scope: ' . $scope,
                    'Entries returned: ' . $entryCount,
                ]
            ));
        }

        $userEntry = $entries[0];
        $userDn = (string) ($userEntry['dn'] ?? '');
        if ($userDn === '') {
            throw new RuntimeException($this->formatLdapFailure(
                'User not found in the directory (empty DN).',
                $connection,
                [
                    'Username: ' . $username,
                    'Search base: ' . $searchBase,
                    'Filter: ' . $filter,
                ]
            ));
        }

        return ['entry' => $userEntry, 'dn' => $userDn];
    }

    /**
     * @param \LDAP\Connection|resource $connection
     * @param array<string, mixed> $server
     * @param list<string>|null $attributes
     * @return array{entry: array<string, mixed>, dn: string}|null
     */
    private function readEntry($connection, array $server, string $dn, ?array $attributes = null): ?array
    {
        $dn = trim($dn);
        if ($dn === '') {
            return null;
        }
        $attrs = $attributes ?? $this->attributeList($server);
        $result = @ldap_read($connection, $dn, '(objectClass=*)', $attrs);
        if ($result === false) {
            return null;
        }
        $entries = @ldap_get_entries($connection, $result);
        if (!is_array($entries) || (int) ($entries['count'] ?? 0) < 1) {
            return null;
        }
        $entry = $entries[0];
        $resolvedDn = (string) ($entry['dn'] ?? $dn);

        return ['entry' => $entry, 'dn' => $resolvedDn];
    }

    /** @param array<string, mixed> $entry */
    private function usernameFromEntry(array $entry, string $fallback): string
    {
        foreach (['samaccountname', 'uid', 'userprincipalname', 'cn'] as $attr) {
            $value = $this->firstAttribute($entry, $attr);
            if ($value === '') {
                continue;
            }
            if ($attr === 'userprincipalname' && str_contains($value, '@')) {
                return (string) strstr($value, '@', true);
            }

            return $value;
        }

        return $fallback;
    }

    /** @param array<string, mixed> $entry */
    private function isLikelyUserEntry(array $entry): bool
    {
        if ($this->entryHasObjectClass($entry, ['computer'])) {
            return false;
        }
        if ($this->firstAttribute($entry, 'samaccountname') !== ''
            || $this->firstAttribute($entry, 'uid') !== ''
            || $this->firstAttribute($entry, 'userprincipalname') !== '') {
            return true;
        }

        return $this->entryHasObjectClass($entry, ['user', 'person', 'inetOrgPerson', 'organizationalPerson', 'posixAccount']);
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $classes
     */
    private function entryHasObjectClass(array $entry, array $classes): bool
    {
        $values = array_map('strtolower', $this->attributeValues($entry, 'objectClass'));
        foreach ($classes as $class) {
            if (in_array(strtolower($class), $values, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function attributeValues(array $entry, string $name): array
    {
        $name = strtolower($name);
        $values = [];
        foreach ($entry as $key => $value) {
            if (!is_string($key) || strtolower($key) !== $name || !is_array($value)) {
                continue;
            }
            foreach ($value as $index => $item) {
                if ($index === 'count' || !is_string($item) || $item === '') {
                    continue;
                }
                $values[] = $item;
            }
        }

        return $values;
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
            $detail = trim((string) (error_get_last()['message'] ?? ''));
            if (stripos($detail, 'Local error') !== false || stripos($detail, 'session handle') !== false) {
                throw new RuntimeException($this->formatLdapFailure(
                    'Unable to connect to the LDAP server (Apache PHP LDAP session error). The app will retry through CLI PHP automatically when available.',
                    null,
                    array_merge($this->connectionContext($server), $detail !== '' ? ['PHP: ' . $detail] : [])
                ));
            }
            throw new RuntimeException($this->formatLdapFailure(
                'Unable to connect to the LDAP server.',
                null,
                array_merge($this->connectionContext($server), $detail !== '' ? ['PHP: ' . $detail] : [])
            ));
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($connection, LDAP_OPT_TIMELIMIT, $timeout);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, ($server['referrals'] ?? '0') === '1' ? 1 : 0);

        if (($server['tls'] ?? '0') === '1' && $protocol !== 'ldaps' && !@ldap_start_tls($connection)) {
            throw new RuntimeException($this->formatLdapFailure(
                'LDAP StartTLS failed.',
                $connection,
                $this->connectionContext($server)
            ));
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
            $body = trim((string) $stdout);
            throw new RuntimeException(implode("\n", array_filter([
                'LDAP CLI worker returned an invalid response.',
                $hint !== '' ? 'stderr: ' . $hint : '',
                $body !== '' ? 'stdout: ' . $body : '',
                $exitCode !== 0 ? 'Exit code: ' . $exitCode : '',
            ])));
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
     * @param array<string, mixed> $entry
     * @return array{
     *   profile: array{username: string, email: string, display_name: string, dn: string, groups: list<string>},
     *   status: array{
     *     enabled: bool,
     *     disabled: bool,
     *     locked: bool,
     *     password_expired: bool,
     *     must_change_password: bool,
     *     password_never_expires: bool,
     *     account_expired: bool,
     *     badges: list<array{label: string, tone: string}>,
     *     notes: list<string>
     *   },
     *   groups: list<array{cn: string, dn: string}>,
     *   fields: array<string, string>,
     *   timestamps: array<string, string>,
     *   attributes: array<string, string|list<string>>
     * }
     */
    private function buildUserDetails(array $server, string $username, array $entry, string $userDn): array
    {
        $profile = $this->extractProfile($server, $username, $entry, $userDn);
        $flat = $this->flattenLdapEntry($entry);
        $groupDns = $profile['groups'];
        $groups = [];
        foreach ($groupDns as $groupDn) {
            $groups[] = [
                'cn' => $this->cnFromDn($groupDn) ?: $groupDn,
                'dn' => $groupDn,
            ];
        }
        usort($groups, static fn (array $a, array $b): int => strcasecmp($a['cn'], $b['cn']));

        $highlightKeys = [
            'title', 'department', 'company', 'manager', 'telephoneNumber', 'mobile',
            'homePhone', 'pager', 'facsimileTelephoneNumber', 'physicalDeliveryOfficeName',
            'streetAddress', 'l', 'st', 'postalCode', 'c', 'co', 'givenName', 'sn', 'initials',
            'description', 'employeeID', 'employeeNumber', 'info', 'wWWHomePage', 'url',
            'userPrincipalName', 'sAMAccountName', 'uid', 'cn', 'mail', 'displayName',
        ];
        $fields = [];
        foreach ($highlightKeys as $key) {
            $value = $this->attributeDisplayValue($flat, $key);
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        $timestamps = $this->extractReadableTimestamps($flat);
        $status = $this->decodeAccountStatus($flat);

        $skipFromDump = array_merge(
            array_map('strtolower', array_keys($fields)),
            array_map('strtolower', array_keys($timestamps)),
            ['memberof', 'dn', 'count']
        );
        $attributes = [];
        foreach ($flat as $name => $values) {
            if (in_array(strtolower($name), $skipFromDump, true)) {
                continue;
            }
            if ($this->isSecretAttribute($name)) {
                continue;
            }
            $attributes[$name] = count($values) === 1 ? $values[0] : $values;
        }
        ksort($attributes, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'profile' => $profile,
            'status' => $status,
            'groups' => $groups,
            'fields' => $fields,
            'timestamps' => $timestamps,
            'attributes' => $attributes,
        ];
    }

    /**
     * Flatten ldap_get_entries style entry into attr => list of display strings.
     *
     * @param array<string, mixed> $entry
     * @return array<string, list<string>>
     */
    private function flattenLdapEntry(array $entry): array
    {
        $out = [];
        foreach ($entry as $key => $value) {
            if (!is_string($key) || $key === 'count' || is_numeric($key)) {
                continue;
            }
            if ($this->isSecretAttribute($key)) {
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $values = [];
            foreach ($value as $index => $item) {
                if ($index === 'count' || !is_string($item)) {
                    continue;
                }
                $values[] = $this->stringifyAttributeValue($key, $item);
            }
            if ($values !== []) {
                $out[$key] = array_values(array_unique($values));
            }
        }

        return $out;
    }

    private function isSecretAttribute(string $name): bool
    {
        $lower = strtolower($name);
        $secrets = [
            'unicodepwd',
            'userpassword',
            'ntpwdhistory',
            'lmpwdhistory',
            'supplementalcredentials',
            'msds-managedpassword',
            'dbcspwd',
            'krbprimarykey',
            'sambantpassword',
            'sambalmpassword',
            'authpassword',
            'krbprincipalkey',
            'userpkcs12',
            'usersmimecertificate',
            'msds-keycredentiallink',
        ];

        return in_array($lower, $secrets, true);
    }

    private function stringifyAttributeValue(string $name, string $raw): string
    {
        $lower = strtolower($name);
        if ($raw === '') {
            return '';
        }

        if ($lower === 'objectsid') {
            $sid = $this->decodeObjectSid($raw);
            if ($sid !== '') {
                return $sid;
            }
        }
        if ($lower === 'objectguid' || $lower === 'msexchmailboxguid') {
            $guid = $this->decodeObjectGuid($raw);
            if ($guid !== '') {
                return $guid;
            }
        }

        if (!mb_check_encoding($raw, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw) === 1) {
            return 'binary (' . strlen($raw) . ' bytes)';
        }

        return $raw;
    }

    private function decodeObjectSid(string $binary): string
    {
        $length = strlen($binary);
        if ($length < 8) {
            return '';
        }
        $revision = ord($binary[0]);
        $subCount = ord($binary[1]);
        if ($length < 8 + ($subCount * 4)) {
            return '';
        }
        $authority = 0;
        for ($i = 2; $i <= 7; $i++) {
            $authority = ($authority << 8) | ord($binary[$i]);
        }
        $parts = ['S', (string) $revision, (string) $authority];
        for ($i = 0; $i < $subCount; $i++) {
            $chunk = substr($binary, 8 + ($i * 4), 4);
            $unpacked = unpack('V', $chunk);
            if ($unpacked === false) {
                return '';
            }
            $parts[] = (string) $unpacked[1];
        }

        return implode('-', $parts);
    }

    private function decodeObjectGuid(string $binary): string
    {
        if (strlen($binary) !== 16) {
            return '';
        }
        $hex = bin2hex($binary);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 6, 2) . substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2),
            substr($hex, 10, 2) . substr($hex, 8, 2),
            substr($hex, 14, 2) . substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * @param array<string, list<string>> $flat
     */
    private function attributeDisplayValue(array $flat, string $name): string
    {
        $lower = strtolower($name);
        foreach ($flat as $key => $values) {
            if (strtolower($key) !== $lower || $values === []) {
                continue;
            }

            return implode(', ', $values);
        }

        return '';
    }

    /**
     * @param array<string, list<string>> $flat
     * @return array<string, string>
     */
    private function extractReadableTimestamps(array $flat): array
    {
        $filetimeAttrs = [
            'lockoutTime',
            'pwdLastSet',
            'lastLogon',
            'lastLogonTimestamp',
            'badPasswordTime',
            'lastLogoff',
            'accountExpires',
            'msDS-UserPasswordExpiryTimeComputed',
        ];
        $generalizedAttrs = [
            'whenCreated',
            'whenChanged',
            'createTimestamp',
            'modifyTimestamp',
            'pwdChangedTime',
            'pwdAccountLockedTime',
            'pwdFailureTime',
            'pwdEndTime',
            'pwdStartTime',
        ];

        $out = [];
        foreach ($filetimeAttrs as $attr) {
            $raw = $this->attributeDisplayValue($flat, $attr);
            if ($raw === '') {
                continue;
            }
            $label = $attr;
            if ($attr === 'accountExpires' && ($raw === '0' || $raw === '9223372036854775807')) {
                $out[$label] = 'Never';
                continue;
            }
            if (($attr === 'lockoutTime' || $attr === 'pwdLastSet') && $raw === '0') {
                $out[$label] = $attr === 'pwdLastSet' ? 'Never set / must change' : 'Not locked';
                continue;
            }
            $converted = $this->windowsFileTimeToUtc($raw);
            $out[$label] = $converted !== '' ? $converted : $raw;
        }
        foreach ($generalizedAttrs as $attr) {
            $raw = $this->attributeDisplayValue($flat, $attr);
            if ($raw === '') {
                continue;
            }
            $converted = $this->ldapGeneralizedTimeToUtc($raw);
            $out[$attr] = $converted !== '' ? $converted : $raw;
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    private function windowsFileTimeToUtc(string $raw): string
    {
        if (!ctype_digit($raw) || $raw === '0') {
            return '';
        }
        // 100-nanosecond intervals since 1601-01-01 UTC → Unix seconds
        if (strlen($raw) > 18) {
            return '';
        }
        $ft = (int) $raw;
        if ($ft <= 0) {
            return '';
        }
        $unix = intdiv($ft, 10000000) - 11644473600;
        if ($unix < 0 || $unix > 4102444800) {
            return '';
        }
        $dt = gmdate('Y-m-d H:i:s', $unix);

        return $dt !== false ? $dt . ' UTC' : '';
    }

    private function ldapGeneralizedTimeToUtc(string $raw): string
    {
        if (preg_match('/^(\d{14})(?:\.\d+)?Z$/', $raw, $m) !== 1) {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('YmdHis', $m[1], new \DateTimeZone('UTC'));
        if ($dt === false) {
            return '';
        }

        return $dt->format('Y-m-d H:i:s') . ' UTC';
    }

    /**
     * @param array<string, list<string>> $flat
     * @return array{
     *   enabled: bool,
     *   disabled: bool,
     *   locked: bool,
     *   password_expired: bool,
     *   must_change_password: bool,
     *   password_never_expires: bool,
     *   account_expired: bool,
     *   badges: list<array{label: string, tone: string}>,
     *   notes: list<string>
     * }
     */
    private function decodeAccountStatus(array $flat): array
    {
        $uac = (int) $this->attributeDisplayValue($flat, 'userAccountControl');
        $computed = (int) $this->attributeDisplayValue($flat, 'msDS-User-Account-Control-Computed');
        $lockoutTime = $this->attributeDisplayValue($flat, 'lockoutTime');
        $pwdLastSet = $this->attributeDisplayValue($flat, 'pwdLastSet');
        $accountExpires = $this->attributeDisplayValue($flat, 'accountExpires');
        $pwdAccountLockedTime = $this->attributeDisplayValue($flat, 'pwdAccountLockedTime');
        $badPwdCount = $this->attributeDisplayValue($flat, 'badPwdCount');

        $disabled = ($uac & 0x0002) === 0x0002;
        $passwordNeverExpires = ($uac & 0x10000) === 0x10000;
        $locked = ($computed & 0x0010) === 0x0010
            || ($uac & 0x0010) === 0x0010
            || ($lockoutTime !== '' && $lockoutTime !== '0')
            || $pwdAccountLockedTime !== '';
        $passwordExpired = ($computed & 0x800000) === 0x800000
            || ($uac & 0x800000) === 0x800000;
        $mustChange = $pwdLastSet === '0';

        $accountExpired = false;
        if ($accountExpires !== '' && $accountExpires !== '0' && $accountExpires !== '9223372036854775807') {
            $converted = $this->windowsFileTimeToUtc($accountExpires);
            if ($converted !== '') {
                $expiresUnix = strtotime(str_replace(' UTC', '', $converted) . ' UTC');
                if ($expiresUnix !== false && $expiresUnix < time()) {
                    $accountExpired = true;
                }
            }
        }

        $enabled = !$disabled && !$accountExpired;
        $badges = [];
        $notes = [];

        if ($disabled) {
            $badges[] = ['label' => 'Disabled', 'tone' => 'danger'];
        } elseif ($accountExpired) {
            $badges[] = ['label' => 'Account expired', 'tone' => 'danger'];
        } else {
            $badges[] = ['label' => 'Enabled', 'tone' => 'ok'];
        }
        if ($locked) {
            $badges[] = ['label' => 'Locked', 'tone' => 'danger'];
        }
        if ($passwordExpired) {
            $badges[] = ['label' => 'Password expired', 'tone' => 'warn'];
        }
        if ($mustChange) {
            $badges[] = ['label' => 'Must change password', 'tone' => 'warn'];
        }
        if ($passwordNeverExpires) {
            $badges[] = ['label' => 'Password never expires', 'tone' => 'info'];
        }
        if ($badPwdCount !== '' && (int) $badPwdCount > 0) {
            $notes[] = 'Bad password count: ' . $badPwdCount;
        }
        if ($uac > 0) {
            $flagNames = $this->userAccountControlFlags($uac);
            if ($flagNames !== []) {
                $notes[] = 'userAccountControl: ' . implode(', ', $flagNames) . ' (0x' . dechex($uac) . ')';
            }
        }
        if ($computed > 0) {
            $computedNames = [];
            if (($computed & 0x0010) === 0x0010) {
                $computedNames[] = 'LOCKOUT';
            }
            if (($computed & 0x800000) === 0x800000) {
                $computedNames[] = 'PASSWORD_EXPIRED';
            }
            if ($computedNames !== []) {
                $notes[] = 'Computed flags: ' . implode(', ', $computedNames);
            }
        }
        if ($pwdAccountLockedTime !== '') {
            $notes[] = 'OpenLDAP lock time: ' . ($this->ldapGeneralizedTimeToUtc($pwdAccountLockedTime) ?: $pwdAccountLockedTime);
        }

        return [
            'enabled' => $enabled,
            'disabled' => $disabled,
            'locked' => $locked,
            'password_expired' => $passwordExpired,
            'must_change_password' => $mustChange,
            'password_never_expires' => $passwordNeverExpires,
            'account_expired' => $accountExpired,
            'badges' => $badges,
            'notes' => $notes,
        ];
    }

    /** @return list<string> */
    private function userAccountControlFlags(int $uac): array
    {
        $map = [
            0x0001 => 'SCRIPT',
            0x0002 => 'ACCOUNTDISABLE',
            0x0008 => 'HOMEDIR_REQUIRED',
            0x0010 => 'LOCKOUT',
            0x0020 => 'PASSWD_NOTREQD',
            0x0040 => 'PASSWD_CANT_CHANGE',
            0x0080 => 'ENCRYPTED_TEXT_PWD_ALLOWED',
            0x0100 => 'TEMP_DUPLICATE_ACCOUNT',
            0x0200 => 'NORMAL_ACCOUNT',
            0x0800 => 'INTERDOMAIN_TRUST_ACCOUNT',
            0x1000 => 'WORKSTATION_TRUST_ACCOUNT',
            0x2000 => 'SERVER_TRUST_ACCOUNT',
            0x10000 => 'DONT_EXPIRE_PASSWORD',
            0x20000 => 'MNS_LOGON_ACCOUNT',
            0x40000 => 'SMARTCARD_REQUIRED',
            0x80000 => 'TRUSTED_FOR_DELEGATION',
            0x100000 => 'NOT_DELEGATED',
            0x200000 => 'USE_DES_KEY_ONLY',
            0x400000 => 'DONT_REQ_PREAUTH',
            0x800000 => 'PASSWORD_EXPIRED',
            0x1000000 => 'TRUSTED_TO_AUTH_FOR_DELEGATION',
            0x04000000 => 'PARTIAL_SECRETS_ACCOUNT',
        ];
        $names = [];
        foreach ($map as $bit => $label) {
            if (($uac & $bit) === $bit) {
                $names[] = $label;
            }
        }

        return $names;
    }

    private function cnFromDn(string $dn): string
    {
        if (preg_match('/^CN=([^,]+)/i', $dn, $matches) === 1) {
            return str_replace('\\', '', $matches[1]);
        }

        return '';
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
        foreach (['mail', 'displayName', 'memberOf', 'userPrincipalName', 'cn', 'objectClass', 'sAMAccountName', 'uid'] as $required) {
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
     * @param array<string, mixed> $server
     * @return list<string>
     */
    private function connectionContext(array $server): array
    {
        $host = trim((string) ($server['server'] ?? ''));
        $port = (int) ($server['port'] ?? 389);
        $protocol = ($server['protocol'] ?? 'ldap') === 'ldaps' ? 'ldaps' : 'ldap';
        $timeout = max(1, min(60, (int) ($server['timeout'] ?? 30)));
        $startTls = ($server['tls'] ?? '0') === '1' && $protocol !== 'ldaps';

        return [
            'URI: ' . $protocol . '://' . $host . ':' . $port,
            'Timeout: ' . $timeout . 's',
            'StartTLS: ' . ($startTls ? 'yes' : 'no'),
            'Follow referrals: ' . (($server['referrals'] ?? '0') === '1' ? 'yes' : 'no'),
        ];
    }

    /**
     * @param array<string, mixed> $server
     */
    private function userFilterFor(array $server, string $username): string
    {
        $filterTemplate = trim((string) ($server['user_filter'] ?? '(sAMAccountName={username})'));
        if ($filterTemplate === '') {
            $filterTemplate = '(sAMAccountName={username})';
        }

        return str_replace('{username}', $this->escapeFilter($username), $filterTemplate);
    }

    /**
     * @param \LDAP\Connection|resource|null $connection
     * @param list<string> $context
     */
    private function formatLdapFailure(string $prefix, $connection = null, array $context = []): string
    {
        $lines = [rtrim($prefix)];
        foreach ($context as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $hasConnection = $connection !== null
            && (is_resource($connection) || $connection instanceof \LDAP\Connection);

        if ($hasConnection) {
            $errno = @ldap_errno($connection);
            $error = trim((string) @ldap_error($connection));
            if (is_int($errno) && $errno !== 0) {
                $lines[] = 'LDAP error ' . $errno . ' (' . ($error !== '' ? $error : 'unknown') . ').';
            } elseif ($error !== '' && strcasecmp($error, 'Success') !== 0) {
                $lines[] = 'LDAP error: ' . $error . '.';
            }

            $diagnostic = $this->ldapOptionString(
                $connection,
                defined('LDAP_OPT_DIAGNOSTIC_MESSAGE') ? LDAP_OPT_DIAGNOSTIC_MESSAGE : 0x0032
            );
            $errorString = $this->ldapOptionString(
                $connection,
                defined('LDAP_OPT_ERROR_STRING') ? LDAP_OPT_ERROR_STRING : 0x0032
            );
            if ($errorString !== '' && strcasecmp($errorString, $diagnostic) !== 0 && stripos($diagnostic, $errorString) === false) {
                $diagnostic = trim($diagnostic . ($diagnostic !== '' ? "\n" : '') . $errorString);
            }
            if ($diagnostic !== '') {
                $lines[] = 'Diagnostic: ' . $diagnostic;
                $hint = $this->activeDirectoryBindHint($diagnostic);
                if ($hint !== '') {
                    $lines[] = 'Meaning: ' . $hint;
                }
            }

            $matchedDn = $this->ldapOptionString(
                $connection,
                defined('LDAP_OPT_MATCHED_DN') ? LDAP_OPT_MATCHED_DN : 0x0033
            );
            if ($matchedDn !== '') {
                $lines[] = 'Matched DN: ' . $matchedDn;
            }
        }

        $phpLast = error_get_last();
        if (is_array($phpLast)) {
            $phpMsg = trim((string) ($phpLast['message'] ?? ''));
            if ($phpMsg !== '' && stripos($phpMsg, 'ldap') !== false) {
                $joined = implode("\n", $lines);
                if (stripos($joined, $phpMsg) === false) {
                    $lines[] = 'PHP: ' . $phpMsg;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param \LDAP\Connection|resource $connection
     */
    private function ldapOptionString($connection, int $option): string
    {
        $value = null;
        if (!@ldap_get_option($connection, $option, $value) || $value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function activeDirectoryBindHint(string $diagnostic): string
    {
        if (preg_match('/\bdata\s+([0-9a-fA-F]+)\b/', $diagnostic, $matches) !== 1) {
            return '';
        }

        $code = strtolower($matches[1]);

        return match ($code) {
            '525' => 'User not found (data 525). Check the username, bind DN, or user DN template.',
            '52e' => 'Invalid credentials (data 52e). Wrong password, or the bind DN / UPN is not what AD expects.',
            '530' => 'Logon hours restriction (data 530). The account is not allowed to sign in at this time.',
            '531' => 'Not permitted to log on from this workstation (data 531).',
            '532' => 'Password expired (data 532). Reset the password in Active Directory.',
            '533' => 'Account disabled in Active Directory (data 533).',
            '534' => 'The account is not allowed this logon type (data 534).',
            '701' => 'Account expired in Active Directory (data 701).',
            '773' => 'Password must be reset in Active Directory (data 773).',
            '775' => 'Account is locked out in Active Directory (data 775).',
            default => 'Active Directory extended error data ' . $code . '.',
        };
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
