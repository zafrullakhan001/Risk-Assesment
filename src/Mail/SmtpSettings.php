<?php

declare(strict_types=1);

namespace RiskAssessment\Mail;

use RiskAssessment\Crypto;
use RiskAssessment\Repositories\SettingsRepository;
use RuntimeException;

/**
 * Load and save outbound SMTP settings in app_settings.
 * Password is encrypted with Crypto (same as LDAP bind / GitHub PAT).
 */
final class SmtpSettings
{
    public const PROVIDER_CUSTOM = 'custom';
    public const PROVIDER_OFFICE365 = 'office365';

    public const ENCRYPTION_NONE = 'none';
    public const ENCRYPTION_SSL = 'ssl';
    public const ENCRYPTION_TLS = 'tls';

    public const OFFICE365_HOST = 'smtp.office365.com';
    public const OFFICE365_PORT = 587;
    public const OFFICE365_ENCRYPTION = self::ENCRYPTION_TLS;

    public const MAX_RECIPIENTS = 20;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Crypto $crypto,
    ) {
    }

    /**
     * @return array{
     *   enabled: bool,
     *   provider: string,
     *   host: string,
     *   port: int,
     *   encryption: string,
     *   username: string,
     *   password: string,
     *   has_password: bool,
     *   from_email: string,
     *   from_name: string
     * }
     */
    public function all(bool $includePassword = false): array
    {
        $provider = $this->normalizeProvider($this->settings->get('smtp_provider', self::PROVIDER_CUSTOM));
        $encryption = $this->normalizeEncryption($this->settings->get('smtp_encryption', self::ENCRYPTION_TLS));
        $port = (int) $this->settings->get('smtp_port', '587');
        if ($port < 1 || $port > 65535) {
            $port = 587;
        }

        $storedPassword = $this->settings->get('smtp_password', '');
        $plainPassword = '';
        if ($includePassword && $storedPassword !== '') {
            try {
                $plainPassword = $this->crypto->decrypt($storedPassword);
            } catch (\Throwable) {
                $plainPassword = '';
            }
        }

        return [
            'enabled' => $this->settings->get('smtp_enabled', '0') === '1',
            'provider' => $provider,
            'host' => trim($this->settings->get('smtp_host', '')),
            'port' => $port,
            'encryption' => $encryption,
            'username' => trim($this->settings->get('smtp_username', '')),
            'password' => $plainPassword,
            'has_password' => $storedPassword !== '',
            'from_email' => trim($this->settings->get('smtp_from_email', '')),
            'from_name' => trim($this->settings->get('smtp_from_name', '')),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('smtp_enabled', '0') === '1'
            && trim($this->settings->get('smtp_host', '')) !== ''
            && trim($this->settings->get('smtp_from_email', '')) !== '';
    }

    /**
     * Config array for SmtpMailer (includes decrypted password).
     *
     * @return array{
     *   host: string,
     *   port: int,
     *   encryption: string,
     *   username: string,
     *   password: string,
     *   from_email: string,
     *   from_name: string
     * }
     */
    public function mailerConfig(): array
    {
        $all = $this->all(true);

        return [
            'host' => $all['host'],
            'port' => $all['port'],
            'encryption' => $all['encryption'],
            'username' => $all['username'],
            'password' => $all['password'],
            'from_email' => $all['from_email'],
            'from_name' => $all['from_name'] !== '' ? $all['from_name'] : 'Risk Register',
        ];
    }

    /**
     * Save from POST-like input. Blank password keeps the stored secret.
     *
     * @param array<string, mixed> $input
     */
    public function save(array $input): void
    {
        $provider = $this->normalizeProvider((string) ($input['smtp_provider'] ?? self::PROVIDER_CUSTOM));
        $enabled = !empty($input['smtp_enabled']);

        if ($provider === self::PROVIDER_OFFICE365) {
            $host = self::OFFICE365_HOST;
            $port = self::OFFICE365_PORT;
            $encryption = self::OFFICE365_ENCRYPTION;
        } else {
            $host = trim((string) ($input['smtp_host'] ?? ''));
            $port = (int) ($input['smtp_port'] ?? 587);
            $encryption = $this->normalizeEncryption((string) ($input['smtp_encryption'] ?? self::ENCRYPTION_TLS));
        }

        $username = trim((string) ($input['smtp_username'] ?? ''));
        $fromEmail = trim((string) ($input['smtp_from_email'] ?? ''));
        $fromName = trim((string) ($input['smtp_from_name'] ?? ''));
        $password = (string) ($input['smtp_password'] ?? '');

        if ($enabled) {
            if ($host === '') {
                throw new RuntimeException('SMTP host is required when email is enabled.');
            }
            if ($fromEmail === '' || !self::isValidEmail($fromEmail)) {
                throw new RuntimeException('A valid From email address is required when email is enabled.');
            }
            if ($port < 1 || $port > 65535) {
                throw new RuntimeException('SMTP port must be between 1 and 65535.');
            }
            // Office 365 needs AUTH LOGIN; Custom SMTP may be an open relay (host + port only).
            if ($provider === self::PROVIDER_OFFICE365) {
                if ($username === '') {
                    throw new RuntimeException('Office 365 requires the full mailbox email as the username.');
                }
                if ($password === '' && !$this->all(false)['has_password']) {
                    throw new RuntimeException('Office 365 requires an SMTP password (or app password).');
                }
            }
        }

        if ($fromEmail !== '' && !self::isValidEmail($fromEmail)) {
            throw new RuntimeException('From email address is not valid.');
        }

        $this->settings->set('smtp_enabled', $enabled ? '1' : '0');
        $this->settings->set('smtp_provider', $provider);
        $this->settings->set('smtp_host', $host);
        $this->settings->set('smtp_port', (string) $port);
        $this->settings->set('smtp_encryption', $encryption);
        $this->settings->set('smtp_username', $username);
        $this->settings->set('smtp_from_email', $fromEmail);
        $this->settings->set('smtp_from_name', mb_substr($fromName, 0, 120));

        if ($password !== '') {
            $this->settings->set('smtp_password', $this->crypto->encrypt($password));
        }
    }

    /**
     * Build a mailer config from form input for a test send.
     * Blank password falls back to the stored secret.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   host: string,
     *   port: int,
     *   encryption: string,
     *   username: string,
     *   password: string,
     *   from_email: string,
     *   from_name: string
     * }
     */
    public function configFromInput(array $input): array
    {
        $provider = $this->normalizeProvider((string) ($input['smtp_provider'] ?? self::PROVIDER_CUSTOM));

        if ($provider === self::PROVIDER_OFFICE365) {
            $host = self::OFFICE365_HOST;
            $port = self::OFFICE365_PORT;
            $encryption = self::OFFICE365_ENCRYPTION;
        } else {
            $host = trim((string) ($input['smtp_host'] ?? ''));
            $port = (int) ($input['smtp_port'] ?? 587);
            $encryption = $this->normalizeEncryption((string) ($input['smtp_encryption'] ?? self::ENCRYPTION_TLS));
        }

        $password = (string) ($input['smtp_password'] ?? '');
        if ($password === '') {
            $password = $this->all(true)['password'];
        }

        $fromEmail = trim((string) ($input['smtp_from_email'] ?? ''));
        $fromName = trim((string) ($input['smtp_from_name'] ?? ''));

        if ($host === '' || $fromEmail === '') {
            throw new RuntimeException('SMTP host and From email are required to send a test.');
        }
        if (!self::isValidEmail($fromEmail)) {
            throw new RuntimeException('From email address is not valid.');
        }
        $username = trim((string) ($input['smtp_username'] ?? ''));
        // Custom open relays need no auth. Office 365 (or custom with a username) needs a password.
        if ($provider === self::PROVIDER_OFFICE365 || $username !== '') {
            if ($password === '') {
                throw new RuntimeException(
                    $provider === self::PROVIDER_OFFICE365
                        ? 'Office 365 requires an SMTP password (or app password). Enter it or save settings first.'
                        : 'SMTP password is required when a username is set. Enter it or save settings first.'
                );
            }
        }

        return [
            'host' => $host,
            'port' => $port > 0 ? $port : 587,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'from_email' => $fromEmail,
            'from_name' => $fromName !== '' ? $fromName : 'Risk Register',
        ];
    }

    /**
     * Accept standard emails and local/dev addresses (e.g. zafar@localhost)
     * where a TLD (.com / any .domain) is optional.
     */
    public static function isValidEmail(string $email): bool
    {
        $email = trim($email);
        if ($email === '' || strlen($email) > 254) {
            return false;
        }
        if (substr_count($email, '@') !== 1) {
            return false;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        // Local SMTP / intranet: user@localhost, user@mailhost, user@127.0.0.1
        return preg_match(
            '/^[^\s@]+@[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/i',
            $email
        ) === 1;
    }

    /**
     * @return list<string>
     */
    public static function normalizeRecipients(string|array|null $emails): array
    {
        $list = [];
        if ($emails === null) {
            return $list;
        }
        if (is_string($emails)) {
            $emails = preg_split('/[,\n;]+/', $emails) ?: [];
        }
        if (!is_array($emails)) {
            return $list;
        }

        $seen = [];
        foreach ($emails as $email) {
            $email = trim((string) $email);
            if ($email === '' || !self::isValidEmail($email)) {
                continue;
            }
            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $list[] = $email;
            if (count($list) >= self::MAX_RECIPIENTS) {
                break;
            }
        }

        return $list;
    }

    public static function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return $provider === self::PROVIDER_OFFICE365 ? self::PROVIDER_OFFICE365 : self::PROVIDER_CUSTOM;
    }

    public static function normalizeEncryption(string $encryption): string
    {
        $encryption = strtolower(trim($encryption));

        return match ($encryption) {
            self::ENCRYPTION_SSL => self::ENCRYPTION_SSL,
            self::ENCRYPTION_NONE => self::ENCRYPTION_NONE,
            default => self::ENCRYPTION_TLS,
        };
    }
}
