<?php

declare(strict_types=1);

use RiskAssessment\Branding;

$footerText = Branding::current()->footerText();
if ($footerText === '') {
    return;
}
?>
        <footer class="site-footer">
            <p><?= nl2br(e($footerText), false) ?></p>
        </footer>
