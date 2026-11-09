<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use LogiDock\Enum\UserRole;
use LogiDock\Model\StockPallet;
use LogiDock\Model\StockProductSummary;
use LogiDock\Repository\ProductRepository;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\StockRepository;
use LogiDock\Repository\SupplierRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Repository\WarehouseLocationRepository;
use LogiDock\Service\ProductService;
use LogiDock\Service\SettingService;
use LogiDock\Service\StockService;
use LogiDock\Service\SupplierService;
use LogiDock\Service\WarehouseLocationService;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * Giacenze e avviso sul database di test. "Oggi" è fisso (lunedì 9 novembre 2026).
 * Il database di test può contenere bancali lasciati dalle prove HTTP (pallet_movements non si
 * cancella), quindi i test guardano solo i propri prodotti e posizioni, o confrontano i conteggi
 * prima e dopo.
 */
final class StockServiceTest extends IntegrationTestCase
{
    private StockService $stock;
    private SettingService $settings;
    private DateTimeImmutable $today;

    private int $userId;
    private int $supplierId;
    private int $productA;
    private int $productB;
    private int $locationA;
    private int $locationB;
    private int $palletNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->database;
        $this->today = new DateTimeImmutable('2026-11-09');
        $this->settings = new SettingService(new SettingRepository($db), $db);
        $this->stock = new StockService(new StockRepository($db), $this->settings);

        $this->userId = (new UserRepository($db))->insert(
            'stock-test@example.com',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            UserRole::Admin
        );
        $this->setThreshold('2');

