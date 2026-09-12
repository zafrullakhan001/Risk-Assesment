<?php

declare(strict_types=1);

/**
 * Download / verify the local Ollama model for Ticket Dossier AI.
 *
 * Usage:
 *   php bin/setup_ollama_model.php
 *   php bin/setup_ollama_model.php qwen3.5:9b
 */

$model = $argv[1] ?? 'qwen3.5:4b';
$model = trim((string) $model);
if ($model === '' || !preg_match('/^[A-Za-z0-9._:-]+$/', $model)) {
    fwrite(STDERR, "Invalid model name.\n");
    exit(1);
}

$ollamaCandidates = [
    getenv('OLLAMA_PATH') ?: '',
    (getenv('LOCALAPPDATA') ?: '') . DIRECTORY_SEPARATOR . 'Programs' . DIRECTORY_SEPARATOR . 'Ollama' . DIRECTORY_SEPARATOR . 'ollama.exe',
    'C:\\Program Files\\Ollama\\ollama.exe',
    'ollama',
];

$ollama = null;
foreach ($ollamaCandidates as $candidate) {
    $candidate = trim((string) $candidate);
    if ($candidate === '') {
        continue;
    }
    if ($candidate === 'ollama') {
        $ollama = 'ollama';
        break;
    }
    if (is_file($candidate)) {
        $ollama = $candidate;
        break;
    }
}

if ($ollama === null) {
    fwrite(STDERR, "Ollama not found. Install from https://ollama.com then re-run this script.\n");
    exit(1);
}

echo "Using Ollama: {$ollama}\n";
echo "Pulling model: {$model}\n";

$cmd = escapeshellarg($ollama) . ' pull ' . escapeshellarg($model);
passthru($cmd, $code);
if ($code !== 0) {
    fwrite(STDERR, "Failed to pull {$model} (exit {$code}).\n");
    exit($code);
}

echo "\nVerifying model list…\n";
passthru(escapeshellarg($ollama) . ' list', $listCode);

echo "\nDone. Ticket Dossier will use model \"{$model}\" via http://127.0.0.1:11434\n";
echo "Set TD_OLLAMA_MODEL in public/ticket-dossier/includes/config.php if you pulled a different tag.\n";
echo "Open a project Summary page and click \"Fill summary with AI\".\n";

exit($listCode === 0 ? 0 : $listCode);
