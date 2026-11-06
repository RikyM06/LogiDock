<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

use LogiDock\Core\Database;
use LogiDock\Core\ValidationException;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base dei test di integrazione: si collega al database di test (e salta i test se MySQL non c'è)
 * e fa girare ogni test dentro una transazione che si annulla alla fine, così il database resta pulito.
 * Chi sovrascrive setUp() deve chiamare parent::setUp().
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Database $database;

    protected function setUp(): void
    {
        try {
            $this->database = TestDatabase::connect();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL di test non raggiungibile: ' . $e->getMessage());
        }

        $this->database->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }
    }

    /**
     * Esegue $action, che deve fallire la validazione, e restituisce gli errori per campo.
     *
     * @return array<string, string>
     */
    protected function errorsOf(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        self::fail('Attesa una ValidationException.');
    }
}
