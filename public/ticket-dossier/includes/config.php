<?php

declare(strict_types=1);

const TD_ROOT = __DIR__ . '/..';
/** RiskRegister install root (parent of public/). */
const TD_APP_ROOT = __DIR__ . '/../../..';
/** Shared app database folder — preserved by the GitHub updater. */
const TD_DATABASE_DIR = TD_APP_ROOT . '/database';
const TD_DATA_DIR = TD_DATABASE_DIR;
const TD_STORAGE_DIR = TD_DATABASE_DIR . '/ticket-dossier-storage/projects';
const TD_SAMPLE_DIR = TD_ROOT . '/sample';
const TD_SQLITE_PATH = TD_DATABASE_DIR . '/ticketdetails.sqlite';
/** Legacy paths used before the DB moved into /database. */
const TD_LEGACY_SQLITE_PATH = TD_ROOT . '/data/ticketdetails.sqlite';
const TD_LEGACY_STORAGE_DIR = TD_ROOT . '/storage/projects';
const TD_MAX_UPLOAD_BYTES = 15 * 1024 * 1024;
const TD_MAX_FILES_PER_UPLOAD = 10;

const TD_ALLOWED_EXTENSIONS = ['pdf', 'json'];
const TD_ALLOWED_MIME = [
    'application/pdf',
    'application/json',
    'text/json',
    'text/plain',
    'application/octet-stream',
];

const TD_SOURCE_KINDS = ['ddr', 'demand', 'story', 'task'];
