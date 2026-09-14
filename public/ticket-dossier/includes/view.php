<?php
declare(strict_types=1);

/**
 * Shared HTML rendering helpers for dossier pages.
 */

/**
 * @param array<string, string> $fields
 * @param list<string|int> $pathPrefix
 */
function renderFieldGrid(
    array $fields,
    bool $showAll = false,
    string $extraClass = '',
    array $pathPrefix = []
): void
{
    $filtered = filterMeaningfulFields($fields, $showAll);
    if ($filtered === []) {
        echo '<p class="muted">No fields to display.</p>';
        return;
    }

    echo '<div class="field-grid ' . e($extraClass) . '">';
    foreach ($filtered as $label => $value) {
        $long = strlen($value) > 160 || str_contains($value, "\n");
        echo '<div class="field-item' . ($long ? ' field-item-wide' : '') . '">';
        echo '<dt>' . e($label) . '</dt>';
        echo '<dd>';
        renderEditableValue($value, [...$pathPrefix, $label], $label, $long);
        echo '</dd>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Render a dossier scalar with the shared pencil control.
 *
 * @param list<string|int> $path
 */
function renderEditableValue(string $value, array $path, string $label, bool $multiline = false): void
{
    $pathJson = json_encode($path, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo '<span class="editable-value">' . nl2br(e($value !== '' ? $value : '—')) . '</span>';
    if (!is_string($pathJson) || $path === []) {
        return;
    }
    echo '<button type="button" class="field-edit-pencil"';
    echo ' data-field-path="' . e($pathJson) . '"';
    echo ' data-field-label="' . e($label) . '"';
    echo ' data-field-value="' . e($value) . '"';
    echo ' data-field-multiline="' . ($multiline ? '1' : '0') . '"';
    echo ' aria-label="Edit ' . e($label) . '" title="Edit ' . e($label) . '"></button>';
}

/**
 * @param array{
 *   kind?: string,
 *   instance?: string,
 *   sys_id?: string,
 *   table?: string,
 *   path?: list<string|int>,
 *   label?: string,
 *   extra_class?: string,
 *   title?: string
 * } $options
 */
function renderServicenowTicketNumber(string $number, array $options = []): void
{
    $number = strtoupper(trim($number));
    $kind = (string) ($options['kind'] ?? servicenowKindFromNumber($number));
    $instance = (string) ($options['instance'] ?? '');
    $sysId = (string) ($options['sys_id'] ?? '');
    $table = (string) ($options['table'] ?? '');
    $path = is_array($options['path'] ?? null) ? $options['path'] : [];
    $label = (string) ($options['label'] ?? ($kind !== '' ? kindLabel($kind) . ' number' : 'Ticket number'));
    $href = $number !== '' ? servicenowRecordUrl($instance, $number, $sysId, $table, $kind) : '';
    $kindLabel = $kind !== '' ? kindLabel($kind) : 'ticket';
    $title = (string) ($options['title'] ?? ($number !== '' ? 'Open ' . $kindLabel . ' ' . $number . ' in ServiceNow' : ''));
    $extraClass = trim((string) ($options['extra_class'] ?? ''));
    $classes = trim('sn-record-link' . ($extraClass !== '' ? ' ' . $extraClass : ''));

    echo '<span class="editable-value">';
    if ($number === '') {
        echo '—';
    } else {
        echo servicenowTicketAnchorHtml($number, $href, $kind, $sysId, $table, $instance, $classes, $title);
    }
    echo '</span>';

    if ($path !== []) {
        renderEditableValuePencil($number, $path, $label, false);
    }
}

/**
 * @param array{
 *   kind?: string,
 *   instance?: string,
 *   sys_id?: string,
 *   table?: string,
 *   path?: list<string|int>,
 *   label?: string,
 *   prefix?: string
 * } $options
 */
function renderServicenowTicketPill(string $number, array $options = []): void
{
    $number = strtoupper(trim($number));
    if ($number === '') {
        echo '<span class="muted">—</span>';
        return;
    }

    $kind = (string) ($options['kind'] ?? servicenowKindFromNumber($number));
    $instance = (string) ($options['instance'] ?? '');
    $sysId = (string) ($options['sys_id'] ?? '');
    $table = (string) ($options['table'] ?? '');
    $path = is_array($options['path'] ?? null) ? $options['path'] : [];
    $label = (string) ($options['label'] ?? ($kind !== '' ? kindLabel($kind) . ' number' : 'Ticket number'));
    $prefix = (string) ($options['prefix'] ?? '');
    $href = servicenowRecordUrl($instance, $number, $sysId, $table, $kind);
    $kindLabel = $kind !== '' ? kindLabel($kind) : 'ticket';
    $title = 'Open ' . $kindLabel . ' ' . $number . ' in ServiceNow';
    $classes = trim(pillClassForKind($kind !== '' ? $kind : 'task') . ' sn-record-link');
    $inner = ($prefix !== '' ? $prefix . ' ' : '') . e($number);

    echo servicenowTicketAnchorHtml($number, $href, $kind, $sysId, $table, $instance, $classes, $title, $inner);
    if ($path !== []) {
        renderEditableValuePencil($number, $path, $label, false);
    }
}

/**
 * @param list<string|int> $path
 */
function renderEditableValuePencil(string $value, array $path, string $label, bool $multiline = false): void
{
    $pathJson = json_encode($path, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($pathJson) || $path === []) {
        return;
    }
    echo '<button type="button" class="field-edit-pencil"';
    echo ' data-field-path="' . e($pathJson) . '"';
    echo ' data-field-label="' . e($label) . '"';
    echo ' data-field-value="' . e($value) . '"';
    echo ' data-field-multiline="' . ($multiline ? '1' : '0') . '"';
    echo ' aria-label="Edit ' . e($label) . '" title="Edit ' . e($label) . '"></button>';
}

function servicenowTicketAnchorHtml(
    string $number,
    string $href,
    string $kind,
    string $sysId,
    string $table,
    string $instance,
    string $className,
    string $title,
    ?string $innerHtml = null
): string {
    $tag = $href !== '' ? 'a' : 'span';
    $html = '<' . $tag . ' class="' . e($className) . '"';
    if ($href !== '') {
        $html .= ' href="' . e($href) . '" target="_blank" rel="noopener noreferrer"';
    }
    if ($title !== '') {
        $html .= ' title="' . e($title) . '"';
    }
    $html .= ' data-sn-number="' . e($number) . '"';
    if ($kind !== '') {
        $html .= ' data-sn-kind="' . e($kind) . '"';
    }
    if ($sysId !== '') {
        $html .= ' data-sn-sys-id="' . e($sysId) . '"';
    }
    if ($table !== '') {
        $html .= ' data-sn-table="' . e($table) . '"';
    }
    if ($instance !== '') {
        $html .= ' data-sn-instance="' . e($instance) . '"';
    }
    $html .= '>' . ($innerHtml ?? e($number)) . '</' . $tag . '>';

    return $html;
}

/**
 * @param list<array{question: string, answer: string}> $qa
 * @return array{total: int, answered: int}
 */
function qaStats(array $qa): array
{
    $answered = 0;
    foreach ($qa as $item) {
        if (trim((string) ($item['answer'] ?? '')) !== '') {
            $answered++;
        }
    }

    return ['total' => count($qa), 'answered' => $answered];
}
