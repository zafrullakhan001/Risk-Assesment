<?php

declare(strict_types=1);

namespace RiskAssessment\SharePoint;

/**
 * Parses catalog Find operators the same way as the browser FuzzySearch parser.
 */
final class SharePointCatalogQuery
{
    /**
     * @return array{
     *   words: list<string>,
     *   phrases: list<string>,
     *   excludes: list<string>,
     *   extensions: list<string>,
     *   types: list<string>,
     *   person: string,
     *   modified_by: string,
     *   created_by: string,
     *   paths: list<string>,
     *   has: list<string>,
     *   lacks: list<string>,
     *   tags: list<string>
     * }
     */
    public static function parse(string $raw): array
    {
        $empty = [
            'words' => [],
            'phrases' => [],
            'excludes' => [],
            'extensions' => [],
            'types' => [],
            'person' => '',
            'modified_by' => '',
            'created_by' => '',
            'paths' => [],
            'has' => [],
            'lacks' => [],
            'tags' => [],
        ];
        $text = trim($raw);
        if ($text === '') {
            return $empty;
        }

        $out = $empty;
        $text = (string) preg_replace_callback(
            '/\b(ext|extension|type|person|modified|modifiedby|mod|created|createdby|author|path|in|has|contain|contains|lacks|missing|without|tag|tags):"([^"]*)"/iu',
            static function (array $match) use (&$out): string {
                self::applyPrefixed($out, (string) $match[1], (string) $match[2]);

                return ' ';
            },
            $text
        );

        if (!preg_match_all('/(-?)"([^"]*)"|(\S+)/u', (string) $text, $matches, PREG_SET_ORDER)) {
            return $out;
        }

        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '' && ($match[3] ?? '') === '') {
                $phrase = mb_strtolower(trim((string) $match[2]));
                if ($phrase === '') {
                    continue;
                }
                if (($match[1] ?? '') === '-') {
                    $out['excludes'][] = $phrase;
                } else {
                    $out['phrases'][] = $phrase;
                }
                continue;
            }

            $value = (string) ($match[3] ?? '');
            if ($value === '') {
                continue;
            }
            if (str_starts_with($value, '-') && strlen($value) > 1 && !str_contains($value, ':')) {
                $out['excludes'][] = mb_strtolower(substr($value, 1));
                continue;
            }

            $applied = false;
            foreach ([
                ['ext:', 'extension:'],
                ['type:'],
                ['person:'],
                ['modified:', 'modifiedby:', 'mod:'],
                ['created:', 'createdby:', 'author:'],
                ['path:', 'in:'],
                ['has:', 'contain:', 'contains:'],
                ['lacks:', 'missing:', 'without:'],
                ['tag:', 'tags:'],
            ] as $prefixes) {
                $taken = self::takePrefixed($value, $prefixes);
                if ($taken === null) {
                    continue;
                }
                self::applyPrefixed($out, rtrim($prefixes[0], ':'), $taken);
                $applied = true;
                break;
            }
            if (!$applied) {
                $out['words'][] = mb_strtolower($value);
            }
        }

        foreach (['extensions', 'types', 'paths', 'has', 'lacks', 'tags'] as $listKey) {
            $out[$listKey] = array_values(array_unique($out[$listKey]));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $out
     */
    private static function applyPrefixed(array &$out, string $prefix, string $value): void
    {
        $key = mb_strtolower(trim($prefix));
        $lower = mb_strtolower(trim($value));
        if ($key === 'ext' || $key === 'extension') {
            self::pushCsv($out['extensions'], ltrim($lower, '.'));
            return;
        }
        if ($key === 'type') {
            foreach (preg_split('/,+/', $lower) ?: [] as $part) {
                $norm = self::normalizeType((string) $part);
                if ($norm !== '') {
                    $out['types'][] = $norm;
                }
            }
            return;
        }
        if ($key === 'person') {
            $out['person'] = $lower;
            return;
        }
        if (in_array($key, ['modified', 'modifiedby', 'mod'], true)) {
            $out['modified_by'] = $lower;
            return;
        }
        if (in_array($key, ['created', 'createdby', 'author'], true)) {
            $out['created_by'] = $lower;
            return;
        }
        if ($key === 'path' || $key === 'in') {
            if ($lower !== '') {
                $out['paths'][] = $lower;
            }
            return;
        }
        if (in_array($key, ['has', 'contain', 'contains'], true)) {
            foreach (preg_split('/,+/', $lower) ?: [] as $part) {
                $norm = self::normalizeType((string) $part);
                if ($norm !== '') {
                    $out['has'][] = $norm;
                }
            }
            return;
        }
        if (in_array($key, ['lacks', 'missing', 'without'], true)) {
            foreach (preg_split('/,+/', $lower) ?: [] as $part) {
                $norm = self::normalizeType((string) $part);
                if ($norm !== '') {
                    $out['lacks'][] = $norm;
                }
            }
            return;
        }
        if ($key === 'tag' || $key === 'tags') {
            self::pushCsv($out['tags'], $lower);
        }
    }

    /**
     * @param list<string> $prefixes
     */
    private static function takePrefixed(string $token, array $prefixes): ?string
    {
        $lower = mb_strtolower($token);
        foreach ($prefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return trim(substr($token, strlen($prefix)));
            }
        }

        return null;
    }

    /**
     * @param list<string> $list
     */
    private static function pushCsv(array &$list, string $value): void
    {
        foreach (preg_split('/,+/', $value) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $list[] = $part;
            }
        }
    }

    public static function normalizeType(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = ltrim($value, '.');
        if (in_array($value, ['drawing', 'drawings'], true)) {
            return 'drawings';
        }
        if (in_array($value, ['image', 'images', 'img', 'photo'], true)) {
            return 'images';
        }
        if (in_array($value, ['folder', 'folders'], true)) {
            return 'folders';
        }
        if (in_array($value, ['cad', 'dwg', 'dxf'], true)) {
            return 'cad';
        }
        if (in_array($value, ['visio', 'vsdx', 'vsd'], true)) {
            return 'visio';
        }
        if ($value === 'pdf') {
            return 'pdf';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $parsed
     * @param list<string> $extraTypes
     * @param list<string> $extraExts
     */
    public static function isActive(array $parsed, array $extraTypes = [], array $extraExts = []): bool
    {
        return ($parsed['words'] ?? []) !== []
            || ($parsed['phrases'] ?? []) !== []
            || ($parsed['excludes'] ?? []) !== []
            || ($parsed['extensions'] ?? []) !== []
            || ($parsed['types'] ?? []) !== []
            || ($parsed['paths'] ?? []) !== []
            || ($parsed['has'] ?? []) !== []
            || ($parsed['lacks'] ?? []) !== []
            || ($parsed['tags'] ?? []) !== []
            || trim((string) ($parsed['person'] ?? '')) !== ''
            || trim((string) ($parsed['modified_by'] ?? '')) !== ''
            || trim((string) ($parsed['created_by'] ?? '')) !== ''
            || $extraTypes !== []
            || $extraExts !== [];
    }
}
