<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use LogiDock\Repository\CarrierRepository;
use LogiDock\Repository\CustomerRepository;
use LogiDock\Repository\PalletTypeRepository;
use LogiDock\Repository\RoundRepository;
use LogiDock\Repository\SupplierRepository;
use LogiDock\Repository\TrailerTypeRepository;
use LogiDock\Repository\VehicleRepository;
use LogiDock\Repository\WarehouseLocationRepository;
use LogiDock\Service\CarrierService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\PalletTypeService;
use LogiDock\Service\RoundService;
use LogiDock\Service\SupplierService;
use LogiDock\Service\TrailerTypeService;
use LogiDock\Service\VehicleService;
use LogiDock\Service\WarehouseLocationService;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * Le regole comuni a tutte le anagrafiche (creare, modificare, cercare, disattivare, duplicati)
 * si provano su alcune, e poi le regole specifiche di ognuna: giri dei clienti, targhe, capacità...
 * Test di integrazione: servizi veri, repository veri, database di test.
 */
final class MasterDataServicesTest extends IntegrationTestCase
{
    private SupplierService $suppliers;
    private RoundService $rounds;
    private CustomerService $customers;
    private CarrierService $carriers;
    private TrailerTypeService $trailerTypes;
    private VehicleService $vehicles;
    private WarehouseLocationService $locations;
    private PalletTypeService $palletTypes;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->database;
        $this->suppliers = new SupplierService(new SupplierRepository($db));
        $this->rounds = new RoundService(new RoundRepository($db));
        $this->customers = new CustomerService(new CustomerRepository($db), $this->rounds);
        $this->carriers = new CarrierService(new CarrierRepository($db));
        $this->trailerTypes = new TrailerTypeService(new TrailerTypeRepository($db));
        $this->vehicles = new VehicleService(new VehicleRepository($db), $this->trailerTypes, $this->carriers);
        $this->locations = new WarehouseLocationService(new WarehouseLocationRepository($db));
        $this->palletTypes = new PalletTypeService(new PalletTypeRepository($db));
    }

    // --- regole comuni (provate sui fornitori) ---

    public function testCreateReadUpdateSupplier(): void
    {
        $id = $this->suppliers->create(['name' => '  Zeta Forniture  ', 'contact_email' => 'info@zeta.example']);

        $supplier = $this->suppliers->find($id);
        self::assertNotNull($supplier);
        self::assertSame('Zeta Forniture', $supplier->name);
        self::assertSame('info@zeta.example', $supplier->contactEmail);
        self::assertNull($supplier->phone, 'Un campo facoltativo vuoto diventa NULL, non una stringa vuota.');
        self::assertTrue($supplier->isActive);

        $this->suppliers->update($id, ['name' => 'Zeta Forniture S.r.l.', 'phone' => '0123']);

        $updated = $this->suppliers->find($id);
        self::assertSame('Zeta Forniture S.r.l.', $updated?->name);
        self::assertSame('0123', $updated?->phone);
        self::assertNull($updated?->contactEmail, 'Il form manda sempre tutti i campi: quelli vuoti si svuotano.');
    }

    public function testSupplierValidation(): void
    {
        $errors = $this->errorsOf(fn () => $this->suppliers->create(['name' => '', 'contact_email' => 'no']));

        self::assertSame(['name', 'contact_email'], array_keys($errors));
    }

    public function testDeactivateAndActivateKeepTheRecord(): void
    {
        $id = $this->suppliers->create(['name' => 'Zeta']);

        $this->suppliers->deactivate($id);
        self::assertFalse($this->suppliers->find($id)?->isActive);

        $this->suppliers->activate($id);
        self::assertTrue($this->suppliers->find($id)?->isActive);
    }

    public function testActiveOnesLeavesOutTheDeactivated(): void
    {
        $keep = $this->suppliers->create(['name' => 'Zz Attivo']);
        $drop = $this->suppliers->create(['name' => 'Zz Disattivato']);
        $this->suppliers->deactivate($drop);

        $ids = array_map(static fn ($s): int => $s->id, $this->suppliers->activeOnes());

        self::assertContains($keep, $ids);
        self::assertNotContains($drop, $ids);
    }

    public function testSearchTreatsWildcardsAsPlainCharacters(): void
    {
        $this->suppliers->create(['name' => 'Zz Alfa']);
        $this->suppliers->create(['name' => 'Zz 100% Bio']);

        $names = static fn (array $found): array => array_values(array_filter(
            array_map(static fn ($s): string => $s->name, $found),
            static fn (string $n): bool => str_starts_with($n, 'Zz ')
        ));

        self::assertSame(['Zz 100% Bio'], $names($this->suppliers->search('%')));
        self::assertSame([], $names($this->suppliers->search('Zz_Alfa')));
        self::assertSame(['Zz Alfa'], $names($this->suppliers->search('zz al')));
    }

    public function testDuplicateIsDecidedByTheDatabaseAndShownOnTheField(): void
    {
        $this->rounds->create(['name' => 'Giro Nord']);

        $errors = $this->errorsOf(fn () => $this->rounds->create(['name' => 'Giro Nord']));

        self::assertSame(['name'], array_keys($errors));
        self::assertStringContainsString('giro', $errors['name']);
    }

    // --- clienti e giri ---

    public function testCustomerCanBeAssignedToAnActiveRound(): void
    {
        $roundId = $this->rounds->create(['name' => 'Giro Costa']);

        $id = $this->customers->create(['name' => 'Bar Sole', 'round_id' => (string) $roundId]);

        $customer = $this->customers->find($id);
        self::assertSame($roundId, $customer?->roundId);
        self::assertSame('Giro Costa', $customer?->roundName);
    }

    public function testCustomerWithoutRoundIsAllowed(): void
    {
        $id = $this->customers->create(['name' => 'Bar Luna', 'round_id' => '']);

        self::assertNull($this->customers->find($id)?->roundId);
    }

    public function testCustomerCannotGetANonExistingRound(): void
    {
        $errors = $this->errorsOf(fn () => $this->customers->create(['name' => 'X', 'round_id' => '2000000000']));

        self::assertSame(['round_id'], array_keys($errors));
    }

    public function testNewCustomerCannotGetADeactivatedRound(): void
    {
        $roundId = $this->rounds->create(['name' => 'Giro Chiuso']);
        $this->rounds->deactivate($roundId);

        $errors = $this->errorsOf(fn () => $this->customers->create(['name' => 'X', 'round_id' => (string) $roundId]));

        self::assertSame(['round_id'], array_keys($errors));
    }

    public function testCustomerKeepsItsRoundEvenIfTheRoundIsDeactivatedLater(): void
    {
        $roundId = $this->rounds->create(['name' => 'Giro Vecchio']);
        $id = $this->customers->create(['name' => 'Bar Stella', 'round_id' => (string) $roundId]);
        $this->rounds->deactivate($roundId);

        // Salvare il cliente senza toccare il giro non deve dare errore né perderlo.
        $this->customers->update($id, ['name' => 'Bar Stella 2', 'round_id' => (string) $roundId]);

        self::assertSame($roundId, $this->customers->find($id)?->roundId);
    }

    // --- trasportatori ---

    public function testCarrierFeeIsKeptAsDecimalString(): void
    {
        $id = $this->carriers->create(['name' => 'Rossi Trasporti', 'trip_fee' => '150,5']);

        self::assertSame('150.50', $this->carriers->find($id)?->tripFee);
    }

    public function testCarrierFeeCannotBeNegative(): void
    {
        $errors = $this->errorsOf(fn () => $this->carriers->create(['name' => 'X', 'trip_fee' => '-1']));

        self::assertSame(['trip_fee'], array_keys($errors));
    }

    // --- rimorchi e veicoli ---

    public function testTrailerTypeNeedsRealMeasures(): void
    {
        $errors = $this->errorsOf(fn () => $this->trailerTypes->create([
            'name' => 'Strano',
            'length_mm' => '50',
            'width_mm' => 'larghissimo',
            'inner_height_mm' => '',
            'max_weight_kg' => '0',
        ]));

        self::assertSame(['length_mm', 'width_mm', 'inner_height_mm', 'max_weight_kg'], array_keys($errors));
    }

    public function testVehicleLinksTrailerTypeAndCarrier(): void
    {
        $trailerId = $this->trailerTypes->create([
            'name' => 'Semirimorchio 13,6 m',
            'length_mm' => '13600',
            'width_mm' => '2450',
            'inner_height_mm' => '2700',
            'max_weight_kg' => '24000',
        ]);
        $carrierId = $this->carriers->create(['name' => 'Bianchi Trasporti', 'trip_fee' => '200']);

        $id = $this->vehicles->create([
            'plate' => ' zz999aa ',
            'description' => 'Motrice con rimorchio',
            'max_weight_kg' => '24000',
            'max_pallets' => '33',
            'trailer_type_id' => (string) $trailerId,
            'carrier_id' => (string) $carrierId,
        ]);

        $vehicle = $this->vehicles->find($id);
        self::assertSame('ZZ999AA', $vehicle?->plate, 'La targa si salva in maiuscolo e senza spazi.');
        self::assertSame('Semirimorchio 13,6 m', $vehicle?->trailerTypeName);
        self::assertSame('Bianchi Trasporti', $vehicle?->carrierName);
    }

    public function testDuplicatePlateIsRejectedEvenInDifferentCase(): void
    {
        $input = ['plate' => 'ZZ111AA', 'description' => 'Camion', 'max_weight_kg' => '1000', 'max_pallets' => '10'];
        $this->vehicles->create($input);

        $errors = $this->errorsOf(fn () => $this->vehicles->create(['plate' => 'zz111aa'] + $input));

        self::assertSame(['plate'], array_keys($errors));
    }

    public function testVehicleSearchFindsByTrailerTypeName(): void
    {
        $trailerId = $this->trailerTypes->create([
            'name' => 'Zzcentinato',
            'length_mm' => '7000',
            'width_mm' => '2400',
            'inner_height_mm' => '2500',
            'max_weight_kg' => '5000',
        ]);
        $id = $this->vehicles->create([
            'plate' => 'ZZ222AA',
            'description' => 'Furgone',
            'max_weight_kg' => '5000',
            'max_pallets' => '8',
            'trailer_type_id' => (string) $trailerId,
        ]);

        $ids = array_map(static fn ($v): int => $v->id, $this->vehicles->search('zzcentinato'));

        self::assertSame([$id], $ids);
    }

    // --- posizioni di magazzino ---

    public function testLocationCapacityIsOptionalButNeverZero(): void
    {
        $withDefault = $this->locations->create(['code' => 'zz-a1', 'zone' => 'Z']);
        $withOwn = $this->locations->create(['code' => 'ZZ-A2', 'capacity' => '120']);

        self::assertSame('ZZ-A1', $this->locations->find($withDefault)?->code);
        self::assertNull($this->locations->find($withDefault)?->capacity, 'Vuota = vale la capacità predefinita.');
        self::assertSame(120, $this->locations->find($withOwn)?->capacity);

        $errors = $this->errorsOf(fn () => $this->locations->create(['code' => 'ZZ-A3', 'capacity' => '0']));
        self::assertSame(['capacity'], array_keys($errors));
    }

    // --- tipi di bancale ---

    public function testTheTwoEpalTypesAreThereAfterMigrations(): void
    {
        $found = [];
        foreach ($this->palletTypes->search() as $type) {
            $found[$type->code] = [$type->lengthMm, $type->widthMm, $type->maxHeightMm];
        }

        self::assertSame([1200, 1000, null], $found['EPAL3'] ?? null);
        self::assertSame([1200, 800, null], $found['EPAL1'] ?? null);
    }

    public function testPalletTypeCodeIsUniqueAndUppercased(): void
    {
        $id = $this->palletTypes->create([
            'code' => 'zz-big',
            'name' => 'Grande',
            'length_mm' => '1200',
            'width_mm' => '1200',
            'max_height_mm' => '900',
        ]);

        self::assertSame('ZZ-BIG', $this->palletTypes->find($id)?->code);
        self::assertSame(900, $this->palletTypes->find($id)?->maxHeightMm);

        $errors = $this->errorsOf(fn () => $this->palletTypes->create([
            'code' => 'ZZ-BIG',
            'name' => 'Doppione',
            'length_mm' => '1200',
            'width_mm' => '1200',
        ]));
        self::assertSame(['code'], array_keys($errors));
    }
}
