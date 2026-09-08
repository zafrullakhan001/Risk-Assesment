<?php
declare(strict_types=1);

final class ServicenowPdfParser
{
    /** Labels that often span multiple lines until the next known label. */
    private const MULTILINE_LABELS = [
        'Description',
        'Business Case',
        'Short Description',
        'Acceptance criteria',
        'Validation plan',
        'Work notes',
        'Additional comments',
        'Request Details',
        'Detailed Description',
        'Solution Description',
        'Goals & Benefits',
        'Notes',
        'Related Project Notes',
    ];

    /**
     * @return array{
     *   kind: string,
     *   number: string,
     *   state: string,
     *   title: string,
     *   description: string,
     *   business_case: string,
     *   fields: array<string, string>,
     *   related: list<array{parent: string, child: string, type: string}>,
     *   related_numbers: array{demand: string, story: string, task: string, ddr: string},
     *   raw_text_excerpt: string
     * }
     */
    public static function parse(string $path, string $kind): array
    {
        $text = self::extractText($path);
        if (trim($text) === '') {
            throw new RuntimeException('No readable text found in PDF: ' . basename($path));
        }

        $text = self::normalizeText($text);
        $fields = self::extractFields($text);
        $related = self::extractRelatedRecords($text);
        $numbers = extractRecordNumbers($text);

        $number = firstNonEmpty(
            $fields['Number'] ?? '',
            match ($kind) {
                'demand' => $numbers['demand'],
                'story' => $numbers['story'],
                'task' => $numbers['task'],
                default => '',
            }
        );

        if ($kind === 'demand' && $numbers['demand'] === '' && $number !== '') {
            $numbers['demand'] = $number;
        }
        if ($kind === 'story' && $numbers['story'] === '' && $number !== '') {
            $numbers['story'] = $number;
        }
        if ($kind === 'task' && $numbers['task'] === '' && $number !== '') {
            $numbers['task'] = $number;
        }

        foreach ($related as $rel) {
            foreach ([$rel['parent'], $rel['child']] as $token) {
                $extra = extractRecordNumbers($token);
                foreach ($extra as $k => $v) {
                    if ($v !== '' && $numbers[$k] === '') {
                        $numbers[$k] = $v;
                    }
                }
            }
        }

        $description = firstNonEmpty(
            $fields['Description'] ?? '',
            $fields['Detailed Description'] ?? '',
            $fields['Short Description'] ?? ''
        );
        if (preg_match('/^Description\s*:\s*/i', $description)) {
            $description = trim(preg_replace('/^Description\s*:\s*/i', '', $description) ?? $description);
        }

        $businessCase = $fields['Business Case'] ?? '';

        // Prefer short description for title when Name is absent.
        $title = firstNonEmpty(
            $fields['Name'] ?? '',
            $fields['Short Description'] ?? '',
            $fields['Product(s) Name'] ?? '',
            $fields['Application Name'] ?? ''
        );

        return [
            'kind' => $kind,
            'number' => $number,
            'state' => $fields['State'] ?? '',
            'title' => $title,
            'description' => $description,
            'business_case' => $businessCase,
            'fields' => $fields,
            'related' => $related,
            'related_numbers' => $numbers,
            'raw_text_excerpt' => substr($text, 0, 2000),
        ];
    }

