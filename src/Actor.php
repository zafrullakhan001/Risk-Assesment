<?php

declare(strict_types=1);

namespace RiskAssessment;

/**
 * Snapshot of the logged-in user for attribution (local or LDAP).
 */
final class Actor
{
    /**
     * @param array<string, mixed> $user
     * @return array{
     *   user_id: int,
     *   username: string,
     *   display_name: string,
     *   auth_source: string,
     *   email: string,
     *   label: string
     * }
     */
    public static function fromUser(array $user): array
    {
        $username = trim((string) ($user['username'] ?? ''));
        $displayName = trim((string) ($user['display_name'] ?? ''));
        $authSource = strtolower(trim((string) ($user['auth_source'] ?? 'local')));
        if ($authSource !== 'ldap') {
            $authSource = 'local';
        }

        return [
            'user_id' => (int) ($user['id'] ?? 0),
            'username' => $username,
            'display_name' => $displayName,
            'auth_source' => $authSource,
            'email' => trim((string) ($user['email'] ?? '')),
            'label' => self::formatLabel($displayName, $username, $authSource),
        ];
    }

    public static function formatLabel(string $displayName, string $username, string $authSource = ''): string
    {
        $displayName = trim($displayName);
        $username = trim($username);
        $authSource = strtolower(trim($authSource));

        if ($displayName !== '' && $username !== '' && strcasecmp($displayName, $username) !== 0) {
            $label = $displayName . ' (' . $username . ')';
        } elseif ($displayName !== '') {
            $label = $displayName;
        } elseif ($username !== '') {
            $label = $username;
        } else {
            $label = 'Unknown user';
        }

        if ($authSource === 'ldap' || $authSource === 'local') {
            $label .= ' · ' . $authSource;
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $row Keys may be updated_by_* or actor_*
     */
    public static function labelFromRow(array $row, string $prefix = 'updated_by'): string
    {
        $username = trim((string) ($row[$prefix . '_username'] ?? $row['actor_username'] ?? ''));
        $displayName = trim((string) ($row[$prefix . '_display_name'] ?? $row['actor_display_name'] ?? ''));
        $authSource = trim((string) ($row[$prefix . '_auth_source'] ?? $row['actor_auth_source'] ?? ''));

        if ($username === '' && $displayName === '') {
            return '';
        }

        return self::formatLabel($displayName, $username, $authSource);
    }

    /**
     * Prefill evaluator identity from the signed-in user when the form is empty.
     *
     * @param array<string, mixed> $user
     * @return array{name: string, email: string}
     */
    public static function evaluatorDefaults(array $user): array
    {
        $actor = self::fromUser($user);
        $name = $actor['display_name'] !== '' ? $actor['display_name'] : $actor['username'];

        return [
            'name' => $name,
            'email' => $actor['email'],
        ];
    }
}
