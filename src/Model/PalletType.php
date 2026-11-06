<?php

declare(strict_types=1);

namespace LogiDock\Model;

final readonly class PalletType
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public int $lengthMm,
        public int $widthMm,
        public ?int $maxHeightMm,
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
            (string) $row['name'],
            (int) $row['length_mm'],
            (int) $row['width_mm'],
            $row['max_height_mm'] === null ? null : (int) $row['max_height_mm'],
            (bool) $row['is_active']
        );
    }
}
