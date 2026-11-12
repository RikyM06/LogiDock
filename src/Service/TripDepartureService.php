<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use LogiDock\Core\Database;
use LogiDock\Model\Order;
use LogiDock\Model\TransportDocument;
use LogiDock\Model\Trip;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\PalletMovementRepository;
use LogiDock\Repository\PalletRepository;
use LogiDock\Repository\TripRepository;

/**
 * La partenza di un viaggio: il momento in cui la merce esce davvero dal magazzino.
 *
 * In UNA transazione (o succede tutto o non succede niente):
 *  1. il viaggio passa da "pianificato" a "in viaggio" (da quel momento non si può più modificare né annullare);
 *  2. ogni bancale diventa "spedito" ed esce dal magazzino, con un movimento di uscita nello storico
 *     (pallet_movements è append-only: l'uscita non si cancella, al massimo si corregge con un altro movimento);
 *  3. le richieste completamente servite da viaggi partiti diventano "spedite";
 *  4. escono i documenti (carico, DDT per cliente, compenso) con i loro PDF.
 *
 * L'ordine dei blocchi è lo stesso di TripService: prima le righe delle richieste, poi i bancali in ordine
 * di id. Così una partenza e una modifica dello stesso viaggio si mettono in fila invece di bloccarsi a vicenda.
 */
final class TripDepartureService
{
    public function __construct(
        private readonly Database $database,
        private readonly TripRepository $trips,
        private readonly PalletRepository $pallets,
        private readonly PalletMovementRepository $movements,
        private readonly OrderRepository $orders,
        private readonly DocumentService $documents
    ) {
    }

    /**
     * Fa partire un viaggio pianificato.
     *
     * @param DateTimeImmutable|null $now l'ora corrente; si passa solo nei test
     * @return list<TransportDocument> i documenti emessi
     * @throws TripChangeNotAllowedException se il viaggio non esiste, non è pianificato o non ha bancali
     */
    public function depart(int $tripId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');

        return $this->database->transaction(function () use ($tripId, $userId, $now): array {
            // Prima di tutto le richieste: è la regola dei blocchi, e la "fotografia" dei dati di MySQL
            // parte alla prima lettura normale, cioè dopo che nessun altro sta toccando questo viaggio.
            $orderStatuses = $this->orders->lockByTrip($tripId);

            // La condizione "è ancora pianificato" sta dentro l'UPDATE: due click insieme fanno partire il viaggio una volta sola.
            if (!$this->trips->changeStatus($tripId, Trip::STATUS_PLANNED, Trip::STATUS_IN_TRANSIT)) {
                throw new TripChangeNotAllowedException('Può partire solo un viaggio pianificato, non ancora partito.');
            }

            $trip = $this->trips->findById($tripId)
                ?? throw new LogicException('Il viaggio ' . $tripId . ' è appena stato aggiornato ma non si legge più.');

            if ($trip->palletCount === 0) {
                // Dà errore dentro la transazione: lo stato torna "pianificato".
                throw new TripChangeNotAllowedException('Un viaggio senza bancali non può partire.');
            }

            $this->shipPallets($trip, $userId, $now);

            foreach ($orderStatuses as $orderId => $status) {
                if ($status === Order::STATUS_PLANNED && $this->orders->isFullyDeparted($orderId)) {
                    $this->orders->setStatus($orderId, Order::STATUS_SHIPPED);
                }
            }

            return $this->documents->issueForTrip($trip, $userId, $now);
        });
    }

    /**
     * Ogni bancale esce dal magazzino: stato "spedito" e un movimento di uscita con il viaggio.
     */
    private function shipPallets(Trip $trip, int $userId, DateTimeImmutable $now): void
    {
        $occurredAt = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        foreach ($this->pallets->lockOfTrip($trip->id) as $pallet) {
            // Un bancale di un viaggio pianificato è sempre "prenotato" (lo garantisce la conferma).
            // Se non lo è, qualcosa è andato storto: meglio fermare la partenza che spedire a metà.
            if ($pallet['status'] !== 'reserved' || !$this->pallets->ship($pallet['id'])) {
                throw new LogicException(sprintf(
                    'Il bancale %d del viaggio %s non è prenotato (stato: %s): la partenza è stata fermata.',
                    $pallet['id'],
                    $trip->code,
                    $pallet['status']
                ));
            }

            $this->movements->insertOutbound(
                $pallet['id'],
                $pallet['location_id'],
                $pallet['quantity'],
                $trip->id,
                $userId,
                $occurredAt,
                'Partenza del viaggio ' . $trip->code
            );
        }
    }
}
