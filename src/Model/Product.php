<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un prodotto così com'è nel database. Il peso resta una stringa ("12.600"):
 * i decimali non passano mai da float.
 */
final readonly class Product
{
    public function __construct(
        public int $id,
        public string $code,
        public string $description,
        public string $unitOfMeasure,
        public string $unitWeightKg,
        public bool $isActive
    ) {
    }

    /**
     * @param array<string, mixed> $row riga della tabella products
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['description'],
            (string) $row['unit_of_measure'],
            (string) $row['unit_weight_kg'],
            (bool) $row['is_active']
        );
    }
}
