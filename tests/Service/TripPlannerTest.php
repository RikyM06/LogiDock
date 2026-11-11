<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use LogiDock\Model\PlanAssignment;
use LogiDock\Model\PlanDemand;
use LogiDock\Model\PlanLeftover;
use LogiDock\Model\PlannedTrip;
use LogiDock\Model\PlanPallet;
use LogiDock\Model\PlanSeedTrip;
use LogiDock\Model\PlanVehicle;
use LogiDock\Model\TripPlan;
use LogiDock\Service\LoadCalculator;
use LogiDock\Service\TripPlanner;
use LogiDock\Tests\Support\PlanFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Le regole del pianificatore con dati finti in memoria (niente database): bancali da 120 cartoni
 * e 500 kg, un camion da 7,2 m e un bilico da 13,6 m, impilamento a 2 livelli.
 */
final class TripPlannerTest extends TestCase
{
    use PlanFixtures;

    private const DATE = '2031-03-10';

    private function planner(int $maxStackLevels = 2): TripPlanner
    {
        return new TripPlanner(new LoadCalculator($maxStackLevels));
    }

    /**
     * @return list<int>
     */
    private function palletIds(PlannedTrip $trip): array
    {
        return array_map(static fn (PlanAssignment $assignment): int => $assignment->pallet->id, $trip->assignments);
    }

    // --- Regola 1: bancali alle richieste ---

    public function testARequestTakesTheOldestPalletsAndAWholePalletMayCarryMoreThanAsked(): void
    {
        // I bancali arrivano già dal più vecchio: il pianificatore deve prendere il primo.
        $pallets = [$this->pallet(id: 7), $this->pallet(id: 3)];

        $plan = $this->planner()->plan(self::DATE, [$this->demand(1, '40.000')], $pallets, [$this->smallTruck()]);

        self::assertCount(1, $plan->trips);
        self::assertSame([7], $this->palletIds($plan->trips[0]));

        $coverage = $plan->coverage[0];
        self::assertTrue($coverage->isCovered());
        self::assertSame('40.000', $coverage->toPlanQuantity);
        self::assertSame('120.000', $coverage->loadedQuantity);
        self::assertSame('80.000', $coverage->surplusQuantity, '120 caricati per 40 richiesti');
        self::assertSame('0.000', $coverage->missingQuantity);
        self::assertSame(1, $coverage->loadedPallets);
    }

    public function testARequestTakesAsManyPalletsAsItNeeds(): void
    {
        $plan = $this->planner()->plan(self::DATE, [$this->demand(1, '240.000')], $this->pallets(3), [$this->smallTruck()]);

        self::assertCount(2, $plan->trips[0]->assignments, 'Due bancali da 120 coprono 240 esatti: il terzo resta libero');
        self::assertFalse($plan->coverage[0]->hasSurplus());
    }

    public function testWhatIsNotInTheWarehouseIsReportedAsMissing(): void
    {
        $plan = $this->planner()->plan(self::DATE, [$this->demand(1, '300.000')], $this->pallets(1), [$this->smallTruck()]);

        $coverage = $plan->coverage[0];
        self::assertSame('120.000', $coverage->loadedQuantity, 'Parte comunque quello che c\'è');
        self::assertSame('180.000', $coverage->missingQuantity);
        self::assertTrue($coverage->hasMissing());
        self::assertFalse($coverage->isCovered());
    }

    public function testTheFirstRequestIsServedFirstWhenStockIsShort(): void
    {
        $demands = [
            $this->demand(1, '100.000', customerName: 'Primo'),
            $this->demand(2, '100.000', customerName: 'Secondo'),
        ];

        $plan = $this->planner()->plan(self::DATE, $demands, $this->pallets(1), [$this->smallTruck()]);

        self::assertTrue($plan->coverage[0]->isCovered());
        self::assertSame('100.000', $plan->coverage[1]->missingQuantity);
        self::assertSame(0, $plan->coverage[1]->loadedPallets);
        self::assertSame(['Primo'], $plan->trips[0]->stops());
    }

