<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Una riga di documento: un bancale caricato su un viaggio, con il cliente a cui va e il prezzo
 * di vendita della sua richiesta. Quantità, pesi e importi sono stringhe decimali, mai float.
 *
 * lineTotal = quantità x prezzo unitario, arrotondato a due decimali (lo calcola MySQL in DECIMAL).
 */
final readonly class DocumentLine
{
    public function __construct(
        public int $customerId,
        public string $customerName,
        public ?string $customerTaxCode,
        public ?string $customerAddress,
        public string $orderCode,
        public string $palletCode,
        public string $productCode,
        public string $productDescription,
        public string $unitOfMeasure,
        public ?string $lotNumber,
        public string $quantity,
        public string $weightKg,
        public string $unitPrice,
        public string $lineTotal
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['customer_id'],
            (string) $row['customer_name'],
            $row['customer_tax_code'] === null ? null : (string) $row['customer_tax_code'],
            $row['customer_address'] === null ? null : (string) $row['customer_address'],
            (string) $row['order_code'],
            (string) $row['pallet_code'],
            (string) $row['product_code'],
            (string) $row['product_description'],
            (string) $row['unit_of_measure'],
            $row['lot_number'] === null ? null : (string) $row['lot_number'],
            (string) $row['quantity'],
            (string) $row['weight_kg'],
            (string) $row['unit_price'],
            (string) $row['line_total']
        );
    }
}
