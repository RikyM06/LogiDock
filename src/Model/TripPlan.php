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
     * @param bool                 $replan    true = piano di "ricalcolo": i bancali non bloccati dei viaggi
     *                                        già confermati vengono riassegnati da capo
     *
     * "trips" contiene solo i viaggi che la conferma cambierebbe: quelli nuovi e quelli già
     * confermati a cui si aggiunge o si toglie qualcosa.
     */
    public function __construct(
        public string $date,
        public array $trips,
        public array $leftovers,
        public array $coverage,
        public bool $replan = false
    ) {
    }

    /** C'è qualcosa da confermare? */
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

            // Un viaggio già confermato e uno nuovo con gli stessi bancali sono cose diverse: conta anche l'id.
            $parts[] = $trip->roundId . ':' . ($trip->tripId ?? 'nuovo') . ':' . $trip->vehicle->id . ':' . implode(',', $pairs);
        }

        return hash('sha256', ($this->replan ? 'ricalcolo' : 'normale') . '|' . $this->date . '|' . implode('|', $parts));
    }
}
