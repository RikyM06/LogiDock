<?php

declare(strict_types=1);

namespace LogiDock\Core;

use PDO;
use RuntimeException;

/**
 * Applica in ordine i file SQL di database/migrations e ricorda quelli già eseguiti
 * nella tabella schema_migrations (nome file + checksum).
 *
 * - Un file già applicato e poi modificato blocca tutto: la correzione si fa con una NUOVA migrazione.
 * - MySQL non può annullare il DDL (CREATE TABLE ecc. fa commit implicito), quindi non c'è rollback:
 *   per questo i file usano IF NOT EXISTS / DROP ... IF EXISTS e un file fallito si può rilanciare.
 * - Un lock a livello di database impedisce che due esecuzioni partano insieme.
 */
final class MigrationRunner
{
    private const LOCK_NAME = 'logidock_migrate';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory
    ) {
    }

    /**
     * @param callable(string): void|null $onApply chiamato col nome del file prima di applicarlo
     * @return list<string> i file applicati in questa esecuzione
     */
    public function migrate(?callable $onApply = null): array
    {
        $this->ensureMigrationsTable();

        $lock = $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 10)");
        if ($lock === false || (int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Un\'altra migrazione è già in corso (lock non ottenuto).');
        }

        try {
            $applied = [];
            $done = $this->executedMigrations();

            foreach ($this->files() as $file) {
                $name = basename($file);
                $checksum = $this->checksum($file);

                if (isset($done[$name])) {
                    $this->assertUnchanged($name, $done[$name], $checksum);
                    continue;
                }

                if ($onApply !== null) {
                    $onApply($name);
                }

                foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
                    $this->pdo->exec($statement);
                }

                $insert = $this->pdo->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)');
                $insert->execute([$name, $checksum]);
                $applied[] = $name;
            }

            return $applied;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /**
     * @return list<array{filename: string, status: string}> status: applied | pending | changed
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();
        $done = $this->executedMigrations();
        $result = [];

        foreach ($this->files() as $file) {
            $name = basename($file);

            if (!isset($done[$name])) {
                $status = 'pending';
            } else {
                $status = $done[$name] === $this->checksum($file) ? 'applied' : 'changed';
            }

            $result[] = ['filename' => $name, 'status' => $status];
        }

        return $result;
    }

    /**
     * Divide un file SQL in istruzioni singole: ignora le righe di commento ("--") e chiude
     * un'istruzione quando una riga finisce con ";". Niente DELIMITER né ";" dentro le stringhe.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';

        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }

            $buffer .= $line . "\n";

            if (str_ends_with(rtrim($line), ';')) {
                $statements[] = trim(substr(rtrim($buffer), 0, -1));
                $buffer = '';
            }
        }

        // Ultima istruzione senza ";" finale.
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                filename VARCHAR(255) NOT NULL,
                checksum CHAR(64) NOT NULL,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_schema_migrations_filename (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );
    }

    /**
     * @return array<string, string> nome file => checksum registrato
     */
    private function executedMigrations(): array
    {
        $rows = $this->pdo->query('SELECT filename, checksum FROM schema_migrations');
        $done = [];

        if ($rows !== false) {
            foreach ($rows as $row) {
                $done[(string) $row['filename']] = (string) $row['checksum'];
            }
        }

        return $done;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    private function checksum(string $file): string
    {
        // Toglie i "\r": lo stesso file deve dare lo stesso checksum su Windows (CRLF) e Linux (LF).
        return hash('sha256', str_replace("\r", '', (string) file_get_contents($file)));
    }

    private function assertUnchanged(string $name, string $recorded, string $current): void
    {
        if ($recorded !== $current) {
            throw new RuntimeException(
                'La migrazione ' . $name . ' è già stata applicata ma il file è stato modificato. '
                . 'Non modificare le migrazioni esistenti: crea una nuova migrazione.'
            );
        }
    }
}
