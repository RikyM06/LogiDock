<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\PlanAssignment;
use LogiDock\Model\PlanDemand;
use LogiDock\Model\PlanningDay;
use LogiDock\Model\PlanPallet;
use LogiDock\Model\PlanSeedTrip;
use LogiDock\Model\PlanVehicle;

/**
 * Solo letture: i dati di partenza della pianificazione (cosa è richiesto, quali bancali e quali
 * mezzi sono liberi). Le regole stanno in TripPlanner e TripService; qui c'è solo l'SQL.
 */
final class PlanningRepository
{
    /**
     * Quanto di una riga è già su viaggi confermati. Il LEFT JOIN su trips esclude i viaggi
     * annullati: per quelli t.id è NULL, quindi il loro bancale non conta.
     */
    private const PLANNED_QUANTITY = 'COALESCE(SUM(CASE WHEN t.id IS NOT NULL THEN ti.quantity END), 0)';

    /** Le righe delle richieste con i viaggi che le servono (si aggiunge dopo "FROM orders o"). */
    private const LINES_WITH_TRIPS = "JOIN order_lines l ON l.order_id = o.id
             LEFT JOIN trip_items ti ON ti.order_line_id = l.id
             LEFT JOIN trips t ON t.id = ti.trip_id AND t.status <> 'cancelled'";

