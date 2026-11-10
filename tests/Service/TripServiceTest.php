<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use LogiDock\Enum\UserRole;
use LogiDock\Model\Order;
use LogiDock\Model\PlanLeftover;
use LogiDock\Model\PlanningDay;
use LogiDock\Model\Trip;
use LogiDock\Repository\CarrierRepository;
use LogiDock\Repository\CustomerRepository;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\PalletRepository;
use LogiDock\Repository\PalletTypeRepository;
use LogiDock\Repository\PlanningRepository;
use LogiDock\Repository\ProductRepository;
use LogiDock\Repository\RoundRepository;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\TrailerTypeRepository;
use LogiDock\Repository\TripRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Repository\VehicleRepository;
use LogiDock\Service\CarrierService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\OrderNotEditableException;
use LogiDock\Service\OrderService;
use LogiDock\Service\PalletTypeService;
use LogiDock\Service\PlanChangedException;
use LogiDock\Service\ProductService;
use LogiDock\Service\RoundService;
use LogiDock\Service\SettingService;
use LogiDock\Service\TrailerTypeService;
use LogiDock\Service\TripNotCancellableException;
use LogiDock\Service\TripService;
use LogiDock\Service\VehicleService;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * Pianificazione dei viaggi sul database di test: piano, conferma e annullamento con servizi e
 * repository veri. Le regole di calcolo (quali bancali, quale mezzo) sono già provate una per una
 * in TripPlannerTest e LoadCalculatorTest: qui si controlla che i dati letti e scritti siano giusti.
 *
 * Il giorno di consegna è lontano (15 marzo 2027) e i prodotti sono solo quelli creati qui, così
 * i dati lasciati nel database di test dalle prove HTTP non entrano nei conti.
 */
final class TripServiceTest extends IntegrationTestCase
{
    private const DAY = '2027-03-15';

    private TripService $trips;
    private OrderService $orders;
    private CustomerService $customers;
    private VehicleService $vehicles;
    private PalletRepository $pallets;

    private DateTimeImmutable $now;
    private int $userId;
    private int $roundId;
    private int $alfaId;
    private int $betaId;
    private int $waterId;
    private int $pastaId;
    private int $trailerTypeId;
    private int $carrierId;
    private int $vehicleId;
    private int $epal1Id;
    private int $palletNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->database;
        $this->now = new DateTimeImmutable('2026-11-10 10:00:00');

        $rounds = new RoundService(new RoundRepository($db));
        $this->customers = new CustomerService(new CustomerRepository($db), $rounds);
        $products = new ProductService(new ProductRepository($db));
        $orderRepository = new OrderRepository($db);
        $sequences = new NumberSequenceRepository($db);
        $this->pallets = new PalletRepository($db);
        $this->orders = new OrderService($db, $orderRepository, $sequences, $this->customers, $products);
        $this->trips = new TripService(
            $db,
            new PlanningRepository($db),
            new TripRepository($db),
            $this->pallets,
            $orderRepository,
            $sequences,
            new PalletTypeService(new PalletTypeRepository($db)),
            new SettingService(new SettingRepository($db), $db)
        );

        $this->userId = (new UserRepository($db))->insert(
            'trips-test@example.com',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            UserRole::Operatore
        );

        $this->roundId = $rounds->create(['name' => 'Zz Giro Viaggi']);
        $this->alfaId = $this->customers->create(['name' => 'Zz Alfa Market', 'round_id' => (string) $this->roundId]);
        $this->betaId = $this->customers->create(['name' => 'Zz Beta Bar', 'round_id' => (string) $this->roundId]);
        $this->waterId = $products->create([
            'code' => 'ZZ-TR-ACQUA', 'description' => 'Acqua di prova', 'unit_of_measure' => 'ct', 'unit_weight_kg' => '10',
        ]);
        $this->pastaId = $products->create([
            'code' => 'ZZ-TR-PASTA', 'description' => 'Pasta di prova', 'unit_of_measure' => 'ct', 'unit_weight_kg' => '5',
        ]);

