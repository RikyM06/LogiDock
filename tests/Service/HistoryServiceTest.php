<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use LogiDock\Core\Logger;
use LogiDock\Model\History\PaymentHistoryRow;
use LogiDock\Model\TransportDocument;
use LogiDock\Repository\HistoryRepository;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\PalletMovementRepository;
use LogiDock\Repository\SupplierRepository;
use LogiDock\Repository\TripRepository;
use LogiDock\Service\HistoryService;
use LogiDock\Service\PaymentService;
use LogiDock\Service\SupplierService;
use LogiDock\Tests\Support\IntegrationTestCase;
use LogiDock\Tests\Support\TripFlowFixture;

/**
 * I report dello storico con servizi e repository veri sul database di test. I viaggi del fixture
 * cadono a marzo 2028: i filtri usano un periodo stretto per non contare eventuali residui.
 */
final class HistoryServiceTest extends IntegrationTestCase
{
    private const PERIOD = ['dal' => '2028-03-01', 'al' => '2028-03-31'];

    private TripFlowFixture $flow;
    private HistoryService $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flow = new TripFlowFixture($this->database);
        $this->history = new HistoryService(new HistoryRepository($this->database));
    }

    public function testSupplierQuantityDoesNotDropAfterDeparture(): void
    {
        // Due bancali da 30 che partono davvero: dopo la partenza hanno quantità 0 e stato "shipped".
        $departed = $this->flow->departedTrip();
        $supplierId = (new SupplierService(new SupplierRepository($this->database)))->create([
            'name' => 'Zz Fornitore storico', 'tax_code' => 'IT00000000997', 'address' => 'Via Prova 3, Milano',
            'contact_email' => 'storico@fornitore.test', 'phone' => '02 0000997',
        ]);
        $pdo = $this->database->pdo();
        $pdo->prepare(
            'INSERT INTO supplier_documents (supplier_id, number, document_date, received_date, created_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, 'ZZ-STO-1', '2027-05-04', '2027-05-05', $this->flow->userId]);
        $documentId = (int) $pdo->lastInsertId();
        $pdo->exec('INSERT INTO warehouse_locations (code, description) VALUES (\'ZZ-STO\', \'Posizione storico\')');
        $locationId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare('SELECT pallet_id FROM trip_items WHERE trip_id = ?');
        $statement->execute([$departed['tripId']]);
        $palletIds = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
        self::assertCount(2, $palletIds);

        $movements = new PalletMovementRepository($this->database);
        foreach ($palletIds as $palletId) {
            $pdo->prepare('UPDATE pallets SET supplier_id = ?, supplier_document_id = ?, unit_price = 1.5 WHERE id = ?')
                ->execute([$supplierId, $documentId, $palletId]);
            $movements->insertInbound($palletId, $locationId, '30.000', $this->flow->userId, '2027-05-05 00:00:00', null);
        }

        // Prova di partenza: i bancali sono davvero partiti e a zero.
        $check = $pdo->query('SELECT status, quantity FROM pallets WHERE id IN (' . implode(',', $palletIds) . ')')->fetchAll();
        foreach ($check as $row) {
            self::assertSame('shipped', $row['status']);
            self::assertSame('0.000', $row['quantity']);
        }

        $filters = $this->history->normalizeFilters(
            ['dal' => '2027-05-01', 'al' => '2027-05-31', 'fornitore' => (string) $supplierId],
            false
        );
        $rows = $this->history->suppliers($filters);

        self::assertCount(1, $rows);
        self::assertSame($supplierId, $rows[0]->supplierId);
        self::assertSame('60.000', $rows[0]->quantity, 'quantità dai movimenti inbound, non da pallets.quantity');
        self::assertSame('90.00', $rows[0]->purchaseValue, '60 x 1,50');
        self::assertSame(1, $rows[0]->documentCount);
        self::assertSame(2, $rows[0]->palletCount);
        self::assertSame('ct', $rows[0]->unitOfMeasure);

        // Un altro fornitore non vede nulla.
        $other = $this->history->normalizeFilters(['dal' => '2027-05-01', 'al' => '2027-05-31', 'fornitore' => '999999999'], false);
        self::assertSame([], $this->history->suppliers($other));
    }

    public function testPaymentsReportSplitsCollectedDueAndPartial(): void
    {
        $paid = $this->flow->departedTrip('2', '60');       // 60 x 2 = 120,00
        $unpaid = $this->flow->departedTrip('3', '10');     // 10 x 3 = 30,00
        $partial = $this->flow->departedPartialTrip();      // un solo bancale da 30 x 2 = 60,00, richiesta non spedita
        $planned = $this->flow->plannedTrip();              // nessun viaggio partito: non deve comparire
        self::assertGreaterThan(0, $planned);

        (new PaymentService(
            $this->database,
            new OrderRepository($this->database),
            new TripRepository($this->database),
            new Logger(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logidock-history-' . uniqid() . '.log')
        ))->markOrderPaid($paid['orderId'], $this->flow->userId);

        $report = $this->history->payments($this->history->normalizeFilters(self::PERIOD, false));
        $byOrder = [];
        foreach ($report->rows as $row) {
            $byOrder[$row->orderId] = $row;
        }

        self::assertCount(3, $report->rows);
        self::assertSame(PaymentHistoryRow::PAID, $byOrder[$paid['orderId']]->state);
        self::assertSame('120.00', $byOrder[$paid['orderId']]->amount);
        self::assertNotNull($byOrder[$paid['orderId']]->paidAt);
        self::assertSame(PaymentHistoryRow::UNPAID, $byOrder[$unpaid['orderId']]->state);
        self::assertSame('30.00', $byOrder[$unpaid['orderId']]->amount);
        self::assertTrue($byOrder[$unpaid['orderId']]->canBePaid());
        self::assertSame(PaymentHistoryRow::PARTIAL, $byOrder[$partial['orderId']]->state);
        self::assertSame('60.00', $byOrder[$partial['orderId']]->amount);
        self::assertFalse($byOrder[$partial['orderId']]->canBePaid());

        self::assertSame('120.00', $report->collectedTotal);
        self::assertSame('30.00', $report->dueTotal);
        self::assertSame('60.00', $report->partialTotal);

        // Filtro sullo stato: solo le richieste da pagare.
        $onlyDue = $this->history->payments($this->history->normalizeFilters(self::PERIOD + ['pagamento' => 'unpaid'], false));
        self::assertCount(1, $onlyDue->rows);
        self::assertSame('0.00', $onlyDue->collectedTotal);
        self::assertSame('30.00', $onlyDue->dueTotal);
    }

    public function testTripsReportShowsBilledTotalOnlyForDepartedTrips(): void
    {
        $planned = $this->flow->plannedTrip();
        $departed = $this->flow->departedTrip('2', '60');

        $rows = [];
        foreach ($this->history->trips($this->history->normalizeFilters(self::PERIOD, true), true) as $row) {
            $rows[$row->id] = $row;
        }

        self::assertArrayHasKey($planned, $rows);
        self::assertNull($rows[$planned]->billedTotal, 'un viaggio pianificato non ha totale fatturato');
        self::assertSame('planned', $rows[$planned]->status);
        self::assertSame(2, $rows[$planned]->palletCount);
        self::assertSame(1, $rows[$planned]->customerCount);

        self::assertArrayHasKey($departed['tripId'], $rows);
        self::assertSame('120.00', $rows[$departed['tripId']]->billedTotal);
        self::assertSame('in_transit', $rows[$departed['tripId']]->status);

        // Filtro per stato.
        $onlyPlanned = $this->history->trips(
            $this->history->normalizeFilters(self::PERIOD + ['stato' => 'planned'], false),
            false
        );
        self::assertSame([$planned], array_map(static fn ($row): int => $row->id, $onlyPlanned));
    }

    public function testOperatorNeverGetsCompensationData(): void
    {
        $departed = $this->flow->departedTrip();
        (new PaymentService(
            $this->database,
            new OrderRepository($this->database),
            new TripRepository($this->database),
            new Logger(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logidock-history-' . uniqid() . '.log')
        ))->markCompensationPaid($departed['tripId'], $this->flow->userId);

        // Operatore: nessun compenso nei viaggi.
        $operatorFilters = $this->history->normalizeFilters(self::PERIOD + ['tipo' => 'compensation'], false);
        self::assertNull($operatorFilters['type'], 'tipo=compensation forzato da un operatore viene ignorato');
        $operatorTrips = $this->history->trips($operatorFilters, false);
        $operatorRow = $this->findTrip($operatorTrips, $departed['tripId']);
        self::assertNull($operatorRow->fee);
        self::assertNull($operatorRow->compensationPaidAt);

        // Operatore: nessun documento di compenso, anche se il filtro lo chiede a mano (aggira normalizeFilters).
        $forged = ['type' => 'compensation'] + $operatorFilters;
        self::assertSame([], $this->history->documents($forged, false));
        // I documenti sono emessi alla partenza (10 novembre 2026, data del fixture).
        $documentFilters = $this->history->normalizeFilters(['dal' => '2026-11-10', 'al' => '2026-11-10'], false);
        $documents = $this->history->documents($documentFilters, false);
        $types = [];
        foreach ($documents as $row) {
            if ($row->tripId === $departed['tripId']) {
                $types[] = $row->type;
            }
        }
        sort($types);
        self::assertSame([TransportDocument::TYPE_DDT, TransportDocument::TYPE_LOAD_LIST], $types);

        // Admin: li vede.
        $adminFilters = $this->history->normalizeFilters(self::PERIOD, true);
        $adminRow = $this->findTrip($this->history->trips($adminFilters, true), $departed['tripId']);
        self::assertSame('150.00', $adminRow->fee);
        self::assertNotNull($adminRow->compensationPaidAt);

        $adminDocs = $this->history->documents(
            $this->history->normalizeFilters(['dal' => '2026-11-10', 'al' => '2026-11-10', 'tipo' => 'compensation'], true),
            true
        );
        $compensations = array_filter(
            $adminDocs,
            static fn ($row): bool => $row->tripId === $departed['tripId'] && $row->type === TransportDocument::TYPE_COMPENSATION
        );
        self::assertCount(1, $compensations);
    }

    public function testDirtyFiltersAreIgnored(): void
    {
        $filters = $this->history->normalizeFilters([
            'dal' => 'abc', 'al' => '2028-02-31', 'giro' => 'x', 'stato' => 'boh', 'fornitore' => '-4',
            'cliente' => '1;DROP', 'pagamento' => 'boh', 'tipo' => ['a'],
        ], true);

        self::assertSame(
            ['from' => null, 'to' => null, 'round' => null, 'status' => null, 'supplier' => null,
                'customer' => null, 'payment' => null, 'type' => null],
            $filters
        );

        // Un a capo in fondo e' sporco come qualsiasi altro valore ($ di regex lo lascerebbe passare).
        $trailingNewline = $this->history->normalizeFilters(['dal' => "2028-02-10\n", 'giro' => "7\n"], false);
        self::assertNull($trailingNewline['from']);
        self::assertNull($trailingNewline['round']);

        // "al" prima di "dal": le date vengono scambiate.
        $swapped = $this->history->normalizeFilters(['dal' => '2028-05-10', 'al' => '2028-05-01'], false);
        self::assertSame('2028-05-01', $swapped['from']);
        self::assertSame('2028-05-10', $swapped['to']);

        // Valori buoni passano.
        $good = $this->history->normalizeFilters([
            'giro' => '7', 'stato' => 'completed', 'fornitore' => '3', 'cliente' => '5', 'pagamento' => 'partial', 'tipo' => 'ddt',
        ], false);
        self::assertSame(7, $good['round']);
        self::assertSame('completed', $good['status']);
        self::assertSame(3, $good['supplier']);
        self::assertSame(5, $good['customer']);
        self::assertSame('partial', $good['payment']);
        self::assertSame('ddt', $good['type']);

        // I report con filtri sporchi non lanciano nulla.
        self::assertIsArray($this->history->trips($filters, true));
        self::assertIsArray($this->history->suppliers($filters));
        self::assertIsArray($this->history->documents($filters, true));
        self::assertIsArray($this->history->payments($filters)->rows);
    }

    /**
     * @param list<\LogiDock\Model\History\TripHistoryRow> $rows
     */
    private function findTrip(array $rows, int $tripId): \LogiDock\Model\History\TripHistoryRow
    {
        foreach ($rows as $row) {
            if ($row->id === $tripId) {
                return $row;
            }
        }
        self::fail('Viaggio non trovato nel report: ' . $tripId);
    }
}
