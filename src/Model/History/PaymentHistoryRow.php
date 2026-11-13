<?php

declare(strict_types=1);

namespace LogiDock\Model\History;

/**
 * Una riga della scheda Incassi: una richiesta con almeno un viaggio partito.
 * Stato: "paid" (pagata), "unpaid" (spedita, da pagare), "partial" (spedita solo in parte: non si può pagare).
 * `amount` è l'importo pagato (fotografato) se pagata, altrimenti quello già spedito.
 */
final readonly class PaymentHistoryRow
{
    public const PAID = 'paid';
    public const UNPAID = 'unpaid';
    public const PARTIAL = 'partial';

    public const STATE_LABELS = [
        self::PAID => 'Pagata',
        self::UNPAID => 'Da pagare',
        self::PARTIAL => 'Parziale',
    ];

    public function __construct(
        public int $orderId,
        public string $orderCode,
        public int $customerId,
        public string $customerName,
        public string $deliveryDate,
        public string $state,
        public string $amount,
        public ?string $paidAt
    ) {
    }

    public function stateLabel(): string
    {
        return self::STATE_LABELS[$this->state] ?? $this->state;
    }

    /** Solo una richiesta spedita e non ancora pagata si può segnare pagata. */
    public function canBePaid(): bool
    {
        return $this->state === self::UNPAID;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $state = (string) $row['payment_state'];

        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (int) $row['customer_id'],
            (string) $row['customer_name'],
            (string) $row['delivery_date'],
            $state,
            (string) ($state === self::PAID ? $row['paid_amount'] : $row['amount']),
            $row['paid_at'] === null ? null : (string) $row['paid_at']
        );
    }
}
