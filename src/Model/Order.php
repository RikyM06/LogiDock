<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Richiesta di un cliente con i suoi totali (numero di righe, valore). Le date sono stringhe
 * AAAA-MM-GG; il valore resta una stringa decimale (mai float).
 */
final readonly class Order
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_CANCELLED = 'cancelled';

    /** Stato => nome da mostrare. */
    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Aperta',
        self::STATUS_PLANNED => 'Pianificata',
        self::STATUS_SHIPPED => 'Spedita',
        self::STATUS_CANCELLED => 'Annullata',
    ];

    public function __construct(
        public int $id,
        public string $code,
        public int $customerId,
        public string $customerName,
        public ?string $roundName,
        public string $requestedDate,
        public string $deliveryDate,
        public string $status,
        public ?string $notes,
        public int $lineCount,
        public string $totalValue,
        public ?string $paidAt = null,
        public ?string $paidAmount = null,
        public ?string $paidByName = null
    ) {
    }

    /** Pagata = ha una data di pagamento (importo e utente si salvano insieme, lo garantisce il database). */
    public function isPaid(): bool
    {
        return $this->paidAt !== null;
    }

    /** Si può ancora modificare o annullare solo finché nessuno l'ha pianificata. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (int) $row['customer_id'],
            (string) $row['customer_name'],
            $row['round_name'] === null ? null : (string) $row['round_name'],
            (string) $row['requested_date'],
            (string) $row['delivery_date'],
            (string) $row['status'],
            $row['notes'] === null ? null : (string) $row['notes'],
            (int) $row['line_count'],
            (string) $row['total_value'],
            isset($row['paid_at']) ? (string) $row['paid_at'] : null,
            isset($row['paid_amount']) ? (string) $row['paid_amount'] : null,
            isset($row['paid_by_name']) ? (string) $row['paid_by_name'] : null
        );
    }
}
