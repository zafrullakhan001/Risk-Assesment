<?php
declare(strict_types=1);

/**
 * Convert ServiceNow field arrays [{label, display_value}] into label => value map.
 * Duplicate labels keep the first non-empty value, then later non-empty overwrites.
 *
 * @param list<array{label?: mixed, display_value?: mixed}>|null $fields
 * @return array<string, string>
 */
function fieldsToMap(?array $fields): array
{
    $map = [];
    if ($fields === null) {
        return $map;
    }

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $label = trim((string) ($field['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $value = normalizeDisplayValue($field['display_value'] ?? '');
        if (!array_key_exists($label, $map) || ($map[$label] === '' && $value !== '')) {
            $map[$label] = $value;
        } elseif ($value !== '') {
            $map[$label] = $value;
        }
    }

    return $map;
}

function normalizeDisplayValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        return trim((string) $value);
    }
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    return '';
}

function fieldValue(array $map, string ...$labels): string
{
    foreach ($labels as $label) {
        if (isset($map[$label]) && trim((string) $map[$label]) !== '') {
            return trim((string) $map[$label]);
        }
    }

    return '';
}

function isEmptyish(?string $value): bool
{
    $v = trim((string) $value);
    if ($v === '') {
        return true;
    }

    $lower = strtolower($v);
    $emptyish = ['false', '0', '0.0', '$0.00', 'unknown', 'n/a', 'na', 'null', 'none', '-', '—'];

    return in_array($lower, $emptyish, true);
}

/**
 * @param array<string, string> $map
 * @return array<string, string>
 */
function filterMeaningfulFields(array $map, bool $showAll = false): array
{
    if ($showAll) {
        return $map;
    }

    $out = [];
    foreach ($map as $label => $value) {
        if (!isEmptyish($value)) {
            $out[$label] = $value;
        }
    }

    return $out;
}

/**
 * Prefer first non-empty string.
 */
