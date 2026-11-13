<?php

declare(strict_types=1);

namespace LogiDock\Model\History;

/**
 * Una riga della scheda Fornitori: un fornitore e un prodotto (righe piatte, una per coppia).
 * La quantità è la somma dei movimenti di ingresso, quindi non scende quando i bancali partono.
 */
final readonly class SupplierHistoryRow
{
    public function __construct(
        public int $supplierId,
        public string $supplierName,
        public int $productId,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public int $documentCount,
        public int $palletCount,
        public string $quantity,
        public string $purchaseValue
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['supplier_id'],
            (string) $row['supplier_name'],
            (int) $row['product_id'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            (int) $row['document_count'],
            (int) $row['pallet_count'],
            (string) $row['quantity'],
            (string) $row['purchase_value']
        );
    }
}
