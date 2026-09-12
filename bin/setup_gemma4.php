<?php

declare(strict_types=1);

/**
 * @deprecated Use bin/setup_ollama_model.php
 * Kept so older docs/commands still work.
 */
$model = $argv[1] ?? 'qwen3.5:4b';
passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/setup_ollama_model.php') . ' ' . escapeshellarg($model),
    $code
);
exit($code);
