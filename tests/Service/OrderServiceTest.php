<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use LogiDock\Enum\UserRole;
use LogiDock\Model\Order;
use LogiDock\Repository\CustomerRepository;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\ProductRepository;
use LogiDock\Repository\RoundRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Service\CustomerService;
use LogiDock\Service\OrderNotEditableException;
use LogiDock\Service\OrderService;
use LogiDock\Service\ProductService;
use LogiDock\Service\RoundService;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * Test di integrazione delle richieste dei clienti: servizi e repository veri sul database di test.
 * "Oggi" è fisso (7 novembre 2026), così i controlli sulle date non dipendono dal giorno del test.
 */
final class OrderServiceTest extends IntegrationTestCase
{
    private OrderService $orders;
    private CustomerService $customers;
    private ProductService $products;

    private DateTimeImmutable $now;
    private int $userId;
    private int $customerId;
    private int $waterId;
    private int $pastaId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->database;
        $this->now = new DateTimeImmutable('2026-11-07 10:00:00');

        $rounds = new RoundService(new RoundRepository($db));
        $this->customers = new CustomerService(new CustomerRepository($db), $rounds);
        $this->products = new ProductService(new ProductRepository($db));
        $this->orders = new OrderService(
            $db,
            new OrderRepository($db),
            new NumberSequenceRepository($db),
            $this->customers,
            $this->products
        );

