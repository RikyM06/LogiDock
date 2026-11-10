<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un giorno di consegna che ha ancora qualcosa da pianificare: quante richieste e quante righe.
 */
final readonly class PlanningDay
{
    public function __construct(
        public string $date,
        public int $orderCount,
        public int $lineCount
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['delivery_date'],
            (int) $row['order_count'],
            (int) $row['line_count']
        );
    }
}
