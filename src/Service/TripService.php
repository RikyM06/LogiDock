<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use LogicException;
use LogiDock\Core\Database;
use LogiDock\Core\ValidationException;
use LogiDock\Core\Validator;
use LogiDock\Model\Order;
use LogiDock\Model\PlanningDay;
use LogiDock\Model\Trip;
use LogiDock\Model\TripItem;
use LogiDock\Model\TripPlan;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\OrderRepository;
use LogiDock\Repository\PalletRepository;
use LogiDock\Repository\PlanningRepository;
use LogiDock\Repository\TripRepository;

/**
 * Pianificazione dei viaggi: calcola il piano di un giorno, lo conferma (i viaggi diventano veri,
 * i bancali "prenotati", le richieste "pianificate") e annulla un viaggio confermato.
 *
 * Il piano non viene mai salvato prima della conferma: si ricalcola ogni volta dai dati del momento
 * (TripPlanner è un calcolo puro, con gli stessi dati dà lo stesso piano). Chi conferma manda
 * l'"impronta" del piano che ha visto: se quello ricalcolato è diverso, qualcosa è cambiato nel
 * frattempo e si chiede di riguardarlo, invece di confermare una cosa mai vista.
 *
 * Più utenti insieme: conferma e annullamento bloccano per prima cosa le righe delle richieste
 * coinvolte (anche OrderService lo fa quando modifica o annulla una richiesta). Tutti prendono i
 * blocchi nello stesso ordine (richieste, poi bancali in ordine di id), quindi si mettono in fila
 * invece di bloccarsi a vicenda. Due conferme dello stesso giorno passano una alla volta: la seconda
 * ricalcola, trova i mezzi e i bancali già presi e viene fermata dal confronto delle impronte.
 */
final class TripService
{
    public function __construct(
        private readonly Database $database,
        private readonly PlanningRepository $planning,
        private readonly TripRepository $trips,
        private readonly PalletRepository $pallets,
        private readonly OrderRepository $orders,
        private readonly NumberSequenceRepository $sequences,
        private readonly PalletTypeService $palletTypes,
        private readonly SettingService $settings
    ) {
    }

    /**
     * Il piano proposto per un giorno di consegna (solo calcolo: non scrive niente).
     *
     * @param string $date giorno di consegna, AAAA-MM-GG
     * @throws ValidationException se la data non è valida
     */
    public function plan(string $date): TripPlan
    {
        return $this->compute($this->validDate($date));
    }

    /**
     * Conferma il piano di un giorno, tutto o niente: crea i viaggi, prenota i bancali e segna le
     * richieste come pianificate.
     *
     * @param string                 $fingerprint l'impronta del piano che l'utente ha visto (TripPlan::fingerprint())
     * @param DateTimeImmutable|null $now         l'ora corrente; si passa solo nei test
     * @return list<int> gli id dei viaggi creati
     * @throws ValidationException  se la data non è valida
     * @throws PlanChangedException se il piano non è più quello che l'utente ha visto
     */
    public function confirm(string $date, string $fingerprint, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $date = $this->validDate($date);

        return $this->database->transaction(function () use ($date, $fingerprint, $userId, $now): array {
            // Deve essere la PRIMA lettura della transazione. MySQL "fotografa" i dati alla prima lettura
            // normale: bloccando prima le richieste, la fotografia parte quando nessun altro le sta
            // modificando o confermando, e il piano ricalcolato qui sotto è quello vero.
            $this->orders->lockByDeliveryDate($date);

            $plan = $this->compute($date);

            if (!$plan->hasTrips()) {
                throw new PlanChangedException('Non c\'è nessun viaggio da confermare per questo giorno.');
            }
            // hash_equals confronta due impronte senza far capire, dal tempo impiegato, quanto sono simili.
            if (!hash_equals($plan->fingerprint(), $fingerprint)) {
                throw new PlanChangedException();
            }

            $this->reservePallets($plan);

            $year = (int) $now->format('Y');
            $tripIds = [];
            $orderIds = [];

            foreach ($plan->trips as $trip) {
                $tripId = $this->trips->insert(
                    sprintf('VIA-%d-%06d', $year, $this->sequences->next('trip', $year)),
                    $trip->roundId,
                    $trip->vehicle->id,
                    $trip->vehicle->carrierId,
                    // Il compenso si copia sul viaggio: se domani la tariffa cambia, questo viaggio resta com'era.
                    $trip->vehicle->carrierFee,
                    $date,
                    Trip::STATUS_PLANNED,
                    $userId
                );

                foreach ($trip->assignments as $position => $assignment) {
                    $this->trips->insertItem(
                        $tripId,
                        $assignment->pallet->id,
                        $assignment->demand->orderId,
                        $assignment->demand->orderLineId,
                        // Il bancale parte intero: quantità e peso sono quelli del bancale in questo momento.
                        $assignment->pallet->quantity,
                        $assignment->pallet->weightKg,
                        $position + 1
                    );
                    $orderIds[$assignment->demand->orderId] = true;
                }

                $tripIds[] = $tripId;
            }

            foreach (array_keys($orderIds) as $orderId) {
                $this->orders->setStatus($orderId, Order::STATUS_PLANNED);
            }

            return $tripIds;
        });
    }

