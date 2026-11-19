<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use LogiDock\Tests\Support\IntegrationTestCase;
use RuntimeException;

/**
 * Database::transaction(): commit, rollback e, quando una transazione c'è già, i savepoint.
 * Si prova con la tabella rounds (giri), che ha solo un nome.
 */
final class DatabaseTransactionTest extends IntegrationTestCase
{
    public function testNestedSuccessKeepsItsWrites(): void
    {
        $this->database->transaction(fn () => $this->insertRound('Zz annidato ok'));

        self::assertSame(1, $this->roundsNamed('Zz annidato ok'));
    }

    public function testNestedFailureUndoesOnlyItsOwnWrites(): void
    {
        $this->insertRound('Zz scritto prima');

        $caught = null;
        try {
            $this->database->transaction(function (): void {
                $this->insertRound('Zz scritto dentro');
                throw new RuntimeException('errore a metà');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $caught, 'L\'eccezione doveva uscire dalla transazione, non essere ingoiata.');
        self::assertSame('errore a metà', $caught->getMessage());
        self::assertSame(0, $this->roundsNamed('Zz scritto dentro'), 'Quello scritto dentro si annulla.');
        self::assertSame(1, $this->roundsNamed('Zz scritto prima'), 'Quello scritto prima resta.');
    }

    public function testTwoNestedTransactionsAreIndependent(): void
    {
        $this->database->transaction(fn () => $this->insertRound('Zz primo'));

        $caught = null;
        try {
            $this->database->transaction(function (): void {
                $this->insertRound('Zz secondo');
                throw new RuntimeException('fallisce');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(RuntimeException::class, $caught, 'L\'eccezione doveva uscire dalla transazione.');

        $this->database->transaction(fn () => $this->insertRound('Zz terzo'));

        self::assertSame(1, $this->roundsNamed('Zz primo'));
        self::assertSame(0, $this->roundsNamed('Zz secondo'));
        self::assertSame(1, $this->roundsNamed('Zz terzo'));
    }

    public function testTopLevelFailureRollsBack(): void
    {
        // Chiude la transazione del test: qui serve provare quella "vera", senza nessuna già aperta.
        $this->database->pdo()->rollBack();

        $caught = null;
        try {
            $this->database->transaction(function (): void {
                $this->insertRound('Zz mai salvato');
                throw new RuntimeException('fallisce');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $caught, 'L\'eccezione doveva uscire dalla transazione, non essere ingoiata.');
        self::assertFalse($this->database->pdo()->inTransaction(), 'La transazione deve essere chiusa.');
        self::assertSame(0, $this->roundsNamed('Zz mai salvato'));
    }

    public function testReturnsTheCallbackResult(): void
    {
        self::assertSame(42, $this->database->transaction(static fn (): int => 42));
    }

    private function insertRound(string $name): void
    {
        $this->database->pdo()->prepare('INSERT INTO rounds (name) VALUES (?)')->execute([$name]);
    }

    private function roundsNamed(string $name): int
    {
        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM rounds WHERE name = ?');
        $statement->execute([$name]);

        return (int) $statement->fetchColumn();
    }
}
