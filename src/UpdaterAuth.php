<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\SettingsRepository;
use RuntimeException;

final class UpdaterAuth
{
    public const SESSION_KEY = 'updater_authenticated';
    private const SETTING_KEY = 'updater_page_password';

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {
    }

    public function hasPassword(): bool
    {
        return $this->settings->get(self::SETTING_KEY, '') !== '';
    }

    public function isAuthenticated(): bool
    {
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public function setPassword(string $password, string $confirm): void
    {
        $password = trim($password);
        if (strlen($password) < 8) {
            throw new RuntimeException('Choose a password with at least 8 characters.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException('Password confirmation does not match.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to store the updater password.');
        }

        $this->settings->set(self::SETTING_KEY, $hash);
        $_SESSION[self::SESSION_KEY] = true;
    }

    public function login(string $password): bool
    {
        $hash = $this->settings->get(self::SETTING_KEY, '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = true;
        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