    public function testWhatIsAlreadyOnAConfirmedTripIsNotPlannedAgain(): void
    {
        // 300 richiesti, 120 già su un viaggio confermato: ne restano 180 = due bancali.
        $demand = $this->demand(1, '300.000', plannedQuantity: '120.000');

        $plan = $this->planner()->plan(self::DATE, [$demand], $this->pallets(5), [$this->smallTruck()]);

        self::assertCount(2, $plan->trips[0]->assignments);
        self::assertSame('180.000', $plan->coverage[0]->toPlanQuantity);
        self::assertSame('60.000', $plan->coverage[0]->surplusQuantity);
    }

    public function testEachProductUsesOnlyItsOwnPallets(): void
    {
        $demands = [$this->demand(1, '100.000', productId: 1), $this->demand(2, '100.000', productId: 2)];
        $pallets = [$this->pallet(productId: 2, id: 20), $this->pallet(productId: 1, id: 10)];

        $plan = $this->planner()->plan(self::DATE, $demands, $pallets, [$this->smallTruck()]);

        $destinations = [];
        foreach ($plan->trips[0]->assignments as $assignment) {
            $destinations[$assignment->pallet->id] = $assignment->demand->orderLineId;
        }

        self::assertSame([10 => 1, 20 => 2], $destinations);
    }

    // --- Regola 2: giri ---

    public function testACustomerWithoutARoundIsLeftOutAndTakesNoPallets(): void
    {
        $demands = [
            $this->demand(1, '100.000', roundId: null, customerName: 'Senza giro'),
            $this->demand(2, '100.000', customerName: 'Con giro'),
        ];

        $plan = $this->planner()->plan(self::DATE, $demands, $this->pallets(1), [$this->smallTruck()]);

        self::assertFalse($plan->coverage[0]->plannable);
        self::assertFalse($plan->coverage[0]->isCovered());
        self::assertSame('0.000', $plan->coverage[0]->missingQuantity, 'Non è merce che manca: manca il giro');
        self::assertTrue($plan->coverage[1]->isCovered(), 'Il bancale va alla richiesta che ha un giro');
        self::assertSame(['Con giro'], $plan->trips[0]->stops());
    }

    public function testEveryRoundGetsItsOwnTripInAlphabeticalOrder(): void
    {
        $demands = [
            $this->demand(1, '100.000', roundId: 20, customerName: 'Cliente del sud'),
            $this->demand(2, '100.000', roundId: 10, customerName: 'Cliente del nord'),
        ];

        $plan = $this->planner()->plan(self::DATE, $demands, $this->pallets(2), [$this->smallTruck(1), $this->smallTruck(2)]);

        self::assertCount(2, $plan->trips);
        self::assertSame(['Giro Nord', 'Giro Sud'], [$plan->trips[0]->roundName, $plan->trips[1]->roundName]);
        self::assertSame([1, 2], [$plan->trips[0]->vehicle->id, $plan->trips[1]->vehicle->id], 'Un mezzo fa un solo viaggio');
        self::assertSame([], $plan->leftovers);
    }

    public function testStopsAreTheCustomersOfTheLoadedPalletsInAlphabeticalOrder(): void
    {
        $demands = [
            $this->demand(1, '100.000', productId: 1, customerName: 'Zeta Market'),
            $this->demand(2, '100.000', productId: 1, customerName: 'Alfa Bar'),
            $this->demand(3, '100.000', productId: 2, customerName: 'Zeta Market', orderId: 1),
        ];
        $pallets = [...$this->pallets(2, productId: 1), ...$this->pallets(1, productId: 2)];

        $plan = $this->planner()->plan(self::DATE, $demands, $pallets, [$this->smallTruck()]);

        self::assertSame(['Alfa Bar', 'Zeta Market'], $plan->trips[0]->stops());
        self::assertSame(
            ['Alfa Bar', 'Zeta Market', 'Zeta Market'],
            array_map(static fn (PlanAssignment $a): string => $a->demand->customerName, $plan->trips[0]->assignments),
            'I bancali sono elencati tappa per tappa'
        );
    }

