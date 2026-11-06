<?php

declare(strict_types=1);

namespace LogiDock\Model;

final readonly class WarehouseLocation
{
    public function __construct(
        public int $id,
        public string $code,
        public ?string $zone,
        public ?string $description,
        public ?int $capacity,
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
            (string) $row['code'],
            $row['zone'] === null ? null : (string) $row['zone'],
            $row['description'] === null ? null : (string) $row['description'],
            $row['capacity'] === null ? null : (int) $row['capacity'],
            (bool) $row['is_active']
        );
    }
}
