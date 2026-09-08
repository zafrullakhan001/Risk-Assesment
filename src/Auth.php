<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\SettingsRepository;
use RiskAssessment\Repositories\UserRepository;
use RuntimeException;

final class Auth
{
    public const SESSION_USER = 'user_id';
    public const METHOD_AUTO = 'auto';
    public const METHOD_LOCAL = 'local';
    public const METHOD_LDAP = 'ldap';
    public const DEFAULT_ADMIN_USERNAME = 'admin';
    public const DEFAULT_ADMIN_PASSWORD = 'admin123';

    private const MAX_FAILURES = 5;
    private const LOCKOUT_SECONDS = 60;

    private static ?self $instance = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
        private readonly LdapAuth $ldap,
    ) {
        self::$instance = $this;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Authentication has not been bootstrapped.');
        }

        return self::$instance;
    }

    public function users(): UserRepository
    {
        return $this->users;
    }

    public function settings(): SettingsRepository
    {
        return $this->settings;
    }

    public function ldap(): LdapAuth
    {
        return $this->ldap;
    }

    public function localEnabled(): bool
    {
        return $this->settings->get('local_auth_enabled', '1') === '1';
    }

    public function registrationEnabled(): bool
    {
        return $this->localEnabled() && $this->settings->get('local_registration_enabled', '0') === '1';
    }

    public function ldapEnabled(): bool
    {
        return $this->ldap->isEnabled();
    }

    public function needsSetup(): bool
    {
        return $this->users->count() === 0;
    }

    /** @return array<string, mixed>|null */
    public function currentUser(): ?array
    {
        $id = (int) ($_SESSION[self::SESSION_USER] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $user = $this->users->findById($id);
        if ($user === null || $user['is_disabled'] || !$user['is_approved']) {
            $this->logout();

            return null;
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public function requireAuth(): array
    {
        $user = $this->currentUser();
        if ($user !== null) {
            return $user;
        }

        if ($this->wantsJson()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . $this->publicUrl('login.php') . '?next=' . urlencode($this->currentRequestTarget()));
        exit;
    }

    /** @return array<string, mixed> */
    public function requireAdmin(): array
    {
        $user = $this->requireAuth();
        if (empty($user['is_admin'])) {
            if ($this->wantsJson()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Admin access required'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            http_response_code(403);
            echo 'Admin access required.';
            exit;
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function login(string $username, string $password, string $method = self::METHOD_AUTO, bool $remember = false, int $ldapServerIndex = 0): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new RuntimeException('Username and password are required.');
        }

        $this->assertNotLocked();

        $method = strtolower($method);
        if (!in_array($method, [self::METHOD_AUTO, self::METHOD_LOCAL, self::METHOD_LDAP], true)) {
            $method = self::METHOD_AUTO;
        }

        $usedLdap = false;
        $user = null;
        $ldapError = null;

        $preferLocal = $method === self::METHOD_AUTO && str_contains(strtolower($username), '@localhost');
        $tryLdap = !$preferLocal && $method !== self::METHOD_LOCAL && $this->ldapEnabled();
        $tryLocal = $method !== self::METHOD_LDAP && $this->localEnabled();

        if ($method === self::METHOD_LDAP && !$this->ldapEnabled()) {
            throw new RuntimeException('LDAP authentication is not enabled.');
        }
        if ($method === self::METHOD_LOCAL && !$this->localEnabled()) {
            throw new RuntimeException('Local authentication is not enabled.');
        }

        if ($tryLdap) {
            try {
                $ldapUser = $this->ldap->authenticate($username, $password, $ldapServerIndex, $method === self::METHOD_AUTO);
                $user = $this->users->upsertLdapUser(
                    $ldapUser,
                    $this->settings->get('ldap_auto_create_users', '1') === '1',
                    $this->settings->get('ldap_auto_update_users', '1') === '1',
                    $this->settings->get('ldap_auto_approve', '1') === '1'
                );
                $usedLdap = true;
            } catch (RuntimeException $exception) {
                $ldapError = $exception->getMessage();
                if ($method === self::METHOD_LDAP) {
                    $this->recordFailure($username, 'ldap', $ldapError);
                    throw $exception;
                }
            }
        }

        if ($user === null && $tryLocal) {
            $user = $this->users->findByUsernameOrEmail($username);
            if ($user === null || $user['auth_source'] === 'ldap' || !password_verify($password, (string) $user['password_hash'])) {
                $user = null;
            }
        }

        if ($user === null) {
            $message = $method === self::METHOD_AUTO && $ldapError !== null && !$tryLocal
                ? $ldapError
                : 'Invalid username or password.';
            $this->recordFailure($username, $method, $ldapError);
            throw new RuntimeException($message);
        }

        if ($user['is_disabled']) {
            $this->users->logAudit('auth.login_denied', null, $username, (int) $user['id'], (string) $user['username'], ['reason' => 'disabled']);
            throw new RuntimeException('This account is disabled. Contact an administrator.');
        }
        if (!$user['is_approved']) {
            $this->users->logAudit('auth.login_denied', null, $username, (int) $user['id'], (string) $user['username'], ['reason' => 'pending']);
            throw new RuntimeException('This account is pending administrator approval.');
        }

        $this->establishSession($user, $remember);
        $this->users->markLogin((int) $user['id']);
        $this->clearFailures();
        $this->users->logAudit(
            'auth.login',
            (int) $user['id'],
            (string) $user['username'],
            (int) $user['id'],
            (string) $user['username'],
            ['method' => $usedLdap ? 'ldap' : 'local']
        );

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function register(string $username, string $email, string $password, string $confirm): array
    {
        if (!$this->registrationEnabled()) {
            throw new RuntimeException('Local registration is disabled.');
        }

        $username = trim($username);
        $email = trim($email);
        $this->assertValidUsername($username);
        $this->assertValidEmail($email);
        if ($password !== $confirm) {
            throw new RuntimeException('Password confirmation does not match.');
        }
        $strength = self::validatePasswordStrength($password);
        if ($strength !== null) {
            throw new RuntimeException($strength);
        }
        if ($this->users->usernameExists($username)) {
            throw new RuntimeException('That username is already taken.');
        }
        if ($this->users->emailExists($email)) {
            throw new RuntimeException('That email is already registered.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to create the account.');
        }

        $id = $this->users->createLocal($username, $email, $hash, false, false, '', '', null, 'self-registration');
        $this->users->logAudit('user.registered', $id, $username, $id, $username, ['source' => 'self_register']);

        $user = $this->users->findById($id);
        if ($user === null) {
            throw new RuntimeException('Unable to load the new account.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function createFirstAdmin(string $username, string $email, string $password, string $confirm): array
    {
        if (!$this->needsSetup()) {
            throw new RuntimeException('An administrator account already exists.');
        }

        $username = trim($username);
        $email = trim($email);
        $this->assertValidUsername($username);
        $this->assertValidEmail($email);
        if ($password !== $confirm) {
            throw new RuntimeException('Password confirmation does not match.');
        }
        $strength = self::validatePasswordStrength($password);
        if ($strength !== null) {
            throw new RuntimeException($strength);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to create the administrator account.');
        }

        $id = $this->users->createLocal(
            $username,
            $email,
            $hash,
            true,
            true,
            $username,
            'First administrator',
            null,
            'system',
            true
        );
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new RuntimeException('Unable to load the administrator account.');
        }

        $this->establishSession($user, false);
        $this->users->markLogin($id);
        $this->users->logAudit('user.bootstrap_admin', $id, $username, $id, $username, []);

        return $user;
    }

    public function logout(): void
    {
        $user = null;
        $id = (int) ($_SESSION[self::SESSION_USER] ?? 0);
        if ($id > 0) {
            $user = $this->users->findById($id);
        }

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
        Session::clearCookie();
        Session::start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        if (is_array($user)) {
            $this->users->logAudit(
                'auth.logout',
                (int) $user['id'],
                (string) $user['username'],
                (int) $user['id'],
                (string) $user['username']
            );
        }
    }

    public function changePassword(int $userId, string $current, string $next, string $confirm): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('Account not found.');
        }
        if ($user['auth_source'] !== 'local') {
            throw new RuntimeException('Directory accounts change their password in LDAP, not here.');
        }
        if (!password_verify($current, (string) $user['password_hash'])) {
            throw new RuntimeException('Current password is incorrect.');
        }
        if ($next !== $confirm) {
            throw new RuntimeException('Password confirmation does not match.');
        }
        $strength = self::validatePasswordStrength($next);
        if ($strength !== null) {
            throw new RuntimeException($strength);
        }
        $hash = password_hash($next, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to update the password.');
        }
        $this->users->setPassword($userId, $hash);
        $this->users->logAudit('user.password_changed', $userId, (string) $user['username'], $userId, (string) $user['username']);
    }

    /** @param array<string, mixed> $user */
    public static function usesDefaultPassword(array $user): bool
    {
        return ($user['username'] ?? '') === self::DEFAULT_ADMIN_USERNAME
            && ($user['auth_source'] ?? '') === 'local'
            && password_verify(self::DEFAULT_ADMIN_PASSWORD, (string) ($user['password_hash'] ?? ''));
    }

    /**
     * Local bootstrap superadmin — unrestricted Risk Register manage/edit (delete any project).
     * Other admins keep normal owner/editor boundaries.
     *
     * @param array<string, mixed> $user
     */
    public static function isSuperAdmin(array $user): bool
    {
        return !empty($user['is_superadmin']) && !empty($user['is_admin']);
    }

    public static function validatePasswordStrength(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (preg_match('/[A-Z]/', $password) !== 1) {
            return 'Password must include an uppercase letter.';
        }
        if (preg_match('/[0-9]/', $password) !== 1) {
            return 'Password must include a number.';
        }
        if (preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            return 'Password must include a special character.';
        }

        return null;
    }

    public function publicUrl(string $file): string
    {
        return $this->publicPrefix() . ltrim($file, '/');
    }

    public function publicPrefix(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        return (str_contains($script, '/admin/') || str_contains($script, '/ticket-dossier/'))
            ? '../'
            : '';
    }

    public function safeNext(string $next): string
    {
        $next = trim($next);
        if ($next === '' || str_starts_with($next, 'http') || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return 'index.php';
        }
        if ($next[0] === '/') {
            return 'index.php';
        }
        // Use ~ delimiter: the pattern allows "#" in the query/hash part.
        if (preg_match('~^(?:admin/|ticket-dossier/)?[A-Za-z0-9._-]+\.php(?:[?#][A-Za-z0-9._/?&=%-]*)?$~', $next) !== 1) {
            return 'index.php';
        }

        return $next;
    }

    /** @param array<string, mixed> $user */
    private function establishSession(array $user, bool $remember): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Session::start();
        }
        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER] = (int) $user['id'];
        $_SESSION['username'] = (string) $user['username'];
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        Session::writeCookie($remember ? 30 * 24 * 60 * 60 : 24 * 60 * 60);
    }

    private function assertValidUsername(string $username): void
    {
        if (strlen($username) < 3 || strlen($username) > 80) {
            throw new RuntimeException('Username must be between 3 and 80 characters.');
        }
        if (preg_match('/^[A-Za-z0-9._@-]+$/', $username) !== 1) {
            throw new RuntimeException('Username may contain letters, numbers, and . _ @ - only.');
        }
    }

    private function assertValidEmail(string $email): void
    {
        $isLocalhost = str_ends_with(strtolower($email), '@localhost');
        if (!$isLocalhost && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Enter a valid email address.');
        }
    }

    private function assertNotLocked(): void
    {
        $until = (int) ($_SESSION['login_lock_until'] ?? 0);
        if ($until > time()) {
            $wait = $until - time();
            throw new RuntimeException('Too many failed sign-in attempts. Try again in ' . $wait . ' seconds.');
        }
    }

    private function recordFailure(string $username, string $method, ?string $detail): void
    {
        $failures = (int) ($_SESSION['login_failures'] ?? 0) + 1;
        $_SESSION['login_failures'] = $failures;
        if ($failures >= self::MAX_FAILURES) {
            $_SESSION['login_lock_until'] = time() + self::LOCKOUT_SECONDS;
        }
        $this->users->logAudit('auth.login_failed', null, $username, null, $username, [
            'method' => $method,
            'detail' => $detail,
        ]);
    }

    private function clearFailures(): void
    {
        unset($_SESSION['login_failures'], $_SESSION['login_lock_until']);
    }

    private function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $requested = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $action = (string) ($_POST['action'] ?? '');

        return str_contains($accept, 'application/json')
            || strcasecmp($requested, 'XMLHttpRequest') === 0
            || str_starts_with($action, 'save_');
    }

    private function currentRequestTarget(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $inAdmin = str_contains($script, '/admin/');
        $inTicketDossier = str_contains($script, '/ticket-dossier/');
        $file = basename($script);
        // Directory URLs (/RiskRegister/, /public/, /admin/) leave REQUEST_URI without a .php
        // basename; SCRIPT_NAME always names the front controller being executed.
        if ($file === '' || !str_ends_with(strtolower($file), '.php')) {
            $file = 'index.php';
        }
        if ($inAdmin) {
            $file = 'admin/' . $file;
        } elseif ($inTicketDossier) {
            $file = 'ticket-dossier/' . $file;
        }

        $query = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $file .= '?' . $query;
        }

        return $file;
    }
}
