<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Thousandths;
use LogiDock\Model\DemandCoverage;
use LogiDock\Model\PlanAssignment;
use LogiDock\Model\PlanDemand;
use LogiDock\Model\PlanLeftover;
use LogiDock\Model\PlannedTrip;
use LogiDock\Model\PlanPallet;
use LogiDock\Model\PlanVehicle;
use LogiDock\Model\TripPlan;

/**
 * Il cuore della pianificazione: dalle richieste di un giorno, dai bancali liberi e dai mezzi liberi
 * costruisce il piano dei viaggi. Solo calcoli in memoria: niente database, niente data di oggi.
 * Con gli stessi dati in ingresso dà sempre lo stesso piano (è questo che rende possibile la conferma
 * "ricalcolo e confronto" di TripService).
 *
 * Le regole, in ordine:
 * 1. Bancali alle richieste. Le righe si servono nell'ordine in cui arrivano (la richiesta registrata
 *    per prima passa per prima). Ogni riga prende i bancali più vecchi del suo prodotto finché è
 *    coperta: un bancale parte intero e va a un solo cliente, quindi l'ultimo può portare più del
 *    richiesto. Se i bancali finiscono, la parte scoperta è "mancante".
 * 2. Richieste ai giri. Ogni cliente ha il suo giro: i bancali di un giro viaggiano insieme.
 *    Un cliente senza giro non entra nel piano (e non consuma bancali).
 * 3. Giri ai mezzi, un giro alla volta in ordine alfabetico. Si prende il mezzo più piccolo che
 *    porta via tutto; se nessuno basta, si riempie il più grande e con quello che resta si ricomincia.
 *    Un mezzo fa un solo viaggio al giorno. Quello che non trova posto resta in magazzino.
 */
final class TripPlanner
{
    public function __construct(private readonly LoadCalculator $calculator)
    {
    }

    /**
     * @param list<PlanDemand>  $demands  righe da pianificare, in ordine di precedenza
     * @param list<PlanPallet>  $pallets  bancali liberi, dal più vecchio al più recente
     * @param list<PlanVehicle> $vehicles mezzi liberi quel giorno
     */
    public function plan(string $date, array $demands, array $pallets, array $vehicles): TripPlan
    {
        $assignments = $this->assignPallets($demands, $pallets);
        [$trips, $leftovers] = $this->loadVehicles($demands, $assignments, $vehicles);

        return new TripPlan($date, $trips, $leftovers, $this->coverage($demands, $assignments, $trips));
    }

    /**
     * Regola 1: a ogni riga i bancali più vecchi del suo prodotto.
     *
     * @param list<PlanDemand> $demands
     * @param list<PlanPallet> $pallets
     * @return array<int, list<PlanAssignment>> id della riga => bancali scelti (anche nessuno)
     */
    private function assignPallets(array $demands, array $pallets): array
    {
        // Una coda di bancali liberi per prodotto: l'ordine di arrivo è quello ricevuto.
        $free = [];
        foreach ($pallets as $pallet) {
            $free[$pallet->productId][] = $pallet;
        }

        $assignments = [];

        foreach ($demands as $demand) {
            $assignments[$demand->orderLineId] = [];

            if ($demand->roundId === null) {
                continue;
            }

            $toCover = $this->toPlan($demand);

            while ($toCover > 0 && ($free[$demand->productId] ?? []) !== []) {
                $pallet = array_shift($free[$demand->productId]);
                $assignments[$demand->orderLineId][] = new PlanAssignment($pallet, $demand);
                $toCover -= Thousandths::fromDecimal($pallet->quantity);
            }
        }

        return $assignments;
    }

    /**
     * Regole 2 e 3: i bancali scelti, raggruppati per giro, salgono sui mezzi.
     *
     * @param list<PlanDemand>                 $demands
     * @param array<int, list<PlanAssignment>> $assignments
     * @param list<PlanVehicle>                $vehicles
     * @return array{list<PlannedTrip>, list<PlanLeftover>}
     */
    private function loadVehicles(array $demands, array $assignments, array $vehicles): array
    {
        $byRound = [];
        $roundNames = [];

        foreach ($demands as $demand) {
            foreach ($assignments[$demand->orderLineId] as $assignment) {
                $byRound[$demand->roundId][] = $assignment;
                $roundNames[$demand->roundId] = (string) $demand->roundName;
            }
        }

        $roundIds = array_keys($byRound);
        usort(
            $roundIds,
            static fn (int $a, int $b): int => strcasecmp($roundNames[$a], $roundNames[$b]) ?: $a <=> $b
        );

        // Dal più piccolo al più grande; a parità di pianale decide l'id, così l'ordine è sempre lo stesso.
        usort(
            $vehicles,
            static fn (PlanVehicle $a, PlanVehicle $b): int => [$a->floorAreaMm2(), $a->id] <=> [$b->floorAreaMm2(), $b->id]
        );

        $trips = [];
        $leftovers = [];

        foreach ($roundIds as $roundId) {
            $toLoad = $byRound[$roundId];

            while ($toLoad !== []) {
                $choice = $this->chooseVehicle($vehicles, $toLoad);

                if ($choice === null) {
                    $reason = $vehicles === [] ? PlanLeftover::REASON_NO_VEHICLE : PlanLeftover::REASON_DOES_NOT_FIT;
                    foreach ($toLoad as $assignment) {
                        $leftovers[] = new PlanLeftover($assignment, $reason);
                    }
                    break;
                }

                [$position, $loaded, $toLoad] = $choice;
                $vehicle = $vehicles[$position];
                // Il mezzo è preso: non è più disponibile per gli altri giri.
                array_splice($vehicles, $position, 1);

                $trips[] = $this->trip($roundId, $roundNames[$roundId], $vehicle, $loaded);
            }
        }

        return [$trips, $leftovers];
    }