        // I mezzi rimasti nel database (se ce ne sono) non devono entrare nel piano di questi test.
        $db->pdo()->exec('UPDATE vehicles SET is_active = 0');

        $trailerTypes = new TrailerTypeService(new TrailerTypeRepository($db));
        $carriers = new CarrierService(new CarrierRepository($db));
        $this->vehicles = new VehicleService(new VehicleRepository($db), $trailerTypes, $carriers);
        $this->trailerTypeId = $trailerTypes->create([
            'name' => 'Zz Rimorchio di prova', 'length_mm' => '7200', 'width_mm' => '2450',
            'inner_height_mm' => '2600', 'max_weight_kg' => '6500',
        ]);
        $this->carrierId = $carriers->create(['name' => 'Zz Trasporti di prova', 'trip_fee' => '150']);
        $this->vehicleId = $this->vehicle('ZZ001TR');

        $this->epal1Id = (int) $db->pdo()->query("SELECT id FROM pallet_types WHERE code = 'EPAL1'")->fetchColumn();
    }

    // --- piano ---

    public function testPlanProposesATripWithTheOldestPalletsAndWritesNothing(): void
    {
        $orderId = $this->order($this->alfaId, [[$this->waterId, '40']]);
        $newest = $this->pallet($this->waterId, '30', '2026-11-03 08:00:00');
        $oldest = $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');
        $middle = $this->pallet($this->waterId, '30', '2026-11-02 08:00:00');

        $plan = $this->trips->plan(self::DAY);

        self::assertCount(1, $plan->trips);
        $trip = $plan->trips[0];
        self::assertSame($this->roundId, $trip->roundId);
        self::assertSame('ZZ001TR', $trip->vehicle->plate);
        self::assertSame(['Zz Alfa Market'], $trip->stops());
        self::assertSame(
            [$oldest, $middle],
            array_map(static fn ($assignment): int => $assignment->pallet->id, $trip->assignments),
            'i due bancali più vecchi; il terzo non serve'
        );
        self::assertSame('600.000', $trip->usage->weightKg, '30 ct x 10 kg x 2 bancali, calcolato da MySQL');

        $coverage = $plan->coverage[0];
        self::assertSame($orderId, $coverage->demand->orderId);
        self::assertSame('60.000', $coverage->loadedQuantity);
        self::assertSame('20.000', $coverage->surplusQuantity, 'i bancali partono interi');
        self::assertTrue($coverage->isCovered());

        // Il piano è solo un calcolo: nel database non è cambiato niente.
        self::assertSame('in_stock', $this->palletStatus($oldest));
        self::assertSame('in_stock', $this->palletStatus($newest));
        self::assertSame(Order::STATUS_OPEN, $this->orders->find($orderId)?->status);
        self::assertSame([], $this->trips->tripsOn(self::DAY));
    }

    public function testRecordedWeightWinsOverComputedWeight(): void
    {
        $this->order($this->alfaId, [[$this->waterId, '10']]);
        $this->pallet($this->waterId, '30', '2026-11-01 08:00:00', recordedWeightKg: '315.5');

        $plan = $this->trips->plan(self::DAY);

        self::assertSame('315.500', $plan->trips[0]->assignments[0]->pallet->weightKg);
    }

    public function testPalletWithoutTypeIsTreatedAsTheLargestType(): void
    {
        $this->order($this->alfaId, [[$this->waterId, '10']]);
        $this->pallet($this->waterId, '30', '2026-11-01 08:00:00', withType: false);

        $largestArea = (int) $this->database->pdo()
            ->query('SELECT MAX(length_mm * width_mm) FROM pallet_types')
            ->fetchColumn();
        $pallet = $this->trips->plan(self::DAY)->trips[0]->assignments[0]->pallet;

        self::assertTrue($pallet->typeAssumed);
        self::assertSame($largestArea, $pallet->lengthMm * $pallet->widthMm);
    }

    public function testCustomerWithoutRoundIsNotPlanned(): void
    {
        $noRound = $this->customers->create(['name' => 'Zz Senza Giro']);
        $this->order($noRound, [[$this->waterId, '10']]);
        $pallet = $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');

        $plan = $this->trips->plan(self::DAY);

        self::assertFalse($plan->hasTrips());
        self::assertFalse($plan->coverage[0]->plannable);
        self::assertSame([], $plan->leftovers, 'la riga non prende nessun bancale');
        self::assertSame('in_stock', $this->palletStatus($pallet));
    }

    public function testVehicleWithoutTrailerTypeIsNotUsed(): void
    {
        $this->vehicles->deactivate($this->vehicleId);
        $before = $this->trips->vehiclesWithoutTrailerType();
        $this->vehicle('ZZ002TR', withTrailerType: false);
        $this->order($this->alfaId, [[$this->waterId, '10']]);
        $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');

        $plan = $this->trips->plan(self::DAY);

        self::assertFalse($plan->hasTrips());
        self::assertCount(1, $plan->leftovers);
        self::assertSame(PlanLeftover::REASON_NO_VEHICLE, $plan->leftovers[0]->reason);
        self::assertSame($before + 1, $this->trips->vehiclesWithoutTrailerType());
    }

    public function testInvalidDateIsRejected(): void
    {
        self::assertArrayHasKey('date', $this->errorsOf(fn () => $this->trips->plan('2027-02-31')));
        self::assertArrayHasKey('date', $this->errorsOf(fn () => $this->trips->plan('domani')));
        self::assertArrayHasKey('date', $this->errorsOf(fn () => $this->trips->confirm('', 'x', $this->userId, $this->now)));
    }

    // --- conferma ---

    public function testConfirmCreatesTheTripReservesPalletsAndPlansOrders(): void
    {
        $alfaOrder = $this->order($this->alfaId, [[$this->waterId, '40'], [$this->pastaId, '20']]);
        $betaOrder = $this->order($this->betaId, [[$this->waterId, '30']]);
        $water1 = $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');
        $water2 = $this->pallet($this->waterId, '30', '2026-11-02 08:00:00');
        $water3 = $this->pallet($this->waterId, '30', '2026-11-03 08:00:00');
        $water4 = $this->pallet($this->waterId, '30', '2026-11-04 08:00:00');
        $pasta = $this->pallet($this->pastaId, '50', '2026-11-01 08:00:00', recordedWeightKg: '262');

        self::assertContains(self::DAY, $this->daysToPlan());

        $tripIds = $this->confirmPlan();

        self::assertCount(1, $tripIds, 'un solo giro, un solo mezzo');
        $trip = $this->trips->find($tripIds[0]);
        self::assertNotNull($trip);
        self::assertMatchesRegularExpression('/^VIA-2026-\d{6}$/', $trip->code);
        self::assertSame(Trip::STATUS_PLANNED, $trip->status);
        self::assertSame(self::DAY, $trip->plannedDate);
        self::assertSame('Zz Giro Viaggi', $trip->roundName);
        self::assertSame('ZZ001TR', $trip->vehiclePlate);
        self::assertSame('Zz Rimorchio di prova', $trip->trailerTypeName);
        self::assertSame('Zz Trasporti di prova', $trip->carrierName);
        self::assertSame('150.00', $trip->carrierFee, 'il compenso è copiato sul viaggio');
        self::assertSame(4, $trip->palletCount);
        self::assertSame('1162.000', $trip->totalWeightKg, '3 x 300 kg di acqua + 262 kg segnati per la pasta');

        $items = $this->trips->itemsOf($tripIds[0]);
        self::assertSame(
            ['Zz Alfa Market', 'Zz Alfa Market', 'Zz Alfa Market', 'Zz Beta Bar'],
            array_map(static fn ($item): string => $item->customerName, $items),
            'in ordine di tappa'
        );
        $beta = $items[3];
        self::assertSame($water3, $beta->palletId, 'alla seconda richiesta va il primo bancale rimasto libero');
        self::assertSame($betaOrder, $beta->orderId);
        self::assertSame('30.000', $beta->quantity);
        self::assertSame('300.000', $beta->weightKg);
        self::assertSame('EPAL 1', $beta->palletTypeName);

        foreach ([$water1, $water2, $water3, $pasta] as $palletId) {
            self::assertSame('reserved', $this->palletStatus($palletId));
        }
        self::assertSame('in_stock', $this->palletStatus($water4), 'il bancale che non serviva resta libero');
        self::assertSame(Order::STATUS_PLANNED, $this->orders->find($alfaOrder)?->status);
        self::assertSame(Order::STATUS_PLANNED, $this->orders->find($betaOrder)?->status);

        // Tutto pianificato: il giorno non è più tra quelli da pianificare e non c'è altro da confermare.
        self::assertNotContains(self::DAY, $this->daysToPlan());
        self::assertFalse($this->trips->plan(self::DAY)->hasTrips());
        self::assertCount(1, $this->trips->tripsOn(self::DAY));
        self::assertSame($tripIds[0], $this->trips->recent()[0]->id);
    }

    public function testTripCodesAreConsecutive(): void
    {
        $this->vehicle('ZZ002TR');
        $otherRound = (new RoundService(new RoundRepository($this->database)))->create(['name' => 'Zz Giro Viaggi 2']);
        $gamma = $this->customers->create(['name' => 'Zz Gamma', 'round_id' => (string) $otherRound]);
        $this->order($this->alfaId, [[$this->waterId, '10']]);
        $this->order($gamma, [[$this->waterId, '10']]);
        $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');
        $this->pallet($this->waterId, '30', '2026-11-02 08:00:00');

        $tripIds = $this->confirmPlan();

        self::assertCount(2, $tripIds, 'due giri: due viaggi su due mezzi diversi');
        $first = $this->trips->find($tripIds[0]);
        $second = $this->trips->find($tripIds[1]);
        self::assertSame((int) substr((string) $first?->code, -6) + 1, (int) substr((string) $second?->code, -6));
        self::assertEqualsCanonicalizing(['ZZ001TR', 'ZZ002TR'], [$first?->vehiclePlate, $second?->vehiclePlate]);
    }

    public function testConfirmIsRefusedWhenThePlanChangedInTheMeantime(): void
    {
        $orderId = $this->order($this->alfaId, [[$this->waterId, '10']]);
        $pallet = $this->pallet($this->waterId, '30', '2026-11-02 08:00:00');
        $seen = $this->trips->plan(self::DAY)->fingerprint();

        // Dopo che l'utente ha guardato il piano arriva un bancale più vecchio: il piano giusto è un altro.
        $older = $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');

        try {
            $this->trips->confirm(self::DAY, $seen, $this->userId, $this->now);
            self::fail('Attesa una PlanChangedException.');
        } catch (PlanChangedException $e) {
            self::assertStringContainsString('cambiato', $e->getMessage());
        }

        self::assertSame([], $this->trips->tripsOn(self::DAY), 'niente è stato scritto');
        self::assertSame('in_stock', $this->palletStatus($pallet));
        self::assertSame('in_stock', $this->palletStatus($older));
        self::assertSame(Order::STATUS_OPEN, $this->orders->find($orderId)?->status);
    }

    public function testConfirmIsRefusedWhenThereIsNoTripToConfirm(): void
    {
        $emptyPlan = $this->trips->plan(self::DAY);
        self::assertFalse($emptyPlan->hasTrips());

        $this->expectException(PlanChangedException::class);
        $this->expectExceptionMessage('nessun viaggio da confermare');

        $this->trips->confirm(self::DAY, $emptyPlan->fingerprint(), $this->userId, $this->now);
    }

    public function testAPalletCanBeReservedOnlyOnce(): void
    {
        $pallet = $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');

        self::assertTrue($this->pallets->reserve($pallet));
        self::assertFalse($this->pallets->reserve($pallet), 'già prenotato: la seconda volta non lo prende');
    }

    // --- richieste pianificate ---

    public function testPlannedOrderCannotBeEditedOrCancelledUntilItsTripIsCancelled(): void
    {
        $orderId = $this->order($this->alfaId, [[$this->waterId, '10']]);
        $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');
        [$tripId] = $this->confirmPlan();

        try {
            $this->orders->update($orderId, $this->orderInput($this->alfaId, [[$this->waterId, '99']]), $this->now);
            self::fail('Attesa una OrderNotEditableException.');
        } catch (OrderNotEditableException $e) {
            self::assertStringContainsString('Pianificata', $e->getMessage());
        }
        try {
            $this->orders->cancel($orderId);
            self::fail('Attesa una OrderNotEditableException.');
        } catch (OrderNotEditableException) {
            self::assertSame(Order::STATUS_PLANNED, $this->orders->find($orderId)?->status);
        }

        $this->trips->cancel($tripId);
        $this->orders->update($orderId, $this->orderInput($this->alfaId, [[$this->waterId, '99']]), $this->now);

        self::assertSame('99.000', $this->orders->linesOf($orderId)[0]->quantity);
        // Le righe sono state riscritte: il viaggio annullato resta nello storico, legato alla richiesta.
        $items = $this->trips->itemsOf($tripId);
        self::assertCount(1, $items);
        self::assertSame($orderId, $items[0]->orderId);
    }

    public function testMissingPartIsPlannedAgainWhenTheGoodsArrive(): void
    {
        $orderId = $this->order($this->alfaId, [[$this->waterId, '100']]);
        $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');
        $this->pallet($this->waterId, '30', '2026-11-02 08:00:00');

        $plan = $this->trips->plan(self::DAY);
        self::assertSame('40.000', $plan->coverage[0]->missingQuantity);
        [$firstTrip] = $this->confirmPlan();

        self::assertSame(Order::STATUS_PLANNED, $this->orders->find($orderId)?->status);
        self::assertContains(self::DAY, $this->daysToPlan(), 'mancano ancora 40 ct');

        // Arriva altra merce, ma l'unico mezzo ha già il suo viaggio per quel giorno.
        $late = $this->pallet($this->waterId, '50', '2026-11-09 08:00:00');
        $plan = $this->trips->plan(self::DAY);
        self::assertFalse($plan->hasTrips());
        self::assertSame('40.000', $plan->coverage[0]->toPlanQuantity);
        self::assertSame(PlanLeftover::REASON_NO_VEHICLE, $plan->leftovers[0]->reason);

        // Con un secondo mezzo la parte mancante parte con un altro viaggio.
        $this->vehicle('ZZ002TR');
        [$secondTrip] = $this->confirmPlan();

        self::assertSame('ZZ002TR', $this->trips->find($secondTrip)?->vehiclePlate);
        self::assertSame('reserved', $this->palletStatus($late));
        self::assertNotContains(self::DAY, $this->daysToPlan());

        // Annullato un viaggio su due, la richiesta resta pianificata; annullato anche l'altro torna aperta.
        $this->trips->cancel($firstTrip);
        self::assertSame(Order::STATUS_PLANNED, $this->orders->find($orderId)?->status);
        $this->trips->cancel($secondTrip);
        self::assertSame(Order::STATUS_OPEN, $this->orders->find($orderId)?->status);
    }

    // --- annullamento ---

    public function testCancelFreesPalletsAndReopensOrders(): void
    {
        $orderId = $this->order($this->alfaId, [[$this->waterId, '40']]);
        $first = $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');
        $second = $this->pallet($this->waterId, '30', '2026-11-02 08:00:00');
        $fingerprint = $this->trips->plan(self::DAY)->fingerprint();
        [$tripId] = $this->confirmPlan();

        $this->trips->cancel($tripId);

        self::assertSame(Trip::STATUS_CANCELLED, $this->trips->find($tripId)?->status);
        self::assertSame('in_stock', $this->palletStatus($first));
        self::assertSame('in_stock', $this->palletStatus($second));
        self::assertSame(Order::STATUS_OPEN, $this->orders->find($orderId)?->status);
        self::assertSame([], $this->trips->tripsOn(self::DAY), 'i viaggi annullati non contano');
        self::assertCount(2, $this->trips->itemsOf($tripId), 'il viaggio annullato tiene i suoi bancali come storico');

        // Tutto è tornato com'era: il piano è di nuovo quello di prima e si può riconfermare.
        self::assertSame($fingerprint, $this->trips->plan(self::DAY)->fingerprint());
        [$again] = $this->confirmPlan();
        self::assertNotSame($tripId, $again);
        self::assertSame('reserved', $this->palletStatus($first));
    }

    public function testOnlyPlannedTripsCanBeCancelled(): void
    {
        $this->order($this->alfaId, [[$this->waterId, '10']]);
        $pallet = $this->pallet($this->waterId, '30', '2026-11-01 08:00:00');
        [$tripId] = $this->confirmPlan();
        $this->trips->cancel($tripId);

        try {
            $this->trips->cancel($tripId);
            self::fail('Attesa una TripNotCancellableException.');
        } catch (TripNotCancellableException) {
            self::assertSame('in_stock', $this->palletStatus($pallet));
        }

        $this->expectException(TripNotCancellableException::class);
        $this->trips->cancel(0);
    }

    // --- aiuti ---

    /**
     * Conferma il piano del giorno così come lo vedrebbe l'utente in quel momento.
     *
     * @return list<int>
     */
    private function confirmPlan(): array
    {
        return $this->trips->confirm(
            self::DAY,
            $this->trips->plan(self::DAY)->fingerprint(),
            $this->userId,
            $this->now
        );
    }

    /**
     * @return list<string> i giorni che hanno ancora qualcosa da pianificare
     */
    private function daysToPlan(): array
    {
        return array_map(static fn (PlanningDay $day): string => $day->date, $this->trips->planningDays());
    }

    /**
     * @param list<array{0: int, 1: string}> $lines prodotto e quantità
     */
    private function order(int $customerId, array $lines): int
    {
        return $this->orders->create($this->orderInput($customerId, $lines), $this->userId, $this->now);
    }

    /**
     * @param list<array{0: int, 1: string}> $lines prodotto e quantità
     * @return array<string, mixed>
     */
    private function orderInput(int $customerId, array $lines): array
    {
        return [
            'customer_id' => (string) $customerId,
            'requested_date' => '2026-11-10',
            'delivery_date' => self::DAY,
            'notes' => '',
            'lines' => array_map(
                static fn (array $line): array => [
                    'product_id' => (string) $line[0], 'quantity' => $line[1], 'unit_price' => '2',
                ],
                $lines
            ),
        ];
    }

    private function pallet(
        int $productId,
        string $quantity,
        string $receivedAt,
        ?string $recordedWeightKg = null,
        bool $withType = true
    ): int {
        $this->database->pdo()->prepare(
            'INSERT INTO pallets (code, product_id, pallet_type_id, quantity, initial_quantity, recorded_weight_kg,
                                  received_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'ZZ-TR-' . ++$this->palletNumber, $productId, $withType ? $this->epal1Id : null, $quantity, $quantity,
            $recordedWeightKg, $receivedAt, $this->userId,
        ]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    private function vehicle(string $plate, bool $withTrailerType = true): int
    {
        return $this->vehicles->create([
            'plate' => $plate, 'description' => 'Camion di prova', 'max_weight_kg' => '6500', 'max_pallets' => '12',
            'trailer_type_id' => $withTrailerType ? (string) $this->trailerTypeId : '',
            'carrier_id' => (string) $this->carrierId,
        ]);
    }

    private function palletStatus(int $palletId): string
    {
        $statement = $this->database->pdo()->prepare('SELECT status FROM pallets WHERE id = ?');
        $statement->execute([$palletId]);

        return (string) $statement->fetchColumn();
    }
}
