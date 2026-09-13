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
