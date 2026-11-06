<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\SupplierDocument;
use PDOException;

/**
 * Solo SQL sui documenti dei fornitori, con i totali dei loro bancali (conteggio, valore, peso).
 */
final class SupplierDocumentRepository
{
    /** Codice di errore MySQL per "Duplicate entry". */
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    /** Quanti documenti mostrare al massimo nella lista: i più recenti. */
    private const LIST_LIMIT = 200;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * I documenti più recenti per primi. Con $search filtra per numero o per fornitore.
     *
     * @return list<SupplierDocument>
     */
    public function search(string $search = ''): array
    {
        $sql = $this->baseSelect();
        $params = [];

        if ($search !== '') {
            // % e _ scritti dall'utente valgono come caratteri normali, non come jolly del LIKE.
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $sql .= ' WHERE d.number LIKE ? OR s.name LIKE ?';
            $params = [$like, $like];
        }

        $statement = $this->database->pdo()->prepare(
            $sql . ' GROUP BY d.id, s.name ORDER BY d.received_date DESC, d.id DESC LIMIT ' . self::LIST_LIMIT
        );
        $statement->execute($params);

        return array_map(SupplierDocument::fromRow(...), $statement->fetchAll());
    }

    public function findById(int $id): ?SupplierDocument
    {
        $statement = $this->database->pdo()->prepare($this->baseSelect() . ' WHERE d.id = ? GROUP BY d.id, s.name');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : SupplierDocument::fromRow($row);
    }

    /**
     * @throws DuplicateEntryException se quel fornitore ha già un documento con lo stesso numero e la stessa data
     */
    public function insert(
        int $supplierId,
        string $number,
        string $documentDate,
        string $receivedDate,
        ?string $notes,
        int $userId
    ): int {
        try {
            $this->database->pdo()
                ->prepare(
                    'INSERT INTO supplier_documents (supplier_id, number, document_date, received_date, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )
                ->execute([$supplierId, $number, $documentDate, $receivedDate, $notes, $userId]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_ENTRY) {
                throw new DuplicateEntryException('Documento già registrato', 0, $e);
            }

            throw $e;
        }

        return (int) $this->database->pdo()->lastInsertId();
    }

    private function baseSelect(): string
    {
        // ROUND porta valore e peso al numero di decimali che si mostrano, restando DECIMAL (stringhe in PHP).
        return 'SELECT d.*, s.name AS supplier_name,
                    COUNT(p.id) AS pallet_count,
                    ROUND(COALESCE(SUM(p.initial_quantity * p.unit_price), 0), 2) AS total_value,
                    ROUND(COALESCE(SUM(p.recorded_weight_kg), 0), 3) AS total_weight
                FROM supplier_documents d
                JOIN suppliers s ON s.id = d.supplier_id
                LEFT JOIN pallets p ON p.supplier_document_id = d.id';
    }
}
