<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un bancale così come si mostra nel dettaglio di un arrivo, con i nomi al posto degli id.
 * "weightMismatch" dice se il peso segnato dal fornitore si discosta da quello calcolato
 * (quantità x peso unitario) oltre la tolleranza: lo calcola il database, senza float in PHP.
 */
final readonly class InboundPallet
{
    public function __construct(
        public int $id,
        public string $code,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public ?string $palletTypeName,
        public string $quantity,
        public ?string $lotNumber,
        public ?string $expiryDate,
        public ?string $locationCode,
        public ?string $unitPrice,
        public ?string $lineValue,
        public ?string $recordedWeightKg,
        public string $computedWeightKg,
        public bool $weightMismatch
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            $row['pallet_type_name'] === null ? null : (string) $row['pallet_type_name'],
            (string) $row['initial_quantity'],
            $row['lot_number'] === null ? null : (string) $row['lot_number'],
            $row['expiry_date'] === null ? null : (string) $row['expiry_date'],
            $row['location_code'] === null ? null : (string) $row['location_code'],
            $row['unit_price'] === null ? null : (string) $row['unit_price'],
            $row['line_value'] === null ? null : (string) $row['line_value'],
            $row['recorded_weight_kg'] === null ? null : (string) $row['recorded_weight_kg'],
            (string) $row['computed_weight_kg'],
            (bool) $row['weight_mismatch']
        );
    }
}
