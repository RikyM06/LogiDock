<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Quanto un carico riempie un mezzo: numero di bancali, metri di pianale e peso, ognuno con il
 * suo massimo. problem dice, a parole, il primo motivo per cui il carico NON ci sta (vuoto = ci sta).
 */
final readonly class LoadUsage
{
    public function __construct(
        public int $palletCount,
        public int $maxPallets,
        public int $usedLengthMm,
        public int $availableLengthMm,
        public string $weightKg,
        public string $maxWeightKg,
        public ?string $problem
    ) {
    }

    public function fits(): bool
    {
        return $this->problem === null;
    }
}
