<?php

declare(strict_types=1);

/**
 * Ticket Dossier module bootstrap — RiskRegister auth, then dossier data stack.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

/** @var \RiskAssessment\Auth $auth */
$currentUser = $auth->requireAuth();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/FileClassifier.php';
require_once __DIR__ . '/parsers/DdrJsonParser.php';
require_once __DIR__ . '/parsers/ServicenowPdfParser.php';
require_once __DIR__ . '/PaginationPreference.php';
require_once __DIR__ . '/ProjectRepository.php';
require_once __DIR__ . '/ProjectImporter.php';

if (!is_dir(TD_DATABASE_DIR)) {
    mkdir(TD_DATABASE_DIR, 0755, true);
}

if (!is_dir(TD_STORAGE_DIR)) {
    mkdir(TD_STORAGE_DIR, 0755, true);
}

getDb();

// Seed the sample LogTag packet on first empty database.
ProjectImporter::seedSampleIfEmpty(is_array($currentUser) ? $currentUser : null);
