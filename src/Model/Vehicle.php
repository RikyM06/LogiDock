<?php

declare(strict_types=1);

namespace LogiDock\Model;

final readonly class Vehicle
{
    public function __construct(
        public int $id,
        public string $plate,
        public string $description,
        public string $maxWeightKg,
        public int $maxPallets,
        public ?int $trailerTypeId,
        public ?string $trailerTypeName,
        public ?int $carrierId,
        public ?string $carrierName,
        public bool $isActive
    ) {
    }

    /**
     * @param array<string, mixed> $row riga di vehicles con trailer_type_name e carrier_name dai JOIN
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['plate'],
            (string) $row['description'],
            (string) $row['max_weight_kg'],
            (int) $row['max_pallets'],
            $row['trailer_type_id'] === null ? null : (int) $row['trailer_type_id'],
            $row['trailer_type_name'] === null ? null : (string) $row['trailer_type_name'],
            $row['carrier_id'] === null ? null : (int) $row['carrier_id'],
            $row['carrier_name'] === null ? null : (string) $row['carrier_name'],
            (bool) $row['is_active']
        );
    }
}