    /**
     * Annulla un viaggio pianificato: i bancali tornano liberi e le richieste che non hanno più
     * niente su altri viaggi tornano "aperte" (così rientrano nel prossimo piano).
     *
     * @throws TripNotCancellableException se il viaggio non esiste o non è più "pianificato"
     */
    public function cancel(int $tripId): void
    {
        $this->database->transaction(function () use ($tripId): void {
            // Prima le richieste, come nella conferma: stesso ordine dei blocchi ovunque.
            $orderStatuses = $this->orders->lockByTrip($tripId);

            if (!$this->trips->changeStatus($tripId, Trip::STATUS_PLANNED, Trip::STATUS_CANCELLED)) {
                throw new TripNotCancellableException('Si possono annullare solo i viaggi pianificati, non ancora partiti.');
            }

            $this->pallets->releaseOfTrip($tripId);

            foreach ($orderStatuses as $orderId => $status) {
                if ($status === Order::STATUS_PLANNED && !$this->trips->orderHasActiveItems($orderId)) {
                    $this->orders->setStatus($orderId, Order::STATUS_OPEN);
                }
            }
        });
    }

    /**
     * @return list<Trip> gli ultimi viaggi, i più recenti per primi
     */
    public function recent(): array
    {
        return $this->trips->latest();
    }

    /**
     * I viaggi già confermati (non annullati) di un giorno.
     *
     * @return list<Trip>
     * @throws ValidationException se la data non è valida
     */
    public function tripsOn(string $date): array
    {
        return $this->trips->onDate($this->validDate($date));
    }

    public function find(int $id): ?Trip
    {
        return $this->trips->findById($id);
    }

    /**
     * @return list<TripItem>
     */
    public function itemsOf(int $tripId): array
    {
        return $this->trips->itemsOf($tripId);
    }

    /**
     * I giorni di consegna che hanno ancora qualcosa da pianificare.
     *
     * @return list<PlanningDay>
     */
    public function planningDays(): array
    {
        return $this->planning->planningDays();
    }

    /**
     * Quanti mezzi attivi il pianificatore non può usare perché non hanno un tipo di rimorchio.
     */
    public function vehiclesWithoutTrailerType(): int
    {
        return $this->planning->countActiveVehiclesWithoutTrailerType();
    }

    /**
     * Legge i dati del momento e li passa al pianificatore.
     */
    private function compute(string $date): TripPlan
    {
        $demands = $this->planning->openDemands($date);

        $productIds = [];
        foreach ($demands as $demand) {
            $productIds[$demand->productId] = $demand->productId;
        }

        $pallets = $productIds === []
            ? []
            : $this->planning->availablePallets(
                array_values($productIds),
                $this->fallbackPalletTypeId(),
                $this->settings->getInt(SettingService::PALLET_MAX_HEIGHT_MM)
            );

        $planner = new TripPlanner(new LoadCalculator($this->settings->getInt(SettingService::MAX_STACK_LEVELS)));

        return $planner->plan($date, $demands, $pallets, $this->planning->freeVehicles($date));
    }

    /**
     * Prenota tutti i bancali del piano. Si procede in ordine di id: due conferme di giorni diversi
     * che vogliono gli stessi bancali li chiedono nello stesso ordine e si mettono in fila (niente
     * deadlock); la seconda trova il bancale già preso e si ferma qui, annullando tutto.
     *
     * @throws PlanChangedException se un bancale non è più libero
     */
    private function reservePallets(TripPlan $plan): void
    {
        $palletIds = [];
        foreach ($plan->trips as $trip) {
            foreach ($trip->assignments as $assignment) {
                $palletIds[] = $assignment->pallet->id;
            }
        }
        sort($palletIds);

        foreach ($palletIds as $palletId) {
            if (!$this->pallets->reserve($palletId)) {
                throw new PlanChangedException();
            }
        }
    }

    /**
     * Il tipo di bancale da usare per i bancali che non ne hanno uno (arrivi registrati prima che
     * esistessero i tipi): il più grande come superficie, così lo spazio non viene mai sottostimato.
     * A parità di superficie vince l'id più basso, per avere sempre lo stesso risultato.
     */
    private function fallbackPalletTypeId(): int
    {
        $largest = null;

        foreach ($this->palletTypes->search() as $type) {
            $area = $type->lengthMm * $type->widthMm;

            if (
                $largest === null
                || $area > $largest['area']
                || ($area === $largest['area'] && $type->id < $largest['id'])
            ) {
                $largest = ['id' => $type->id, 'area' => $area];
            }
        }

        if ($largest === null) {
            // Dall'applicazione non può succedere: i tipi di bancale si disattivano, non si cancellano.
            throw new LogicException('Nessun tipo di bancale definito: impossibile calcolare gli ingombri.');
        }

        return $largest['id'];
    }

    /**
     * @throws ValidationException
     */
    private function validDate(string $date): string
    {
        $validator = new Validator(['date' => $date]);
        $date = $validator->requiredDate('date', 'Giorno');
        $validator->assertValid();

        return $date;
    }
}
