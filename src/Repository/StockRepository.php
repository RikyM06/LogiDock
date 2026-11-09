<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\StockPallet;
use LogiDock\Model\StockProductSummary;

/**
 * Solo letture, per la vista delle giacenze: cosa c'è adesso in magazzino.
 * Un bancale è in magazzino se è "in_stock" oppure "reserved" (prenotato per un viaggio ma non
 * ancora partito): stessa scelta di PalletRepository::countInLocation(). Le regole (soglia,
 * data limite) arrivano dal service come semplici parametri.
 */
final class StockRepository
{
    private const IN_WAREHOUSE = "p.status IN ('in_stock', 'reserved')";

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Una riga per prodotto presente in magazzino. Le somme le fa MySQL in DECIMAL.
     *
     * @param string $receivedBefore i bancali arrivati prima di questa data (Y-m-d) contano come fermi
     * @return list<StockProductSummary>
     */
    public function summaryByProduct(string $receivedBefore): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT pr.id AS product_id, pr.code AS product_code, pr.description AS product_description,
                    pr.unit_of_measure,
                    COUNT(*) AS pallet_count,
                    SUM(p.quantity) AS total_quantity,
                    MIN(p.received_at) AS oldest_received_at,
                    SUM(p.received_at < ?) AS aging_count
             FROM pallets p
             JOIN products pr ON pr.id = p.product_id
             WHERE ' . self::IN_WAREHOUSE . '
             GROUP BY pr.id, pr.code, pr.description, pr.unit_of_measure
             ORDER BY pr.code'
        );
        $statement->execute([$receivedBefore]);

        return array_map(StockProductSummary::fromRow(...), $statement->fetchAll());
    }

    /**
     * I bancali in magazzino, dal più vecchio, con i filtri facoltativi (null = nessun filtro).
     *
     * @param string|null $receivedBefore solo quelli arrivati prima di questa data (Y-m-d)
     * @return list<StockPallet>
     */
    public function pallets(?int $productId, ?int $locationId, ?string $receivedBefore, int $limit): array
    {
        // Le condizioni sono testo fisso scritto qui; i valori passano sempre come parametri.
        $conditions = [self::IN_WAREHOUSE];
        $params = [];
        if ($productId !== null) {
            $conditions[] = 'p.product_id = ?';
            $params[] = $productId;
        }
        if ($locationId !== null) {
            $conditions[] = 'p.location_id = ?';
            $params[] = $locationId;
        }
        if ($receivedBefore !== null) {
            $conditions[] = 'p.received_at < ?';
            $params[] = $receivedBefore;
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT p.id, p.code, p.status, p.quantity, p.lot_number, p.expiry_date, p.received_at,
                    p.product_id, pr.code AS product_code, pr.description AS product_description, pr.unit_of_measure,
                    pt.name AS pallet_type_name, l.code AS location_code, s.name AS supplier_name,
                    p.supplier_document_id, d.number AS document_number
             FROM pallets p
             JOIN products pr ON pr.id = p.product_id
             LEFT JOIN pallet_types pt ON pt.id = p.pallet_type_id
             LEFT JOIN warehouse_locations l ON l.id = p.location_id
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             LEFT JOIN supplier_documents d ON d.id = p.supplier_document_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY p.received_at, p.id
             LIMIT ' . max(1, $limit)
        );
        $statement->execute($params);

        return array_map(StockPallet::fromRow(...), $statement->fetchAll());
    }

    /**
     * Quanti bancali in magazzino sono arrivati prima di questa data (Y-m-d).
     */
    public function countReceivedBefore(string $receivedBefore): int
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM pallets p WHERE ' . self::IN_WAREHOUSE . ' AND p.received_at < ?'
        );
        $statement->execute([$receivedBefore]);

        return (int) $statement->fetchColumn();
    }
}
