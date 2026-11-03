<?php

declare(strict_types=1);

// Tutta la configurazione arriva da .env (caricato da phpdotenv in Application).
// I default sono pensati per essere sicuri: il debug è spento se manca la variabile.

$root = dirname(__DIR__);

return [
    'name' => $_ENV['APP_NAME'] ?? 'LogiDock',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url' => $_ENV['APP_URL'] ?? 'http://localhost',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Europe/Rome',
    'log_path' => $root . '/' . ltrim($_ENV['LOG_PATH'] ?? 'storage/logs/app.log', '/'),
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
        'name' => $_ENV['DB_NAME'] ?? 'logidock',
        'user' => $_ENV['DB_USER'] ?? 'logidock',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4',
    ],
];
