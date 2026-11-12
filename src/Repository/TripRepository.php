<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\Trip;
use LogiDock\Model\TripItem;

/**
 * Solo SQL sui viaggi confermati e sui bancali che portano. Le regole (quando si conferma, quando
 * si annulla) stanno in TripService, che apre anche le transazioni.
 */
final class TripRepository
{
    /** Quanti viaggi mostrare al massimo nella lista. */
    private const LIST_LIMIT = 200;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * I viaggi più recenti per primi.
     *
     * @return list<Trip>
     */
    public function latest(): array
    {
        $statement = $this->database->pdo()->query(
            $this->baseSelect() . $this->groupBy() . ' ORDER BY t.planned_date DESC, t.id DESC LIMIT ' . self::LIST_LIMIT
        );

        return array_map(Trip::fromRow(...), $statement->fetchAll());
    }

    /**
     * I viaggi non annullati di un giorno.
     *
     * @return list<Trip>
     */
    public function onDate(string $date): array
    {
        $statement = $this->database->pdo()->prepare(
            $this->baseSelect() . " WHERE t.planned_date = ? AND t.status <> 'cancelled'" . $this->groupBy() . ' ORDER BY t.id'
        );
        $statement->execute([$date]);

        return array_map(Trip::fromRow(...), $statement->fetchAll());
    }

    public function findById(int $id): ?Trip
    {
        $statement = $this->database->pdo()->prepare($this->baseSelect() . ' WHERE t.id = ?' . $this->groupBy());
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : Trip::fromRow($row);
    }

    /**
     * I bancali di un viaggio, cliente per cliente (alfabetico: l'ordine vero delle tappe non è deciso).
     * L'ordine non dipende dall'ordine in cui i bancali sono stati scritti, così anche un bancale
     * spostato a mano da un altro viaggio finisce accanto a quelli dello stesso cliente.
     *
     * @return list<TripItem>
     */
    public function itemsOf(int $tripId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT ti.pallet_id, p.code AS pallet_code, pr.code AS product_code,
                    pr.description AS product_description, pr.unit_of_measure,
                    ti.quantity, ti.weight_kg, pt.name AS pallet_type_name,
                    o.id AS order_id, o.code AS order_code, c.id AS customer_id, c.name AS customer_name,
                    ti.is_locked, u.name AS locked_by_name, ti.locked_at
             FROM trip_items ti
             JOIN pallets p ON p.id = ti.pallet_id
             JOIN products pr ON pr.id = p.product_id
             LEFT JOIN pallet_types pt ON pt.id = p.pallet_type_id
             JOIN orders o ON o.id = ti.order_id
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN users u ON u.id = ti.locked_by
             WHERE ti.trip_id = ?
             ORDER BY c.name, c.id, o.id, ti.sort_order, ti.id'
        );
        $statement->execute([$tripId]);

