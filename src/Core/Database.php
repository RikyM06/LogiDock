<?php

declare(strict_types=1);

namespace LogiDock\Core;

use PDO;
use Throwable;

/**
 * Connessione PDO (creata solo al primo uso) e gestione delle transazioni.
 * Tutta l'applicazione usa prepared statement: l'input dell'utente non va mai concatenato nell'SQL.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** Contatore per dare un nome diverso a ogni SAVEPOINT. */
    private int $savepointCounter = 0;

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string, charset: string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['name'],
                $this->config['charset']
            );

            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Prepared statement veri sul server, non simulati da PHP.
                PDO::ATTR_EMULATE_PREPARES => false,
                // Le date nel database sono sempre in UTC: si convertono solo quando si mostrano.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        }

        return $this->pdo;
    }

    /**
     * Esegue $callback in una transazione: commit se finisce bene, rollback se lancia un'eccezione
     * (che poi viene rilanciata, non ingoiata).
     *
     * Se esiste già una transazione (un altro service che ne ha aperta una, o un test) MySQL non
     * ne permette una seconda: si crea un SAVEPOINT, un punto di ritorno. Se $callback fallisce si
     * torna solo a quel punto, annullando ciò che ha scritto $callback e nient'altro; commit e
     * rollback finali restano a chi ha aperto la transazione esterna.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            // Il nome è generato qui (mai input dell'utente), quindi si può scrivere nell'SQL.
            $savepoint = 'logidock_sp_' . ++$this->savepointCounter;
            $pdo->exec('SAVEPOINT ' . $savepoint);

            try {
                $result = $callback();
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);

                return $result;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);

                throw $e;
            }
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
