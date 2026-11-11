<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * "Questo bancale va a questa riga di richiesta": la decisione di base del pianificatore.
 * Un bancale parte intero e va a un solo cliente.
 *
 * fixed = il bancale è già su un viaggio confermato e il piano non lo tocca.
 * locked = qualcuno lo ha bloccato a mano (spostato o bloccato con il pulsante): il ricalcolo
 * del giorno lo lascia dov'è.
 */
final readonly class PlanAssignment
{
    public function __construct(
        public PlanPallet $pallet,
        public PlanDemand $demand,
        public bool $fixed = false,
        public bool $locked = false
    ) {
    }

    /** Lo stesso abbinamento, segnato come già presente sul viaggio. */
    public function asFixed(): self
    {
        return new self($this->pallet, $this->demand, true, $this->locked);
    }
}
