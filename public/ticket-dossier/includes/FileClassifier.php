<?php
declare(strict_types=1);

final class FileClassifier
{
    /**
     * Prefer content detection; fall back to filename hints.
     *
     * @return 'ddr'|'demand'|'story'|'task'|null
     */
    public static function classify(string $path, string $originalName): ?string
    {
        $byContent = self::classifyByContent($path, $originalName);
        if ($byContent !== null) {
            return $byContent;
        }

        return self::classifyByFilename($originalName);
    }

    /**
     * @return 'ddr'|'demand'|'story'|'task'|null
     */
    public static function classifyByContent(string $path, string $originalName = ''): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $ext = extensionOf($originalName !== '' ? $originalName : $path);

        // Peek at bytes for JSON even with wrong/missing extension.
        $head = (string) @file_get_contents($path, false, null, 0, 4096);
        $trimmed = ltrim($head);
        if ($ext === 'json' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            if (self::looksLikeDdrJson($path)) {
                return 'ddr';
            }
            // JSON that is not a DDR export is rejected.
            if ($ext === 'json') {
                return null;
            }
        }

        if ($ext === 'pdf' || str_starts_with($head, '%PDF')) {
            return self::classifyPdfByContent($path);
        }

        return null;
    }

    /**
     * @return 'ddr'|'demand'|'story'|'task'|null
     */
    public static function classifyByFilename(string $originalName): ?string
    {
        $base = strtolower(safeBasename($originalName));

        if (str_ends_with($base, '.json')) {
            return 'ddr';
        }
        if (str_contains($base, 'ddr_') || preg_match('/\bddr\d+/', $base)) {
            return 'ddr';
        }

        if (str_contains($base, 'dmn_demand') || (str_contains($base, 'demand') && str_ends_with($base, '.pdf'))) {
            return 'demand';
        }
        if (str_contains($base, 'rm_story') || (preg_match('/\bstory\b/', $base) && str_ends_with($base, '.pdf'))) {
            return 'story';
        }
        if (str_contains($base, 'sc_task') || (preg_match('/\btask\b/', $base) && str_ends_with($base, '.pdf'))) {
            return 'task';
        }

        return null;
    }

    public static function looksLikeDdrJson(string $path): bool
    {
        $raw = @file_get_contents($path, false, null, 0, 200000);
        if ($raw === false || $raw === '') {
            return false;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['ddr']['fields']) && is_array($data['ddr']['fields'])) {
            return true;
        }

        $rootTable = (string) ($data['root_table'] ?? '');
        if ($rootTable !== '' && str_contains($rootTable, 'dd_request')) {
            return true;
        }

        return false;
    }

    /**
     * @return 'demand'|'story'|'task'|null
     */
    public static function classifyPdfByContent(string $path): ?string
    {
        $text = ServicenowPdfParser::extractText($path);
        if ($text === '') {
            return null;
        }

        $head = substr($text, 0, 4000);
        $lower = strtolower($head);

        if (str_contains($lower, 'table name:') && str_contains($lower, 'dmn_demand')) {
            return 'demand';
        }
        if (str_contains($lower, 'table name:') && str_contains($lower, 'rm_story')) {
            return 'story';
        }
        if (str_contains($lower, 'table name:') && str_contains($lower, 'sc_task')) {
            return 'task';
        }

        if (str_contains($lower, 'demand details') || preg_match('/\bnumber:\s*dmnd\d+/i', $head)) {
            return 'demand';
        }
        if (str_contains($lower, 'story details') || preg_match('/\bnumber:\s*stry\d+/i', $head)) {
            return 'story';
        }
        if (str_contains($lower, 'request task details') || preg_match('/\bnumber:\s*task\d+/i', $head)) {
            return 'task';
        }

        return null;
    }
}
