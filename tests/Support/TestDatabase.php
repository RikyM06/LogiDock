<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

use LogiDock\Core\Application;
use LogiDock\Core\Database;
use RuntimeException;

/**
 * Connessione al database di test per i test di integrazione.
 */
final class TestDatabase
{
    /**
     * @throws RuntimeException se la configurazione non punta a un database di test
     * @throws \PDOException    se MySQL non è raggiungibile
     */
    public static function connect(): Database
    {
        $config = Application::loadConfig(dirname(__DIR__, 2));
        $name = $config['db']['name'];

        // Rete di sicurezza: i test scrivono dati, quindi non devono mai toccare il database vero.
        if (!str_ends_with($name, '_test')) {
            throw new RuntimeException(
                'I test di integrazione richiedono un database che finisce con "_test" (trovato "' . $name . '").'
            );
        }

        $database = new Database($config['db']);
        $database->pdo(); // apre la connessione adesso, così un errore esce subito

        return $database;
    }
}
