<?php

declare(strict_types=1);

use LogiDock\Core\Application;
use LogiDock\Core\Database;

// Uso:  php bin/seed.php
// Inserisce i dati di esempio di database/seeds (ripetibile: non crea doppioni).
// Si rifiuta di girare in produzione: gli utenti di esempio non devono mai esistere lì.

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $config = Application::loadConfig($root);

    if ($config['env'] === 'production') {
        throw new RuntimeException('Il seed è disabilitato con APP_ENV=production.');
    }

    $database = new Database($config['db']);

    foreach (glob($root . '/database/seeds/*.php') ?: [] as $file) {
        echo 'Seed ' . basename($file) . "...\n";

        /** @var callable(Database): void $seed */
        $seed = require $file;
        $database->transaction(static fn () => $seed($database));
    }

    echo "Seed completato.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}
