<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un bancale GIÀ validato, pronto per essere inserito all'arrivo. Lo crea solo InboundService.
 */
final readonly class NewPallet
{
    public function __construct(
        public string $code,
        public int $productId,
        public int $palletTypeId,
        public int $supplierId,
        public int $supplierDocumentId,
        public int $locationId,
        public string $quantity,
        public string $unitPrice,
        public string $recordedWeightKg,
        public ?string $lotNumber,
        public ?string $expiryDate,
        public string $receivedAt,
        public int $createdBy
    ) {
    }
}
