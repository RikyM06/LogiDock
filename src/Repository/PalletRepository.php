<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\InboundPallet;
use LogiDock\Model\NewPallet;

/**
 * Solo SQL sui bancali. Per ora: inserimento all'arrivo e letture legate agli arrivi.
 * (Lo stato dei bancali e la loro storia si aggiornano insieme nella transazione del service.)
 */
final class PalletRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function insert(NewPallet $pallet): int
    {
        // quantity e initial_quantity partono uguali: la prima cambierà con le uscite, la seconda no.
        $this->database->pdo()
            ->prepare(
                'INSERT INTO pallets (code, product_id, pallet_type_id, supplier_id, supplier_document_id, location_id,
                                      quantity, initial_quantity, unit_price, recorded_weight_kg,
                                      lot_number, expiry_date, received_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )
            ->execute([
                $pallet->code,
                $pallet->productId,
                $pallet->palletTypeId,
                $pallet->supplierId,
                $pallet->supplierDocumentId,
                $pallet->locationId,
                $pallet->quantity,
                $pallet->quantity,
                $pallet->unitPrice,
                $pallet->recordedWeightKg,
                $pallet->lotNumber,
                $pallet->expiryDate,
                $pallet->receivedAt,
                $pallet->createdBy,
            ]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * Quanti bancali occupano adesso una posizione (quelli già usciti o scartati non contano).
     */
    public function countInLocation(int $locationId): int
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT COUNT(*) FROM pallets WHERE location_id = ? AND status IN ('in_stock', 'reserved')"
        );
        $statement->execute([$locationId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * I bancali arrivati con un documento, con i nomi al posto degli id. Il confronto tra peso segnato
     * e peso calcolato lo fa il database in DECIMAL: in PHP non servono calcoli con i float.
     *
     * @param string $tolerancePercent tolleranza in percentuale, es. "5"
     * @return list<InboundPallet>
     */
    public function listByDocument(int $documentId, string $tolerancePercent): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT p.id, p.code, p.initial_quantity, p.lot_number, p.expiry_date, p.unit_price,
                    p.recorded_weight_kg,
                    pr.code AS product_code, pr.description AS product_description, pr.unit_of_measure,
                    pt.name AS pallet_type_name, l.code AS location_code,
                    ROUND(p.initial_quantity * p.unit_price, 2) AS line_value,
                    ROUND(p.initial_quantity * pr.unit_weight_kg, 3) AS computed_weight_kg,
                    (ABS(p.recorded_weight_kg - p.initial_quantity * pr.unit_weight_kg)
                        > p.initial_quantity * pr.unit_weight_kg * CAST(? AS DECIMAL(5,2)) / 100) AS weight_mismatch
             FROM pallets p
             JOIN products pr ON pr.id = p.product_id
             LEFT JOIN pallet_types pt ON pt.id = p.pallet_type_id
             LEFT JOIN warehouse_locations l ON l.id = p.location_id
             WHERE p.supplier_document_id = ?
             ORDER BY p.id'
        );
        $statement->execute([$tolerancePercent, $documentId]);

        return array_map(InboundPallet::fromRow(...), $statement->fetchAll());
    }
}
