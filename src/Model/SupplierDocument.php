<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Documento del fornitore con i totali dei bancali arrivati con esso.
 * Le date sono stringhe AAAA-MM-GG; valore e peso restano stringhe decimali (mai float).
 */
final readonly class SupplierDocument
{
    public function __construct(
        public int $id,
        public int $supplierId,
        public string $supplierName,
        public string $number,
        public string $documentDate,
        public string $receivedDate,
        public ?string $notes,
        public int $palletCount,
        public string $totalValue,
        public string $totalRecordedWeightKg
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['supplier_id'],
            (string) $row['supplier_name'],
            (string) $row['number'],
            (string) $row['document_date'],
            (string) $row['received_date'],
            $row['notes'] === null ? null : (string) $row['notes'],
            (int) $row['pallet_count'],
            (string) $row['total_value'],
            (string) $row['total_weight']
        );
    }
}