function firstNonEmpty(string ...$values): string
{
    foreach ($values as $value) {
        if (trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

/**
 * Extract ServiceNow record numbers from free text.
 *
 * @return array{demand: string, story: string, task: string, ddr: string}
 */
function extractRecordNumbers(string $text): array
{
    $found = [
        'demand' => '',
        'story' => '',
        'task' => '',
        'ddr' => '',
    ];

    if (preg_match('/\b(DMND\d+)\b/i', $text, $m)) {
        $found['demand'] = strtoupper($m[1]);
    }
    if (preg_match('/\b(STRY\d+)\b/i', $text, $m)) {
        $found['story'] = strtoupper($m[1]);
    }
    if (preg_match('/\b(TASK\d+)\b/i', $text, $m)) {
        $found['task'] = strtoupper($m[1]);
    }
    if (preg_match('/\b(DDR\d+)\b/i', $text, $m)) {
        $found['ddr'] = strtoupper($m[1]);
    }

    return $found;
}

function servicenowKindFromNumber(string $number): string
{
    $number = strtoupper(trim($number));
    if (str_starts_with($number, 'DMND')) {
        return 'demand';
    }
    if (str_starts_with($number, 'STRY')) {
        return 'story';
    }
    if (str_starts_with($number, 'TASK')) {
        return 'task';
    }
    if (str_starts_with($number, 'DDR')) {
        return 'ddr';
    }

    return '';
}

function servicenowNormalizeInstanceOrigin(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    try {
        return \RiskAssessment\ServiceNow\ServiceNowBrowserSync::normalizeInstanceOrigin($url);
    } catch (Throwable) {
        return '';
    }
}

/**
 * @param array<string, mixed> $parsed
 */
function servicenowInstanceOriginFromParsed(array $parsed): string
{
    $packetMeta = is_array($parsed['packet_meta'] ?? null) ? $parsed['packet_meta'] : [];
    $candidates = [
        (string) ($packetMeta['instance'] ?? ''),
        (string) ($parsed['instance'] ?? ''),
    ];
    foreach ($candidates as $candidate) {
        $origin = servicenowNormalizeInstanceOrigin($candidate);
        if ($origin !== '') {
            return $origin;
        }
    }

    return '';
}

function servicenowTableForRecord(string $kind = '', string $table = '', string $sysClass = '', string $number = ''): string
{
    foreach ([$table, $sysClass] as $candidate) {
        $name = strtolower(trim($candidate));
        if ($name !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1) {
            return $name;
        }
    }

    $resolvedKind = $kind !== '' ? $kind : servicenowKindFromNumber($number);

    return match ($resolvedKind) {
        'demand' => 'dmn_demand',
        'story' => 'rm_story',
        'ddr' => 'sn_tprm_dd_request',
        default => 'task',
    };
}

function servicenowRecordUrl(
    string $instance,
    string $number,
    string $sysId = '',
    string $table = '',
    string $kind = ''
): string {
    $origin = servicenowNormalizeInstanceOrigin($instance);
    $number = strtoupper(trim($number));
    if ($origin === '' || $number === '' || preg_match('/^(?:DMND|STRY|TASK|DDR)\d+$/', $number) !== 1) {
        return '';
    }

    $tableName = servicenowTableForRecord($kind, $table, '', $number);
    $uri = $tableName . '.do';
    $sysId = strtolower(trim($sysId));
    if ($sysId !== '' && preg_match('/^[0-9a-f]{32}$/', $sysId) === 1) {
        $uri .= '?sys_id=' . rawurlencode($sysId);
    } else {
        $uri .= '?sysparm_query=number=' . rawurlencode($number);
    }

    return $origin . '/nav_to.do?uri=' . $uri;
}

/**
 * @param array<string, mixed> $parsed
 * @return array<string, array{kind: string, sys_id: string, table: string}>
 */
function servicenowTicketLookup(array $parsed): array
{
    $out = [];
    $add = static function (array $ticket, string $fallbackKind = '') use (&$out): void {
        $number = strtoupper(trim((string) ($ticket['number'] ?? '')));
        if ($number === '') {
            return;
        }
        $kind = (string) ($ticket['kind'] ?? '');
        if ($kind === '') {
            $kind = $fallbackKind !== '' ? $fallbackKind : servicenowKindFromNumber($number);
        }
        $table = (string) ($ticket['table'] ?? $ticket['sys_class_name'] ?? '');
        $sysId = trim((string) ($ticket['sys_id'] ?? ''));
        $existing = $out[$number] ?? ['kind' => '', 'sys_id' => '', 'table' => ''];
        $out[$number] = [
            'kind' => $kind !== '' ? $kind : $existing['kind'],
            'sys_id' => $sysId !== '' ? $sysId : $existing['sys_id'],
            'table' => $table !== '' ? $table : $existing['table'],
        ];
    };

    foreach (['demand', 'story', 'task', 'ddr'] as $kind) {
        if (is_array($parsed[$kind] ?? null)) {
            $add($parsed[$kind], $kind);
        }
    }
    if (is_array($parsed['related_tickets'] ?? null)) {
        foreach ($parsed['related_tickets'] as $ticket) {
            if (is_array($ticket)) {
                $add($ticket);
            }
        }
    }
    if (is_array($parsed['tickets'] ?? null)) {
        foreach ($parsed['tickets'] as $ticket) {
            if (is_array($ticket)) {
                $add($ticket);
            }
        }
    }

    $ddr = is_array($parsed['ddr'] ?? null) ? $parsed['ddr'] : [];
    $exportMeta = is_array($ddr['export_meta'] ?? null) ? $ddr['export_meta'] : [];
    $ddrNumber = strtoupper(trim((string) ($ddr['number'] ?? '')));
    if ($ddrNumber !== '') {
        $add([
            'number' => $ddrNumber,
            'kind' => 'ddr',
            'sys_id' => (string) ($exportMeta['root_sys_id'] ?? $ddr['sys_id'] ?? ''),
            'table' => (string) ($exportMeta['root_table'] ?? $ddr['table'] ?? $ddr['sys_class_name'] ?? ''),
        ], 'ddr');
    }

    return $out;
}

/**
 * @param array<string, array{kind: string, sys_id: string, table: string}> $lookup
 * @return array{kind: string, sys_id: string, table: string}
 */
function servicenowTicketMeta(array $lookup, string $number, string $kind = '', string $sysId = '', string $table = ''): array
{
    $number = strtoupper(trim($number));
    $hit = $lookup[$number] ?? ['kind' => '', 'sys_id' => '', 'table' => ''];

    return [
        'kind' => $kind !== '' ? $kind : (string) ($hit['kind'] !== '' ? $hit['kind'] : servicenowKindFromNumber($number)),
        'sys_id' => $sysId !== '' ? $sysId : (string) $hit['sys_id'],
        'table' => $table !== '' ? $table : (string) $hit['table'],
    ];
}

function nowUtc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * Convert a stored UTC "Y-m-d H:i:s" value to an unambiguous ISO-8601 UTC string for <time datetime>.
 */
function dossierUtcIso(?string $utc): string
{
    $utc = trim((string) $utc);
    if ($utc === '') {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
    if ($dt instanceof DateTimeImmutable) {
        return $dt->format('Y-m-d\TH:i:s\Z');
    }

    // Already ISO-ish with Z or offset — pass through when parseable.
    try {
        $parsed = new DateTimeImmutable($utc);
        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
        return '';
    }
}

function dossierFilenameSlug(string $title, string $fallback = 'dossier'): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $title) ?? $fallback;
    $slug = trim($slug, '-._');
    if ($slug === '') {
        $slug = $fallback;
    }
    if (strlen($slug) > 60) {
        $slug = substr($slug, 0, 60);
    }

    return $slug;
}

/**
 * Snapshot of the signed-in user who created a Ticket Dossier project.
 *
 * @param array<string, mixed>|null $user
 * @return array{
 *   owner_user_id: int|null,
 *   owner_username: string,
 *   owner_display_name: string,
 *   owner_auth_source: string
 * }
 */
function projectOwnerFromUser(?array $user): array
{
    if ($user === null) {
        return [
            'owner_user_id' => null,
            'owner_username' => '',
            'owner_display_name' => '',
            'owner_auth_source' => '',
        ];
    }

    $actor = \RiskAssessment\Actor::fromUser($user);

    return [
        'owner_user_id' => $actor['user_id'] > 0 ? $actor['user_id'] : null,
        'owner_username' => $actor['username'],
        'owner_display_name' => $actor['display_name'],
        'owner_auth_source' => $actor['auth_source'],
    ];
}

/**
 * Short owner name for lists and chips (display name, else username).
 *
 * @param array<string, mixed> $project
 */
function projectOwnerName(array $project): string
{
    $display = trim((string) ($project['owner_display_name'] ?? ''));
    if ($display !== '') {
        return $display;
    }

    return trim((string) ($project['owner_username'] ?? ''));
}

/**
 * Full attribution title for tooltips.
 *
 * @param array<string, mixed> $project
 */
function projectOwnerTitle(array $project): string
{
    $label = \RiskAssessment\Actor::labelFromRow($project, 'owner');
    if ($label === '') {
        return '';
    }

    return 'Project owner (created by): ' . $label;
}

function kindLabel(string $kind): string
{
    return match ($kind) {
        'ddr' => 'Due Diligence',
        'demand' => 'Demand',
        'story' => 'Story',
        'task' => 'Task',
        'packet' => 'Task packet',
        'attachment' => 'Attachment',
        'related' => 'Related tickets',
        default => ucfirst($kind),
    };
}

function kindEmoji(string $kind): string
{
    return match ($kind) {
        'ddr' => '🛡️',
        'demand' => '🎯',
        'story' => '📖',
        'task' => '✅',
        'packet' => '📦',
        'attachment' => '📎',
        'related' => '🔗',
        'vendor' => '🏢',
        'assessments' => '📝',
        'overview' => '🔭',
        'files' => '📎',
        default => '📄',
    };
}

function sectionTitle(string $section): string
{
    return match ($section) {
        'overview' => kindEmoji('overview') . ' Overview',
        'demand' => kindEmoji('demand') . ' Demand',
        'story' => kindEmoji('story') . ' Story',
        'task' => kindEmoji('task') . ' Task',
        'ddr' => kindEmoji('ddr') . ' Due Diligence',
        'vendor' => kindEmoji('vendor') . ' Vendor',
        'assessments' => kindEmoji('assessments') . ' Assessments',
        'related' => kindEmoji('related') . ' Related tickets',
        'files' => kindEmoji('files') . ' Original files',
        default => kindEmoji($section) . ' ' . ucfirst($section),
    };
}

/**
 * @param array<string, mixed> $parsed
 * @return list<string>
 */
function availableSections(array $parsed): array
{
    $sections = ['overview'];
    foreach (['demand', 'story', 'task', 'ddr', 'vendor', 'assessments'] as $key) {
        if (!empty($parsed[$key]) && is_array($parsed[$key])) {
            if ($key === 'assessments') {
                $ext = $parsed[$key]['external'] ?? [];
                $int = $parsed[$key]['internal'] ?? [];
                if ($ext !== [] || $int !== []) {
                    $sections[] = $key;
                }
            } elseif ($key === 'vendor') {
                $fields = $parsed[$key]['fields'] ?? $parsed[$key];
                if (is_array($fields) && $fields !== []) {
                    $sections[] = $key;
                }
            } else {
                $sections[] = $key;
            }
        }
    }
    if (!empty($parsed['related_tickets']) && is_array($parsed['related_tickets'])) {
        $sections[] = 'related';
    }
    $sections[] = 'files';

    return $sections;
}
