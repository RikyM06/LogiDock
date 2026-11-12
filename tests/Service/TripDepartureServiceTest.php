<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use LogicException;
use LogiDock\Enum\UserRole;
use LogiDock\Model\Order;
use LogiDock\Model\TransportDocument;
use LogiDock\Model\Trip;
use LogiDock\Pdf\DocumentRenderer;
use LogiDock\Repository\CarrierRepository;
use LogiDock\Repository\CustomerRepository;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\PalletMovementRepository;
use LogiDock\Repository\PalletRepository;
use LogiDock\Repository\PalletTypeRepository;
use LogiDock\Repository\PlanningRepository;
use LogiDock\Repository\ProductRepository;
use LogiDock\Repository\RoundRepository;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\TrailerTypeRepository;
use LogiDock\Repository\TransportDocumentRepository;
use LogiDock\Repository\TripRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Repository\VehicleRepository;
use LogiDock\Service\CarrierService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\DocumentService;
use LogiDock\Service\OrderService;
use LogiDock\Service\PalletTypeService;
use LogiDock\Service\ProductService;
use LogiDock\Service\RoundService;
use LogiDock\Service\SettingService;
use LogiDock\Service\TrailerTypeService;
use LogiDock\Service\TripChangeNotAllowedException;
use LogiDock\Service\TripDepartureService;
use LogiDock\Service\TripNotCancellableException;
use LogiDock\Service\TripService;
use LogiDock\Service\VehicleService;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * La partenza di un viaggio sul database di test: bancali spediti, movimenti di uscita, richieste
 * spedite e documenti emessi, con servizi e repository veri. Ogni test gira in una transazione annullata.
 *
 * Come in TripServiceTest, il giorno di consegna è lontano (15 marzo 2027) e i prodotti sono solo
 * quelli creati qui, così i dati lasciati nel database di test da altre prove non entrano nei conti.
 */
final class TripDepartureServiceTest extends IntegrationTestCase
{
    private const DAY = '2027-03-15';

    private TripService $trips;
    private TripDepartureService $departures;
    private DocumentService $documents;
    private OrderService $orders;
    private VehicleService $vehicles;

    private DateTimeImmutable $now;
    private int $userId;
    private int $roundId;
    private int $alfaId;
    private int $betaId;
    private int $waterId;
    private int $trailerTypeId;
    private int $carrierId;
    private int $epal1Id;
    private int $palletNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->database;
        $this->now = new DateTimeImmutable('2026-11-10 10:00:00');

        $rounds = new RoundService(new RoundRepository($db));
        $customers = new CustomerService(new CustomerRepository($db), $rounds);
        $products = new ProductService(new ProductRepository($db));
        $orderRepository = new OrderRepository($db);
        $sequences = new NumberSequenceRepository($db);
        $tripRepository = new TripRepository($db);
        $pallets = new PalletRepository($db);
        $settings = new SettingService(new SettingRepository($db), $db);

        $this->orders = new OrderService($db, $orderRepository, $sequences, $customers, $products);
        $this->trips = new TripService(
            $db,
            new PlanningRepository($db),
            $tripRepository,
            $pallets,
            $orderRepository,
            $sequences,
            new PalletTypeService(new PalletTypeRepository($db)),
            $settings
        );
        // Il renderer senza compressione: così nei test il testo si può cercare dentro il PDF.
        $this->documents = new DocumentService(
            $db,
            new TransportDocumentRepository($db),
            $sequences,
            $settings,
            new DocumentRenderer(false)
        );
        $this->departures = new TripDepartureService(
            $db,
            $tripRepository,
            $pallets,
            new PalletMovementRepository($db),
            $orderRepository,
            $this->documents
        );

        $this->userId = (new UserRepository($db))->insert(
            'departure-test@example.com',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            UserRole::Operatore
        );

        $this->roundId = $rounds->create(['name' => 'Zz Giro Partenze']);
        $this->alfaId = $customers->create([
            'name' => 'Zz Alfa Market', 'round_id' => (string) $this->roundId,
            'tax_code' => 'IT00000000001', 'address' => 'Via Alfa 1',
        ]);
        $this->betaId = $customers->create(['name' => 'Zz Beta Bar', 'round_id' => (string) $this->roundId]);
        $this->waterId = $products->create([
            'code' => 'ZZ-DP-ACQUA', 'description' => 'Acqua di prova', 'unit_of_measure' => 'ct', 'unit_weight_kg' => '10',
        ]);

