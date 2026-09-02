<?php

declare(strict_types=1);

/**
 * @param int|string $value
 */
function renderHeroMedallion($value, string $label): void
{
    ?>
    <div class="hero-art">
        <div class="hero-medallion">
            <span class="hero-ring hero-ring-outer"></span>
            <span class="hero-ring hero-ring-mid"></span>
            <span class="hero-ring hero-ring-inner"></span>
            <div class="hero-stat">
                <strong><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </div>
    <?php
}
