<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use DateTimeImmutable;
use LogiDock\Model\Trip;
use LogiDock\Repository\TripRepository;
use LogiDock\Service\TripChangeNotAllowedException;
use LogiDock\Service\TripClosureService;
use LogiDock\Service\TripNotCancellableException;
use LogiDock\Tests\Support\IntegrationTestCase;
use LogiDock\Tests\Support\TripFlowFixture;

/**
 * La conclusione di un viaggio partito, con servizi e repository veri sul database di test.
 */
final class TripClosureServiceTest extends IntegrationTestCase
{
    private TripFlowFixture $flow;
    private TripClosureService $closure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flow = new TripFlowFixture($this->database);
        $this->closure = new TripClosureService($this->database, new TripRepository($this->database));
    }

    public function testConcludeMarksTheTripCompleted(): void
    {
        $departed = $this->flow->departedTrip();
        $this->closure->conclude($departed['tripId'], $this->flow->userId, new DateTimeImmutable('2026-11-12 09:30:00 UTC'));

        $trip = $this->flow->trips->find($departed['tripId']);
        self::assertInstanceOf(Trip::class, $trip);
        self::assertSame(Trip::STATUS_COMPLETED, $trip->status);
        self::assertTrue($trip->isConcluded());
        self::assertSame('2026-11-12 09:30:00', $trip->concludedAt);
        self::assertSame($this->flow->userId, $this->concludedBy($departed['tripId']));
    }

    public function testConcludeTwiceFails(): void
    {
        $departed = $this->flow->departedTrip();
        $this->closure->conclude($departed['tripId'], $this->flow->userId, new DateTimeImmutable('2026-11-12 09:30:00 UTC'));

        try {
            $this->closure->conclude($departed['tripId'], $this->flow->userId, new DateTimeImmutable('2026-11-13 18:00:00 UTC'));
            self::fail('La seconda conclusione doveva essere rifiutata.');
        } catch (TripChangeNotAllowedException $e) {
            self::assertSame('Si può concludere solo un viaggio già partito e non ancora concluso.', $e->getMessage());
        }

        $trip = $this->flow->trips->find($departed['tripId']);
        self::assertInstanceOf(Trip::class, $trip);
        self::assertSame('2026-11-12 09:30:00', $trip->concludedAt);
    }

    public function testPlannedTripCannotBeConcluded(): void
    {
        $tripId = $this->flow->plannedTrip();

        $this->expectException(TripChangeNotAllowedException::class);
        $this->closure->conclude($tripId, $this->flow->userId);
    }

    public function testUnknownTripCannotBeConcluded(): void
    {
        $this->expectException(TripChangeNotAllowedException::class);
        $this->closure->conclude(999999999, $this->flow->userId);
    }

    public function testConcludedTripCannotBeCancelledOrChanged(): void
    {
        $departed = $this->flow->departedTrip();
        $this->closure->conclude($departed['tripId'], $this->flow->userId);

        $this->expectException(TripNotCancellableException::class);
        $this->flow->trips->cancel($departed['tripId']);
    }

    private function concludedBy(int $tripId): int
    {
        $statement = $this->database->pdo()->prepare('SELECT concluded_by FROM trips WHERE id = ?');
        $statement->execute([$tripId]);

        return (int) $statement->fetchColumn();
    }
}