    // --- Regola 3: mezzi ---

    public function testTheSmallestVehicleThatTakesEverythingIsChosen(): void
    {
        // I mezzi arrivano in ordine sparso: deve scegliere comunque il camion piccolo (id 1).
        $vehicles = [$this->bigTruck(2), $this->smallTruck(1)];

        $plan = $this->planner()->plan(self::DATE, [$this->demand(1, '600.000')], $this->pallets(5), $vehicles);

        self::assertCount(1, $plan->trips);
        self::assertSame(1, $plan->trips[0]->vehicle->id);
        self::assertSame(5, $plan->trips[0]->usage->palletCount);
        self::assertSame('2500.000', $plan->trips[0]->usage->weightKg);
        self::assertSame(1200, $plan->trips[0]->usage->usedLengthMm, '5 bancali su 2 livelli = 3 pile = 1 fila');
    }

    public function testABiggerVehicleIsChosenWhenTheSmallOneIsNotEnough(): void
    {
        // 14 bancali da 500 kg = 7000 kg: oltre i 6500 del camion piccolo.
        $plan = $this->planner()->plan(
            self::DATE,
            [$this->demand(1, '1680.000')],
            $this->pallets(14),
            [$this->smallTruck(1), $this->bigTruck(2)]
        );

        self::assertCount(1, $plan->trips);
        self::assertSame(2, $plan->trips[0]->vehicle->id);
    }

    public function testALoadTooBigForOneVehicleIsSplitStartingFromTheBiggest(): void
    {
        // 50 bancali da 500 kg = 25 t: il bilico ne porta 48 (24 t), gli altri 2 vanno sul camion.
        $plan = $this->planner()->plan(
            self::DATE,
            [$this->demand(1, '6000.000')],
            $this->pallets(50),
            [$this->smallTruck(1), $this->bigTruck(2)]
        );

        self::assertCount(2, $plan->trips);
        self::assertSame([2, 1], [$plan->trips[0]->vehicle->id, $plan->trips[1]->vehicle->id]);
        self::assertSame([48, 2], [$plan->trips[0]->usage->palletCount, $plan->trips[1]->usage->palletCount]);
        self::assertSame([], $plan->leftovers);
        self::assertTrue($plan->coverage[0]->isCovered());
    }

    public function testWhatDoesNotFitAnywhereStaysInTheWarehouse(): void
    {
        // Un solo camion con al massimo 3 bancali: il quarto resta a terra.
        $plan = $this->planner()->plan(
            self::DATE,
            [$this->demand(1, '480.000')],
            $this->pallets(4),
            [$this->smallTruck(1, maxPallets: 3)]
        );

        self::assertSame(3, $plan->trips[0]->usage->palletCount);
        self::assertCount(1, $plan->leftovers);
        self::assertSame(PlanLeftover::REASON_NO_VEHICLE, $plan->leftovers[0]->reason);

        $coverage = $plan->coverage[0];
        self::assertSame('360.000', $coverage->loadedQuantity);
        self::assertSame('120.000', $coverage->notLoadedQuantity);
        self::assertSame(1, $coverage->notLoadedPallets);
        self::assertSame('0.000', $coverage->missingQuantity, 'La merce c\'è: manca il posto');
        self::assertTrue($coverage->hasNotLoaded());
        self::assertFalse($coverage->isCovered());
    }

