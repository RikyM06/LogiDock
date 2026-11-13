<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use DateTimeZone;
use LogiDock\Core\Database;
use LogiDock\Repository\TripRepository;

/**
 * La conclusione di un viaggio: il camion è tornato e il viaggio entra nello storico.
 *
 * Si conclude solo un viaggio già partito ("in viaggio"). Da quel momento il viaggio è "concluso":
 * le regole esistenti già impediscono di annullarlo o modificarlo (valgono solo per i pianificati).
 */
final class TripClosureService
{
    public function __construct(
        private readonly Database $database,
        private readonly TripRepository $trips
    ) {
    }

    /**
     * @param DateTimeImmutable|null $now l'ora corrente; si passa solo nei test
     * @throws TripChangeNotAllowedException se il viaggio non esiste, non è partito o è già concluso
     */
    public function conclude(int $tripId, int $userId, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now');
        $concludedAt = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $this->database->transaction(function () use ($tripId, $userId, $concludedAt): void {
            // La condizione "è in viaggio" sta dentro l'UPDATE: due click insieme concludono il viaggio una volta sola.
            if (!$this->trips->markConcluded($tripId, $userId, $concludedAt)) {
                throw new TripChangeNotAllowedException('Si può concludere solo un viaggio già partito e non ancora concluso.');
            }
        });
    }
}
