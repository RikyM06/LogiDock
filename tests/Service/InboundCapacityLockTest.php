<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use LogiDock\Core\Database;
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
use LogiDock\Tests\Support\TestDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Prova di concorrenza vera, con DUE connessioni MySQL: la prima blocca una posizione, la seconda deve aspettare.
 * Per questo la posizione di prova si salva davvero (fuori dalla transazione dei test) e si cancella alla fine.
 */
final class InboundCapacityLockTest extends TestCase
{
    /** Codice di errore MySQL per "Lock wait timeout exceeded". */
    private const LOCK_WAIT_TIMEOUT = 1205;

    private Database $first;
    private Database $second;
    private int $locationId = 0;

    protected function setUp(): void
    {
        try {
            $this->first = TestDatabase::connect();
            $this->second = TestDatabase::connect();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL di test non raggiungibile: ' . $e->getMessage());
        }

        $this->first->pdo()->prepare('INSERT INTO warehouse_locations (code) VALUES (?)')
            ->execute(['ZZ-LOCK-' . strtoupper(uniqid())]);
        $this->locationId = (int) $this->first->pdo()->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->first)) {
            return;
        }
        foreach ([$this->first, $this->second ?? null] as $database) {
            if ($database !== null && $database->pdo()->inTransaction()) {
                $database->pdo()->rollBack();
            }
        }
        if ($this->locationId > 0) {
            $this->first->pdo()->prepare('DELETE FROM warehouse_locations WHERE id = ?')->execute([$this->locationId]);
        }
    }

    public function testASecondArrivalWaitsForTheFirstOneToFinish(): void
    {
        $this->second->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->first->pdo()->beginTransaction();
        (new WarehouseLocationRepository($this->first))->lockForUpdate([$this->locationId]);

        // Mentre la prima transazione tiene la posizione, la seconda resta in attesa e dopo 1 secondo si arrende.
        $this->second->pdo()->beginTransaction();
        try {
            (new WarehouseLocationRepository($this->second))->lockForUpdate([$this->locationId]);
            self::fail('La seconda connessione doveva aspettare il blocco della prima.');
        } catch (PDOException $e) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT, (int) ($e->errorInfo[1] ?? 0));
        }
        $this->second->pdo()->rollBack();

        // Finita la prima transazione, la posizione è di nuovo libera.
        $this->first->pdo()->commit();
        $this->second->pdo()->beginTransaction();
        (new WarehouseLocationRepository($this->second))->lockForUpdate([$this->locationId]);
        $this->second->pdo()->rollBack();

        self::assertFalse($this->second->pdo()->inTransaction());
    }

    /**
     * Prova che l'arrivo usa davvero il blocco. La prima connessione tiene la posizione con un blocco CONDIVISO
     * (FOR SHARE): è compatibile con quello che MySQL prende da solo quando si inserisce un bancale in quella
     * posizione, ma NON con il FOR UPDATE di InboundService. Se l'arrivo non bloccasse la posizione passerebbe
     * senza aspettare; se la blocca, resta in attesa e dopo 1 secondo si arrende.
     */
    public function testReceiveWaitsForTheLockOnItsLocation(): void
    {
        $pdo = $this->first->pdo();
        $tag = strtoupper(uniqid());

        // Dati salvati davvero (l'altra connessione deve vederli) e cancellati alla fine.
        $userId = (new UserRepository($this->first))->insert(
            'zz-lock-' . $tag . '@example.test',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            UserRole::Operatore
        );
        $supplierId = (new SupplierService(new SupplierRepository($this->first)))->create([
            'name' => 'Zz Lock ' . $tag, 'tax_code' => 'IT00000000997', 'address' => 'Via Prova 3, Milano',
            'contact_email' => 'lock@example.test', 'phone' => '02 0000997',
        ]);
        $productId = (new ProductService(new ProductRepository($this->first)))->create([
            'code' => 'ZZ-L-' . $tag, 'description' => 'Prodotto di prova', 'unit_of_measure' => 'ct', 'unit_weight_kg' => '10',
        ]);
        $palletTypeId = (int) $pdo->query('SELECT id FROM pallet_types ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $palletTypeId, 'Servono i tipi di bancale delle migrazioni.');

        try {
            $this->second->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM warehouse_locations WHERE id = ? FOR SHARE')->execute([$this->locationId]);

            // L'arrivo gira dentro una transazione esterna che alla fine si annulla SEMPRE: se un domani il blocco
            // sparisse, l'arrivo passerebbe senza aspettare e il test fallirebbe, ma senza lasciare bancali nel
            // database (i movimenti sono append-only e non si possono cancellare).
            $this->second->pdo()->beginTransaction();

            try {
                $this->inboundOn($this->second)->receive([
                    'supplier_id' => (string) $supplierId,
                    'number' => 'ZZ-LOCK-' . $tag,
                    'document_date' => '2026-11-05',
                    'received_date' => '2026-11-06',
                    'notes' => '',
                    'lines' => [[
                        'product_id' => (string) $productId,
                        'pallet_type_id' => (string) $palletTypeId,
                        'quantity' => '5',
                        'unit_price' => '1,5',
                        'recorded_weight_kg' => '50',
                        'lot_number' => '',
                        'expiry_date' => '',
                        'location_id' => (string) $this->locationId,
                    ]],
                ], $userId, new DateTimeImmutable('2026-11-06 10:00:00'));
                self::fail('L\'arrivo doveva aspettare il blocco sulla posizione.');
            } catch (PDOException $e) {
                self::assertSame(self::LOCK_WAIT_TIMEOUT, (int) ($e->errorInfo[1] ?? 0));
            }
        } finally {
            foreach ([$this->second->pdo(), $pdo] as $connection) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
            }
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
            $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }

    public function testLockingSeveralLocationsAndNoneIsFine(): void
    {
        $repository = new WarehouseLocationRepository($this->first);

        $this->first->pdo()->beginTransaction();
        $repository->lockForUpdate([]);
        $repository->lockForUpdate([$this->locationId, $this->locationId + 1000000]);
        $this->first->pdo()->rollBack();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $this->first->pdo()->getAttribute(PDO::ATTR_ERRMODE));
    }

    /** Lo stesso InboundService dell'applicazione, ma collegato alla connessione indicata. */
    private function inboundOn(Database $db): InboundService
    {
        return new InboundService(
            $db,
            new SupplierDocumentRepository($db),
            new PalletRepository($db),
            new PalletMovementRepository($db),
            new NumberSequenceRepository($db),
            new SupplierService(new SupplierRepository($db)),
            new ProductService(new ProductRepository($db)),
            new PalletTypeService(new PalletTypeRepository($db)),
            new WarehouseLocationService(new WarehouseLocationRepository($db)),
            new SettingService(new SettingRepository($db), $db)
        );
    }
}
