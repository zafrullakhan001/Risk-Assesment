<?php

declare(strict_types=1);

/**
 * Applies a user correction to an existing scalar value in parsed_json.
 *
 * Paths are JSON arrays (for example ["demand","fields","Priority"]). Only
 * existing values below displayed dossier roots can be changed; this prevents
 * the generic editor from creating arbitrary document structures.
 */
final class DossierFieldEditor
{
    private const MAX_VALUE_BYTES = 50000;

    /** @var array<string, true> */
    private const EDITABLE_ROOTS = [
        'overview' => true,
        'demand' => true,
        'story' => true,
        'task' => true,
        'ddr' => true,
        'vendor' => true,
        'related_tickets' => true,
        'assessments' => true,
    ];

    /** @var array<string, true> */
    private const HIDDEN_BRANCHES = [
        'attachments' => true,
        'export_meta' => true,
        'metadata' => true,
        'packet_meta' => true,
    ];

    /**
     * @param list<string|int> $path
     */
    public static function update(int $projectId, array $path, string $value): void
    {
        if ($projectId <= 0) {
            throw new InvalidArgumentException('Invalid project.');
        }
        self::validatePath($path);
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            throw new InvalidArgumentException('The corrected value is too long.');
        }

        $project = ProjectRepository::find($projectId);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        $parsed = json_decode((string) ($project['parsed_json'] ?? ''), true);
        if (!is_array($parsed)) {
            throw new RuntimeException('This dossier has no editable parsed data.');
        }

        $cursor =& $parsed;
        $last = array_pop($path);
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                throw new InvalidArgumentException('That dossier field no longer exists.');
            }
            $cursor =& $cursor[$segment];
        }
        if (!is_array($cursor) || !array_key_exists($last, $cursor)) {
            throw new InvalidArgumentException('That dossier field no longer exists.');
        }
        if (is_array($cursor[$last]) || is_object($cursor[$last])) {
            throw new InvalidArgumentException('Only individual dossier values can be edited.');
        }
        $cursor[$last] = str_replace("\0", '', $value);
        unset($cursor);

        self::persist($project, $parsed, [...$path, $last]);
    }

    /**
     * @param list<string|int> $path
     */
    private static function validatePath(array $path): void
    {
        if ($path === [] || count($path) > 12 || !is_string($path[0]) || !isset(self::EDITABLE_ROOTS[$path[0]])) {
            throw new InvalidArgumentException('Invalid dossier field.');
        }

        foreach ($path as $index => $segment) {
            if (!is_string($segment) && !is_int($segment)) {
                throw new InvalidArgumentException('Invalid dossier field.');
            }
            if (is_string($segment)) {
                if ($segment === '' || strlen($segment) > 250 || str_contains($segment, "\0")) {
                    throw new InvalidArgumentException('Invalid dossier field.');
                }
                if ($index > 0 && isset(self::HIDDEN_BRANCHES[$segment])) {
                    throw new InvalidArgumentException('That dossier value is not editable.');
                }
            } elseif ($segment < 0 || $segment > 10000) {
                throw new InvalidArgumentException('Invalid dossier field.');
            }
        }
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $parsed
     * @param list<string|int> $path
     */
    private static function persist(array $project, array $parsed, array $path): void
    {
        $columns = [];
        $pathKey = implode('.', array_map(static fn (string|int $part): string => (string) $part, $path));
        $value = self::valueAt($parsed, $path);

        if ($pathKey === 'overview.title') {
            $title = trim($value);
            if ($title === '') {
                throw new InvalidArgumentException('Project name cannot be empty.');
            }
            $columns['title'] = substr($title, 0, 200);
            $parsed['overview']['title'] = $columns['title'];
        } elseif ($pathKey === 'overview.vendor') {
            $columns['vendor'] = substr(trim($value), 0, 200);
            $parsed['overview']['vendor'] = $columns['vendor'];
        }

        foreach (['demand', 'story', 'task', 'ddr'] as $kind) {
            if ($pathKey === $kind . '.number') {
                $columns[$kind . '_number'] = substr(trim($value), 0, 200);
                $parsed[$kind]['number'] = $columns[$kind . '_number'];
            } elseif ($pathKey === $kind . '.state') {
                $columns[$kind . '_state'] = substr(trim($value), 0, 200);
                $parsed[$kind]['state'] = $columns[$kind . '_state'];
            }
        }

        ProjectRepository::updateFieldCorrection((int) $project['id'], $parsed, $columns);
    }

    /**
     * @param array<string, mixed> $parsed
     * @param list<string|int> $path
     */
    private static function valueAt(array $parsed, array $path): string
    {
        $value = $parsed;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return '';
            }
            $value = $value[$segment];
        }

        return is_scalar($value) || $value === null ? (string) $value : '';
    }
}
