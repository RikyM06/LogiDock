<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un bancale in magazzino così come si mostra nelle giacenze, con i nomi al posto degli id.
 * Quantità come stringa DECIMAL (mai float). Se è fermo da troppo lo dice AgingRule, non il model.
 */
final readonly class StockPallet
{
    public const STATUS_RESERVED = 'reserved';

    public function __construct(
        public int $id,
        public string $code,
        public string $status,
        public int $productId,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public ?string $palletTypeName,
        public string $quantity,
        public ?string $lotNumber,
        public ?string $expiryDate,
        public ?string $locationCode,
        public ?string $supplierName,
        public ?int $supplierDocumentId,
        public ?string $documentNumber,
        public string $receivedAt
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $nullable = static fn (string $key): ?string => $row[$key] === null ? null : (string) $row[$key];

        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['status'],
            (int) $row['product_id'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            $nullable('pallet_type_name'),
            (string) $row['quantity'],
            $nullable('lot_number'),
            $nullable('expiry_date'),
            $nullable('location_code'),
            $nullable('supplier_name'),
            $row['supplier_document_id'] === null ? null : (int) $row['supplier_document_id'],
            $nullable('document_number'),
            (string) $row['received_at']
        );
    }

    /** Già prenotato per un viaggio (lo farà il pianificatore): è ancora in magazzino, ma non è libero. */
    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }
}
