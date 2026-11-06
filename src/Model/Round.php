<?php

declare(strict_types=1);

namespace LogiDock\Model;

final readonly class Round
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
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
            $row['description'] === null ? null : (string) $row['description'],
            (bool) $row['is_active']
        );
    }
}
