<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Giacenza di un prodotto: quanti bancali ci sono, quanta merce in tutto (DECIMAL come stringa),
 * da quando c'è il bancale più vecchio e quanti bancali sono fermi oltre la soglia.
 */
final readonly class StockProductSummary
{
    public function __construct(
        public int $productId,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public int $palletCount,
        public string $totalQuantity,
        public string $oldestReceivedAt,
        public int $agingCount
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['product_id'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            (int) $row['pallet_count'],
            (string) $row['total_quantity'],
            (string) $row['oldest_received_at'],
            (int) $row['aging_count']
        );
    }
}