    public function testWithoutVehiclesNothingIsPlanned(): void
    {
        $plan = $this->planner()->plan(self::DATE, [$this->demand(1, '100.000')], $this->pallets(1), []);

        self::assertFalse($plan->hasTrips());
        self::assertCount(1, $plan->leftovers);
        self::assertSame(PlanLeftover::REASON_NO_VEHICLE, $plan->leftovers[0]->reason);
    }

    public function testAPalletThatFitsNoVehicleIsReportedWithItsReason(): void
    {
        $tooTall = $this->pallet(heightMm: 3000);

        $plan = $this->planner()->plan(self::DATE, [$this->demand(1, '100.000')], [$tooTall], [$this->smallTruck()]);

        self::assertFalse($plan->hasTrips());
        self::assertSame(PlanLeftover::REASON_DOES_NOT_FIT, $plan->leftovers[0]->reason);
    }

    public function testAPalletThatDoesNotFitIsSkippedAndTheNextOnesAreStillLoaded(): void
    {
        // Portata 1000 kg: il bancale da 900 entra, quello da 800 no, quello da 100 sì.
        $pallets = [$this->pallet(weightKg: '900.000', id: 1), $this->pallet(weightKg: '800.000', id: 2), $this->pallet(weightKg: '100.000', id: 3)];

        $plan = $this->planner()->plan(
            self::DATE,
            [$this->demand(1, '360.000')],
            $pallets,
            [$this->smallTruck(1, maxWeightKg: '1000.00')]
        );

        self::assertSame([1, 3], $this->palletIds($plan->trips[0]));
        self::assertSame(2, $plan->leftovers[0]->assignment->pallet->id);
    }

    public function testASecondRoundFindsNoVehicleWhenTheOnlyOneIsTaken(): void
    {
        $demands = [
            $this->demand(1, '100.000', roundId: 10, customerName: 'Nord'),
            $this->demand(2, '100.000', roundId: 20, customerName: 'Sud'),
        ];

        $plan = $this->planner()->plan(self::DATE, $demands, $this->pallets(2), [$this->smallTruck()]);

        self::assertCount(1, $plan->trips);
        self::assertSame('Giro Nord', $plan->trips[0]->roundName);
        self::assertSame('Sud', $plan->leftovers[0]->assignment->demand->customerName);
        self::assertSame(PlanLeftover::REASON_NO_VEHICLE, $plan->leftovers[0]->reason);
    }

    public function testNoRequestsMeansAnEmptyPlan(): void
    {
        $plan = $this->planner()->plan(self::DATE, [], $this->pallets(3), [$this->smallTruck()]);

        self::assertFalse($plan->hasTrips());
        self::assertSame([], $plan->leftovers);
        self::assertSame([], $plan->coverage);
    }

    // --- Impronta del piano ---

    public function testTheSameInputAlwaysGivesTheSameFingerprint(): void
    {
        $build = fn (): TripPlan => $this->planner()->plan(
            self::DATE,
            [$this->demand(1, '200.000')],
            [$this->pallet(id: 1), $this->pallet(id: 2)],
            [$this->smallTruck()]
        );

        self::assertSame($build()->fingerprint(), $build()->fingerprint());
        self::assertSame(64, strlen($build()->fingerprint()));
    }

    public function testTheFingerprintChangesWhenAnythingThatWouldBeWrittenChanges(): void
    {
        $demand = $this->demand(1, '100.000');
        $base = $this->planner()->plan(self::DATE, [$demand], [$this->pallet(id: 1)], [$this->smallTruck(1)])->fingerprint();

        $otherPallet = $this->planner()->plan(self::DATE, [$demand], [$this->pallet(id: 2)], [$this->smallTruck(1)])->fingerprint();
        $otherVehicle = $this->planner()->plan(self::DATE, [$demand], [$this->pallet(id: 1)], [$this->smallTruck(5)])->fingerprint();
        $otherDay = $this->planner()->plan('2031-03-11', [$demand], [$this->pallet(id: 1)], [$this->smallTruck(1)])->fingerprint();
        $otherLine = $this->planner()->plan(self::DATE, [$this->demand(9, '100.000')], [$this->pallet(id: 1)], [$this->smallTruck(1)])->fingerprint();
        $nothing = $this->planner()->plan(self::DATE, [$demand], [], [$this->smallTruck(1)])->fingerprint();

        self::assertCount(6, array_unique([$base, $otherPallet, $otherVehicle, $otherDay, $otherLine, $nothing]));
    }