        // I mezzi rimasti nel database (se ce ne sono) non devono entrare nei piani di questi test.
        $db->pdo()->exec('UPDATE vehicles SET is_active = 0');

        $trailerTypes = new TrailerTypeService(new TrailerTypeRepository($db));
        $carriers = new CarrierService(new CarrierRepository($db));
        $this->vehicles = new VehicleService(new VehicleRepository($db), $trailerTypes, $carriers);
        $this->trailerTypeId = $trailerTypes->create([
            'name' => 'Zz Rimorchio partenze', 'length_mm' => '7200', 'width_mm' => '2450',
            'inner_height_mm' => '2600', 'max_weight_kg' => '6500',
        ]);
        $this->carrierId = $carriers->create(['name' => 'Zz Trasporti partenze', 'trip_fee' => '150']);

        $this->epal1Id = (int) $db->pdo()->query("SELECT id FROM pallet_types WHERE code = 'EPAL1'")->fetchColumn();
    }

    // --- bancali e movimenti ---

    public function testDepartureShipsThePalletsAndWritesOneOutboundMovementEach(): void
    {
        $locationId = $this->location('ZZ-DP-01');
        $this->vehicle('ZZ001DP');
        $this->order($this->alfaId, '60');
        $first = $this->pallet('30', '2026-11-01 08:00:00', $locationId);
        $second = $this->pallet('30', '2026-11-02 08:00:00');
        $tripId = $this->confirmedTrip();

        $this->departures->depart($tripId, $this->userId, $this->now);

        self::assertSame(Trip::STATUS_IN_TRANSIT, $this->trips->find($tripId)?->status);

        foreach ([$first, $second] as $palletId) {
            $pallet = $this->palletRow($palletId);
            self::assertSame('shipped', $pallet['status']);
            self::assertSame('0.000', $pallet['quantity'], 'un bancale spedito non ha più merce in magazzino');
            self::assertNull($pallet['location_id'], 'e non ha più una posizione');
        }

        $movements = $this->outboundMovements($tripId);
        self::assertCount(2, $movements);
        self::assertSame('-30.000', $movements[0]['quantity_delta'], 'l\'uscita è negativa');
        self::assertSame($locationId, (int) $movements[0]['from_location_id'], 'da dove è uscito il bancale');
        self::assertNull($movements[1]['from_location_id'], 'il secondo bancale non aveva una posizione');
        self::assertSame($this->userId, (int) $movements[0]['user_id']);
        self::assertSame('2026-11-10 10:00:00', $movements[0]['occurred_at']);
    }

    public function testADepartedTripCannotDepartAgainBeCancelledOrChanged(): void
    {
        $this->vehicle('ZZ001DP');
        $this->order($this->alfaId, '60');
        $this->pallet('30', '2026-11-01 08:00:00');
        $palletId = $this->pallet('30', '2026-11-02 08:00:00');
        $tripId = $this->confirmedTrip();
        $this->departures->depart($tripId, $this->userId, $this->now);

        try {
            $this->departures->depart($tripId, $this->userId, $this->now);
            self::fail('Un viaggio già partito non può partire una seconda volta.');
        } catch (TripChangeNotAllowedException) {
            self::assertCount(1, $this->outboundMovementsOf($palletId), 'nessun secondo movimento di uscita');
        }

        $this->expectException(TripNotCancellableException::class);
        $this->trips->cancel($tripId);
    }

    public function testADepartedTripCannotBeChangedByHand(): void
    {
        $this->vehicle('ZZ001DP');
        $this->order($this->alfaId, '60');
        $palletId = $this->pallet('30', '2026-11-01 08:00:00');
        $this->pallet('30', '2026-11-02 08:00:00');
        $tripId = $this->confirmedTrip();
        $this->departures->depart($tripId, $this->userId, $this->now);

        $this->expectException(TripChangeNotAllowedException::class);
        $this->trips->setPalletLock($tripId, $palletId, true, $this->userId);
    }

    public function testAnUnknownTripCannotDepart(): void
    {
        $this->expectException(TripChangeNotAllowedException::class);

        $this->departures->depart(999999999, $this->userId, $this->now);
    }

    public function testNothingIsWrittenWhenADocumentCannotBeBuilt(): void
    {
        $this->vehicle('ZZ001DP');
        $this->order($this->alfaId, '60');
        $first = $this->pallet('30', '2026-11-01 08:00:00');
        $this->pallet('30', '2026-11-02 08:00:00');
        $tripId = $this->confirmedTrip();

        // Una riga senza il collegamento alla riga della richiesta (senza prezzo): il documento uscirebbe incompleto.
        $this->database->pdo()->prepare('UPDATE trip_items SET order_line_id = NULL WHERE trip_id = ? AND pallet_id = ?')
            ->execute([$tripId, $first]);

        try {
            $this->departures->depart($tripId, $this->userId, $this->now);
            self::fail('La partenza doveva essere fermata.');
        } catch (LogicException) {
            // atteso
        }

        self::assertSame(Trip::STATUS_PLANNED, $this->trips->find($tripId)?->status, 'il viaggio è ancora pianificato');
        self::assertSame('reserved', $this->palletRow($first)['status'], 'i bancali non sono usciti');
        self::assertSame([], $this->outboundMovements($tripId), 'nessun movimento di uscita');
        self::assertSame([], $this->documents->forTrip($tripId, true), 'nessun documento');
    }

    // --- richieste ---

    public function testAnOrderFullyCoveredByTheDepartedTripBecomesShipped(): void
    {
        $this->vehicle('ZZ001DP');
        $orderId = $this->order($this->alfaId, '60');
        $this->pallet('30', '2026-11-01 08:00:00');
        $this->pallet('30', '2026-11-02 08:00:00');
        $tripId = $this->confirmedTrip();

        $this->departures->depart($tripId, $this->userId, $this->now);

        self::assertSame(Order::STATUS_SHIPPED, $this->orders->find($orderId)?->status);
    }

    public function testAnOrderWithMissingGoodsStaysPlannedAfterTheDeparture(): void
    {
        $this->vehicle('ZZ001DP');
        $orderId = $this->order($this->alfaId, '150');
        $this->pallet('30', '2026-11-01 08:00:00');
        $this->pallet('30', '2026-11-02 08:00:00');
        $tripId = $this->confirmedTrip();

        $this->departures->depart($tripId, $this->userId, $this->now);

        self::assertSame(
            Order::STATUS_PLANNED,
            $this->orders->find($orderId)?->status,
            'mancano 90 ct: la richiesta non è completa, e quando arriva la merce rientra nel piano'
        );
    }

    public function testAnOrderSplitOverTwoTripsIsShippedOnlyWhenBothHaveLeft(): void
    {
        // Due mezzi da due bancali l'uno: i tre bancali della richiesta si dividono su due viaggi.
        $this->vehicle('ZZ001DP', maxPallets: 2);
        $this->vehicle('ZZ002DP', maxPallets: 2);
        $orderId = $this->order($this->alfaId, '90');
        $this->pallet('30', '2026-11-01 08:00:00');
        $this->pallet('30', '2026-11-02 08:00:00');
        $this->pallet('30', '2026-11-03 08:00:00');

        $tripIds = $this->trips->confirm(self::DAY, $this->trips->plan(self::DAY)->fingerprint(), $this->userId, $this->now);
        self::assertCount(2, $tripIds);

        $this->departures->depart($tripIds[0], $this->userId, $this->now);
        self::assertSame(Order::STATUS_PLANNED, $this->orders->find($orderId)?->status, 'un bancale è ancora a terra');

        $this->departures->depart($tripIds[1], $this->userId, $this->now);
        self::assertSame(Order::STATUS_SHIPPED, $this->orders->find($orderId)?->status);
    }

    // --- documenti ---

    public function testDepartureIssuesTheLoadListOneDdtPerCustomerAndTheCompensation(): void
    {
        $this->vehicle('ZZ001DP');
        $this->order($this->alfaId, '30', '2.5');
        $this->order($this->betaId, '30', '4');
        $this->pallet('30', '2026-11-01 08:00:00');
        $this->pallet('30', '2026-11-02 08:00:00');
        $tripId = $this->confirmedTrip();

        $issued = $this->departures->depart($tripId, $this->userId, $this->now);

        self::assertSame(
            [TransportDocument::TYPE_LOAD_LIST, TransportDocument::TYPE_DDT, TransportDocument::TYPE_DDT, TransportDocument::TYPE_COMPENSATION],
            array_map(static fn (TransportDocument $d): string => $d->type, $issued)
        );

        [$load, $ddtAlfa, $ddtBeta, $compensation] = $issued;
        self::assertSame($this->alfaId, $ddtAlfa->customerId, 'i DDT seguono l\'ordine alfabetico dei clienti');
        self::assertSame($this->betaId, $ddtBeta->customerId);
        self::assertNull($load->customerId);
        self::assertSame($ddtAlfa->number + 1, $ddtBeta->number, 'numeri consecutivi, senza buchi');
        self::assertMatchesRegularExpression('/^CAR-2026-\d{6}$/', $load->code());
        self::assertMatchesRegularExpression('/^DDT-2026-\d{6}$/', $ddtAlfa->code());
        self::assertMatchesRegularExpression('/^COM-2026-\d{6}$/', $compensation->code());
        self::assertSame($tripId, $load->tripId);

        // Il DDT di Alfa ha i prezzi della SUA richiesta: 30 ct x 2,50 = 75,00. Quello di Beta: 30 x 4 = 120,00.
        $pdfAlfa = (string) $this->documents->pdf($ddtAlfa->id);
        self::assertStringStartsWith('%PDF-', $pdfAlfa);
        self::assertStringContainsString($ddtAlfa->code(), $pdfAlfa);
        self::assertStringContainsString('Zz Alfa Market', $pdfAlfa);
        self::assertStringContainsString('75,00', $pdfAlfa);
        self::assertStringNotContainsString('Zz Beta Bar', $pdfAlfa, 'un DDT contiene solo il suo cliente');

        $pdfBeta = (string) $this->documents->pdf($ddtBeta->id);
        self::assertStringContainsString('120,00', $pdfBeta);
        self::assertStringNotContainsString('Zz Alfa Market', $pdfBeta);

        // Il carico elenca tutti i clienti ma non mostra prezzi.
        $pdfLoad = (string) $this->documents->pdf($load->id);
        self::assertStringContainsString('Zz Alfa Market', $pdfLoad);
        self::assertStringContainsString('Zz Beta Bar', $pdfLoad);
        self::assertStringNotContainsString('75,00', $pdfLoad);

        // Il compenso è quello fotografato alla conferma: 150 del trasportatore.
        self::assertStringContainsString('150,00', (string) $this->documents->pdf($compensation->id));
    }

    public function testTheCompensationIsIssuedOnlyForAVehicleWithACarrier(): void
    {
        $this->vehicle('ZZ001DP', carrierId: 0);
        $this->order($this->alfaId, '30');
        $this->pallet('30', '2026-11-01 08:00:00');
        $tripId = $this->confirmedTrip();

        $issued = $this->departures->depart($tripId, $this->userId, $this->now);

        self::assertSame(
            [TransportDocument::TYPE_LOAD_LIST, TransportDocument::TYPE_DDT],
            array_map(static fn (TransportDocument $d): string => $d->type, $issued)
        );
    }

    public function testOnlyAnAdminSeesTheCompensationDocument(): void
    {
        $this->vehicle('ZZ001DP');
        $this->order($this->alfaId, '30');
        $this->pallet('30', '2026-11-01 08:00:00');
        $tripId = $this->confirmedTrip();
        $this->departures->depart($tripId, $this->userId, $this->now);

        $forEveryone = $this->documents->forTrip($tripId, false);
        $forAdmin = $this->documents->forTrip($tripId, true);

        self::assertSame(
            [TransportDocument::TYPE_LOAD_LIST, TransportDocument::TYPE_DDT],
            array_map(static fn (TransportDocument $d): string => $d->type, $forEveryone)
        );
        self::assertSame(
            [TransportDocument::TYPE_LOAD_LIST, TransportDocument::TYPE_DDT, TransportDocument::TYPE_COMPENSATION],
            array_map(static fn (TransportDocument $d): string => $d->type, $forAdmin)
        );
        self::assertTrue($forAdmin[2]->isAdminOnly());
        self::assertFalse($forAdmin[1]->isAdminOnly());
    }

    public function testAnIssuedDocumentNeverChanges(): void
    {
        $this->vehicle('ZZ001DP');
        $this->order($this->alfaId, '30');
        $this->pallet('30', '2026-11-01 08:00:00');
        $tripId = $this->confirmedTrip();
        [, $ddt] = $this->departures->depart($tripId, $this->userId, $this->now);
        $before = (string) $this->documents->pdf($ddt->id);

        // Dopo l'emissione il cliente cambia nome: il documento consegnato resta com'era.
        $this->database->pdo()->prepare('UPDATE customers SET name = ? WHERE id = ?')->execute(['Zz Nome Cambiato', $this->alfaId]);

        $after = (string) $this->documents->pdf($ddt->id);
        self::assertSame($before, $after);
        self::assertStringContainsString('Zz Alfa Market', $after);
        self::assertStringNotContainsString('Zz Nome Cambiato', $after);
    }

    public function testAnUnknownDocumentHasNoPdf(): void
    {
        self::assertNull($this->documents->find(999999999));
        self::assertNull($this->documents->pdf(999999999));
    }

    // --- aiuti ---

    private function location(string $code): int
    {
        $this->database->pdo()->prepare('INSERT INTO warehouse_locations (code) VALUES (?)')->execute([$code]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * Conferma il piano del giorno e restituisce l'id del (primo) viaggio creato.
     */
    private function confirmedTrip(): int
    {
        return $this->trips->confirm(self::DAY, $this->trips->plan(self::DAY)->fingerprint(), $this->userId, $this->now)[0];
    }

    private function order(int $customerId, string $quantity, string $unitPrice = '2'): int
    {
        return $this->orders->create([
            'customer_id' => (string) $customerId,
            'requested_date' => '2026-11-10',
            'delivery_date' => self::DAY,
            'notes' => '',
            'lines' => [['product_id' => (string) $this->waterId, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
        ], $this->userId, $this->now);
    }

    private function pallet(string $quantity, string $receivedAt, ?int $locationId = null): int
    {
        $this->database->pdo()->prepare(
            'INSERT INTO pallets (code, product_id, pallet_type_id, location_id, quantity, initial_quantity, received_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'ZZ-DP-' . ++$this->palletNumber, $this->waterId, $this->epal1Id, $locationId, $quantity, $quantity,
            $receivedAt, $this->userId,
        ]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * @param int|null $carrierId null = il trasportatore di prova, 0 = nessun trasportatore
     */
    private function vehicle(string $plate, int $maxPallets = 12, ?int $carrierId = null): int
    {
        $carrier = $carrierId ?? $this->carrierId;

        return $this->vehicles->create([
            'plate' => $plate, 'description' => 'Camion di prova', 'max_weight_kg' => '6500',
            'max_pallets' => (string) $maxPallets,
            'trailer_type_id' => (string) $this->trailerTypeId,
            'carrier_id' => $carrier === 0 ? '' : (string) $carrier,
        ]);
    }

    /**
     * @return array{status: string, quantity: string, location_id: int|null}
     */
    private function palletRow(int $palletId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT status, quantity, location_id FROM pallets WHERE id = ?');
        $statement->execute([$palletId]);
        $row = $statement->fetch();

        return [
            'status' => (string) $row['status'],
            'quantity' => (string) $row['quantity'],
            'location_id' => $row['location_id'] === null ? null : (int) $row['location_id'],
        ];
    }

    /**
     * @return list<array<string, mixed>> i movimenti di uscita di un viaggio, in ordine di bancale
     */
    private function outboundMovements(int $tripId): array
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT * FROM pallet_movements WHERE trip_id = ? AND type = 'outbound' ORDER BY pallet_id"
        );
        $statement->execute([$tripId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outboundMovementsOf(int $palletId): array
    {
        $statement = $this->database->pdo()->prepare("SELECT * FROM pallet_movements WHERE pallet_id = ? AND type = 'outbound'");
        $statement->execute([$palletId]);

        return $statement->fetchAll();
    }
}
