<?php
declare(strict_types=1);

/**
 * Shared HTML rendering helpers for dossier pages.
 */

/**
 * @param array<string, string> $fields
 */
function renderFieldGrid(array $fields, bool $showAll = false, string $extraClass = ''): void
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
        echo '<dd>' . nl2br(e($value)) . '</dd>';
        echo '</div>';
    }
    echo '</div>';
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