    public function testTheFingerprintTellsANewTripFromAnExistingOneAndANormalPlanFromAReplan(): void
    {
        $demand = $this->demand(2, '100.000');
        $pallet = $this->pallet(id: 5);
        $seed = $this->seedTrip(40, 1, [$this->assignment($this->pallet(id: 1), $this->demand(1, '100.000'))]);

        $onNewTrip = $this->planner()->plan(self::DATE, [$demand], [$pallet], [$this->smallTruck(1)]);
        $onExistingTrip = $this->planner()->plan(self::DATE, [$demand], [$pallet], [], [$seed]);
        $replan = $this->planner()->plan(self::DATE, [$demand], [$pallet], [], [$seed], true);

        self::assertCount(3, array_unique([
            $onNewTrip->fingerprint(), $onExistingTrip->fingerprint(), $replan->fingerprint(),
        ]));
    }

    // --- Viaggi già confermati e scelte a mano ---

    public function testNewPalletsFillAConfirmedTripBeforeTakingAnotherVehicle(): void
    {
        $seed = $this->seedTrip(40, 1, [$this->assignment($this->pallet(id: 1), $this->demand(1, '100.000'))]);

        // Il bilico (id 2) è libero e più grande, ma il mezzo del viaggio già confermato ha posto.
        $plan = $this->planner()->plan(
            self::DATE,
            [$this->demand(2, '100.000')],
            [$this->pallet(id: 2)],
            [$this->bigTruck(2)],
            [$seed]
        );

        self::assertCount(1, $plan->trips);
        $trip = $plan->trips[0];
        self::assertTrue($trip->isExisting());
        self::assertSame(40, $trip->tripId);
        self::assertSame('VIA-TEST-40', $trip->tripCode);
        self::assertSame(1, $trip->vehicle->id, 'il mezzo del viaggio confermato non cambia');
        self::assertSame([1, 2], $this->palletIds($trip));
        self::assertSame([true, false], array_map(static fn (PlanAssignment $a): bool => $a->fixed, $trip->assignments));
        self::assertSame([2], array_map(static fn (PlanAssignment $a): int => $a->pallet->id, $trip->newAssignments()));
        self::assertSame(1, $plan->coverage[0]->loadedPallets, 'il bancale su un viaggio confermato conta come caricato');
        self::assertSame([], $plan->leftovers);
    }

    public function testAConfirmedTripThePlanDoesNotChangeIsLeftOut(): void
    {
        $seed = $this->seedTrip(40, 1, [$this->assignment($this->pallet(id: 1), $this->demand(1, '100.000'))]);

        $plan = $this->planner()->plan(self::DATE, [], [], [$this->bigTruck(2)], [$seed]);

        self::assertFalse($plan->hasTrips());
    }

    public function testAFullConfirmedTripTakesNothingAndTheRestGoesToAnotherVehicle(): void
    {
        $seed = $this->seedTrip(
            40,
            1,
            [$this->assignment($this->pallet(id: 1), $this->demand(1, '100.000'))],
            $this->smallTruck(1, maxPallets: 1)
        );

        $plan = $this->planner()->plan(
            self::DATE,
            [$this->demand(2, '100.000')],
            [$this->pallet(id: 2)],
            [$this->bigTruck(2)],
            [$seed]
        );

        self::assertCount(1, $plan->trips, 'il viaggio pieno non cambia e non compare');
        self::assertFalse($plan->trips[0]->isExisting());
        self::assertSame(2, $plan->trips[0]->vehicle->id);
        self::assertSame([2], $this->palletIds($plan->trips[0]));
    }

