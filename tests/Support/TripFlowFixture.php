<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

use DateTimeImmutable;
use LogiDock\Core\Database;
use LogiDock\Core\Thousandths;
use LogiDock\Enum\UserRole;
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
use LogiDock\Pdf\DocumentRenderer;
use LogiDock\Service\CarrierService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\DocumentService;
use LogiDock\Service\OrderService;
use LogiDock\Service\PalletTypeService;
use LogiDock\Service\ProductService;
use LogiDock\Service\RoundService;
use LogiDock\Service\SettingService;
use LogiDock\Service\TrailerTypeService;
use LogiDock\Service\TripDepartureService;
use LogiDock\Service\TripService;
use LogiDock\Service\VehicleService;

/**
 * Prepara viaggi veri (pianificati o partiti) per i test di integrazione, con servizi e repository
 * veri. Va usato dentro la transazione annullata di IntegrationTestCase.
 *
 * Ogni chiamata crea dati propri (giro, cliente, prodotto, mezzo, bancali, richiesta) e un giorno di
 * consegna diverso e lontano (da marzo 2028), così si può chiamare più volte nello stesso test
 * senza che i piani si mescolino. Alla creazione disattiva i mezzi già presenti nel database.
 */
final class TripFlowFixture
{
    public readonly TripService $trips;
    public readonly TripDepartureService $departures;
    public readonly OrderService $orders;
    public readonly DocumentService $documents;
    public readonly int $userId;
    public readonly DateTimeImmutable $now;

    private readonly CustomerService $customers;
    private readonly ProductService $products;
    private readonly RoundService $rounds;
    private readonly VehicleService $vehicles;
    private readonly int $trailerTypeId;
    private readonly int $carrierId;
    private readonly int $epal1Id;
    private int $calls = 0;
    private int $palletNumber = 0;
    private readonly string $tag;

    public function __construct(private readonly Database $db)
    {
        $this->now = new DateTimeImmutable('2026-11-10 10:00:00');
        $this->tag = strtoupper(substr(uniqid(), -6));

        $this->rounds = new RoundService(new RoundRepository($db));
        $this->customers = new CustomerService(new CustomerRepository($db), $this->rounds);
        $this->products = new ProductService(new ProductRepository($db));
        $orderRepository = new OrderRepository($db);
        $sequences = new NumberSequenceRepository($db);
        $tripRepository = new TripRepository($db);
        $pallets = new PalletRepository($db);
        $settings = new SettingService(new SettingRepository($db), $db);

        $this->orders = new OrderService($db, $orderRepository, $sequences, $this->customers, $this->products);
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
            'flow-' . $this->tag . '@example.com',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            UserRole::Operatore
        );

        // I mezzi rimasti nel database non devono entrare nei piani di questi test.
        $db->pdo()->exec('UPDATE vehicles SET is_active = 0');

        $trailerTypes = new TrailerTypeService(new TrailerTypeRepository($db));
        $carriers = new CarrierService(new CarrierRepository($db));
        $this->vehicles = new VehicleService(new VehicleRepository($db), $trailerTypes, $carriers);
        $this->trailerTypeId = $trailerTypes->create([
            'name' => 'Zz Rimorchio flusso ' . $this->tag, 'length_mm' => '7200', 'width_mm' => '2450',
            'inner_height_mm' => '2600', 'max_weight_kg' => '6500',
        ]);
        $this->carrierId = $carriers->create(['name' => 'Zz Trasporti flusso ' . $this->tag, 'trip_fee' => '150']);
        $this->epal1Id = (int) $db->pdo()->query("SELECT id FROM pallet_types WHERE code = 'EPAL1'")->fetchColumn();
    }

    /**
     * Un viaggio confermato e ancora pianificato, con trasportatore e compenso (150).
     */
    public function plannedTrip(): int
    {
        return $this->build('2')['tripId'];
    }

    /**
     * Un viaggio come plannedTrip(), poi partito (documenti emessi).
     *
     * @param string $unitPrice prezzo di vendita per unità della richiesta
     * @param string $quantity quantità richiesta (due bancali da metà quantità l'uno)
     *
     * @return array{tripId: int, orderId: int}
     */
    public function departedTrip(string $unitPrice = '2', string $quantity = '60'): array
    {
        $built = $this->build($unitPrice, $quantity);
        $this->departures->depart($built['tripId'], $this->userId, $this->now);

        return $built;
    }

    /**
     * Un viaggio partito che copre solo metà della richiesta (c'è un solo bancale): la richiesta
     * resta "pianificata", non diventa "spedita".
     *
     * @return array{tripId: int, orderId: int}
     */
    public function departedPartialTrip(): array
    {
        $built = $this->build('2', '60', true);
        $this->departures->depart($built['tripId'], $this->userId, $this->now);

        return $built;
    }

    /**
     * @return array{tripId: int, orderId: int}
     */
    private function build(string $unitPrice, string $quantity = '60', bool $onlyFirstPallet = false): array
    {
        $n = ++$this->calls;
        $suffix = $this->tag . $n;
        $day = (new DateTimeImmutable('2028-03-15'))->modify('+' . ($n - 1) . ' days')->format('Y-m-d');

        $roundId = $this->rounds->create(['name' => 'Zz Giro flusso ' . $suffix]);
        $customerId = $this->customers->create([
            'name' => 'Zz Cliente flusso ' . $suffix, 'round_id' => (string) $roundId,
            'tax_code' => 'IT00000000001', 'address' => 'Via Flusso 1',
        ]);
        $productId = $this->products->create([
            'code' => 'ZZ-TF-' . $suffix, 'description' => 'Merce di prova', 'unit_of_measure' => 'ct', 'unit_weight_kg' => '10',
        ]);
        $this->vehicles->create([
            'plate' => 'ZF' . $suffix, 'description' => 'Camion di prova', 'max_weight_kg' => '6500',
            'max_pallets' => '12', 'trailer_type_id' => (string) $this->trailerTypeId,
            'carrier_id' => (string) $this->carrierId,
        ]);

        $orderId = $this->orders->create([
            'customer_id' => (string) $customerId,
            'requested_date' => '2026-11-10',
            'delivery_date' => $day,
            'notes' => '',
            'lines' => [['product_id' => (string) $productId, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
        ], $this->userId, $this->now);

        // Due bancali interi che insieme coprono la quantità (la metà, poi il resto: i conti
        // sono fatti in millesimi interi con Thousandths, senza float).
        $total = Thousandths::fromDecimal($quantity);
        $half = Thousandths::toDecimal(intdiv($total, 2));
        $this->pallet($productId, $half, '2026-11-01 08:00:00');
        if (!$onlyFirstPallet) {
            $this->pallet($productId, Thousandths::toDecimal($total - intdiv($total, 2)), '2026-11-02 08:00:00');
        }

        $tripId = $this->trips->confirm($day, $this->trips->plan($day)->fingerprint(), $this->userId, $this->now)[0];

        return ['tripId' => $tripId, 'orderId' => $orderId];
    }

    private function pallet(int $productId, string $quantity, string $receivedAt): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO pallets (code, product_id, pallet_type_id, location_id, quantity, initial_quantity, received_at, created_by)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?)'
        )->execute([
            'ZZ-TF-' . $this->tag . '-' . ++$this->palletNumber, $productId, $this->epal1Id, $quantity, $quantity,
            $receivedAt, $this->userId,
        ]);
    }
}
