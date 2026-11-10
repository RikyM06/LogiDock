<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un viaggio proposto dal pianificatore (non ancora salvato): un giro, un mezzo e i bancali
 * che ci salgono sopra, ognuno con la riga di richiesta a cui è destinato.
 */
final readonly class PlannedTrip
{
    /**
     * @param list<PlanAssignment> $assignments in ordine di tappa (cliente), poi di richiesta
     */
    public function __construct(
        public int $roundId,
        public string $roundName,
        public PlanVehicle $vehicle,
        public array $assignments,
        public LoadUsage $usage
    ) {
    }

    /**
     * Le tappe del giro: i clienti dei bancali caricati, ognuno una volta sola, nell'ordine degli
     * abbinamenti (alfabetico: in che ordine si fanno davvero le tappe non è ancora deciso).
     *
     * @return list<string>
     */
    public function stops(): array
    {
        $names = [];
        foreach ($this->assignments as $assignment) {
            $names[$assignment->demand->customerId] = $assignment->demand->customerName;
        }

        return array_values($names);
    }
}
