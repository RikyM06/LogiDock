<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un bancale libero in magazzino, con tutto quello che serve per caricarlo su un mezzo:
 * quanto prodotto porta, quanto pesa e quanto spazio occupa (misure del suo tipo di bancale).
 *
 * typeAssumed = true quando il bancale non ha un tipo registrato: il pianificatore usa il tipo
 * più grande (scelta prudente) e la pagina lo segnala.
 */
final readonly class PlanPallet
{
    public function __construct(
        public int $id,
        public string $code,
        public int $productId,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public string $quantity,
        public string $weightKg,
        public int $palletTypeId,
        public string $palletTypeName,
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
        public bool $typeAssumed,
        public string $receivedAt,
        public ?string $locationCode
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
            (int) $row['product_id'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            (string) $row['quantity'],
            (string) $row['weight_kg'],
            (int) $row['pallet_type_id'],
            (string) $row['pallet_type_name'],
            (int) $row['length_mm'],
            (int) $row['width_mm'],
            (int) $row['height_mm'],
            (bool) $row['type_assumed'],
            (string) $row['received_at'],
            $row['location_code'] === null ? null : (string) $row['location_code']
        );
    }
}
