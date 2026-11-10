<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * "Questo bancale va a questa riga di richiesta": la decisione di base del pianificatore.
 * Un bancale parte intero e va a un solo cliente.
 */
final readonly class PlanAssignment
{
    public function __construct(
        public PlanPallet $pallet,
        public PlanDemand $demand
    ) {
    }
}
