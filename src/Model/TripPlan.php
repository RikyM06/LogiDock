<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Il piano dei viaggi di un giorno, così come esce dal pianificatore. Non è salvato da nessuna
 * parte: si ricalcola ogni volta dai dati del momento, e diventa "vero" solo con la conferma.
 */
final readonly class TripPlan
{
    /**
     * @param list<PlannedTrip>    $trips     i viaggi proposti
     * @param list<PlanLeftover>   $leftovers bancali che servirebbero ma restano in magazzino
     * @param list<DemandCoverage> $coverage  una voce per ogni riga di richiesta del giorno
     */
    public function __construct(
        public string $date,
        public array $trips,
        public array $leftovers,
        public array $coverage
    ) {
    }

    public function hasTrips(): bool
    {
        return $this->trips !== [];
    }

    /**
     * Impronta del piano: cambia se cambia anche una sola cosa che la conferma scriverebbe
     * (un bancale, il cliente a cui va, il mezzo, il giro).
     *
     * Serve alla conferma: la pagina manda l'impronta del piano che l'utente ha visto e il service
     * la confronta con quella del piano ricalcolato in quel momento. Se sono diverse, nel frattempo
     * è cambiato qualcosa (un arrivo, una richiesta, un altro utente) e non si conferma alla cieca.
     */
    public function fingerprint(): string
    {
        $parts = [];

        foreach ($this->trips as $trip) {
            $pairs = array_map(
                static fn (PlanAssignment $assignment): string => $assignment->pallet->id . '>' . $assignment->demand->orderLineId,
                $trip->assignments
            );
            // Ordinati: l'impronta non deve dipendere dall'ordine in cui i bancali sono elencati.
            sort($pairs);

            $parts[] = $trip->roundId . ':' . $trip->vehicle->id . ':' . implode(',', $pairs);
        }

        return hash('sha256', $this->date . '|' . implode('|', $parts));
    }
}