        $this->supplierId = (new SupplierService(new SupplierRepository($db)))->create([
            'name' => 'Zz Fornitore Giacenze', 'tax_code' => 'IT00000000997', 'address' => 'Via Prova 3, Milano',
            'contact_email' => 'giacenze@fornitore.test', 'phone' => '02 0000997',
        ]);
        $products = new ProductService(new ProductRepository($db));
        $this->productA = $products->create([
            'code' => 'ZZ-ST-A', 'description' => 'Acqua di prova', 'unit_of_measure' => 'ct', 'unit_weight_kg' => '9',
        ]);
        $this->productB = $products->create([
            'code' => 'ZZ-ST-B', 'description' => 'Pasta di prova', 'unit_of_measure' => 'kg', 'unit_weight_kg' => '1',
        ]);
        $locations = new WarehouseLocationService(new WarehouseLocationRepository($db));
        $this->locationA = $locations->create(['code' => 'ZZ-ST-L1']);
        $this->locationB = $locations->create(['code' => 'ZZ-ST-L2']);
    }

    // --- riepilogo per prodotto ---

    public function testSummaryCountsOnlyPalletsStillInTheWarehouse(): void
    {
        $this->pallet($this->productA, $this->locationA, '12.500', '2026-11-04 09:00:00');
        $this->pallet($this->productA, $this->locationB, '3.250', '2026-11-08 09:00:00', 'reserved');
        $this->pallet($this->productA, $this->locationA, '100', '2026-10-30 09:00:00', 'shipped');
        $this->pallet($this->productA, $this->locationA, '50', '2026-10-30 09:00:00', 'discarded');
        $this->pallet($this->productB, $this->locationA, '20', '2026-10-30 09:00:00', 'shipped');

        $summary = $this->summaryOf($this->productA);

        self::assertNotNull($summary);
        self::assertSame(2, $summary->palletCount, 'in_stock + reserved; spediti e scartati no');
        self::assertSame('15.750', $summary->totalQuantity, 'somma in DECIMAL, senza float');
        self::assertSame('2026-11-04 09:00:00', $summary->oldestReceivedAt);
        self::assertSame(1, $summary->agingCount, 'solo quello di 5 giorni supera la soglia di 2');
        self::assertSame('ct', $summary->unitOfMeasure);

        self::assertNull($this->summaryOf($this->productB), 'un prodotto senza bancali in magazzino non compare');
    }

    // --- avviso ---

    public function testAgingCountFollowsTheThresholdSetting(): void
    {
        // Conteggi di partenza per tutte e due le soglie: i bancali già nel database cambiano con la soglia.
        $this->setThreshold('4');
        $beforeWith4 = $this->stock->agingCount($this->stock->agingRule($this->today));
        $this->setThreshold('2');
        $beforeWith2 = $this->stock->agingCount($this->stock->agingRule($this->today));

        $this->pallet($this->productA, $this->locationA, '1', '2026-11-04 09:00:00'); // 5 giorni
        $this->pallet($this->productA, $this->locationA, '1', '2026-11-06 09:00:00'); // 3 giorni
        $this->pallet($this->productA, $this->locationA, '1', '2026-11-08 09:00:00'); // 1 giorno
        $this->pallet($this->productA, $this->locationA, '1', '2026-11-01 09:00:00', 'shipped');

        self::assertSame($beforeWith2 + 2, $this->stock->agingCount($this->stock->agingRule($this->today)));

        $this->setThreshold('4');
        $rule = $this->stock->agingRule($this->today);
        self::assertSame(4, $rule->thresholdDays, 'la soglia arriva dalle impostazioni');
        self::assertSame($beforeWith4 + 1, $this->stock->agingCount($rule), 'con soglia 4 resta solo quello di 5 giorni');
    }

    public function testThePalletAtTheThresholdIsNotAgingButTheDayAfterItIs(): void
    {
        // 2 giorni (= soglia), sia a mezzanotte sia a fine giornata: non ancora fermi.
        $this->pallet($this->productA, $this->locationA, '1', '2026-11-07 00:00:00');
        $this->pallet($this->productA, $this->locationA, '1', '2026-11-07 23:59:59');
        // 3 giorni, anche se arrivato all'ultimo secondo: fermo.
        $overThreshold = $this->pallet($this->productA, $this->locationA, '1', '2026-11-06 23:59:59');

        $aging = $this->stock->pallets($this->stock->agingRule($this->today), $this->productA, null, true);

        self::assertSame([$overThreshold], $this->ids($aging));
    }

    public function testOldestAgingListsTheOldestFirstUpToTheLimit(): void
    {
        // Date molto vecchie: così sono i più vecchi di tutto il database di test.
        $second = $this->pallet($this->productA, $this->locationA, '1', '2001-01-02 09:00:00');
        $first = $this->pallet($this->productB, $this->locationB, '1', '2001-01-01 09:00:00');
        $this->pallet($this->productA, $this->locationA, '1', '2001-01-03 09:00:00');

        $oldest = $this->stock->oldestAging($this->stock->agingRule($this->today), 2);

        self::assertSame([$first, $second], $this->ids($oldest));
    }

    // --- lista dei bancali ---

    public function testPalletsAreFilteredAndSortedFromTheOldest(): void
    {
        $documentId = $this->document('DDT-ST-1');
        $recent = $this->pallet($this->productA, $this->locationA, '5', '2026-11-08 09:00:00', 'in_stock', $documentId);
        $old = $this->pallet($this->productA, $this->locationB, '7.5', '2026-11-01 09:00:00', 'reserved');
        $otherProduct = $this->pallet($this->productB, $this->locationA, '9', '2026-11-02 09:00:00');
        $this->pallet($this->productA, $this->locationA, '4', '2026-11-03 09:00:00', 'shipped');

        $rule = $this->stock->agingRule($this->today);

        $byProduct = $this->stock->pallets($rule, $this->productA, null, false);
        self::assertSame([$old, $recent], $this->ids($byProduct), 'dal più vecchio; lo spedito non c\'è');

        self::assertTrue($byProduct[0]->isReserved());
        self::assertSame('7.500', $byProduct[0]->quantity);
        self::assertSame('ZZ-ST-L2', $byProduct[0]->locationCode);
        self::assertSame('DDT-ST-1', $byProduct[1]->documentNumber);
        self::assertSame($documentId, $byProduct[1]->supplierDocumentId);
        self::assertSame('Zz Fornitore Giacenze', $byProduct[1]->supplierName);

        $byLocation = $this->ids($this->stock->pallets($rule, null, $this->locationA, false));
        self::assertSame([$otherProduct, $recent], $byLocation);

        self::assertSame([$old], $this->ids($this->stock->pallets($rule, $this->productA, null, true)), 'solo i fermi');
        self::assertSame([], $this->stock->pallets($rule, $this->productA, $this->locationA, true), 'filtri insieme');
    }

    // --- aiuti ---

    private function setThreshold(string $days): void
    {
        $this->settings->update(
            array_merge($this->settings->values(), [SettingService::AGING_THRESHOLD_DAYS => $days]),
            $this->userId
        );
    }

    private function summaryOf(int $productId): ?StockProductSummary
    {
        foreach ($this->stock->summaryByProduct($this->stock->agingRule($this->today)) as $summary) {
            if ($summary->productId === $productId) {
                return $summary;
            }
        }

        return null;
    }

    /**
     * Inserisce un bancale direttamente: qui serve solo lo stato del magazzino, non un arrivo completo.
     */
    private function pallet(
        int $productId,
        int $locationId,
        string $quantity,
        string $receivedAt,
        string $status = 'in_stock',
        ?int $documentId = null
    ): int {
        $this->database->pdo()->prepare(
            'INSERT INTO pallets (code, product_id, supplier_id, supplier_document_id, location_id, quantity,
                                  initial_quantity, received_at, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'ZZ-ST-' . ++$this->palletNumber, $productId, $this->supplierId, $documentId, $locationId,
            $quantity, $quantity, $receivedAt, $status, $this->userId,
        ]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    private function document(string $number): int
    {
        $this->database->pdo()->prepare(
            'INSERT INTO supplier_documents (supplier_id, number, document_date, received_date, created_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $number, '2026-11-07', '2026-11-08', $this->userId]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * @param list<StockPallet> $pallets
     * @return list<int>
     */
    private function ids(array $pallets): array
    {
        return array_map(static fn (StockPallet $pallet): int => $pallet->id, $pallets);
    }
}
