<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use LogiDock\Core\Logger;
use LogiDock\Core\Thousandths;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\TransportDocumentRepository;
use LogiDock\Repository\TripRepository;
use LogiDock\Service\PaymentNotAllowedException;
use LogiDock\Service\PaymentService;
use LogiDock\Tests\Support\IntegrationTestCase;
use LogiDock\Tests\Support\TripFlowFixture;

/**
 * I pagamenti (richiesta pagata e compenso pagato), con servizi e repository veri sul database di test.
 */
final class PaymentServiceTest extends IntegrationTestCase
{
    private TripFlowFixture $flow;
    private PaymentService $payments;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logidock-payments-' . uniqid() . '.log';
        $this->flow = new TripFlowFixture($this->database);
        $this->payments = new PaymentService(
            $this->database,
            new OrderRepository($this->database),
            new TripRepository($this->database),
            new Logger($this->logFile)
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testPaidAmountEqualsTheSumOfTheDdtLines(): void
    {
        // Due bancali da 30.5 a 0.005 l'uno: ogni riga vale 0.1525 -> 0.15 (mezzo in su), la somma delle righe
        // arrotondate e' 0.30, mentre l'arrotondamento della somma esatta (0.305) darebbe 0.31.
        $departed = $this->flow->departedTrip('0.005', '61');

        // Atteso calcolato qui dai dati grezzi, con interi (niente float, niente bcmath, niente SQL del codice):
        // quantita' e prezzo in millesimi, il prodotto e' in milionesimi, 10000 milionesimi = 1 centesimo.
        $expectedCents = 0;
        foreach (['30.5', '30.5'] as $palletQuantity) {
            $exactLine = Thousandths::fromDecimal($palletQuantity) * Thousandths::fromDecimal('0.005');
            $expectedCents += intdiv($exactLine + 5000, 10000); // +mezzo centesimo e taglio = mezzo in su
        }
        $expected = sprintf('%d.%02d', intdiv($expectedCents, 100), $expectedCents % 100);
        self::assertSame('0.30', $expected);

        // Le righe dei DDT veri (quelle del PDF): la loro somma deve essere lo stesso importo.
        $ddtLines = (new TransportDocumentRepository($this->database))->linesOfTrip($departed['tripId']);
        self::assertCount(2, $ddtLines);
        $ddtThousandths = 0;
        foreach ($ddtLines as $line) {
            $ddtThousandths += Thousandths::fromDecimal($line->lineTotal);
        }
        $ddtTotal = substr(Thousandths::toDecimal($ddtThousandths), 0, -1); // centesimi: l'ultima cifra e' 0
        self::assertSame($expected, $ddtTotal);

        $amount = $this->payments->markOrderPaid($departed['orderId'], $this->flow->userId);

        self::assertSame('0.30', $amount);
        self::assertSame($ddtTotal, $amount);
        self::assertSame('0.30', $this->orderColumn($departed['orderId'], 'paid_amount'));
        self::assertSame($this->flow->userId, (int) $this->orderColumn($departed['orderId'], 'paid_by'));
    }

    public function testAmountDueIsTheAmountThatWillBeSaved(): void
    {
        $departed = $this->flow->departedTrip('0.005', '61');

        self::assertSame('0.30', $this->payments->amountDue($departed['orderId']));
    }

    public function testPayingTwiceFailsAndKeepsTheFirstDate(): void
    {
        $departed = $this->flow->departedTrip();
        $this->payments->markOrderPaid($departed['orderId'], $this->flow->userId, new DateTimeImmutable('2026-11-12 09:00:00 UTC'));

        try {
            $this->payments->markOrderPaid($departed['orderId'], $this->flow->userId, new DateTimeImmutable('2026-11-13 18:00:00 UTC'));
            self::fail('Il secondo pagamento doveva essere rifiutato.');
        } catch (PaymentNotAllowedException $e) {
            self::assertSame('Questa richiesta risulta già pagata.', $e->getMessage());
        }

        self::assertSame('2026-11-12 09:00:00', $this->orderColumn($departed['orderId'], 'paid_at'));
    }

    public function testAZeroAmountOrderCanBePaid(): void
    {
        $departed = $this->flow->departedTrip('0');

        self::assertSame('0.00', $this->payments->markOrderPaid($departed['orderId'], $this->flow->userId));
        self::assertSame('0.00', $this->orderColumn($departed['orderId'], 'paid_amount'));
        self::assertNotNull($this->orderColumn($departed['orderId'], 'paid_at'));
    }

    public function testPlannedOrderCannotBePaid(): void
    {
        $tripId = $this->flow->plannedTrip();

        $this->expectException(PaymentNotAllowedException::class);
        $this->payments->markOrderPaid($this->orderOfTrip($tripId), $this->flow->userId);
    }

    public function testCancelledOrderCannotBePaid(): void
    {
        $departed = $this->flow->departedTrip();
        // Stato inventato a mano (POST forgiato): il CHECK del database non lo vieta, il service si'.
        $this->database->pdo()->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$departed['orderId']]);

