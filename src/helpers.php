<?php

declare(strict_types=1);

/**
 * Legge una variabile di configurazione.
 * Cerca in $_ENV (riempito da phpdotenv da .env), poi in $_SERVER e getenv():
 * con variables_order=GPCS (default di php.ini-production) le variabili reali dell'ambiente
 * (fastcgi_param di Nginx, systemd...) NON finiscono in $_ENV.
 */
function env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return is_string($value) ? $value : $default;
}

/**
 * Escape dell'output HTML. Da usare in ogni template per ogni valore dinamico.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
