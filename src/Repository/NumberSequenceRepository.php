<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;

/**
 * Numeratori progressivi per anno (bancali, viaggi, documenti), nella tabella number_sequences.
 */
final class NumberSequenceRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Restituisce il prossimo numero della sequenza (1, 2, 3... per ogni tipo e anno).
     *
     * Funziona solo DENTRO una transazione aperta dal service: la riga resta bloccata finché la
     * transazione non finisce, quindi due arrivi contemporanei non possono avere lo stesso numero,
     * e se la transazione viene annullata anche il numero torna indietro (nessun buco).
     */
    public function next(string $type, int $year): int
    {
        $pdo = $this->database->pdo();

        // LAST_INSERT_ID(espressione) memorizza il nuovo valore nella connessione: si rilegge subito dopo.
        $pdo->prepare(
            'INSERT INTO number_sequences (type, year, last_number) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        )->execute([$type, $year]);

        return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }
}
