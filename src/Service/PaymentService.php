<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use LogiDock\Core\Database;
use LogiDock\Core\Logger;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\TripRepository;

/**
 * Pagamenti: la richiesta pagata dal cliente (importo = somma dei DDT) e il compenso pagato al trasportatore.
 *
 * Niente pagamenti parziali (scelta del titolare). Chi può farlo (solo admin) lo decide il controller.
 * Le condizioni "non ancora pagato" stanno dentro l'UPDATE e la riga è bloccata con FOR UPDATE:
 * due click insieme registrano un solo pagamento e la data del primo non cambia.
 */
final class PaymentService
{
    public function __construct(
        private readonly Database $database,
        private readonly OrderRepository $orders,
        private readonly TripRepository $trips,
        private readonly Logger $logger
    ) {
    }

    /**
     * L'importo da incassare: la somma dei DDT della richiesta (serve alla pagina per mostrarlo prima del pagamento).
     */
    public function amountDue(int $orderId): string
    {
        return $this->orders->billedAmount($orderId);
    }

    /**
     * @param DateTimeImmutable|null $now l'ora corrente; si passa solo nei test
     * @return string l'importo salvato, con due decimali
     * @throws PaymentNotAllowedException se la richiesta non esiste, non è spedita o è già pagata
     */
    public function markOrderPaid(int $orderId, int $userId, ?DateTimeImmutable $now = null): string
    {
        $paidAt = $this->utc($now);

        return $this->database->transaction(function () use ($orderId, $userId, $paidAt): string {
            $state = $this->orders->lockForPayment($orderId);
            if ($state === null || $state['status'] !== 'shipped') {
                throw new PaymentNotAllowedException('Si può segnare pagata solo una richiesta spedita per intero.');
            }
            if ($state['paid_at'] !== null) {
                throw new PaymentNotAllowedException('Questa richiesta risulta già pagata.');
            }

            // Una richiesta spedita ha sempre bancali su viaggi partiti. Un importo 0,00 (prezzo 0) è lecito.
            if ($this->orders->departedItemCount($orderId) === 0) {
                throw new LogicException('Richiesta ' . $orderId . ' spedita ma senza bancali su viaggi partiti.');
            }

            $amount = $this->orders->billedAmount($orderId);
            if (!$this->orders->markPaid($orderId, $amount, $userId, $paidAt)) {
                throw new PaymentNotAllowedException('Questa richiesta risulta già pagata.');
            }

            return $amount;
        });
    }

    /**
     * @throws PaymentNotAllowedException se la richiesta non era pagata
     */
    public function undoOrderPayment(int $orderId, int $userId): void
    {
        $old = $this->database->transaction(function () use ($orderId): array {
            $old = $this->orders->markUnpaid($orderId);
            if ($old === null) {
                throw new PaymentNotAllowedException('Questa richiesta non risulta pagata.');
            }

            return $old;
        });

        // Il log si scrive a transazione chiusa: se ha fallito, non c'è nulla da annullare.
        $order = $this->orders->findById($orderId);
        $this->logger->info('Pagamento richiesta annullato', [
            'order' => $order?->code ?? (string) $orderId,
            'amount' => $old['paid_amount'],
            'previous_paid_at' => $old['paid_at'],
            'user_id' => $userId,
        ]);
    }

    /**
     * @param DateTimeImmutable|null $now l'ora corrente; si passa solo nei test
     * @throws PaymentNotAllowedException se il viaggio non è partito, non ha un compenso o è già pagato
     */
    public function markCompensationPaid(int $tripId, int $userId, ?DateTimeImmutable $now = null): void
    {
        $paidAt = $this->utc($now);

        $this->database->transaction(function () use ($tripId, $userId, $paidAt): void {
            if (!$this->trips->markCompensationPaid($tripId, $userId, $paidAt)) {
                throw new PaymentNotAllowedException($this->compensationRefusal($tripId));
            }
        });
    }

    /**
     * @throws PaymentNotAllowedException se il compenso non era pagato
     */
    public function undoCompensationPayment(int $tripId, int $userId): void
    {
        $old = $this->database->transaction(function () use ($tripId): array {
            $old = $this->trips->markCompensationUnpaid($tripId);
            if ($old === null) {
                throw new PaymentNotAllowedException('Il compenso di questo viaggio non risulta pagato.');
            }

            return $old;
        });

        $trip = $this->trips->findById($tripId);
        $this->logger->info('Pagamento compenso annullato', [
            'trip' => $trip?->code ?? (string) $tripId,
            'amount' => $old['carrier_fee'],
            'previous_paid_at' => $old['compensation_paid_at'],
            'user_id' => $userId,
        ]);
    }

    /**
     * Il messaggio giusto quando l'UPDATE non ha toccato nulla (dentro la stessa transazione, dopo il rifiuto).
     */
    private function compensationRefusal(int $tripId): string
    {
        $trip = $this->trips->findById($tripId);
        if ($trip !== null && $trip->compensationPaidAt !== null) {
            return 'Il compenso di questo viaggio risulta già pagato.';
        }

        return 'Il compenso si può segnare pagato solo per un viaggio già partito con un trasportatore e un compenso.';
    }

    private function utc(?DateTimeImmutable $now): string
    {
        return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
