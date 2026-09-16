<?php

declare(strict_types=1);

/**
 * Map a ServiceNow console task packet into Ticket Dossier parsed JSON.
 */
final class ServicenowTaskPacketParser
{
    public const FORMAT = 'architecture-risk.servicenow-task-packet.v1';

    /**
     * @return array{
     *   kind: string,
     *   root_number: string,
     *   root_sys_id: string,
     *   instance: string,
     *   exported_at: string,
     *   relationships: list<array{parent: string, child: string, type: string, parent_sys_id?: string, child_sys_id?: string}>,
     *   tickets: list<array<string, mixed>>,
     *   demand: array<string, mixed>|null,
     *   story: array<string, mixed>|null,
     *   task: array<string, mixed>|null,
     *   ddr: array<string, mixed>|null,
     *   vendor: null,
     *   assessments: array{external: list, internal: list},
     *   overview: array{title: string, vendor: string, description: string, business_case: string},
     *   related_tickets: list<array<string, mixed>>,
     *   packet_meta: array<string, mixed>
     * }
     */
    public static function parse(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('Cannot read ServiceNow task packet JSON.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('ServiceNow task packet is not valid JSON.');
        }

        return self::parseArray($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function parseArray(array $data): array
    {
        $format = (string) ($data['format'] ?? '');
        if ($format !== '' && $format !== self::FORMAT) {
            throw new RuntimeException(
                'Unsupported packet format "' . $format . '". Expected ' . self::FORMAT . '.'
            );
        }

        $rootNumber = strtoupper(trim((string) ($data['root_number'] ?? '')));
        if ($rootNumber === '' || !preg_match('/^[A-Z]+\d+$/', $rootNumber)) {
            throw new RuntimeException(
                'Packet is missing a valid root ticket number (e.g. TASK…, DMND…, STRY…, DDR…, PRJ…).'
            );
        }

        $ticketsIn = is_array($data['tickets'] ?? null) ? $data['tickets'] : [];
        $relationshipsIn = is_array($data['relationships'] ?? null) ? $data['relationships'] : [];

        $relationships = [];
        foreach ($relationshipsIn as $rel) {
            if (!is_array($rel)) {
                continue;
            }
            $parent = strtoupper(trim((string) ($rel['parent'] ?? '')));
            $child = strtoupper(trim((string) ($rel['child'] ?? '')));
            $type = trim((string) ($rel['type'] ?? $rel['relationship_type'] ?? ''));
            if ($parent === '' || $child === '') {
                continue;
            }
            $relationships[] = [
                'parent' => $parent,
                'child' => $child,
                'type' => $type !== '' ? $type : 'Related',
                'parent_sys_id' => trim((string) ($rel['parent_sys_id'] ?? '')),
                'child_sys_id' => trim((string) ($rel['child_sys_id'] ?? '')),
            ];
        }

        $tickets = [];
        foreach ($ticketsIn as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }
            $mapped = self::mapTicket($ticket, $relationships);
            if ($mapped !== null) {
                $tickets[] = $mapped;
            }
        }

        if ($tickets === []) {
            throw new RuntimeException('Packet contains no ticket records.');
        }

        $root = null;
        foreach ($tickets as $ticket) {
            if (strcasecmp((string) $ticket['number'], $rootNumber) === 0) {
                $root = $ticket;
                break;
            }
        }
        if ($root === null) {
            $root = $tickets[0];
            $rootNumber = (string) $root['number'];
        }

        $rootKind = self::kindFromNumber(
            (string) $root['number'],
            (string) ($root['sys_class_name'] ?? $root['table'] ?? '')
        );
        // Slot kinds used by the dossier ribbon (project/other stay in related).
        $slotKind = in_array($rootKind, ['demand', 'story', 'task', 'ddr'], true) ? $rootKind : '';

        $story = null;
        $demand = null;
        $task = null;
        $ddr = null;
        $relatedTickets = [];

        if ($slotKind === 'demand') {
            $demand = $root;
        } elseif ($slotKind === 'story') {
            $story = $root;
        } elseif ($slotKind === 'task') {
            $task = $root;
        } elseif ($slotKind === 'ddr') {
            $ddr = $root;
        } else {
            // Project or unknown root: keep in related_tickets, do not force into task.
            $relatedTickets[] = $root;
        }

        foreach ($tickets as $ticket) {
            if (strcasecmp((string) $ticket['number'], $rootNumber) === 0) {
                continue;
            }
            $kind = self::kindFromNumber((string) $ticket['number'], (string) ($ticket['sys_class_name'] ?? ''));
            if ($kind === 'story' && $story === null) {
                $story = $ticket;
                continue;
            }
            if ($kind === 'demand' && $demand === null) {
                $demand = $ticket;
                continue;
            }
            if ($kind === 'task' && $task === null) {
                $task = $ticket;
                continue;
            }
            if ($kind === 'ddr' && $ddr === null) {
                $ddr = $ticket;
                continue;
            }
            $relatedTickets[] = $ticket;
        }

        $taskSection = $task !== null ? self::toSection($task, 'task', $relationships) : null;
        $storySection = $story !== null ? self::toSection($story, 'story', $relationships) : null;
        $demandSection = $demand !== null ? self::toSection($demand, 'demand', $relationships) : null;
        $ddrSection = $ddr !== null ? self::toSection($ddr, 'ddr', $relationships) : null;

        $relatedSections = [];
        foreach ($relatedTickets as $ticket) {
            $kind = self::kindFromNumber((string) $ticket['number'], (string) ($ticket['sys_class_name'] ?? ''));
            $sectionKind = match ($kind) {
                'demand', 'story', 'task', 'ddr', 'project' => $kind,
                default => 'task',
            };
            $relatedSections[] = self::toSection($ticket, $sectionKind, $relationships);
        }

        $title = firstNonEmpty(
            (string) ($root['short_description'] ?? ''),
            (string) ($root['title'] ?? ''),
            $rootNumber
        );

        return [
            'kind' => 'packet',
            'root_number' => $rootNumber,
            'root_sys_id' => (string) ($data['root_sys_id'] ?? $root['sys_id'] ?? ''),
            'instance' => (string) ($data['instance'] ?? ''),
            'exported_at' => (string) ($data['exported_at'] ?? ''),
            'relationships' => $relationships,
            'tickets' => $tickets,
            'demand' => $demandSection,
            'story' => $storySection,
            'task' => $taskSection,
            'ddr' => $ddrSection,
            'vendor' => null,
            'assessments' => ['external' => [], 'internal' => []],
            'overview' => [
                'title' => $title,
                'vendor' => '',
                'description' => (string) ($root['description'] ?? $root['short_description'] ?? ''),
                'business_case' => '',
            ],
            'related_tickets' => $relatedSections,
            'packet_meta' => [
                'format' => self::FORMAT,
                'instance' => (string) ($data['instance'] ?? ''),
                'exported_at' => (string) ($data['exported_at'] ?? ''),
                'ticket_count' => count($tickets),
                'relationship_count' => count($relationships),
                'attachment_count' => self::countAttachments($tickets),
                'root_kind' => $rootKind !== '' ? $rootKind : 'other',
            ],
        ];
    }

    public static function looksLikePacket(string $path): bool
    {
        $raw = @file_get_contents($path, false, null, 0, 200000);
        if ($raw === false || $raw === '') {
            return false;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }

        $format = (string) ($data['format'] ?? '');
        if ($format === self::FORMAT) {
            return true;
        }

        $root = strtoupper(trim((string) ($data['root_number'] ?? '')));
        $tickets = $data['tickets'] ?? null;

        return preg_match('/^[A-Z]+\d+$/', $root) === 1 && is_array($tickets) && $tickets !== [];
    }

    /**
     * @param array<string, mixed> $ticket
     * @param list<array{parent: string, child: string, type: string}> $relationships
     * @return array<string, mixed>|null
     */
    private static function mapTicket(array $ticket, array $relationships): ?array
    {
        $number = strtoupper(trim((string) ($ticket['number'] ?? '')));
        if ($number === '') {
            return null;
        }

        $fields = [];
        if (isset($ticket['fields']) && is_array($ticket['fields'])) {
            // Already label => value, or list of {label, display_value}.
            $isList = array_is_list($ticket['fields']);
            if ($isList) {
                $fields = fieldsToMap($ticket['fields']);
            } else {
                foreach ($ticket['fields'] as $label => $value) {
                    $fields[(string) $label] = normalizeDisplayValue($value);
                }
            }
        }

        $display = is_array($ticket['display_fields'] ?? null) ? $ticket['display_fields'] : [];
        foreach ($display as $label => $value) {
            $key = (string) $label;
            if ($key === '' || isset($fields[$key])) {
                continue;
            }
            $fields[$key] = normalizeDisplayValue($value);
        }

        // Flatten common SN API field map (raw column names).
        if ($fields === [] && is_array($ticket['raw'] ?? null)) {
            foreach ($ticket['raw'] as $col => $value) {
                $fields[self::humanizeColumn((string) $col)] = normalizeDisplayValue($value);
            }
        }

        $short = firstNonEmpty(
            (string) ($ticket['short_description'] ?? ''),
            (string) ($ticket['title'] ?? ''),
            (string) ($fields['Short description'] ?? ''),
            (string) ($fields['Short Description'] ?? ''),
            (string) ($fields['short_description'] ?? ''),
            (string) ($fields['Name'] ?? ''),
            (string) ($fields['Project name'] ?? ''),
            (string) ($fields['Project Name'] ?? '')
        );
        $description = firstNonEmpty(
            (string) ($ticket['description'] ?? ''),
            (string) ($fields['Description'] ?? ''),
            (string) ($fields['description'] ?? ''),
            $short
        );
        $state = firstNonEmpty(
            (string) ($ticket['state'] ?? ''),
            (string) ($fields['State'] ?? ''),
            (string) ($fields['state'] ?? '')
        );

        $journal = [];
        if (is_array($ticket['journal'] ?? null)) {
            foreach ($ticket['journal'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $journal[] = [
                    'element' => (string) ($entry['element'] ?? $entry['field'] ?? ''),
                    'value' => (string) ($entry['value'] ?? $entry['display_value'] ?? ''),
                    'created' => (string) ($entry['sys_created_on'] ?? $entry['created'] ?? ''),
                    'created_by' => (string) ($entry['sys_created_by'] ?? $entry['created_by'] ?? ''),
                ];
            }
        }

        $attachments = [];
        if (is_array($ticket['attachments'] ?? null)) {
            foreach ($ticket['attachments'] as $att) {
                if (!is_array($att)) {
                    continue;
                }
                $fileName = (string) ($att['file_name'] ?? $att['filename'] ?? $att['name'] ?? '');
                if ($fileName === '') {
                    continue;
                }
                $attachments[] = [
                    'sys_id' => (string) ($att['sys_id'] ?? ''),
                    'file_name' => $fileName,
                    'content_type' => (string) ($att['content_type'] ?? $att['content_type'] ?? ''),
                    'size_bytes' => (int) ($att['size_bytes'] ?? $att['size'] ?? 0),
                    'relative_path' => (string) ($att['relative_path'] ?? ''),
                ];
            }
        }

        $relatedForTicket = [];
        foreach ($relationships as $rel) {
            if (
                strcasecmp($rel['parent'], $number) === 0
                || strcasecmp($rel['child'], $number) === 0
            ) {
                $relatedForTicket[] = [
                    'parent' => $rel['parent'],
                    'child' => $rel['child'],
                    'type' => $rel['type'],
                    'parent_sys_id' => (string) ($rel['parent_sys_id'] ?? ''),
                    'child_sys_id' => (string) ($rel['child_sys_id'] ?? ''),
                ];
            }
        }

        $relatedNumbers = extractRecordNumbers($number . ' ' . implode(' ', array_column($relatedForTicket, 'parent')) . ' ' . implode(' ', array_column($relatedForTicket, 'child')));
        $parsedKind = self::kindFromNumber($number, (string) ($ticket['sys_class_name'] ?? $ticket['table'] ?? ''));

        return [
            'kind' => (string) ($ticket['kind'] ?? $parsedKind),
            'number' => $number,
            'sys_id' => (string) ($ticket['sys_id'] ?? ''),
            'sys_class_name' => (string) ($ticket['sys_class_name'] ?? $ticket['table'] ?? ''),
            'table' => (string) ($ticket['table'] ?? $ticket['sys_class_name'] ?? 'task'),
            'state' => $state,
            'title' => $short,
            'short_description' => $short,
            'description' => $description,
            'fields' => $fields,
            'journal' => $journal,
            'attachments' => $attachments,
            'related' => $relatedForTicket,
            'related_numbers' => $relatedNumbers,
        ];
    }

    /**
     * @param array<string, mixed> $ticket
     * @param list<array{parent: string, child: string, type: string}> $relationships
     * @return array<string, mixed>
     */
    private static function toSection(array $ticket, string $kind, array $relationships): array
    {
        $related = [];
        $number = (string) $ticket['number'];
        foreach ($relationships as $rel) {
            if (
                strcasecmp($rel['parent'], $number) === 0
                || strcasecmp($rel['child'], $number) === 0
            ) {
                $related[] = [
                    'parent' => $rel['parent'],
                    'child' => $rel['child'],
                    'type' => $rel['type'],
                    'parent_sys_id' => (string) ($rel['parent_sys_id'] ?? ''),
                    'child_sys_id' => (string) ($rel['child_sys_id'] ?? ''),
                ];
            }
        }

        return [
            'kind' => $kind,
            'number' => $number,
            'sys_id' => (string) ($ticket['sys_id'] ?? ''),
            'sys_class_name' => (string) ($ticket['sys_class_name'] ?? ''),
            'table' => (string) ($ticket['table'] ?? $ticket['sys_class_name'] ?? ''),
            'state' => (string) ($ticket['state'] ?? ''),
            'title' => (string) ($ticket['title'] ?? $ticket['short_description'] ?? ''),
            'description' => (string) ($ticket['description'] ?? ''),
            'business_case' => '',
            'fields' => is_array($ticket['fields'] ?? null) ? $ticket['fields'] : [],
            'journal' => is_array($ticket['journal'] ?? null) ? $ticket['journal'] : [],
            'attachments' => is_array($ticket['attachments'] ?? null) ? $ticket['attachments'] : [],
            'related' => $related !== [] ? $related : (is_array($ticket['related'] ?? null) ? $ticket['related'] : []),
            'related_numbers' => is_array($ticket['related_numbers'] ?? null)
                ? $ticket['related_numbers']
                : ['demand' => '', 'story' => '', 'task' => '', 'ddr' => '', 'project' => ''],
        ];
    }

    private static function kindFromNumber(string $number, string $sysClass): string
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
        if (str_starts_with($number, 'PRJ')) {
            return 'project';
        }

        $class = strtolower($sysClass);
        if (str_contains($class, 'demand')) {
            return 'demand';
        }
        if (str_contains($class, 'story') || str_contains($class, 'rm_story')) {
            return 'story';
        }
        if (str_contains($class, 'pm_project')) {
            return 'project';
        }
        if (str_contains($class, 'diligence') || str_contains($class, 'tprm_dd')) {
            return 'ddr';
        }

        return 'other';
    }

    private static function humanizeColumn(string $col): string
    {
        $col = str_replace('_', ' ', $col);

        return ucwords($col);
    }

    /**
     * @param list<array<string, mixed>> $tickets
     */
    private static function countAttachments(array $tickets): int
    {
        $n = 0;
        foreach ($tickets as $ticket) {
            $atts = $ticket['attachments'] ?? [];
            if (is_array($atts)) {
                $n += count($atts);
            }
        }

        return $n;
    }
}