    /**
     * Sceglie il mezzo per un gruppo di bancali dello stesso giro.
     *
     * @param list<PlanVehicle>    $vehicles mezzi ancora liberi, dal più piccolo al più grande
     * @param list<PlanAssignment> $toLoad
     * @return array{int, list<PlanAssignment>, list<PlanAssignment>}|null
     *         [posizione del mezzo nella lista, bancali caricati, bancali rimasti a terra];
     *         null se nessun mezzo riesce a caricare nemmeno un bancale
     */
    private function chooseVehicle(array $vehicles, array $toLoad): ?array
    {
        // Prima scelta: il mezzo più piccolo che porta via tutto in un viaggio solo.
        foreach ($vehicles as $position => $vehicle) {
            if ($this->calculator->usage($vehicle, $this->palletsOf($toLoad))->fits()) {
                return [$position, $toLoad, []];
            }
        }

        // Nessuno basta: si riempie il più grande, un bancale alla volta. Un bancale che non entra
        // (per esempio perché pesa troppo) si salta e si prova con i successivi.
        foreach (array_reverse($vehicles, true) as $position => $vehicle) {
            $loaded = [];
            $rest = [];

            foreach ($toLoad as $assignment) {
                if ($this->calculator->usage($vehicle, $this->palletsOf([...$loaded, $assignment]))->fits()) {
                    $loaded[] = $assignment;
                } else {
                    $rest[] = $assignment;
                }
            }

            if ($loaded !== []) {
                return [$position, $loaded, $rest];
            }
        }

        return null;
    }

    /**
     * @param list<PlanAssignment> $loaded
     */
    private function trip(int $roundId, string $roundName, PlanVehicle $vehicle, array $loaded): PlannedTrip
    {
        // I bancali dello stesso cliente stanno vicini: per cliente (alfabetico, perché l'ordine vero
        // delle tappe non è ancora deciso), poi per richiesta. L'ordinamento di PHP è
        // stabile, quindi dentro la stessa riga i bancali restano dal più vecchio al più recente.
        usort(
            $loaded,
            static fn (PlanAssignment $a, PlanAssignment $b): int => strcasecmp($a->demand->customerName, $b->demand->customerName)
                ?: [$a->demand->customerId, $a->demand->orderId, $a->demand->orderLineId]
                    <=> [$b->demand->customerId, $b->demand->orderId, $b->demand->orderLineId]
        );

        return new PlannedTrip(
            $roundId,
            $roundName,
            $vehicle,
            $loaded,
            $this->calculator->usage($vehicle, $this->palletsOf($loaded))
        );
    }

    /**
     * Il riepilogo riga per riga: quanto parte, quanto resta a terra, quanto manca, quanto è in più.
     *
     * @param list<PlanDemand>                 $demands
     * @param array<int, list<PlanAssignment>> $assignments
     * @param list<PlannedTrip>                $trips
     * @return list<DemandCoverage>
     */
    private function coverage(array $demands, array $assignments, array $trips): array
    {
        $loadedPalletIds = [];
        foreach ($trips as $trip) {
            foreach ($trip->assignments as $assignment) {
                $loadedPalletIds[$assignment->pallet->id] = true;
            }
        }

        $coverage = [];

        foreach ($demands as $demand) {
            $loaded = 0;
            $notLoaded = 0;
            $loadedPallets = 0;
            $notLoadedPallets = 0;

            foreach ($assignments[$demand->orderLineId] as $assignment) {
                $quantity = Thousandths::fromDecimal($assignment->pallet->quantity);

                if (isset($loadedPalletIds[$assignment->pallet->id])) {
                    $loaded += $quantity;
                    $loadedPallets++;
                } else {
                    $notLoaded += $quantity;
                    $notLoadedPallets++;
                }
            }

            $toPlan = $this->toPlan($demand);
            $plannable = $demand->roundId !== null;

            $coverage[] = new DemandCoverage(
                $demand,
                $plannable,
                Thousandths::toDecimal($toPlan),
                Thousandths::toDecimal($loaded),
                Thousandths::toDecimal($notLoaded),
                // Manca solo quello che in magazzino non c'è: i bancali scelti ma rimasti a terra non contano qui.
                Thousandths::toDecimal($plannable ? max(0, $toPlan - $loaded - $notLoaded) : 0),
                Thousandths::toDecimal(max(0, $loaded - $toPlan)),
                $loadedPallets,
                $notLoadedPallets
            );
        }

        return $coverage;
    }

    /** Quanto resta da pianificare di una riga, in millesimi: richiesto meno già su viaggi confermati. */
    private function toPlan(PlanDemand $demand): int
    {
        return max(0, Thousandths::fromDecimal($demand->quantity) - Thousandths::fromDecimal($demand->plannedQuantity));
    }

    /**
     * @param list<PlanAssignment> $assignments
     * @return list<PlanPallet>
     */
    private function palletsOf(array $assignments): array
    {
        return array_map(static fn (PlanAssignment $assignment): PlanPallet => $assignment->pallet, $assignments);
    }
}
