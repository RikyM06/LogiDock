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
     * Blocca le righe indicate (FOR UPDATE) fino alla fine della transazione, SEMPRE in ordine di id: due
     * transazioni che ne bloccano alcune in comune si mettono in fila invece di bloccarsi a vicenda.
     * Va chiamato dentro una transazione.
     *
     * @param list<int> $ids
     */
    public function lockForUpdate(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->database->pdo()->prepare(
            'SELECT id FROM ' . $this->table() . ' WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE'
        );
        $statement->execute($ids);
        $statement->fetchAll();
    }

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
        [$where, $params] = $this->searchCondition($search);

        $statement = $this->database->pdo()->prepare($this->baseSelect() . $where . ' ORDER BY ' . $this->orderBy());
        $statement->execute($params);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * Una pagina della stessa lista di search(): stessa condizione, stesso ordine.
     *
     * @return list<T>
     */
    public function searchPage(string $search, int $limit, int $offset): array
    {
        [$where, $params] = $this->searchCondition($search);

        $statement = $this->database->pdo()->prepare(
            $this->baseSelect() . $where . ' ORDER BY ' . $this->orderBy() . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $statement->execute($params);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /** Quante righe trova search() con questa ricerca (stessa condizione di searchPage). */
    public function countSearch(string $search): int
    {
        [$where, $params] = $this->searchCondition($search);

        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM ' . $this->table() . ' t ' . $this->joins() . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * La condizione WHERE della ricerca testuale (scritta una volta sola, per la lista e per il conteggio).
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
        $conditions = array_map(static fn (string $column): string => $column . ' LIKE ?', $this->searchColumns());

        return [' WHERE ' . implode(' OR ', $conditions), array_fill(0, count($conditions), $like)];
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
