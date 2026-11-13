<?php

declare(strict_types=1);

namespace LogiDock\Model\History;

use LogiDock\Model\TransportDocument;

/**
 * Una riga della scheda Documenti: un documento emesso (senza il PDF, che si apre da /documents/{id}).
 */
final readonly class DocumentHistoryRow
{
    public function __construct(
        public int $id,
        public string $type,
        public int $year,
        public int $number,
        public int $tripId,
        public string $tripCode,
        public ?string $customerName,
        public string $issuedAt
    ) {
    }

    public function code(): string
    {
        return TransportDocument::formatCode($this->type, $this->year, $this->number);
    }

    public function typeLabel(): string
    {
        return TransportDocument::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['type'],
            (int) $row['year'],
            (int) $row['number'],
            (int) $row['trip_id'],
            (string) $row['trip_code'],
            $row['customer_name'] === null ? null : (string) $row['customer_name'],
            (string) $row['issued_at']
        );
    }
}
