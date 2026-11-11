<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\Order;
use LogiDock\Model\OrderLine;

/**
 * Solo SQL sulle richieste dei clienti e sulle loro righe. Le regole (chi, quando, cosa si può
 * modificare) stanno in OrderService, che apre anche le transazioni.
 */
final class OrderRepository
{
    /** Quante richieste mostrare al massimo nella lista. */
    private const LIST_LIMIT = 200;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Le richieste con consegna più vicina per prime (le più recenti in cima). Si può filtrare per
     * codice o cliente e per stato.
     *
     * @return list<Order>
     */
    public function search(string $search = '', ?string $status = null): array
    {
        $conditions = [];
        $params = [];

        if ($search !== '') {
            // % e _ scritti dall'utente valgono come caratteri normali, non come jolly del LIKE.
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $conditions[] = '(o.code LIKE ? OR c.name LIKE ?)';
            array_push($params, $like, $like);
        }
        if ($status !== null) {
            $conditions[] = 'o.status = ?';
            $params[] = $status;
        }

        $sql = $this->baseSelect()
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            . ' GROUP BY o.id, c.name, r.name ORDER BY o.delivery_date DESC, o.id DESC LIMIT ' . self::LIST_LIMIT;

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return array_map(Order::fromRow(...), $statement->fetchAll());
    }

    public function findById(int $id): ?Order
    {
        $statement = $this->database->pdo()->prepare($this->baseSelect() . ' WHERE o.id = ? GROUP BY o.id, c.name, r.name');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : Order::fromRow($row);
    }

    /**
     * Le righe di una richiesta, con la giacenza attuale di ogni prodotto (bancali in magazzino).
     *
     * @return list<OrderLine>
     */
    public function linesOf(int $orderId): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT l.id, l.product_id, l.quantity, l.unit_price,
                    p.code AS product_code, p.description AS product_description, p.unit_of_measure,
                    ROUND(l.quantity * l.unit_price, 2) AS line_value,
                    s.stock_quantity,
                    (s.stock_quantity < l.quantity) AS stock_short
             FROM order_lines l
             JOIN products p ON p.id = l.product_id
             JOIN (
                 SELECT ol.product_id, COALESCE(SUM(pa.quantity), 0) AS stock_quantity
                 FROM order_lines ol
                 LEFT JOIN pallets pa ON pa.product_id = ol.product_id AND pa.status = 'in_stock'
                 WHERE ol.order_id = ?
                 GROUP BY ol.product_id
             ) s ON s.product_id = l.product_id
             WHERE l.order_id = ?
             ORDER BY l.id"
        );
        $statement->execute([$orderId, $orderId]);

        return array_map(OrderLine::fromRow(...), $statement->fetchAll());
    }

    public function insert(
        string $code,
        int $customerId,
        string $requestedDate,
        string $deliveryDate,
        ?string $notes,
        int $userId
    ): int {
        $this->database->pdo()
            ->prepare(
                'INSERT INTO orders (code, customer_id, requested_date, delivery_date, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )
            ->execute([$code, $customerId, $requestedDate, $deliveryDate, $notes, $userId]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    public function updateHeader(int $id, int $customerId, string $requestedDate, string $deliveryDate, ?string $notes): void
    {
        $this->database->pdo()
            ->prepare('UPDATE orders SET customer_id = ?, requested_date = ?, delivery_date = ?, notes = ? WHERE id = ?')
            ->execute([$customerId, $requestedDate, $deliveryDate, $notes, $id]);
    }

    /**
     * Sostituisce tutte le righe di una richiesta con quelle nuove (va chiamata dentro una transazione).
     *
     * @param list<array{product_id: int, quantity: string, unit_price: string}> $lines
     */
    public function replaceLines(int $orderId, array $lines): void
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('DELETE FROM order_lines WHERE order_id = ?')->execute([$orderId]);

        $insert = $pdo->prepare('INSERT INTO order_lines (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
        foreach ($lines as $line) {
            $insert->execute([$orderId, $line['product_id'], $line['quantity'], $line['unit_price']]);
        }
    }

    public function setStatus(int $id, string $status): void
    {
        $this->database->pdo()->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /**
     * Legge lo stato di una richiesta e blocca la sua riga fino alla fine della transazione
     * (FOR UPDATE): chi vuole modificarla o pianificarla nello stesso momento aspetta il suo turno.
     * Una lettura con blocco vede sempre l'ultimo dato salvato. Va chiamata dentro una transazione.
     *
     * @return string|null lo stato, o null se la richiesta non esiste
     */
    public function lockStatus(int $id): ?string
    {
        $statement = $this->database->pdo()->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);
        $status = $statement->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    /**
     * Blocca, fino alla fine della transazione, le richieste ancora da servire con consegna in quel
     * giorno: mentre si conferma il piano nessuno può modificarle o annullarle.
     *
     * @return array<int, string> id della richiesta => stato (come l'ultimo dato salvato)
     */
    public function lockByDeliveryDate(string $date): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT id, status FROM orders WHERE delivery_date = ? AND status IN ('open', 'planned') ORDER BY id FOR UPDATE"
        );
        $statement->execute([$date]);

        $statuses = [];
        foreach ($statement->fetchAll() as $row) {
            $statuses[(int) $row['id']] = (string) $row['status'];
        }

        return $statuses;
    }

    /**
     * Blocca le richieste servite da due o più viaggi con UN solo comando, in ordine di id: due
     * persone che spostano bancali in direzioni opposte (A verso B e B verso A) prendono i blocchi
     * nello stesso ordine e si mettono in fila invece di bloccarsi a vicenda.
     *
     * @param list<int> $tripIds
     */
    public function lockByTrips(array $tripIds): void
    {
        $placeholders = implode(', ', array_fill(0, count($tripIds), '?'));
        $statement = $this->database->pdo()->prepare(
            "SELECT o.id FROM orders o
             WHERE o.id IN (SELECT ti.order_id FROM trip_items ti WHERE ti.trip_id IN ($placeholders))
             ORDER BY o.id
             FOR UPDATE"
        );
        $statement->execute($tripIds);
        $statement->fetchAll();
    }

    /**
     * Blocca le richieste servite da un viaggio e restituisce il loro stato.
     *
     * @return array<int, string> id della richiesta => stato
     */
    public function lockByTrip(int $tripId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT o.id, o.status FROM orders o
             WHERE o.id IN (SELECT ti.order_id FROM trip_items ti WHERE ti.trip_id = ?)
             ORDER BY o.id
             FOR UPDATE'
        );
        $statement->execute([$tripId]);

        $statuses = [];
        foreach ($statement->fetchAll() as $row) {
            $statuses[(int) $row['id']] = (string) $row['status'];
        }

        return $statuses;
    }

    private function baseSelect(): string
    {
        return 'SELECT o.*, c.name AS customer_name, r.name AS round_name,
                    COUNT(l.id) AS line_count,
                    ROUND(COALESCE(SUM(l.quantity * l.unit_price), 0), 2) AS total_value
                FROM orders o
                JOIN customers c ON c.id = o.customer_id
                LEFT JOIN rounds r ON r.id = c.round_id
                LEFT JOIN order_lines l ON l.order_id = o.id';
    }
}
