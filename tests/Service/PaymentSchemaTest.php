<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use LogiDock\Model\Order;
use LogiDock\Model\Trip;
use LogiDock\Tests\Support\IntegrationTestCase;
use LogiDock\Tests\Support\TripFlowFixture;
use PDOException;

/**
 * I vincoli della migrazione 0028 (pagamento delle richieste, chiusura dei viaggi e compenso pagato):
 * il database stesso rifiuta gli stati impossibili, anche se il codice sbagliasse.
 */
final class PaymentSchemaTest extends IntegrationTestCase
{
    private TripFlowFixture $flow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flow = new TripFlowFixture($this->database);
    }

    public function testPaidAtWithoutAmountAndUserIsRejected(): void
    {
        $departed = $this->flow->departedTrip();

        $this->expectException(PDOException::class);
        $this->exec('UPDATE orders SET paid_at = NOW() WHERE id = ?', [$departed['orderId']]);
    }

    public function testPaidAtOnAnOrderThatIsNotShippedIsRejected(): void
    {
        $tripId = $this->flow->plannedTrip();
        $orderId = $this->orderOfTrip($tripId);

        $this->expectException(PDOException::class);
        $this->exec(
            'UPDATE orders SET paid_at = NOW(), paid_amount = 10, paid_by = ? WHERE id = ?',
            [$this->flow->userId, $orderId]
        );
    }

    public function testACompletePaymentOnAShippedOrderIsAccepted(): void
    {
        $departed = $this->flow->departedTrip();
        $this->exec(
            'UPDATE orders SET paid_at = NOW(), paid_amount = 120, paid_by = ? WHERE id = ?',
            [$this->flow->userId, $departed['orderId']]
        );

        $order = $this->flow->orders->find($departed['orderId']);
        self::assertInstanceOf(Order::class, $order);
        self::assertTrue($order->isPaid());
        self::assertSame('120.00', $order->paidAmount);
        self::assertSame('Utente di prova', $order->paidByName);
    }

    public function testACompletedTripWithoutConcludedAtIsRejected(): void
    {
        $departed = $this->flow->departedTrip();

        $this->expectException(PDOException::class);
        $this->exec("UPDATE trips SET status = 'completed' WHERE id = ?", [$departed['tripId']]);
    }

    public function testCompensationPaidWithoutAFeeIsRejected(): void
    {
        $departed = $this->flow->departedTrip();
        $this->exec('UPDATE trips SET carrier_fee = NULL WHERE id = ?', [$departed['tripId']]);

        $this->expectException(PDOException::class);
        $this->exec(
            'UPDATE trips SET compensation_paid_at = NOW(), compensation_paid_by = ? WHERE id = ?',
            [$this->flow->userId, $departed['tripId']]
        );
    }

    public function testConclusionAndCompensationAreReadBackByTheModel(): void
    {
        $departed = $this->flow->departedTrip();
        $this->exec(
            "UPDATE trips SET status = 'completed', concluded_at = NOW(), concluded_by = ?,
                 compensation_paid_at = NOW(), compensation_paid_by = ? WHERE id = ?",
            [$this->flow->userId, $this->flow->userId, $departed['tripId']]
        );

        $trip = $this->flow->trips->find($departed['tripId']);
        self::assertInstanceOf(Trip::class, $trip);
        self::assertTrue($trip->isConcluded());
        self::assertTrue($trip->isCompensationPaid());
        self::assertNotNull($trip->concludedAt);
    }

    /**
     * @param list<mixed> $params
     */
    private function exec(string $sql, array $params): void
    {
        $this->database->pdo()->prepare($sql)->execute($params);
    }

    private function orderOfTrip(int $tripId): int
    {
        $statement = $this->database->pdo()->prepare('SELECT order_id FROM trip_items WHERE trip_id = ? LIMIT 1');
        $statement->execute([$tripId]);

        return (int) $statement->fetchColumn();
    }
}