        return array_map(TripItem::fromRow(...), $statement->fetchAll());
    }

    /**
     * @param string|null $carrierFee compenso del trasportatore fotografato alla conferma
     */
    public function insert(
        string $code,
        int $roundId,
        int $vehicleId,
        ?int $carrierId,
        ?string $carrierFee,
        string $plannedDate,
        string $status,
        int $userId
    ): int {
        $this->database->pdo()
            ->prepare(
                'INSERT INTO trips (code, round_id, vehicle_id, carrier_id, carrier_fee, planned_date, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )
            ->execute([$code, $roundId, $vehicleId, $carrierId, $carrierFee, $plannedDate, $status, $userId]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    public function insertItem(
        int $tripId,
        int $palletId,
        int $orderId,
        int $orderLineId,
        string $quantity,
        string $weightKg,
        int $sortOrder
    ): void {
        $this->database->pdo()
            ->prepare(
                'INSERT INTO trip_items (trip_id, pallet_id, order_id, order_line_id, quantity, weight_kg, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )
            ->execute([$tripId, $palletId, $orderId, $orderLineId, $quantity, $weightKg, $sortOrder]);
    }

    /**
     * Cambia lo stato di un viaggio, ma solo se è ancora in quello atteso: la condizione sta dentro
     * l'UPDATE, così due click insieme (o un viaggio nel frattempo partito) non fanno danni.
     *
     * @return bool false se il viaggio non era in quello stato (non è stato toccato)
     */
    public function changeStatus(int $id, string $from, string $to): bool
    {
        $statement = $this->database->pdo()->prepare('UPDATE trips SET status = ? WHERE id = ? AND status = ?');
        $statement->execute([$to, $id, $from]);

        return $statement->rowCount() === 1;
    }

    /**
     * La richiesta ha ancora bancali su un viaggio non annullato? Lettura con blocco (FOR SHARE):
     * guarda l'ultimo dato salvato.
     */
    public function orderHasActiveItems(int $orderId): bool
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT ti.id FROM trip_items ti
             JOIN trips t ON t.id = ti.trip_id
             WHERE ti.order_id = ? AND t.status <> 'cancelled'
             LIMIT 1 FOR SHARE"
        );
        $statement->execute([$orderId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Sposta un bancale su un altro viaggio e lo blocca (scelta fatta a mano da $userId).
     *
     * @return bool false se il bancale non era su quel viaggio (non è stato toccato)
     */
    public function moveItem(int $fromTripId, int $palletId, int $toTripId, int $userId, string $now): bool
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE trip_items SET trip_id = ?, is_locked = 1, locked_by = ?, locked_at = ?
             WHERE trip_id = ? AND pallet_id = ?'
        );
        $statement->execute([$toTripId, $userId, $now, $fromTripId, $palletId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Blocca o sblocca un bancale di un viaggio. Un bancale bloccato non viene spostato dal ricalcolo.
     *
     * @return bool false se il bancale non è su quel viaggio
     */
    public function setItemLock(int $tripId, int $palletId, bool $locked, int $userId, string $now): bool
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE trip_items SET is_locked = ?, locked_by = ?, locked_at = ? WHERE trip_id = ? AND pallet_id = ?'
        );
        // MySQL conta come "modificata" solo una riga che cambia davvero: per sapere se esiste si guarda a parte.
        $statement->execute([$locked ? 1 : 0, $locked ? $userId : null, $locked ? $now : null, $tripId, $palletId]);

        return $statement->rowCount() === 1 || $this->hasItem($tripId, $palletId);
    }

    /**
     * Cambia il mezzo di un viaggio e aggiorna la fotografia del trasportatore e del suo compenso.
     */
    public function changeVehicle(int $tripId, int $vehicleId, ?int $carrierId, ?string $carrierFee): void
    {
        $this->database->pdo()
            ->prepare('UPDATE trips SET vehicle_id = ?, carrier_id = ?, carrier_fee = ? WHERE id = ?')
            ->execute([$vehicleId, $carrierId, $carrierFee, $tripId]);
    }

    /**
     * Blocca la riga di un mezzo fino alla fine della transazione: chi vuole usare lo stesso mezzo
     * nello stesso momento aspetta il suo turno.
     */
    public function lockVehicle(int $vehicleId): void
    {
        $statement = $this->database->pdo()->prepare('SELECT id FROM vehicles WHERE id = ? FOR UPDATE');
        $statement->execute([$vehicleId]);
        $statement->fetchAll();
    }

    /**
     * Il mezzo ha già un viaggio (non annullato) in quel giorno? Lettura con blocco: guarda l'ultimo
     * dato salvato, non la "fotografia" della transazione.
     */
    public function vehicleIsBusy(int $vehicleId, string $date, int $exceptTripId = 0): bool
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT id FROM trips
             WHERE vehicle_id = ? AND planned_date = ? AND status <> 'cancelled' AND id <> ?
             LIMIT 1 FOR SHARE"
        );
        $statement->execute([$vehicleId, $date, $exceptTripId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Gli id dei bancali NON bloccati di certi viaggi pianificati, in ordine di id (si prendono
     * sempre nello stesso ordine). Lettura con blocco: serve al ricalcolo.
     *
     * @param list<int> $tripIds
     * @return list<int>
     */
    public function unlockedPalletIdsOfTrips(array $tripIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($tripIds), '?'));
        $statement = $this->database->pdo()->prepare(
            "SELECT ti.pallet_id FROM trip_items ti
             JOIN trips t ON t.id = ti.trip_id
             WHERE ti.trip_id IN ($placeholders) AND t.status = 'planned' AND ti.is_locked = 0
             ORDER BY ti.pallet_id
             FOR UPDATE"
        );
        $statement->execute($tripIds);

        return array_map(static fn ($id): int => (int) $id, $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Toglie da certi viaggi pianificati i bancali non bloccati (il ricalcolo li riassegna).
     *
     * @param list<int> $tripIds
     */
    public function deleteUnlockedItemsOfTrips(array $tripIds): void
    {
        $placeholders = implode(', ', array_fill(0, count($tripIds), '?'));
        $this->database->pdo()
            ->prepare(
                "DELETE ti FROM trip_items ti
                 JOIN trips t ON t.id = ti.trip_id
                 WHERE ti.trip_id IN ($placeholders) AND t.status = 'planned' AND ti.is_locked = 0"
            )
            ->execute($tripIds);
    }

    private function hasItem(int $tripId, int $palletId): bool
    {
        $statement = $this->database->pdo()->prepare('SELECT 1 FROM trip_items WHERE trip_id = ? AND pallet_id = ?');
        $statement->execute([$tripId, $palletId]);

        return $statement->fetchColumn() !== false;
    }

    private function baseSelect(): string
    {
        return 'SELECT t.id, t.code, t.planned_date, t.status, t.carrier_fee, t.round_id, t.vehicle_id,
                    COALESCE(SUM(ti.is_locked), 0) AS locked_count,
                    r.name AS round_name, v.plate AS vehicle_plate, v.description AS vehicle_description,
                    tt.name AS trailer_type_name, c.name AS carrier_name, c.tax_code AS carrier_tax_code,
                    COUNT(ti.id) AS pallet_count, COALESCE(SUM(ti.weight_kg), 0) AS total_weight_kg
                FROM trips t
                JOIN rounds r ON r.id = t.round_id
                JOIN vehicles v ON v.id = t.vehicle_id
                LEFT JOIN trailer_types tt ON tt.id = v.trailer_type_id
                LEFT JOIN carriers c ON c.id = t.carrier_id
                LEFT JOIN trip_items ti ON ti.trip_id = t.id';
    }

    private function groupBy(): string
    {
        return ' GROUP BY t.id, r.name, v.plate, v.description, tt.name, c.name, c.tax_code';
    }
}
