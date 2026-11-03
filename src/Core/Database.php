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
     * (che poi viene rilanciata, non ingoiata). Le transazioni non si annidano: se ne esiste già una,
     * il callback ne fa parte e commit/rollback spettano a chi l'ha aperta.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $callback();
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
