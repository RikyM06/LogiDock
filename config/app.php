<?php

declare(strict_types=1);

// Tutta la configurazione arriva da .env (caricato da phpdotenv in Application) o dalle
// variabili d'ambiente reali, lette con env(). I default sono sicuri: senza APP_DEBUG il debug è spento.

$root = dirname(__DIR__);

return [
    'name' => env('APP_NAME', 'LogiDock'),
    'env' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),
    'log_path' => $root . '/' . ltrim(env('LOG_PATH', 'storage/logs/app.log'), '/'),
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'logidock'),
        'user' => env('DB_USER', 'logidock'),
        'password' => env('DB_PASSWORD'),
        'charset' => 'utf8mb4',
    ],
];
