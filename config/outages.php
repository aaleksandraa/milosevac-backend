<?php

return [
    'imap' => [
        'host' => env('OUTAGE_IMAP_HOST'),
        'port' => (int) env('OUTAGE_IMAP_PORT', 993),
        'encryption' => env('OUTAGE_IMAP_ENCRYPTION', 'ssl'),
        'username' => env('OUTAGE_IMAP_USERNAME'),
        'password' => env('OUTAGE_IMAP_PASSWORD'),
        'folder' => env('OUTAGE_IMAP_FOLDER', 'INBOX'),
    ],
    'from' => env('OUTAGE_IMAP_FROM'),
    'subject_contains' => env('OUTAGE_SUBJECT', 'planiranim prekidima'),
    'lookback_days' => (int) env('OUTAGE_LOOKBACK_DAYS', 30),
    'allowed_source_hosts' => ['elektrodoboj.net', 'www.elektrodoboj.net'],
    'auto_publish' => env('OUTAGE_AUTO_PUBLISH', true),
    'default_image' => env(
        'OUTAGE_DEFAULT_IMAGE',
        'wordpress/2026/06/748cb43f-d07e-4539-a3f0-1ee7a129aa3c-1200.webp'
    ),
    'default_image_alt' => env('OUTAGE_DEFAULT_IMAGE_ALT', 'Planirani prekid struje'),
];
