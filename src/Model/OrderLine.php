<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Una riga di una richiesta, con i dati del prodotto e la giacenza attuale in magazzino.
 * "stockShort" dice se in magazzino adesso ce n'è meno di quanto chiesto: lo calcola il database.
 * È un'indicazione: la merce davvero assegnata a ogni cliente la deciderà la pianificazione.
 */
final readonly class OrderLine
{
    public function __construct(
        public int $id,
        public int $productId,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public string $quantity,
        public string $unitPrice,
        public string $lineValue,
        public string $stockQuantity,
        public bool $stockShort
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['product_id'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            (string) $row['quantity'],
            (string) $row['unit_price'],
            (string) $row['line_value'],
            (string) $row['stock_quantity'],
            (bool) $row['stock_short']
        );
    }
}
