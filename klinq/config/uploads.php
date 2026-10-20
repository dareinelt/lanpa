<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'path' => dirname(__DIR__) . '/storage/uploads',
    'max_bytes' => Env::int('MAX_UPLOAD_BYTES', 20 * 1024 * 1024),
    'allowed_extensions' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('ALLOWED_UPLOAD_EXTENSIONS', 'pdf,png,jpg,jpeg,webp,heic,txt,csv,xlsx,docx'))))),
    // Erlaubte MIME-Typen je Endung (serverseitig per finfo geprüft)
    'mime_map' => [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ],
    'image_extensions' => ['png', 'jpg', 'jpeg', 'webp', 'heic'],
];
