<?php

declare(strict_types=1);

/**
 * Shared popover: per-catalog background + text color pickers.
 * Expects $catalogColorPresets (hex => label).
 */
$catalogColorPresets = is_array($catalogColorPresets ?? null) ? $catalogColorPresets : [];
$catalogTextColorPresets = [
    '#0c1524' => 'Ink',
    '#ffffff' => 'White',
    '#334155' => 'Slate',
    '#e2e8f0' => 'Snow',
    '#115e59' => 'Teal',
    '#fef3c7' => 'Gold',
];
?>
<div class="sharepoint-catalog-color-pop" id="sharepoint-catalog-color-pop" hidden role="dialog" aria-label="Choose background and text colors">
    <p class="sharepoint-catalog-color-pop-kicker" id="sharepoint-catalog-color-pop-title">Card colors</p>
    <p class="sharepoint-catalog-color-section-label">Background</p>
    <div class="sharepoint-catalog-color-presets" data-color-target="bg" role="list">
        <?php foreach ($catalogColorPresets as $hex => $name): ?>
            <button type="button" class="sharepoint-catalog-color-preset" data-hex="<?= e($hex) ?>" data-color-target="bg" title="<?= e($name) ?>" aria-label="Background <?= e($name) ?>" style="background: <?= e($hex) ?>"></button>
        <?php endforeach; ?>
    </div>
    <label class="sharepoint-catalog-color-custom">
        <span>Background</span>
        <input type="color" id="sharepoint-catalog-color-native" value="#0f766e" aria-label="Custom background color">
    </label>
    <p class="sharepoint-catalog-color-section-label">Text</p>
    <div class="sharepoint-catalog-color-presets sharepoint-catalog-text-presets" data-color-target="text" role="list">
        <?php foreach ($catalogTextColorPresets as $hex => $name): ?>
            <button type="button" class="sharepoint-catalog-color-preset" data-hex="<?= e($hex) ?>" data-color-target="text" title="<?= e($name) ?>" aria-label="Text <?= e($name) ?>" style="background: <?= e($hex) ?>"></button>
        <?php endforeach; ?>
    </div>
    <label class="sharepoint-catalog-color-custom">
        <span>Text</span>
        <input type="color" id="sharepoint-catalog-text-native" value="#0c1524" aria-label="Custom text color">
    </label>
    <button type="button" class="sharepoint-catalog-color-reset-one" id="sharepoint-catalog-color-reset-one">Reset this catalog</button>
</div>