        try {
            $this->payments->markOrderPaid($departed['orderId'], $this->flow->userId);
            self::fail('Una richiesta annullata non si puo segnare pagata.');
        } catch (PaymentNotAllowedException $e) {
            self::assertSame('Si può segnare pagata solo una richiesta spedita per intero.', $e->getMessage());
        }
        self::assertNull($this->orderColumn($departed['orderId'], 'paid_at'));
    }

    public function testUnknownOrderCannotBePaid(): void
    {
        $this->expectException(PaymentNotAllowedException::class);
        $this->payments->markOrderPaid(999999999, $this->flow->userId);
    }

    public function testPartialOrderCannotBePaid(): void
    {
        $departed = $this->flow->departedPartialTrip();

        $this->expectException(PaymentNotAllowedException::class);
        $this->payments->markOrderPaid($departed['orderId'], $this->flow->userId);
    }

    public function testShippedOrderWithoutDepartedItemsIsALogicError(): void
    {
        $departed = $this->flow->departedTrip();
        // Stato impossibile: richiesta "spedita" ma senza bancali su viaggi partiti.
        $this->database->pdo()->prepare('DELETE FROM trip_items WHERE order_id = ?')->execute([$departed['orderId']]);

        $this->expectException(\LogicException::class);
        $this->payments->markOrderPaid($departed['orderId'], $this->flow->userId);
    }

    public function testUndoOrderPaymentClearsTheFieldsAndLogsTheOldOnes(): void
    {
        $departed = $this->flow->departedTrip();
        $amount = $this->payments->markOrderPaid($departed['orderId'], $this->flow->userId, new DateTimeImmutable('2026-11-12 09:00:00 UTC'));

        $this->payments->undoOrderPayment($departed['orderId'], $this->flow->userId);

        self::assertNull($this->orderColumn($departed['orderId'], 'paid_at'));
        self::assertNull($this->orderColumn($departed['orderId'], 'paid_amount'));
        self::assertNull($this->orderColumn($departed['orderId'], 'paid_by'));

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString($this->orderColumn($departed['orderId'], 'code'), $log);
        self::assertStringContainsString($amount, $log);
        self::assertStringContainsString('2026-11-12 09:00:00', $log);
        self::assertStringContainsString('"user_id":' . $this->flow->userId, $log);
    }

    public function testUndoOnAnUnpaidOrderFails(): void
    {
        $departed = $this->flow->departedTrip();

        $this->expectException(PaymentNotAllowedException::class);
        $this->payments->undoOrderPayment($departed['orderId'], $this->flow->userId);
    }

    public function testCompensationPaidNeedsCarrierFeeAndADepartedTrip(): void
    {
        $planned = $this->flow->plannedTrip();
        try {
            $this->payments->markCompensationPaid($planned, $this->flow->userId);
            self::fail('Un viaggio non partito non ha un compenso da pagare.');
        } catch (PaymentNotAllowedException) {
            self::assertNull($this->tripColumn($planned, 'compensation_paid_at'));
        }

        $departed = $this->flow->departedTrip()['tripId'];
        $this->database->pdo()->prepare('UPDATE trips SET carrier_id = NULL, carrier_fee = NULL WHERE id = ?')->execute([$departed]);

        $this->expectException(PaymentNotAllowedException::class);
        $this->payments->markCompensationPaid($departed, $this->flow->userId);
    }

    public function testCompensationCanBePaidOnADepartedTrip(): void
    {
        $departed = $this->flow->departedTrip()['tripId'];

        $this->payments->markCompensationPaid($departed, $this->flow->userId, new DateTimeImmutable('2026-11-12 09:00:00 UTC'));

        self::assertSame('2026-11-12 09:00:00', $this->tripColumn($departed, 'compensation_paid_at'));
        self::assertSame($this->flow->userId, (int) $this->tripColumn($departed, 'compensation_paid_by'));
    }

    public function testCompensationPaidTwiceFails(): void
    {
        $departed = $this->flow->departedTrip()['tripId'];
        $this->payments->markCompensationPaid($departed, $this->flow->userId, new DateTimeImmutable('2026-11-12 09:00:00 UTC'));

        try {
            $this->payments->markCompensationPaid($departed, $this->flow->userId, new DateTimeImmutable('2026-11-13 18:00:00 UTC'));
            self::fail('Il secondo pagamento del compenso doveva essere rifiutato.');
        } catch (PaymentNotAllowedException $e) {
            self::assertSame('Il compenso di questo viaggio risulta già pagato.', $e->getMessage());
        }

        self::assertSame('2026-11-12 09:00:00', $this->tripColumn($departed, 'compensation_paid_at'));
    }

    public function testUndoCompensationLogs(): void
    {
        $departed = $this->flow->departedTrip()['tripId'];
        $this->payments->markCompensationPaid($departed, $this->flow->userId, new DateTimeImmutable('2026-11-12 09:00:00 UTC'));

        $this->payments->undoCompensationPayment($departed, $this->flow->userId);

        self::assertNull($this->tripColumn($departed, 'compensation_paid_at'));
        self::assertNull($this->tripColumn($departed, 'compensation_paid_by'));

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString($this->tripColumn($departed, 'code'), $log);
        self::assertStringContainsString('150.00', $log);
        self::assertStringContainsString('2026-11-12 09:00:00', $log);
        self::assertStringContainsString('"user_id":' . $this->flow->userId, $log);
    }

    public function testUndoCompensationOnAnUnpaidTripFails(): void
    {
        $departed = $this->flow->departedTrip()['tripId'];

        $this->expectException(PaymentNotAllowedException::class);
        $this->payments->undoCompensationPayment($departed, $this->flow->userId);
    }

    private function orderColumn(int $orderId, string $column): ?string
    {
        $statement = $this->database->pdo()->prepare("SELECT $column FROM orders WHERE id = ?");
        $statement->execute([$orderId]);
        $value = $statement->fetchColumn();

        return $value === null ? null : (string) $value;
    }

    private function tripColumn(int $tripId, string $column): ?string
    {
        $statement = $this->database->pdo()->prepare("SELECT $column FROM trips WHERE id = ?");
        $statement->execute([$tripId]);
        $value = $statement->fetchColumn();

        return $value === null ? null : (string) $value;
    }

    private function orderOfTrip(int $tripId): int
    {
        $statement = $this->database->pdo()->prepare('SELECT order_id FROM trip_items WHERE trip_id = ? LIMIT 1');
        $statement->execute([$tripId]);

        return (int) $statement->fetchColumn();
    }
}
