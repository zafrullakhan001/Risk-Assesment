<?php

declare(strict_types=1);

use RiskAssessment\AppModules;
use RiskAssessment\Auth;

/**
 * Ticket Dossier topbar shortcut (relative URL — host/path independent).
 * Optional: set $ticketDossierSolo = true on dossier pages for active state.
 * Optional: set $ticketDossierNavPrefix (e.g. '' from public/, unused on dossier pages that link to index).
 */
$ticketNavUser = $currentUser ?? Auth::instance()->currentUser();
if (!AppModules::instance()->canAccess(is_array($ticketNavUser) ? $ticketNavUser : null, AppModules::TICKET)) {
    return;
}
$ticketDossierSolo = $ticketDossierSolo ?? false;
$ticketDossierNavPrefix = $ticketDossierNavPrefix ?? '';
$ticketDossierNavUrl = $ticketDossierNavUrl ?? ($ticketDossierNavPrefix . 'ticket-dossier/');
?>
<a
    class="button ghost home-link<?= $ticketDossierSolo ? ' is-active' : '' ?>"
    data-menu-tone="butter"
    data-menu-group="ticket"
    data-nav-dest="ticket"
    href="<?= e($ticketDossierNavUrl) ?>"
    title="Open Ticket Dossier to build and read ServiceNow Demand, Story, Task, and DDR packets"
    <?= $ticketDossierSolo ? ' aria-current="page"' : '' ?>
><span class="topbar-menu-emoji" aria-hidden="true">🎫</span>Ticket Dossier</a>
