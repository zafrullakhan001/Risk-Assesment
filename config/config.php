<?php

declare(strict_types=1);

return [
    'app_name' => 'Architecture Risk Assessment Dashboard',
    'upload_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads',
    'max_upload_bytes' => 5 * 1024 * 1024,
    'max_upload_files' => 10,
    'allowed_extensions' => ['xlsx'],
    'allowed_mime_types' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
    ],
];
