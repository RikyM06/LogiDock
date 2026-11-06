<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use LogiDock\Enum\UserRole;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\PalletMovementRepository;
use LogiDock\Repository\PalletRepository;
use LogiDock\Repository\PalletTypeRepository;
use LogiDock\Repository\ProductRepository;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\SupplierDocumentRepository;
use LogiDock\Repository\SupplierRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Repository\WarehouseLocationRepository;
use LogiDock\Service\InboundService;
use LogiDock\Service\PalletTypeService;
use LogiDock\Service\ProductService;
use LogiDock\Service\SettingService;
use LogiDock\Service\SupplierService;
use LogiDock\Service\WarehouseLocationService;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * Test di integrazione dell'arrivo di merce: servizi e repository veri sul database di test.
 * La data "di oggi" è fissa (6 novembre 2026), così i controlli sulle date non dipendono dal giorno
 * in cui si lanciano i test.
 */
final class InboundServiceTest extends IntegrationTestCase
{
    private InboundService $inbound;
    private SupplierService $suppliers;
    private ProductService $products;
    private WarehouseLocationService $locations;
    private PalletTypeService $palletTypes;

    private DateTimeImmutable $now;
    private int $userId;
    private int $supplierId;
    private int $productId;
    private int $palletTypeId;
    private int $smallLocationId;
    private int $bigLocationId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->database;
        $this->now = new DateTimeImmutable('2026-11-06 10:00:00');

        $this->suppliers = new SupplierService(new SupplierRepository($db));
        $this->products = new ProductService(new ProductRepository($db));
        $this->palletTypes = new PalletTypeService(new PalletTypeRepository($db));
        $this->locations = new WarehouseLocationService(new WarehouseLocationRepository($db));
        $settings = new SettingService(new SettingRepository($db), $db);

        $this->inbound = new InboundService(
            $db,
            new SupplierDocumentRepository($db),
            new PalletRepository($db),
            new PalletMovementRepository($db),
            new NumberSequenceRepository($db),
            $this->suppliers,
            $this->products,
            $this->palletTypes,
            $this->locations,
            $settings
        );

