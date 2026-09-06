<?php

declare(strict_types=1);

/**
 * Compact rearrange controls for SharePoint catalog page sections.
 * Set $showSectionMove = false (or omit when $panelSolo) to hide.
 */
$showSectionMove = !empty($showSectionMove);
if (!$showSectionMove) {
    return;
}
?>
<div class="sp-section-move" data-no-toggle onclick="event.stopPropagation()" role="group" aria-label="Move section">
    <button type="button" class="sp-section-move-btn" data-sp-move="up" title="Move up" aria-label="Move up">↑</button>
    <button type="button" class="sp-section-move-btn" data-sp-move="down" title="Move down" aria-label="Move down">↓</button>
</div>
