<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un viaggio del piano (non ancora salvato): un giro, un mezzo e i bancali che ci salgono sopra,
 * ognuno con la riga di richiesta a cui è destinato.
 *
 * Può essere un viaggio nuovo (tripId vuoto) oppure un viaggio già confermato a cui il piano
 * aggiunge o toglie dei bancali: quelli già presenti che restano hanno fixed = true.
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
        public LoadUsage $usage,
        public ?int $tripId = null,
        public ?string $tripCode = null
    ) {
    }

    /** Il viaggio esiste già: il piano lo completa, non lo crea. */
    public function isExisting(): bool
    {
        return $this->tripId !== null;
    }

    /**
     * I bancali che la conferma deve ancora scrivere sul viaggio.
     *
     * @return list<PlanAssignment>
     */
    public function newAssignments(): array
    {
        return array_values(array_filter(
            $this->assignments,
            static fn (PlanAssignment $assignment): bool => !$assignment->fixed
        ));
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
