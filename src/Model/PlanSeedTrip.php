<?php

declare(strict_types=1);

namespace LogiDock\Model;

/**
 * Un viaggio già confermato (ancora "pianificato") visto dal pianificatore: il giro, il mezzo e i
 * bancali che porta oggi. Il piano ci costruisce attorno: ci aggiunge bancali finché ci stanno,
 * prima di prendere un altro mezzo. Il mezzo non cambia mai da solo.
 */
final readonly class PlanSeedTrip
{
    /**
     * @param list<PlanAssignment> $assignments tutti i bancali sul viaggio adesso, con locked impostato
     */
    public function __construct(
        public int $tripId,
        public string $code,
        public int $roundId,
        public string $roundName,
        public PlanVehicle $vehicle,
        public array $assignments
    ) {
    }

    /**
     * "Chi è su questo viaggio e per quale riga": serve a capire se il piano lo cambia.
     */
    public function signature(): string
    {
        return self::signatureOf($this->assignments);
    }

    /**
     * @param list<PlanAssignment> $assignments
     */
    public static function signatureOf(array $assignments): string
    {
        $pairs = array_map(
            static fn (PlanAssignment $assignment): string => $assignment->pallet->id . '>' . $assignment->demand->orderLineId,
            $assignments
        );
        sort($pairs);

        return implode(',', $pairs);
    }
}