    public static function extractText(string $path): string
    {
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($path);
                $text = $pdf->getText();
                if (trim($text) !== '') {
                    return self::normalizeText($text);
                }
            } catch (Throwable) {
                // Fall through to native extraction.
            }
        }

        return self::normalizeText(self::extractPdfTextNative($path));
    }

    public static function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // ServiceNow exports often use tabs between label and value.
        $text = str_replace("\t", ' ', $text);
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        // Collapse "Label: value" that got split across lines.
        $text = preg_replace('/:\s*\n\s*/', ': ', $text) ?? $text;
        // Repair accidental double colons from mixed "Label:\tvalue" layouts.
        $text = preg_replace('/:\s*:\s*/', ': ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string, string>
     */
    public static function extractFields(string $text): array
    {
        $known = self::knownLabels();
        $text = self::unglueLabels($text, $known);
        $fields = [];

        // Pattern: Label: value (value may continue on following lines until next Label:)
        $labelAlt = implode('|', array_map(static fn (string $l): string => preg_quote($l, '/'), $known));
        $pattern = '/(?:^|\n)\s*(' . $labelAlt . ')\s*:\s*(.*?)(?=(?:\n\s*(?:' . $labelAlt . ')\s*:)|\z)/is';

        if (preg_match_all($pattern, "\n" . $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = self::canonicalLabel($match[1]);
                $value = trim(preg_replace('/\s+/', ' ', $match[2]) ?? $match[2]);
                $value = ltrim($value, ": \t");
                // Strip page headers that leaked into values.
                $value = preg_replace('/\b(?:Demand|Story|Request Task) Details\s+Page\s+\d+\b.*/i', '', $value) ?? $value;
                $value = preg_replace('/\bRun By\s*:.*$/i', '', $value) ?? $value;
                $value = trim($value);

                if ($label === '') {
                    continue;
                }

                if (!isset($fields[$label]) || ($fields[$label] === '' && $value !== '')) {
                    $fields[$label] = $value;
                } elseif ($value !== '' && strlen($value) > strlen($fields[$label])) {
                    // Prefer richer capture for multiline labels.
                    if (in_array($label, self::MULTILINE_LABELS, true)) {
                        $fields[$label] = $value;
                    }
                }
            }
        }

        // Fallback simple line parser for "Label: value" single lines missed above.
        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if (!preg_match('/^([A-Za-z][A-Za-z0-9 \/&#\'()\-]{1,60}?)\s*:\s*(.+)$/', $line, $m)) {
                continue;
            }
            $label = self::canonicalLabel($m[1]);
            if ($label === '' || !in_array($label, $known, true)) {
                continue;
            }
            $value = trim(ltrim($m[2], ": \t"));
            if (!isset($fields[$label]) || $fields[$label] === '') {
                $fields[$label] = $value;
            }
        }

        return $fields;
    }

    /**
     * ServiceNow PDF text often glues empty fields: "Foo: Bar: value".
     * Split so each known label starts on its own logical line.
     *
     * @param list<string> $known
     */
    private static function unglueLabels(string $text, array $known): string
    {
        // Longest labels first so "Related Project Notes" wins over "Notes".
        usort($known, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        for ($pass = 0; $pass < 40; $pass++) {
            $changed = false;
            foreach ($known as $label) {
                $quoted = preg_quote($label, '/');
                // Only split when the label is not already at the start of a line.
                $next = preg_replace(
                    '/:\s+(' . $quoted . ')\s*:/',
                    ":\n\$1:",
                    $text,
                    -1,
                    $count
                );
                if (!is_string($next) || $count === 0) {
                    continue;
                }
                if ($next !== $text) {
                    $text = $next;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }

        return $text;
    }

    /**
     * @return list<array{parent: string, child: string, type: string}>
     */
    public static function extractRelatedRecords(string $text): array
    {
        $related = [];
        if (preg_match_all(
            '/\b((?:TASK|STRY|DMND|DDR)\d+)\s+((?:TASK|STRY|DMND|DDR)\d+)\s+(Contains::Task of|Task of::Contains|[A-Za-z: ]+)/i',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $related[] = [
                    'parent' => strtoupper($m[1]),
                    'child' => strtoupper($m[2]),
                    'type' => trim($m[3]),
                ];
            }
        }

        return $related;
    }

    /**
     * @return list<string>
     */
    private static function knownLabels(): array
    {
        return [
            'Report Title', 'Run Date and Time', 'Run by', 'Table name',
            'Name', 'Number', 'Total Age', 'Initiative Source', 'Business Unit', 'Scale',
            'Division/Region', 'Facility', 'Submitted By', 'Requesting VP', 'Business Owner',
            'Funding CFO', 'AIT Executive Sponsor', 'AIT Product Owner', 'AIT Product Manager',
            'AIT Demand Manager', 'Target Project Start', 'Estimated Duration', 'Target Project Finish',
            'Impacted End Users', 'Quarterly Committment', 'QP-Scoping/Planning', 'QP-Building/Testing',
            'QP-Go-Live', 'Funding Status', 'Entity Type', 'Net New Provider Count', 'Number of Physicians',
            'Entity Services', 'Location Address(s)', 'Project', 'Related Project Number', 'Related Project Notes',
            'State', 'Active Age', 'On Hold Reason', 'Initiative Group', 'Initiative Category', 'Portfolio',
            'Program', 'Primary goal', 'Primary target', 'Aligned Governance Committee', 'Core Level',
            'Priority Alignment', 'AI Enabled Technologies', 'Reporting Flags', 'Billable', 'Confidential',
            'Impacted Business Applications', 'Product(s) Name', 'Third-Party Vendor', 'Status - Budget',
            'Core 4 Documentation', 'Capital outlay', 'Operational expense', 'Total planned cost',
            'Funding AIT SVP', 'Finance ProjectID', 'Governance Committee Decision',
            'Governance Committee Review Date', 'Governance Committee Action', 'Contract Group',
            'Contract Status', 'Letter of Intent Status', 'Description', 'Business Case',
            'Product', 'Product Product Manager', 'Catalog Item', 'Theme', 'Epic', 'Impacted Products',
            'Requested by', 'Assignment group', 'Assignee', 'Peer Reviewer', 'QA Assignee',
            'Testing completed', 'Tested by', 'Tested date', 'Additional assignee list',
            'Percent complete', 'Priority', 'Type', 'Classification', 'Extract Classification',
            'Sprint', 'Points', 'Blocked', 'Blocked Duration', 'Waiting on User', 'Need By Date',
            'Product rank', 'Project phase', 'Blocked reason', 'Short Description',
            'Acceptance criteria', 'Validation plan', 'Watch list', 'Work notes', 'Activities',
            'Opened', 'Updated', 'Opened by', 'Updated by', 'Release', 'Request Item', 'Demand',
            'HSD#', 'HSF#', 'Adhoc', 'External Ticket', 'Skip Survey(Internal)', 'Exception Status',
            'Additional Information', 'Parent Project', 'Time worked', 'Primary Update Set',
            'Deployment Steps', 'Communication Required', 'Documentation Required', 'User Training Required',
            'Request Contact type', 'Affected User', "Affected User's Location", 'Affected User Department',
            'Best Contact Number', 'Initial Affected CI', 'Follow up', 'Task Type', 'Approval',
            'Converted To', 'Order', 'Await Completion', 'Environment', 'Vendor', 'Task Category Type',
            'Bypass Inventory Intake Form', 'Request item', 'Request item Category', 'Request item Item',
            'Additional comments', 'Work notes list', 'Request', 'Request Description', 'Requester',
            'Requester Location', 'Affected User Location', 'Best Contact Email', 'Application Name',
            'Detailed Description', 'Consultation (Assistance with e',
        ];
    }

    private static function canonicalLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        foreach (self::knownLabels() as $known) {
            if (strcasecmp($known, $label) === 0) {
                return $known;
            }
        }

        return $label;
    }

    private static function extractPdfTextNative(string $path): string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return '';
        }

        $parts = [];
        if (preg_match_all('/stream\s*(.*?)\s*endstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false) {
                    $decoded = $stream;
                }
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $decoded, $textMatches)) {
                    foreach ($textMatches[0] as $chunk) {
                        $chunk = substr($chunk, 1, -1);
                        $chunk = stripcslashes($chunk);
                        if (trim($chunk) !== '') {
                            $parts[] = $chunk;
                        }
                    }
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $decoded, $tjMatches)) {
                    foreach ($tjMatches[1] as $arr) {
                        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $arr, $inner)) {
                            foreach ($inner[0] as $chunk) {
                                $chunk = substr($chunk, 1, -1);
                                $chunk = stripcslashes($chunk);
                                if ($chunk !== '') {
                                    $parts[] = $chunk;
                                }
                            }
                        }
                    }
                }
            }
        }

        return implode(' ', $parts);
    }
}
