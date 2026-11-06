<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogicException;
use PDOException;

/**
 * Parte comune dei repository delle anagrafiche (fornitori, clienti, veicoli...): ricerca, lettura,
 * inserimento, modifica e attivazione. Le sottoclassi dicono solo CHE tabella e CHE colonne.
 *
 * Sicurezza: nell'SQL entrano soltanto nomi di tabelle e colonne scritti nel codice delle
 * sottoclassi (mai input dell'utente). I VALORI passano sempre da prepared statement.
 *
 * @template T of object
 */
abstract class MasterDataRepository
{
    /** Codice di errore MySQL per "Duplicate entry". */
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(protected readonly Database $database)
    {
    }

    abstract protected function table(): string;

    /**
     * Colonne scrivibili. I valori di insert() e update() arrivano con queste chiavi.
     *
     * @return list<string>
     */
    abstract protected function columns(): array;

    /**
     * Colonne in cui cerca la ricerca testuale (LIKE).
     *
     * @return list<string>
     */
    abstract protected function searchColumns(): array;

    /**
     * @param array<string, mixed> $row
     * @return T
     */
    abstract protected function hydrate(array $row): object;

    /** Colonne extra da leggere insieme alla riga, es. ", r.name AS round_name". */
    protected function extraSelect(): string
    {
        return '';
    }

    /** JOIN per le colonne extra. La tabella principale ha sempre l'alias "t". */
    protected function joins(): string
    {
        return '';
    }

    protected function orderBy(): string
    {
        return 't.is_active DESC, t.name ASC';
    }

    /**
     * @return list<T>
     */
    public function search(string $search = ''): array
    {
        $sql = $this->baseSelect();
        $params = [];

        if ($search !== '') {
            // % e _ scritti dall'utente valgono come caratteri normali, non come jolly del LIKE.
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $conditions = array_map(static fn (string $column): string => $column . ' LIKE ?', $this->searchColumns());
            $sql .= ' WHERE ' . implode(' OR ', $conditions);
            $params = array_fill(0, count($conditions), $like);
        }

        $statement = $this->database->pdo()->prepare($sql . ' ORDER BY ' . $this->orderBy());
        $statement->execute($params);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * @return T|null
     */
    public function findById(int $id): ?object
    {
        $statement = $this->database->pdo()->prepare($this->baseSelect() . ' WHERE t.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, string|int|null> $values colonna => valore, già validati dal service
     * @throws DuplicateEntryException se una colonna UNIQUE ha già quel valore
     */
    public function insert(array $values): int
    {
        $columns = $this->columns();
        $sql = 'INSERT INTO ' . $this->table() . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';

        try {
            $this->database->pdo()->prepare($sql)->execute($this->orderedValues($values));
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * @param array<string, string|int|null> $values
     * @throws DuplicateEntryException
     */
    public function update(int $id, array $values): void
    {
        $assignments = array_map(static fn (string $column): string => $column . ' = ?', $this->columns());
        $sql = 'UPDATE ' . $this->table() . ' SET ' . implode(', ', $assignments) . ' WHERE id = ?';

        try {
            $this->database->pdo()->prepare($sql)->execute([...$this->orderedValues($values), $id]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->pdo()
            ->prepare('UPDATE ' . $this->table() . ' SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    private function baseSelect(): string
    {
        return 'SELECT t.*' . $this->extraSelect() . ' FROM ' . $this->table() . ' t ' . $this->joins();
    }

    /**
     * Mette i valori nell'ordine delle colonne. Una colonna mancante è un errore di programma
     * (non dell'utente), quindi LogicException.
     *
     * @param array<string, string|int|null> $values
     * @return list<string|int|null>
     */
    private function orderedValues(array $values): array
    {
        $ordered = [];
        foreach ($this->columns() as $column) {
            if (!array_key_exists($column, $values)) {
                throw new LogicException('Manca il valore della colonna ' . $column . ' per ' . $this->table());
            }
            $ordered[] = $values[$column];
        }

        return $ordered;
    }

    private function translate(PDOException $e): \Throwable
    {
        if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_ENTRY) {
            return new DuplicateEntryException('Valore duplicato', 0, $e);
        }

        return $e;
    }
}
