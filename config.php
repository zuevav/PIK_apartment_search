<?php
/**
 * PIK Apartment Tracker - Configuration
 */

return [
    // Database
    'db_path' => __DIR__ . '/data/apartments.db',

    // PIK API
    'pik_api_base' => 'https://api.pik.ru',
    'pik_api_version' => 'v2',
    'pik_site_url' => 'https://www.pik.ru',

    // Request settings
    'request_timeout' => 30,
    'request_delay' => 2, // seconds between requests to avoid rate limiting

    // Email notifications
    'email' => [
        'enabled' => false,
        'from' => 'noreply@example.com',
        'from_name' => 'PIK Tracker',
        'to' => '', // Your email address
        'smtp' => [
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls', // tls or ssl
        ],
    ],

    // Cron settings
    'check_interval_hours' => 6, // How often to check for new apartments

    // UI settings
    'timezone' => 'Europe/Moscow',
    'locale' => 'ru_RU',
    'items_per_page' => 50,
];
