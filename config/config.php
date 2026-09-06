<?php

declare(strict_types=1);

return [
    'app_name' => 'Architecture Risk Assessment Dashboard',
    'upload_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads',
    'template_upload_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'templates',
    'max_upload_bytes' => 5 * 1024 * 1024,
    'max_upload_files' => 10,
    'max_template_workbooks' => 15,
    'max_template_prompt_bytes' => 512 * 1024,
    'allowed_extensions' => ['xlsx'],
    'allowed_mime_types' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
    ],
    'allowed_prompt_extensions' => ['txt', 'md', 'prompt'],
    'branding_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'branding',
    'branding_max_bytes' => 1024 * 1024,
];