        $this->userId = (new UserRepository($db))->insert(
            'inbound-test@example.com',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            UserRole::Operatore
        );
        $this->supplierId = $this->suppliers->create(['name' => 'Zz Fornitore']);
        // Peso unitario 10 kg: 5 pezzi pesano 50 kg, il che rende facili i conti sul peso segnato.
        $this->productId = $this->products->create([
            'code' => 'ZZ-IN1',
            'description' => 'Prodotto di prova',
            'unit_of_measure' => 'ct',
            'unit_weight_kg' => '10',
        ]);
        $this->palletTypeId = $this->firstPalletTypeId();
        // Una posizione piccola (2 bancali) e una con la capacità predefinita.
        $this->smallLocationId = $this->locations->create(['code' => 'ZZ-PICCOLA', 'capacity' => '2']);
        $this->bigLocationId = $this->locations->create(['code' => 'ZZ-GRANDE']);
    }

    // --- registrazione ---

    public function testReceiveStoresDocumentPalletsAndMovements(): void
    {
        $documentId = $this->inbound->receive($this->input([
            $this->line(['quantity' => '5', 'unit_price' => '2,5', 'recorded_weight_kg' => '50', 'lot_number' => 'L1']),
            $this->line(['quantity' => '3', 'unit_price' => '4', 'recorded_weight_kg' => '31,5', 'expiry_date' => '2027-01-31']),
        ]), $this->userId, $this->now);

        $document = $this->inbound->find($documentId);
        self::assertNotNull($document);
        self::assertSame('DDT-1', $document->number);
        self::assertSame('Zz Fornitore', $document->supplierName);
        self::assertSame('2026-11-05', $document->documentDate);
        self::assertSame('2026-11-06', $document->receivedDate);
        self::assertSame(2, $document->palletCount);
        self::assertSame('24.50', $document->totalValue, '5 x 2,50 + 3 x 4,00');
        self::assertSame('81.500', $document->totalRecordedWeightKg);

        $pallets = $this->inbound->palletsOf($documentId);
        self::assertCount(2, $pallets);
        self::assertSame('5.000', $pallets[0]->quantity);
        self::assertSame('2.5000', $pallets[0]->unitPrice);
        self::assertSame('L1', $pallets[0]->lotNumber);
        self::assertSame('ZZ-PICCOLA', $pallets[0]->locationCode);
        self::assertSame('2027-01-31', $pallets[1]->expiryDate);

        // Ogni bancale ha il suo movimento di ingresso, con la stessa quantità e la posizione di arrivo.
        $movements = $this->database->pdo()->query(
            "SELECT m.type, m.quantity_delta, m.to_location_id, m.user_id, m.occurred_at
             FROM pallet_movements m JOIN pallets p ON p.id = m.pallet_id
             WHERE p.supplier_document_id = " . $documentId . ' ORDER BY m.id'
        )->fetchAll();
        self::assertCount(2, $movements);
        self::assertSame('inbound', $movements[0]['type']);
        self::assertSame('5.000', $movements[0]['quantity_delta']);
        self::assertSame($this->smallLocationId, (int) $movements[0]['to_location_id']);
        self::assertSame($this->userId, (int) $movements[0]['user_id']);
        self::assertSame('2026-11-06 00:00:00', $movements[0]['occurred_at']);
    }

    public function testPalletCodesAreConsecutiveAndUseTheYear(): void
    {
        $first = $this->inbound->receive($this->input([$this->bigLine(), $this->bigLine()]), $this->userId, $this->now);
        $second = $this->inbound->receive($this->input([$this->bigLine()], ['number' => 'DDT-2']), $this->userId, $this->now);

        $codes = array_map(
            static fn ($pallet): string => $pallet->code,
            [...$this->inbound->palletsOf($first), ...$this->inbound->palletsOf($second)]
        );

        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^PAL-2026-\d{6}$/', $code);
        }
        $numbers = array_map(static fn (string $code): int => (int) substr($code, -6), $codes);
        self::assertSame([$numbers[0], $numbers[0] + 1, $numbers[0] + 2], $numbers);
    }

    public function testSequenceGivesNextNumberPerTypeAndYear(): void
    {
        $sequences = new NumberSequenceRepository($this->database);

        $a = $sequences->next('prova', 2090);
        $b = $sequences->next('prova', 2090);
        $otherYear = $sequences->next('prova', 2091);

        self::assertSame(1, $a);
        self::assertSame(2, $b);
        self::assertSame(1, $otherYear);
    }

    public function testBlankLinesAreIgnored(): void
    {
        $documentId = $this->inbound->receive(
            $this->input([$this->emptyLine(), $this->line(), $this->emptyLine()]),
            $this->userId,
            $this->now
        );

        self::assertSame(1, $this->inbound->find($documentId)?->palletCount);
    }

    // --- validazione ---

    public function testAtLeastOneLineIsRequired(): void
    {
        $errors = $this->errorsOf(
            fn () => $this->inbound->receive($this->input([$this->emptyLine()]), $this->userId, $this->now)
        );

        self::assertSame(['lines'], array_keys($errors));
    }

    public function testErrorsPointAtTheRightLineAndField(): void
    {
        $errors = $this->errorsOf(fn () => $this->inbound->receive($this->input([
            $this->line(),
            $this->line(['quantity' => '0', 'unit_price' => '-1', 'recorded_weight_kg' => '']),
        ]), $this->userId, $this->now));

        self::assertSame(
            ['lines.1.quantity', 'lines.1.unit_price', 'lines.1.recorded_weight_kg'],
            array_keys($errors)
        );
    }

    public function testHeaderIsValidated(): void
    {
        $errors = $this->errorsOf(fn () => $this->inbound->receive(
            ['lines' => [$this->line()]],
            $this->userId,
            $this->now
        ));

        self::assertSame(['supplier_id', 'number', 'document_date', 'received_date'], array_keys($errors));
    }

    public function testDatesAreChecked(): void
    {
        $future = $this->errorsOf(fn () => $this->inbound->receive(
            $this->input([$this->line()], ['received_date' => '2026-11-07', 'document_date' => '2026-11-07']),
            $this->userId,
            $this->now
        ));
        self::assertSame(['received_date'], array_keys($future));

        $order = $this->errorsOf(fn () => $this->inbound->receive(
            $this->input([$this->line()], ['document_date' => '2026-11-06', 'received_date' => '2026-11-05']),
            $this->userId,
            $this->now
        ));
        self::assertSame(['document_date'], array_keys($order));

        $impossible = $this->errorsOf(fn () => $this->inbound->receive(
            $this->input([$this->line()], ['document_date' => '2026-02-31']),
            $this->userId,
            $this->now
        ));
        self::assertSame(['document_date'], array_keys($impossible));
    }

    public function testExpiryBeforeArrivalIsRejected(): void
    {
        $errors = $this->errorsOf(fn () => $this->inbound->receive(
            $this->input([$this->line(['expiry_date' => '2026-11-01'])]),
            $this->userId,
            $this->now
        ));

        self::assertSame(['lines.0.expiry_date'], array_keys($errors));
    }

    public function testDeactivatedSupplierProductAndLocationAreRejected(): void
    {
        $this->suppliers->deactivate($this->supplierId);
        $this->products->deactivate($this->productId);
        $this->locations->deactivate($this->bigLocationId);

        $errors = $this->errorsOf(fn () => $this->inbound->receive(
            $this->input([$this->line(['location_id' => (string) $this->bigLocationId])]),
            $this->userId,
            $this->now
        ));

        self::assertSame(['supplier_id', 'lines.0.product_id', 'lines.0.location_id'], array_keys($errors));
    }

    public function testSameDocumentCannotBeRegisteredTwice(): void
    {
        $this->inbound->receive($this->input([$this->line()]), $this->userId, $this->now);

        $errors = $this->errorsOf(
            fn () => $this->inbound->receive($this->input([$this->line()]), $this->userId, $this->now)
        );

        self::assertSame(['number'], array_keys($errors));
    }

    public function testDuplicateDocumentIsReportedEvenIfTheLocationIsFull(): void
    {
        // Riempie la posizione piccola (2 bancali) con il documento DDT-1...
        $this->inbound->receive($this->input([$this->line(), $this->line()]), $this->userId, $this->now);

        // ...e lo registra di nuovo: l'errore giusto è "già registrato", non "posizione piena".
        $errors = $this->errorsOf(
            fn () => $this->inbound->receive($this->input([$this->line()]), $this->userId, $this->now)
        );

        self::assertSame(['number'], array_keys($errors));
    }

    // --- tutto o niente ---

    public function testNothingIsSavedIfOneLineIsInvalid(): void
    {
        $before = $this->rows('supplier_documents') . '/' . $this->rows('pallets') . '/' . $this->rows('pallet_movements');

        $this->errorsOf(fn () => $this->inbound->receive(
            $this->input([$this->line(), $this->line(['quantity' => 'abc'])]),
            $this->userId,
            $this->now
        ));

        $after = $this->rows('supplier_documents') . '/' . $this->rows('pallets') . '/' . $this->rows('pallet_movements');
        self::assertSame($before, $after);
    }

    // --- capacità delle posizioni ---

    public function testLocationCapacityIsRespected(): void
    {
        $tooMany = $this->errorsOf(fn () => $this->inbound->receive(
            $this->input([$this->line(), $this->line(), $this->line()]),
            $this->userId,
            $this->now
        ));
        self::assertSame(['lines.0.location_id'], array_keys($tooMany));
        self::assertStringContainsString('ZZ-PICCOLA', $tooMany['lines.0.location_id']);
        self::assertSame(0, $this->rows('supplier_documents', 'number', 'DDT-1'), 'Nessun arrivo a metà.');

        // Due bancali ci stanno: la posizione è piena.
        $this->inbound->receive($this->input([$this->line(), $this->line()]), $this->userId, $this->now);

        // E un terzo, in un altro arrivo, non ci entra più.
        $full = $this->errorsOf(fn () => $this->inbound->receive(
            $this->input([$this->line()], ['number' => 'DDT-2']),
            $this->userId,
            $this->now
        ));
        self::assertSame(['lines.0.location_id'], array_keys($full));
    }

    public function testLocationWithoutOwnCapacityUsesTheDefaultSetting(): void
    {
        $lines = array_fill(0, 5, $this->line(['location_id' => (string) $this->bigLocationId]));

        $documentId = $this->inbound->receive($this->input($lines), $this->userId, $this->now);

        self::assertSame(5, $this->inbound->find($documentId)?->palletCount);
    }

    // --- peso segnato e calcolato ---

    public function testWeightMismatchBeyondToleranceIsFlagged(): void
    {
        // 5 pezzi x 10 kg = 50 kg calcolati; tolleranza di partenza 5%.
        $documentId = $this->inbound->receive($this->input([
            $this->bigLine(['quantity' => '5', 'recorded_weight_kg' => '50']),      // identico
            $this->bigLine(['quantity' => '5', 'recorded_weight_kg' => '52,4']),    // +4,8%: dentro
            $this->bigLine(['quantity' => '5', 'recorded_weight_kg' => '53']),      // +6%: fuori
            $this->bigLine(['quantity' => '5', 'recorded_weight_kg' => '46']),      // -8%: fuori
        ]), $this->userId, $this->now);

        $flags = array_map(static fn ($pallet): bool => $pallet->weightMismatch, $this->inbound->palletsOf($documentId));

        self::assertSame([false, false, true, true], $flags);
        self::assertSame('50.000', $this->inbound->palletsOf($documentId)[0]->computedWeightKg);
    }

    // --- aiuti ---

    /**
     * @param list<array<string, string>> $lines
     * @param array<string, string>       $overrides
     * @return array<string, mixed>
     */
    private function input(array $lines, array $overrides = []): array
    {
        return $overrides + [
            'supplier_id' => (string) $this->supplierId,
            'number' => 'DDT-1',
            'document_date' => '2026-11-05',
            'received_date' => '2026-11-06',
            'notes' => '',
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function line(array $overrides = []): array
    {
        return $overrides + [
            'product_id' => (string) $this->productId,
            'pallet_type_id' => (string) $this->palletTypeId,
            'quantity' => '5',
            'unit_price' => '1,5',
            'recorded_weight_kg' => '50',
            'lot_number' => '',
            'expiry_date' => '',
            'location_id' => (string) $this->smallLocationId,
        ];
    }

    /**
     * Come line(), ma nella posizione con la capacità predefinita (300), che non si riempie mai nei test.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function bigLine(array $overrides = []): array
    {
        return $this->line(['location_id' => (string) $this->bigLocationId] + $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function emptyLine(): array
    {
        return array_fill_keys(
            ['product_id', 'pallet_type_id', 'quantity', 'unit_price', 'recorded_weight_kg', 'lot_number', 'expiry_date', 'location_id'],
            ''
        );
    }

    private function firstPalletTypeId(): int
    {
        $types = $this->palletTypes->activeOnes();
        self::assertNotSame([], $types, 'Servono i tipi di bancale delle migrazioni (EPAL 1 e EPAL 3).');

        return $types[0]->id;
    }

    private function rows(string $table, ?string $column = null, ?string $value = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $table . ($column !== null ? ' WHERE ' . $column . ' = ?' : '');
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($column !== null ? [$value] : []);

        return (int) $statement->fetchColumn();
    }
}
