<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Una riga di richiesta ancora da pianificare, come la vede il pianificatore dei viaggi:
 * chi la vuole (cliente e giro), cosa (prodotto), quanto e quanto è già su un viaggio confermato.
 * Quantità come stringhe DECIMAL (mai float). roundId vuoto = il cliente non ha ancora un giro.
 */
final readonly class PlanDemand
{
    public function __construct(
        public int $orderId,
        public string $orderCode,
        public int $orderLineId,
        public int $customerId,
        public string $customerName,
        public ?int $roundId,
        public ?string $roundName,
        public int $productId,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public string $quantity,
        public string $plannedQuantity
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['order_id'],
            (string) $row['order_code'],
            (int) $row['order_line_id'],
            (int) $row['customer_id'],
            (string) $row['customer_name'],
            $row['round_id'] === null ? null : (int) $row['round_id'],
            $row['round_name'] === null ? null : (string) $row['round_name'],
            (int) $row['product_id'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            (string) $row['quantity'],
            (string) $row['planned_quantity']
        );
    }
}
