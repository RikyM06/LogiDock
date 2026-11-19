<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\Product;
use LogiDock\Model\ProductData;
use PDOException;

/**
 * Solo SQL: nessuna regola di business (la validazione sta in ProductService).
 */
final class ProductRepository
{
    /** Codice di errore MySQL per "Duplicate entry". */
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Prodotti attivi per primi, poi per codice. Con $search filtra per codice o descrizione.
     *
     * @return list<Product>
     */
    public function search(string $search = ''): array
    {
        [$where, $params] = $this->searchCondition($search);

        $statement = $this->database->pdo()->prepare('SELECT * FROM products' . $where . ' ORDER BY is_active DESC, code ASC');
        $statement->execute($params);

        return array_map(Product::fromRow(...), $statement->fetchAll());
    }

    /**
     * Una pagina della stessa lista di search(): stessa condizione, stesso ordine.
     *
     * @return list<Product>
     */
    public function searchPage(string $search, int $limit, int $offset): array
    {
        [$where, $params] = $this->searchCondition($search);

        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM products' . $where . ' ORDER BY is_active DESC, code ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $statement->execute($params);

        return array_map(Product::fromRow(...), $statement->fetchAll());
    }

    /** Quante righe trova search() con questa ricerca (stessa condizione di searchPage). */
    public function countSearch(string $search): int
    {
        [$where, $params] = $this->searchCondition($search);

        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM products' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * La condizione WHERE della ricerca (scritta una volta sola, per la lista e per il conteggio).
     *
     * @return array{string, list<string>} testo SQL (vuoto se non si cerca nulla) e valori da legare
     */
    private function searchCondition(string $search): array
    {
        if ($search === '') {
            return ['', []];
        }

        // % e _ scritti dall'utente valgono come caratteri normali, non come jolly del LIKE.
        $like = '%' . addcslashes($search, '%_\\') . '%';

        return [' WHERE code LIKE ? OR description LIKE ?', [$like, $like]];
    }

    public function findById(int $id): ?Product
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM products WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : Product::fromRow($row);
    }

    public function findByCode(string $code): ?Product
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM products WHERE code = ?');
        $statement->execute([$code]);
        $row = $statement->fetch();

        return $row === false ? null : Product::fromRow($row);
    }

    /**
     * @throws DuplicateEntryException se il codice esiste già
     */
    public function insert(ProductData $data): int
    {
        try {
            $this->database->pdo()
                ->prepare('INSERT INTO products (code, description, unit_of_measure, unit_weight_kg) VALUES (?, ?, ?, ?)')
                ->execute([$data->code, $data->description, $data->unitOfMeasure, $data->unitWeightKg]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * @throws DuplicateEntryException se il nuovo codice esiste già
     */
    public function update(int $id, ProductData $data): void
    {
        try {
            $this->database->pdo()
                ->prepare('UPDATE products SET code = ?, description = ?, unit_of_measure = ?, unit_weight_kg = ? WHERE id = ?')
                ->execute([$data->code, $data->description, $data->unitOfMeasure, $data->unitWeightKg, $id]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->pdo()
            ->prepare('UPDATE products SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    private function translate(PDOException $e): \Throwable
    {
        if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_ENTRY) {
            return new DuplicateEntryException('Valore duplicato', 0, $e);
        }

        return $e;
    }
}