        $this->userId = (new UserRepository($db))->insert(
            'orders-test@example.com',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            UserRole::Operatore
        );
        $roundId = $rounds->create(['name' => 'Zz Giro Prova']);
        $this->customerId = $this->customers->create(['name' => 'Zz Bar Centrale', 'round_id' => (string) $roundId]);
        $this->waterId = $this->products->create([
            'code' => 'ZZ-ACQUA', 'description' => 'Acqua di prova', 'unit_of_measure' => 'ct', 'unit_weight_kg' => '12',
        ]);
        $this->pastaId = $this->products->create([
            'code' => 'ZZ-PASTA', 'description' => 'Pasta di prova', 'unit_of_measure' => 'ct', 'unit_weight_kg' => '6',
        ]);
    }

    // --- registrazione ---

    public function testCreateStoresOrderAndLines(): void
    {
        $id = $this->orders->create($this->input([
            $this->line($this->waterId, '10', '3,5'),
            $this->line($this->pastaId, '4', '12'),
        ], ['notes' => 'Consegnare al mattino']), $this->userId, $this->now);

        $order = $this->orders->find($id);
        self::assertNotNull($order);
        self::assertMatchesRegularExpression('/^ORD-2026-\d{6}$/', $order->code);
        self::assertSame('Zz Bar Centrale', $order->customerName);
        self::assertSame('Zz Giro Prova', $order->roundName);
        self::assertSame('2026-11-07', $order->requestedDate);
        self::assertSame('2026-11-10', $order->deliveryDate);
        self::assertSame(Order::STATUS_OPEN, $order->status);
        self::assertSame('Consegnare al mattino', $order->notes);
        self::assertSame(2, $order->lineCount);
        self::assertSame('83.00', $order->totalValue, '10 x 3,50 + 4 x 12,00');

        $lines = $this->orders->linesOf($id);
        self::assertSame('ZZ-ACQUA', $lines[0]->productCode);
        self::assertSame('10.000', $lines[0]->quantity);
        self::assertSame('3.5000', $lines[0]->unitPrice);
        self::assertSame('35.00', $lines[0]->lineValue);
    }

    public function testOrderCodesAreConsecutive(): void
    {
        $first = $this->orders->create($this->input([$this->line($this->waterId)]), $this->userId, $this->now);
        $second = $this->orders->create($this->input([$this->line($this->waterId)]), $this->userId, $this->now);

        $a = (int) substr((string) $this->orders->find($first)?->code, -6);
        $b = (int) substr((string) $this->orders->find($second)?->code, -6);

        self::assertSame($a + 1, $b);
    }

    public function testBlankLinesAreIgnored(): void
    {
        $id = $this->orders->create(
            $this->input([$this->emptyLine(), $this->line($this->waterId), $this->emptyLine()]),
            $this->userId,
            $this->now
        );

        self::assertSame(1, $this->orders->find($id)?->lineCount);
    }

    // --- validazione ---

    public function testAtLeastOneProductIsRequired(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->create($this->input([$this->emptyLine()]), $this->userId, $this->now));

        self::assertSame(['lines'], array_keys($errors));
    }

    public function testHeaderIsValidated(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->create(
            ['lines' => [$this->line($this->waterId)]],
            $this->userId,
            $this->now
        ));

        self::assertSame(['customer_id', 'requested_date', 'delivery_date'], array_keys($errors));
    }

    public function testDatesAreChecked(): void
    {
        $future = $this->errorsOf(fn () => $this->orders->create(
            $this->input([$this->line($this->waterId)], ['requested_date' => '2026-11-08', 'delivery_date' => '2026-11-09']),
            $this->userId,
            $this->now
        ));
        self::assertSame(['requested_date'], array_keys($future));

        $before = $this->errorsOf(fn () => $this->orders->create(
            $this->input([$this->line($this->waterId)], ['delivery_date' => '2026-11-06']),
            $this->userId,
            $this->now
        ));
        self::assertSame(['delivery_date'], array_keys($before));
    }

    public function testLineErrorsPointAtTheRightLine(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->create($this->input([
            $this->line($this->waterId),
            $this->line($this->pastaId, '0', '-1'),
        ]), $this->userId, $this->now));

        self::assertSame(['lines.1.quantity', 'lines.1.unit_price'], array_keys($errors));
    }

    public function testSameProductCannotBeOnTwoLines(): void
    {
        $errors = $this->errorsOf(fn () => $this->orders->create($this->input([
            $this->line($this->waterId, '5'),
            $this->line($this->waterId, '3'),
        ]), $this->userId, $this->now));

        self::assertSame(['lines.1.product_id'], array_keys($errors));
    }

    public function testDeactivatedCustomerAndProductCannotBeUsedInANewOrder(): void
    {
        $this->customers->deactivate($this->customerId);
        $this->products->deactivate($this->pastaId);

        $errors = $this->errorsOf(fn () => $this->orders->create(
            $this->input([$this->line($this->pastaId)]),
            $this->userId,
            $this->now
        ));

        self::assertSame(['customer_id', 'lines.0.product_id'], array_keys($errors));
    }

    public function testNothingIsSavedIfALineIsInvalid(): void
    {
        $before = $this->rowsIn('orders') . '/' . $this->rowsIn('order_lines');

        $this->errorsOf(fn () => $this->orders->create(
            $this->input([$this->line($this->waterId), $this->line($this->pastaId, 'tanti')]),
            $this->userId,
            $this->now
        ));

        self::assertSame($before, $this->rowsIn('orders') . '/' . $this->rowsIn('order_lines'));
    }

    // --- modifica e annullamento ---

    public function testUpdateChangesHeaderAndReplacesLines(): void
    {
        $id = $this->orders->create(
            $this->input([$this->line($this->waterId, '10'), $this->line($this->pastaId, '4')]),
            $this->userId,
            $this->now
        );
        $code = $this->orders->find($id)?->code;

        $this->orders->update($id, $this->input(
            [$this->line($this->pastaId, '7', '11')],
            ['delivery_date' => '2026-11-12', 'notes' => 'Cambiata']
        ), $this->now);

        $order = $this->orders->find($id);
        self::assertSame($code, $order?->code, 'Il codice non cambia con la modifica.');
        self::assertSame('2026-11-12', $order?->deliveryDate);
        self::assertSame('Cambiata', $order?->notes);

        $lines = $this->orders->linesOf($id);
        self::assertCount(1, $lines, 'La riga tolta dal form sparisce.');
        self::assertSame('ZZ-PASTA', $lines[0]->productCode);
        self::assertSame('7.000', $lines[0]->quantity);
    }

    public function testEditingKeepsCustomerAndProductsDeactivatedInTheMeantime(): void
    {
        $id = $this->orders->create($this->input([$this->line($this->pastaId, '4')]), $this->userId, $this->now);
        $this->customers->deactivate($this->customerId);
        $this->products->deactivate($this->pastaId);

        // Salvare senza toccarli non deve dare errore.
        $this->orders->update($id, $this->input([$this->line($this->pastaId, '5')]), $this->now);

        self::assertSame('5.000', $this->orders->linesOf($id)[0]->quantity);
    }

    public function testCancelledOrderCannotBeChangedAnymore(): void
    {
        $id = $this->orders->create($this->input([$this->line($this->waterId)]), $this->userId, $this->now);

        $this->orders->cancel($id);
        self::assertSame(Order::STATUS_CANCELLED, $this->orders->find($id)?->status);
        self::assertFalse($this->orders->find($id)?->isEditable());

        $this->expectException(OrderNotEditableException::class);
        $this->orders->update($id, $this->input([$this->line($this->waterId)]), $this->now);
    }

    public function testPlannedOrderCannotBeCancelled(): void
    {
        $id = $this->orders->create($this->input([$this->line($this->waterId)]), $this->userId, $this->now);
        // Lo stato "pianificata" lo metterà la pianificazione dei viaggi (fase 13): qui lo si simula.
        $this->database->pdo()->prepare("UPDATE orders SET status = 'planned' WHERE id = ?")->execute([$id]);

        $this->expectException(OrderNotEditableException::class);
        $this->expectExceptionMessage('Pianificata');
        $this->orders->cancel($id);
    }

    // --- ricerca ---

    public function testSearchByCodeOrCustomerAndByStatus(): void
    {
        $open = $this->orders->create($this->input([$this->line($this->waterId)]), $this->userId, $this->now);
        $cancelled = $this->orders->create($this->input([$this->line($this->waterId)]), $this->userId, $this->now);
        $this->orders->cancel($cancelled);

        $ids = static fn (array $found): array => array_map(static fn (Order $o): int => $o->id, $found);

        self::assertContains($open, $ids($this->orders->search('zz bar')));
        self::assertSame([$open], $ids($this->orders->search((string) $this->orders->find($open)?->code)));

        $openOnes = $ids($this->orders->search('Zz Bar', Order::STATUS_OPEN));
        self::assertContains($open, $openOnes);
        self::assertNotContains($cancelled, $openOnes);

        // Uno stato sconosciuto si ignora (nessun filtro) invece di dare errore.
        self::assertContains($cancelled, $ids($this->orders->search('Zz Bar', 'inventato')));
    }

    // --- giacenza in magazzino ---

    public function testLinesShowStockInWarehouseAndShortage(): void
    {
        // Acqua: 2 bancali da 50 in magazzino + 1 già spedito (non conta) = 100 disponibili.
        $this->insertPallet($this->waterId, '50', 'in_stock');
        $this->insertPallet($this->waterId, '50', 'in_stock');
        $this->insertPallet($this->waterId, '30', 'shipped');
        // Pasta: niente in magazzino.

        $id = $this->orders->create($this->input([
            $this->line($this->waterId, '80'),
            $this->line($this->pastaId, '5'),
        ]), $this->userId, $this->now);

        [$water, $pasta] = $this->orders->linesOf($id);
        self::assertSame('100.000', $water->stockQuantity);
        self::assertFalse($water->stockShort, '80 chiesti, 100 disponibili.');
        self::assertSame('0.000', $pasta->stockQuantity);
        self::assertTrue($pasta->stockShort, '5 chiesti, 0 disponibili.');
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
            'customer_id' => (string) $this->customerId,
            'requested_date' => '2026-11-07',
            'delivery_date' => '2026-11-10',
            'notes' => '',
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function line(int $productId, string $quantity = '1', string $unitPrice = '2'): array
    {
        return ['product_id' => (string) $productId, 'quantity' => $quantity, 'unit_price' => $unitPrice];
    }

    /**
     * @return array<string, string>
     */
    private function emptyLine(): array
    {
        return ['product_id' => '', 'quantity' => '', 'unit_price' => ''];
    }

    private function insertPallet(int $productId, string $quantity, string $status): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO pallets (code, product_id, quantity, initial_quantity, received_at, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(['ZZ-' . bin2hex(random_bytes(6)), $productId, $quantity, $quantity, '2026-11-01 00:00:00', $status, $this->userId]);
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->database->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
