<?php

declare(strict_types=1);

namespace LogiDock\Model;

use LogiDock\Core\Thousandths;

/**
 * Che fine fa una riga di richiesta nel piano: quanto parte, quanto resta in magazzino perché non
 * entra nei mezzi, quanto manca perché la merce non c'è, quanto parte in più (i bancali sono interi).
 * Tutte le quantità sono stringhe DECIMAL nell'unità di misura del prodotto.
 */
final readonly class DemandCoverage
{
    /**
     * @param bool   $plannable        false = il cliente non ha un giro: la riga non entra nel piano
     * @param string $toPlanQuantity   quanto restava da pianificare (richiesto meno già pianificato)
     * @param string $loadedQuantity   quantità sui bancali caricati in questo piano
     * @param string $notLoadedQuantity quantità sui bancali scelti ma rimasti a terra
     * @param string $missingQuantity  quantità che in magazzino non c'è proprio
     * @param string $surplusQuantity  quantità caricata oltre il richiesto
     */
    public function __construct(
        public PlanDemand $demand,
        public bool $plannable,
        public string $toPlanQuantity,
        public string $loadedQuantity,
        public string $notLoadedQuantity,
        public string $missingQuantity,
        public string $surplusQuantity,
        public int $loadedPallets,
        public int $notLoadedPallets
    ) {
    }

    public function hasMissing(): bool
    {
        return Thousandths::fromDecimal($this->missingQuantity) > 0;
    }

    public function hasNotLoaded(): bool
    {
        return $this->notLoadedPallets > 0;
    }

    public function hasSurplus(): bool
    {
        return Thousandths::fromDecimal($this->surplusQuantity) > 0;
    }

    /** Coperta = parte tutto quello che restava da pianificare. */
    public function isCovered(): bool
    {
        return $this->plannable && !$this->hasMissing() && !$this->hasNotLoaded();
    }
}
