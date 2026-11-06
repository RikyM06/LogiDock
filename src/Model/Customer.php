<?php

declare(strict_types=1);

namespace LogiDock\Model;

final readonly class Customer
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $taxCode,
        public ?string $address,
        public ?string $contactEmail,
        public ?string $phone,
        public ?int $roundId,
        public ?string $roundName,
        public bool $isActive
    ) {
    }

    /**
     * @param array<string, mixed> $row riga di customers con round_name dal JOIN
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            $row['tax_code'] === null ? null : (string) $row['tax_code'],
            $row['address'] === null ? null : (string) $row['address'],
            $row['contact_email'] === null ? null : (string) $row['contact_email'],
            $row['phone'] === null ? null : (string) $row['phone'],
            $row['round_id'] === null ? null : (int) $row['round_id'],
            $row['round_name'] === null ? null : (string) $row['round_name'],
            (bool) $row['is_active']
        );
    }
}
