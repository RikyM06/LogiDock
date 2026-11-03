<?php

declare(strict_types=1);

use LogiDock\Core\Application;
use LogiDock\Core\Database;
use LogiDock\Core\MigrationRunner;

// Uso:  php bin/migrate.php            applica le migrazioni mancanti
//       php bin/migrate.php --status   mostra lo stato di ogni migrazione

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $config = Application::loadConfig($root);
    $database = new Database($config['db']);
    $runner = new MigrationRunner($database->pdo(), $root . '/database/migrations');

    if (in_array('--status', $argv, true)) {
        foreach ($runner->status() as $row) {
            printf("%-9s %s\n", $row['status'], $row['filename']);
        }
        exit(0);
    }

    $applied = $runner->migrate(static function (string $name): void {
        echo 'Applico ' . $name . "...\n";
    });

    echo $applied === [] ? "Database già aggiornato.\n" : count($applied) . " migrazioni applicate.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}