    /**
     * Come PLANNED_QUANTITY, ma per il ricalcolo del giorno: i bancali NON bloccati dei viaggi ancora
     * pianificati non contano come già serviti (verranno riassegnati), quelli bloccati sì.
     */
    private const PLANNED_QUANTITY_REPLAN = "COALESCE(SUM(CASE WHEN t.id IS NOT NULL
             AND NOT (t.status = 'planned' AND ti.is_locked = 0) THEN ti.quantity END), 0)";

    /** Si pianificano le richieste aperte e quelle già pianificate solo in parte. */
    private const PLANNABLE_ORDERS = "o.status IN ('open', 'planned')";

    /**
     * Le colonne di un bancale come le vuole PlanPallet. Un bancale senza tipo (arrivi vecchi) vale
     * come il tipo di ripiego; senza altezza propria vale l'altezza di default. Servono due parametri,
     * nell'ordine: altezza di default e tipo di ripiego.
     */
    private const PALLET_COLUMNS = 'p.id, p.code, p.product_id, pr.code AS product_code, pr.description AS product_description,
                    pr.unit_of_measure, p.quantity,
                    COALESCE(p.recorded_weight_kg, ROUND(p.quantity * pr.unit_weight_kg, 3)) AS weight_kg,
                    pt.id AS pallet_type_id, pt.name AS pallet_type_name, pt.length_mm, pt.width_mm,
                    COALESCE(pt.max_height_mm, CAST(? AS UNSIGNED)) AS height_mm,
                    (p.pallet_type_id IS NULL) AS type_assumed,
                    p.received_at, l.code AS location_code';

    private const PALLET_JOINS = 'FROM pallets p
             JOIN products pr ON pr.id = p.product_id
             JOIN pallet_types pt ON pt.id = COALESCE(p.pallet_type_id, CAST(? AS UNSIGNED))
             LEFT JOIN warehouse_locations l ON l.id = p.location_id';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Le righe con consegna in quel giorno che hanno ancora qualcosa da pianificare, nell'ordine
     * di precedenza: la richiesta registrata per prima passa per prima.
     *
     * @param bool $replan true = nel "già pianificato" non contano i bancali non bloccati dei viaggi
     *                     ancora pianificati (il ricalcolo li riassegna)
     * @return list<PlanDemand>
     */
    public function openDemands(string $date, bool $replan = false): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT o.id AS order_id, o.code AS order_code, l.id AS order_line_id,
                    c.id AS customer_id, c.name AS customer_name, r.id AS round_id, r.name AS round_name,
                    pr.id AS product_id, pr.code AS product_code, pr.description AS product_description,
                    pr.unit_of_measure, l.quantity,
                    ' . ($replan ? self::PLANNED_QUANTITY_REPLAN : self::PLANNED_QUANTITY) . ' AS planned_quantity
             FROM orders o
             ' . self::LINES_WITH_TRIPS . '
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN rounds r ON r.id = c.round_id
             JOIN products pr ON pr.id = l.product_id
             WHERE o.delivery_date = ? AND ' . self::PLANNABLE_ORDERS . '
             GROUP BY o.id, o.code, l.id, c.id, c.name, r.id, r.name,
                      pr.id, pr.code, pr.description, pr.unit_of_measure, l.quantity
             HAVING planned_quantity < l.quantity
             ORDER BY o.id, l.id'
        );
        $statement->execute([$date]);

        return array_map(PlanDemand::fromRow(...), $statement->fetchAll());
    }

    /**
     * I giorni di consegna con almeno una riga ancora da pianificare, dal più vicino.
     *
     * @return list<PlanningDay>
     */
    public function planningDays(): array
    {
        $statement = $this->database->pdo()->query(
            'SELECT d.delivery_date, COUNT(DISTINCT d.order_id) AS order_count, COUNT(*) AS line_count
             FROM (
                 SELECT o.delivery_date, o.id AS order_id, l.id AS order_line_id, l.quantity,
                        ' . self::PLANNED_QUANTITY . ' AS planned_quantity
                 FROM orders o
                 ' . self::LINES_WITH_TRIPS . '
                 WHERE ' . self::PLANNABLE_ORDERS . '
                 GROUP BY o.delivery_date, o.id, l.id, l.quantity
                 HAVING planned_quantity < l.quantity
             ) d
             GROUP BY d.delivery_date
             ORDER BY d.delivery_date'
        );

        return array_map(PlanningDay::fromRow(...), $statement->fetchAll());
    }

    /**
     * I bancali liberi dei prodotti richiesti, dal più vecchio (chi è arrivato prima esce prima).
     * "Libero" = in magazzino, non prenotato, con ancora qualcosa sopra.
     *
     * Il peso è quello segnato dal fornitore; se manca, quantità x peso unitario (lo calcola MySQL
     * in DECIMAL). Un bancale senza tipo (arrivi vecchi) viene trattato come il tipo di ripiego
     * scelto dal service, e la colonna type_assumed lo segnala.
     *
     * @param list<int>   $productIds
     * @param int         $fallbackTypeId  tipo di bancale da usare quando sul bancale non è indicato
     * @param int         $defaultHeightMm altezza da usare quando il tipo di bancale non ha la sua
     * @param string|null $replanDate      per il ricalcolo di quel giorno: contano come liberi anche i
     *                                     bancali prenotati da righe NON bloccate dei viaggi ancora
     *                                     pianificati (verranno riassegnati)
     * @return list<PlanPallet>
     */
    public function availablePallets(
        array $productIds,
        int $fallbackTypeId,
        int $defaultHeightMm,
        ?string $replanDate = null
    ): array {
        if ($productIds === []) {
            return [];
        }

        // Un "?" per ogni prodotto: i valori passano come parametri, mai dentro il testo dell'SQL.
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));

        $params = [$defaultHeightMm, $fallbackTypeId];
        $orFreedByReplan = '';
        if ($replanDate !== null) {
            $orFreedByReplan = " OR p.id IN (
                 SELECT ti.pallet_id FROM trip_items ti
                 JOIN trips t ON t.id = ti.trip_id
                 WHERE t.planned_date = ? AND t.status = 'planned' AND ti.is_locked = 0
             )";
            $params[] = $replanDate;
        }
        array_push($params, ...$productIds);

        $statement = $this->database->pdo()->prepare(
            'SELECT ' . self::PALLET_COLUMNS . '
             ' . self::PALLET_JOINS . "
             WHERE (p.status = 'in_stock'$orFreedByReplan) AND p.quantity > 0 AND p.product_id IN ($placeholders)
             ORDER BY p.received_at, p.id"
        );
        $statement->execute($params);

        return array_map(PlanPallet::fromRow(...), $statement->fetchAll());
    }

    /**
     * I bancali caricati su un viaggio (anche se prenotati), nella forma che serve al calcolo del carico.
     *
     * @return list<PlanPallet>
     */
    public function palletsOfTrip(int $tripId, int $fallbackTypeId, int $defaultHeightMm): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT ' . self::PALLET_COLUMNS . '
             ' . self::PALLET_JOINS . '
             JOIN trip_items ti ON ti.pallet_id = p.id
             WHERE ti.trip_id = ?
             ORDER BY p.received_at, p.id'
        );
        $statement->execute([$defaultHeightMm, $fallbackTypeId, $tripId]);

        return array_map(PlanPallet::fromRow(...), $statement->fetchAll());
    }

    /**
     * I viaggi ancora "pianificati" di un giorno, con il mezzo e i bancali che portano: il punto di
     * partenza del piano. Un viaggio il cui mezzo non ha più un tipo di rimorchio non si può
     * ricalcolare (mancano le misure): non compare, resta com'è.
     *
     * @return list<PlanSeedTrip>
     */
    public function seedTrips(string $date, int $fallbackTypeId, int $defaultHeightMm): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT t.id AS trip_id, t.code AS trip_code, t.round_id, r.name AS round_name,
                    v.id, v.plate, v.description, v.max_pallets, v.max_weight_kg,
                    tt.name AS trailer_type_name, tt.length_mm, tt.width_mm, tt.inner_height_mm,
                    tt.max_weight_kg AS trailer_max_weight_kg,
                    c.id AS carrier_id, c.name AS carrier_name, c.trip_fee AS carrier_fee
             FROM trips t
             JOIN rounds r ON r.id = t.round_id
             JOIN vehicles v ON v.id = t.vehicle_id
             JOIN trailer_types tt ON tt.id = v.trailer_type_id
             LEFT JOIN carriers c ON c.id = v.carrier_id
             WHERE t.planned_date = ? AND t.status = 'planned'
             ORDER BY t.id"
        );
        $statement->execute([$date]);
        $tripRows = $statement->fetchAll();

        if ($tripRows === []) {
            return [];
        }

        $items = $this->seedItems($date, $fallbackTypeId, $defaultHeightMm);

        $seeds = [];
        foreach ($tripRows as $row) {
            $tripId = (int) $row['trip_id'];
            $seeds[] = new PlanSeedTrip(
                $tripId,
                (string) $row['trip_code'],
                (int) $row['round_id'],
                (string) $row['round_name'],
                PlanVehicle::fromRow($row),
                $items[$tripId] ?? []
            );
        }

        return $seeds;
    }

    /**
     * Il mezzo con l'id dato (con le misure del suo rimorchio), anche se oggi è occupato.
     * Null se non esiste o non ha un tipo di rimorchio.
     */
    public function vehicle(int $id): ?PlanVehicle
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT v.id, v.plate, v.description, v.max_pallets, v.max_weight_kg,
                    tt.name AS trailer_type_name, tt.length_mm, tt.width_mm, tt.inner_height_mm,
                    tt.max_weight_kg AS trailer_max_weight_kg,
                    c.id AS carrier_id, c.name AS carrier_name, c.trip_fee AS carrier_fee
             FROM vehicles v
             JOIN trailer_types tt ON tt.id = v.trailer_type_id
             LEFT JOIN carriers c ON c.id = v.carrier_id
             WHERE v.id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : PlanVehicle::fromRow($row);
    }

    /**
     * I bancali di tutti i viaggi pianificati di un giorno, ognuno con la riga di richiesta che serve.
     *
     * @return array<int, list<PlanAssignment>> id del viaggio => bancali (con "locked" impostato)
     */
    private function seedItems(string $date, int $fallbackTypeId, int $defaultHeightMm): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT ' . self::PALLET_COLUMNS . ',
                    ti.trip_id, ti.is_locked,
                    o.id AS order_id, o.code AS order_code, ti.order_line_id,
                    cu.id AS customer_id, cu.name AS customer_name, r.id AS round_id, r.name AS round_name,
                    ol.quantity AS line_quantity
             ' . self::PALLET_JOINS . "
             JOIN trip_items ti ON ti.pallet_id = p.id
             JOIN trips t ON t.id = ti.trip_id
             JOIN order_lines ol ON ol.id = ti.order_line_id
             JOIN orders o ON o.id = ti.order_id
             JOIN customers cu ON cu.id = o.customer_id
             LEFT JOIN rounds r ON r.id = cu.round_id
             WHERE t.planned_date = ? AND t.status = 'planned'
             ORDER BY ti.trip_id, ti.id"
        );
        $statement->execute([$defaultHeightMm, $fallbackTypeId, $date]);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $demand = PlanDemand::fromRow([
                'order_id' => $row['order_id'],
                'order_code' => $row['order_code'],
                'order_line_id' => $row['order_line_id'],
                'customer_id' => $row['customer_id'],
                'customer_name' => $row['customer_name'],
                'round_id' => $row['round_id'],
                'round_name' => $row['round_name'],
                'product_id' => $row['product_id'],
                'product_code' => $row['product_code'],
                'product_description' => $row['product_description'],
                'unit_of_measure' => $row['unit_of_measure'],
                'quantity' => $row['line_quantity'],
                'planned_quantity' => '0.000',
            ]);
            $items[(int) $row['trip_id']][] = new PlanAssignment(
                PlanPallet::fromRow($row),
                $demand,
                false,
                (bool) $row['is_locked']
            );
        }

        return $items;
    }

    /**
     * I mezzi attivi, con un tipo di rimorchio, che quel giorno non hanno già un viaggio.
     * Senza tipo di rimorchio non si conoscono le misure del pianale, quindi il mezzo non si usa.
     *
     * @return list<PlanVehicle>
     */
    public function freeVehicles(string $date): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT v.id, v.plate, v.description, v.max_pallets, v.max_weight_kg,
                    tt.name AS trailer_type_name, tt.length_mm, tt.width_mm, tt.inner_height_mm,
                    tt.max_weight_kg AS trailer_max_weight_kg,
                    c.id AS carrier_id, c.name AS carrier_name, c.trip_fee AS carrier_fee
             FROM vehicles v
             JOIN trailer_types tt ON tt.id = v.trailer_type_id
             LEFT JOIN carriers c ON c.id = v.carrier_id
             WHERE v.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM trips t
                   WHERE t.vehicle_id = v.id AND t.planned_date = ? AND t.status <> 'cancelled'
               )
             ORDER BY tt.length_mm * tt.width_mm, v.id"
        );
        $statement->execute([$date]);

        return array_map(PlanVehicle::fromRow(...), $statement->fetchAll());
    }

    /**
     * Quanti mezzi attivi non hanno un tipo di rimorchio (la pagina del piano lo fa notare).
     */
    public function countActiveVehiclesWithoutTrailerType(): int
    {
        return (int) $this->database->pdo()
            ->query('SELECT COUNT(*) FROM vehicles WHERE is_active = 1 AND trailer_type_id IS NULL')
            ->fetchColumn();
    }
}
