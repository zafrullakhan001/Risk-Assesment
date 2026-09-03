<?php

declare(strict_types=1);

use RiskAssessment\Branding;

$brandingHead = Branding::current();
$logoSize = $brandingHead->logoSize();
$faviconHref = $brandingHead->faviconUrl();
$faviconType = $brandingHead->faviconType();
$appleTouch = $brandingHead->appleTouchUrl();
?>
    <style>
        :root { --brand-mark-size: <?= (int) $logoSize ?>px; }
    </style>
<?php if ($faviconHref !== ''): ?>
    <link rel="icon" href="<?= e($faviconHref) ?>"<?= $faviconType !== '' ? ' type="' . e($faviconType) . '"' : '' ?>>
<?php endif; ?>
<?php if ($appleTouch !== ''): ?>
    <link rel="apple-touch-icon" href="<?= e($appleTouch) ?>">
<?php endif; ?>
