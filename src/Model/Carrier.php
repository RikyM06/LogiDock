<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Trasportatore (proprietario del camion). Il compenso per viaggio resta una stringa ("150.00"):
 * i soldi non passano mai da float.
 */
final readonly class Carrier
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $taxCode,
        public ?string $phone,
        public string $tripFee,
        public bool $isActive
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            $row['tax_code'] === null ? null : (string) $row['tax_code'],
            $row['phone'] === null ? null : (string) $row['phone'],
            (string) $row['trip_fee'],
            (bool) $row['is_active']
        );
    }
}