    public function testReplanKeepsLockedPalletsAndReassignsTheOthers(): void
    {
        $locked = $this->assignment($this->pallet(id: 3), $this->demand(1, '100.000'), locked: true);
        $unlocked = $this->assignment($this->pallet(id: 4), $this->demand(2, '100.000'));
        $seed = $this->seedTrip(40, 1, [$locked, $unlocked]);

        // Nel mucchio c'è un bancale più vecchio (id 2, arriva prima) e quello sbloccato (id 4).
        $plan = $this->planner()->plan(
            self::DATE,
            [$this->demand(2, '100.000')],
            [$this->pallet(id: 2), $this->pallet(id: 4)],
            [],
            [$seed],
            true
        );

        self::assertTrue($plan->replan);
        self::assertCount(1, $plan->trips);
        $trip = $plan->trips[0];
        self::assertSame(40, $trip->tripId);
        self::assertSame([3, 2], $this->palletIds($trip), 'il bloccato resta, al posto dello sbloccato va il più vecchio');
        self::assertSame([true, false], array_map(static fn (PlanAssignment $a): bool => $a->fixed, $trip->assignments));
        self::assertTrue($trip->assignments[0]->locked);
    }

    public function testReplanWithNothingToChangeLeavesTheTripOut(): void
    {
        $locked = $this->assignment($this->pallet(id: 3), $this->demand(1, '100.000'), locked: true);
        $unlocked = $this->assignment($this->pallet(id: 4), $this->demand(2, '100.000'));
        $seed = $this->seedTrip(40, 1, [$locked, $unlocked]);

        $plan = $this->planner()->plan(self::DATE, [$this->demand(2, '100.000')], [$this->pallet(id: 4)], [], [$seed], true);

        self::assertFalse($plan->hasTrips(), 'lo stesso bancale va alla stessa riga: niente da cambiare');
    }

    public function testReplanCanEmptyATripWhenItsPalletsAreBetterUsedElsewhere(): void
    {
        $first = $this->seedTrip(40, 1, [$this->assignment($this->pallet(id: 1), $this->demand(1, '100.000'))]);
        $second = $this->seedTrip(41, 2, [$this->assignment($this->pallet(id: 2), $this->demand(2, '100.000'))], $this->smallTruck(2));

        // Le due righe stanno sullo stesso giro: il primo viaggio prende tutto e il secondo resta vuoto.
        $plan = $this->planner()->plan(
            self::DATE,
            [$this->demand(1, '100.000'), $this->demand(2, '100.000')],
            [$this->pallet(id: 1), $this->pallet(id: 2)],
            [],
            [$first, $second],
            true
        );

        self::assertCount(2, $plan->trips);
        self::assertSame(40, $plan->trips[0]->tripId);
        self::assertSame([1, 2], $this->palletIds($plan->trips[0]), 'il primo viaggio prende anche il bancale dell\'altro');
        self::assertSame(41, $plan->trips[1]->tripId);
        self::assertSame([], $plan->trips[1]->assignments, 'il secondo viaggio resterebbe senza bancali');
    }

    /**
     * @param list<PlanAssignment> $assignments
     */
    private function seedTrip(int $tripId, int $vehicleId, array $assignments, ?PlanVehicle $vehicle = null): PlanSeedTrip
    {
        return new PlanSeedTrip(
            $tripId,
            'VIA-TEST-' . $tripId,
            10,
            'Giro Nord',
            $vehicle ?? $this->smallTruck($vehicleId),
            $assignments
        );
    }

    private function assignment(PlanPallet $pallet, PlanDemand $demand, bool $locked = false): PlanAssignment
    {
        return new PlanAssignment($pallet, $demand, false, $locked);
    }
}
