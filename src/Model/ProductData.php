<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Dati di un prodotto GIÀ validati e normalizzati, pronti per essere scritti.
 * Solo il service li crea: il repository si fida che siano a posto.
 */
final readonly class ProductData
{
    public function __construct(
        public string $code,
        public string $description,
        public string $unitOfMeasure,
        public string $unitWeightKg
    ) {
    }
}
