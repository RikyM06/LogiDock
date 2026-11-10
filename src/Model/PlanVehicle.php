<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un mezzo libero in un certo giorno, con le misure del suo tipo di rimorchio e il trasportatore
 * (con il compenso per viaggio) che lo guida. Le misure sono in millimetri, i pesi stringhe DECIMAL.
 */
final readonly class PlanVehicle
{
    public function __construct(
        public int $id,
        public string $plate,
        public string $description,
        public int $maxPallets,
        public string $maxWeightKg,
        public string $trailerTypeName,
        public int $lengthMm,
        public int $widthMm,
        public int $innerHeightMm,
        public string $trailerMaxWeightKg,
        public ?int $carrierId,
        public ?string $carrierName,
        public ?string $carrierFee
    ) {
    }

    /** Superficie del pianale: serve a ordinare i mezzi dal più piccolo al più grande. */
    public function floorAreaMm2(): int
    {
        return $this->lengthMm * $this->widthMm;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['plate'],
            (string) $row['description'],
            (int) $row['max_pallets'],
            (string) $row['max_weight_kg'],
            (string) $row['trailer_type_name'],
            (int) $row['length_mm'],
            (int) $row['width_mm'],
            (int) $row['inner_height_mm'],
            (string) $row['trailer_max_weight_kg'],
            $row['carrier_id'] === null ? null : (int) $row['carrier_id'],
            $row['carrier_name'] === null ? null : (string) $row['carrier_name'],
            $row['carrier_fee'] === null ? null : (string) $row['carrier_fee']
        );
    }
}
