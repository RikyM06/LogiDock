<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un bancale caricato su un viaggio confermato, con il cliente e la richiesta a cui è destinato.
 * Quantità e peso sono quelli fotografati alla conferma (stringhe decimali).
 */
final readonly class TripItem
{
    public function __construct(
        public int $palletId,
        public string $palletCode,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public string $quantity,
        public string $weightKg,
        public ?string $palletTypeName,
        public int $orderId,
        public string $orderCode,
        public int $customerId,
        public string $customerName
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['pallet_id'],
            (string) $row['pallet_code'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            (string) $row['quantity'],
            (string) $row['weight_kg'],
            $row['pallet_type_name'] === null ? null : (string) $row['pallet_type_name'],
            (int) $row['order_id'],
            (string) $row['order_code'],
            (int) $row['customer_id'],
            (string) $row['customer_name']
        );
    }
}
