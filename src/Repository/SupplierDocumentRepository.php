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

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Una pagina della lista: i documenti piu' recenti per primi. Con $search filtra per numero o per fornitore.
     *
     * @return list<SupplierDocument>
     */
    public function searchPage(string $search, int $limit, int $offset): array
    {
        [$where, $params] = $this->searchCondition($search);

        $statement = $this->database->pdo()->prepare(
            $this->baseSelect() . $where . ' GROUP BY d.id, s.name ORDER BY d.received_date DESC, d.id DESC'
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $statement->execute($params);

        return array_map(SupplierDocument::fromRow(...), $statement->fetchAll());
    }

    /** Quanti documenti trova la lista con questa ricerca (stessa condizione di searchPage). */
    public function countSearch(string $search): int
    {
        [$where, $params] = $this->searchCondition($search);

        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM supplier_documents d JOIN suppliers s ON s.id = d.supplier_id' . $where
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * La condizione WHERE della ricerca (scritta una volta sola, per la lista e per il conteggio).
     *
     * @return array{string, list<string>}
     */
    private function searchCondition(string $search): array
    {
        if ($search === '') {
            return ['', []];
        }

        // % e _ scritti dall'utente valgono come caratteri normali, non come jolly del LIKE.
        $like = '%' . addcslashes($search, '%_\\') . '%';

        return [' WHERE d.number LIKE ? OR s.name LIKE ?', [$like, $like]];
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
