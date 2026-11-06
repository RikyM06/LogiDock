<?php

declare(strict_types=1);

namespace LogiDock\Model;

final readonly class TrailerType
{
    public function __construct(
        public int $id,
        public string $name,
        public int $lengthMm,
        public int $widthMm,
        public int $innerHeightMm,
        public string $maxWeightKg,
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
            (int) $row['length_mm'],
            (int) $row['width_mm'],
            (int) $row['inner_height_mm'],
            (string) $row['max_weight_kg'],
            (bool) $row['is_active']
        );
    }
}
