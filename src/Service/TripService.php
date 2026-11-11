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
use LogiDock\Model\PlanPallet;
use LogiDock\Model\PlanVehicle;
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
 *
 * Scelte a mano su un viaggio ancora pianificato: spostare un bancale su un altro viaggio dello stesso
 * giro (o su uno nuovo), bloccarlo o sbloccarlo, cambiare il mezzo. Un bancale spostato si blocca e il
 * ricalcolo del giorno (confirm con $replan) non lo sposta mai. Anche qui i blocchi si prendono nello
 * stesso ordine: prima le richieste dei viaggi coinvolti, poi l'eventuale mezzo.
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
     * @param string $date   giorno di consegna, AAAA-MM-GG
     * @param bool   $replan true = ricalcolo: i bancali non bloccati dei viaggi già confermati
     *                       vengono riassegnati, quelli bloccati a mano restano dove sono
     * @throws ValidationException se la data non è valida
     */
    public function plan(string $date, bool $replan = false): TripPlan
    {
        return $this->compute($this->validDate($date), $replan);
    }

    /**
     * Conferma il piano di un giorno, tutto o niente: crea i viaggi, prenota i bancali e segna le
     * richieste come pianificate.
     *
     * Con $replan = true (ricalcolo) si conferma il piano di ricalcolo: i bancali non bloccati dei
     * viaggi già confermati vengono prima tolti dai loro viaggi e rimessi in magazzino, poi si scrive
     * il piano. I bancali bloccati a mano non si toccano mai.
     *
     * @param string                 $fingerprint l'impronta del piano che l'utente ha visto (TripPlan::fingerprint())
     * @param DateTimeImmutable|null $now         l'ora corrente; si passa solo nei test
     * @param bool                   $replan      true = è il piano di ricalcolo quello che l'utente ha visto
     * @return list<int> gli id dei viaggi creati o modificati
     * @throws ValidationException  se la data non è valida
     * @throws PlanChangedException se il piano non è più quello che l'utente ha visto
     */
    public function confirm(
        string $date,
        string $fingerprint,
        int $userId,
        ?DateTimeImmutable $now = null,
        bool $replan = false
    ): array {
        $now ??= new DateTimeImmutable('now');
        $date = $this->validDate($date);

        return $this->database->transaction(function () use ($date, $fingerprint, $userId, $now, $replan): array {
            // Deve essere la PRIMA lettura della transazione. MySQL "fotografa" i dati alla prima lettura
            // normale: bloccando prima le richieste, la fotografia parte quando nessun altro le sta
            // modificando o confermando, e il piano ricalcolato qui sotto è quello vero.
            $orderStatuses = $this->orders->lockByDeliveryDate($date);

            $plan = $this->compute($date, $replan);

            if (!$plan->hasTrips()) {
                throw new PlanChangedException('Non c\'è nessun viaggio da confermare per questo giorno.');
            }
            // hash_equals confronta due impronte senza far capire, dal tempo impiegato, quanto sono simili.
            if (!hash_equals($plan->fingerprint(), $fingerprint)) {
                throw new PlanChangedException();
            }

            if ($replan) {
                $this->releaseUnlocked($plan);
            }
            $this->reservePallets($plan);

            $year = (int) $now->format('Y');
            $tripIds = [];

            foreach ($plan->trips as $trip) {
                if ($trip->isExisting()) {
                    $tripId = (int) $trip->tripId;

                    if ($trip->assignments === []) {
                        // Nel ricalcolo un viaggio può restare senza bancali: è già stato annullato (releaseUnlocked).
                        continue;
                    }
                } else {
                    $tripId = $this->insertTrip($trip->roundId, $trip->vehicle, $date, $userId, $year);
                }

                foreach ($trip->assignments as $position => $assignment) {
                    if ($assignment->fixed) {
                        continue;
                    }

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
                }

                $tripIds[] = $tripId;
            }

            $this->syncOrderStatuses($orderStatuses);

            return $tripIds;
        });
    }

    /**
     * I viaggi pianificati dello stesso giro e dello stesso giorno su cui si può spostare un bancale
     * di questo viaggio.
     *
     * @return list<Trip>
     */
    public function moveTargets(Trip $trip): array
    {
        return array_values(array_filter(
            $this->trips->onDate($trip->plannedDate),
            static fn (Trip $other): bool => $other->id !== $trip->id
                && $other->roundId === $trip->roundId
                && $other->isEditable()
        ));
    }

    /**
     * I mezzi liberi in un giorno (attivi, con un tipo di rimorchio, senza altri viaggi).
     *
     * @return list<PlanVehicle>
     * @throws ValidationException se la data non è valida
     */
    public function freeVehiclesOn(string $date): array
    {
        return $this->planning->freeVehicles($this->validDate($date));
    }

    /**
     * Sposta a mano un bancale su un altro viaggio dello stesso giro e dello stesso giorno. Il bancale
     * si BLOCCA: il ricalcolo del giorno non lo sposta più.
     *
     * @throws TripChangeNotAllowedException se non si può (viaggi non pianificati, giri diversi, il carico non entra...)
     */
    public function movePalletToTrip(
        int $tripId,
        int $palletId,
        int $targetTripId,
        int $userId,
        ?DateTimeImmutable $now = null
    ): void {
        $now ??= new DateTimeImmutable('now');

        if ($tripId === $targetTripId) {
            throw new TripChangeNotAllowedException('Il bancale è già su questo viaggio.');
        }

        $this->database->transaction(function () use ($tripId, $palletId, $targetTripId, $userId, $now): void {
            // Prima le richieste dei due viaggi (un solo comando, in ordine di id), poi tutto il resto.
            $this->orders->lockByTrips([$tripId, $targetTripId]);

            $source = $this->editableTrip($tripId);
            $target = $this->editableTrip($targetTripId);

            if ($source->plannedDate !== $target->plannedDate || $source->roundId !== $target->roundId) {
                throw new TripChangeNotAllowedException(
                    'Un bancale si può spostare solo su un altro viaggio dello stesso giro e dello stesso giorno.'
                );
            }

            $moving = $this->palletLeaving($source, $palletId);
            $vehicle = $this->planning->vehicle($target->vehicleId)
                ?? throw new TripChangeNotAllowedException('Il mezzo di quel viaggio non ha più un tipo di rimorchio.');
            $this->assertFits($vehicle, [...$this->palletsOfTrip($targetTripId), $moving]);

            $this->moveItem($tripId, $palletId, $targetTripId, $userId, $now);
        });
    }

    /**
     * Sposta a mano un bancale su un NUOVO viaggio dello stesso giro, con un mezzo libero. Il bancale
     * si blocca, come in movePalletToTrip.
     *
     * @return int l'id del nuovo viaggio
     * @throws TripChangeNotAllowedException se non si può
     */
    public function movePalletToNewTrip(
        int $tripId,
        int $palletId,
        int $vehicleId,
        int $userId,
        ?DateTimeImmutable $now = null
    ): int {
        $now ??= new DateTimeImmutable('now');

        return $this->database->transaction(function () use ($tripId, $palletId, $vehicleId, $userId, $now): int {
            $this->orders->lockByTrip($tripId);

            $source = $this->editableTrip($tripId);
            $moving = $this->palletLeaving($source, $palletId);
            $vehicle = $this->claimFreeVehicle($vehicleId, $source->plannedDate);
            $this->assertFits($vehicle, [$moving]);

            $newTripId = $this->insertTrip(
                $source->roundId,
                $vehicle,
                $source->plannedDate,
                $userId,
                (int) $now->format('Y')
            );
            $this->moveItem($tripId, $palletId, $newTripId, $userId, $now);

            return $newTripId;
        });
    }

    /**
     * Cambia il mezzo di un viaggio pianificato con un altro mezzo libero quel giorno, se il carico
     * ci sta. Il trasportatore e il suo compenso si aggiornano a quelli del nuovo mezzo.
     *
     * @throws TripChangeNotAllowedException se non si può
     */
    public function changeVehicle(int $tripId, int $vehicleId): void
    {
        $this->database->transaction(function () use ($tripId, $vehicleId): void {
            $this->orders->lockByTrip($tripId);

            $trip = $this->editableTrip($tripId);

            if ($trip->vehicleId === $vehicleId) {
                throw new TripChangeNotAllowedException('Il viaggio usa già questo mezzo.');
            }

            $vehicle = $this->claimFreeVehicle($vehicleId, $trip->plannedDate);
            $this->assertFits($vehicle, $this->palletsOfTrip($tripId), 'Il carico non entra su ' . $vehicle->plate);

            $this->trips->changeVehicle($tripId, $vehicle->id, $vehicle->carrierId, $vehicle->carrierFee);
        });
    }

    /**
     * Blocca (o sblocca) un bancale su un viaggio pianificato. Un bancale bloccato non viene spostato
     * dal ricalcolo del giorno.
     *
     * @throws TripChangeNotAllowedException se il viaggio non è pianificato o il bancale non è lì
     */
    public function setPalletLock(
        int $tripId,
        int $palletId,
        bool $locked,
        int $userId,
        ?DateTimeImmutable $now = null
    ): void {
        $now ??= new DateTimeImmutable('now');

        $this->database->transaction(function () use ($tripId, $palletId, $locked, $userId, $now): void {
            $this->orders->lockByTrip($tripId);
            $this->editableTrip($tripId);

            if (!$this->trips->setItemLock($tripId, $palletId, $locked, $userId, $now->format('Y-m-d H:i:s'))) {
                throw new TripChangeNotAllowedException('Il bancale non è su questo viaggio.');
            }
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
    private function compute(string $date, bool $replan = false): TripPlan
    {
        $demands = $this->planning->openDemands($date, $replan);

        $productIds = [];
        foreach ($demands as $demand) {
            $productIds[$demand->productId] = $demand->productId;
        }

        $fallbackTypeId = $this->fallbackPalletTypeId();
        $defaultHeightMm = $this->settings->getInt(SettingService::PALLET_MAX_HEIGHT_MM);

        $pallets = $productIds === []
            ? []
            : $this->planning->availablePallets(
                array_values($productIds),
                $fallbackTypeId,
                $defaultHeightMm,
                $replan ? $date : null
            );

        return (new TripPlanner($this->calculator()))->plan(
            $date,
            $demands,
            $pallets,
            $this->planning->freeVehicles($date),
            $this->planning->seedTrips($date, $fallbackTypeId, $defaultHeightMm),
            $replan
        );
    }

    private function calculator(): LoadCalculator
    {
        return new LoadCalculator($this->settings->getInt(SettingService::MAX_STACK_LEVELS));
    }

    /**
     * Crea un viaggio pianificato, con il codice successivo e la fotografia del trasportatore.
     */
    private function insertTrip(int $roundId, PlanVehicle $vehicle, string $date, int $userId, int $year): int
    {
        return $this->trips->insert(
            sprintf('VIA-%d-%06d', $year, $this->sequences->next('trip', $year)),
            $roundId,
            $vehicle->id,
            $vehicle->carrierId,
            // Il compenso si copia sul viaggio: se domani la tariffa cambia, questo viaggio resta com'era.
            $vehicle->carrierFee,
            $date,
            Trip::STATUS_PLANNED,
            $userId
        );
    }

    /**
     * Il viaggio, solo se è ancora pianificato (le scelte a mano valgono finché non è partito).
     * Va chiamata dopo aver bloccato le richieste del viaggio: la lettura parte da lì.
     *
     * @throws TripChangeNotAllowedException
     */
    private function editableTrip(int $tripId): Trip
    {
        $trip = $this->trips->findById($tripId);

        if ($trip === null || !$trip->isEditable()) {
            throw new TripChangeNotAllowedException('Si possono modificare solo i viaggi pianificati, non ancora partiti.');
        }

        return $trip;
    }

    /**
     * Il bancale che si vuole togliere da un viaggio, se può andarsene: deve essere sul viaggio e il
     * viaggio non deve restare vuoto (un viaggio senza bancali si annulla).
     *
     * @throws TripChangeNotAllowedException
     */
    private function palletLeaving(Trip $source, int $palletId): PlanPallet
    {
        $onTrip = false;
        foreach ($this->trips->itemsOf($source->id) as $item) {
            $onTrip = $onTrip || $item->palletId === $palletId;
        }

        if (!$onTrip) {
            throw new TripChangeNotAllowedException('Il bancale non è su questo viaggio.');
        }
        if ($source->palletCount < 2) {
            throw new TripChangeNotAllowedException('È l\'ultimo bancale del viaggio: se non serve più, annulla il viaggio.');
        }

        foreach ($this->palletsOfTrip($source->id) as $pallet) {
            if ($pallet->id === $palletId) {
                return $pallet;
            }
        }

        // Il bancale è tra le righe del viaggio, quindi esiste: non può succedere.
        throw new LogicException('Bancale ' . $palletId . ' non trovato sul viaggio ' . $source->id);
    }

    /**
     * @return list<PlanPallet>
     */
    private function palletsOfTrip(int $tripId): array
    {
        return $this->planning->palletsOfTrip(
            $tripId,
            $this->fallbackPalletTypeId(),
            $this->settings->getInt(SettingService::PALLET_MAX_HEIGHT_MM)
        );
    }

    /**
     * @param list<PlanPallet> $pallets il carico che si vuole mettere sul mezzo
     * @param string|null      $prefix  l'inizio del messaggio, se diverso dal solito
     * @throws TripChangeNotAllowedException se il carico non entra
     */
    private function assertFits(PlanVehicle $vehicle, array $pallets, ?string $prefix = null): void
    {
        $usage = $this->calculator()->usage($vehicle, $pallets);

        if (!$usage->fits()) {
            throw new TripChangeNotAllowedException(
                ($prefix ?? 'Il bancale non entra su ' . $vehicle->plate) . ': ' . lcfirst((string) $usage->problem) . '.'
            );
        }
    }

    /**
     * Prende un mezzo che quel giorno è libero. Si blocca la riga del mezzo e si ricontrolla con una
     * lettura "fresca": due persone che scelgono lo stesso mezzo nello stesso momento passano una alla volta.
     *
     * @throws TripChangeNotAllowedException
     */
    private function claimFreeVehicle(int $vehicleId, string $date): PlanVehicle
    {
        $this->trips->lockVehicle($vehicleId);

        $vehicle = null;
        foreach ($this->planning->freeVehicles($date) as $candidate) {
            if ($candidate->id === $vehicleId) {
                $vehicle = $candidate;
            }
        }

        if ($vehicle === null || $this->trips->vehicleIsBusy($vehicleId, $date)) {
            throw new TripChangeNotAllowedException('Il mezzo scelto non è libero in quel giorno.');
        }

        return $vehicle;
    }

    private function moveItem(int $fromTripId, int $palletId, int $toTripId, int $userId, DateTimeImmutable $now): void
    {
        if (!$this->trips->moveItem($fromTripId, $palletId, $toTripId, $userId, $now->format('Y-m-d H:i:s'))) {
            throw new TripChangeNotAllowedException('Il bancale non è su questo viaggio.');
        }
    }

    /**
     * Ricalcolo: toglie dai viaggi che il piano cambia i bancali non bloccati e li rimette in
     * magazzino (il piano poi li prenota di nuovo, dove vanno). I viaggi che il piano non cambia
     * non si toccano. Gli id vanno in ordine crescente, come in ogni altro punto che tocca i bancali.
     */
    private function releaseUnlocked(TripPlan $plan): void
    {
        $tripIds = [];
        $emptiedIds = [];
        foreach ($plan->trips as $trip) {
            if ($trip->tripId === null) {
                continue;
            }

            if ($trip->assignments === []) {
                $emptiedIds[] = $trip->tripId;
            } else {
                $tripIds[] = $trip->tripId;
            }
        }

        // Un viaggio che resta senza bancali si annulla come ogni altro: le sue righe restano come storico
        // e i suoi bancali tornano liberi.
        foreach ($emptiedIds as $tripId) {
            $this->trips->changeStatus($tripId, Trip::STATUS_PLANNED, Trip::STATUS_CANCELLED);
            $this->pallets->releaseOfTrip($tripId);
        }

        if ($tripIds === []) {
            return;
        }

        $palletIds = $this->trips->unlockedPalletIdsOfTrips($tripIds);
        $this->trips->deleteUnlockedItemsOfTrips($tripIds);
        $this->pallets->releaseByIds($palletIds);
    }

    /**
     * Dopo una conferma ogni richiesta del giorno deve avere lo stato giusto: "pianificata" se ha
     * bancali su un viaggio non annullato, "aperta" altrimenti (nel ricalcolo può succedere di perderli).
     *
     * @param array<int, string> $statuses id della richiesta => stato prima della conferma
     */
    private function syncOrderStatuses(array $statuses): void
    {
        foreach ($statuses as $orderId => $status) {
            $hasItems = $this->trips->orderHasActiveItems($orderId);

            if ($hasItems && $status === Order::STATUS_OPEN) {
                $this->orders->setStatus($orderId, Order::STATUS_PLANNED);
            } elseif (!$hasItems && $status === Order::STATUS_PLANNED) {
                $this->orders->setStatus($orderId, Order::STATUS_OPEN);
            }
        }
    }

    /**
     * Prenota tutti i bancali nuovi del piano (quelli già sui viaggi sono già prenotati). Si procede
     * in ordine di id: due conferme di giorni diversi che vogliono gli stessi bancali li chiedono
     * nello stesso ordine e si mettono in fila (niente deadlock); la seconda trova il bancale già
     * preso e si ferma qui, annullando tutto.
     *
     * @throws PlanChangedException se un bancale non è più libero
     */
    private function reservePallets(TripPlan $plan): void
    {
        $palletIds = [];
        foreach ($plan->trips as $trip) {
            foreach ($trip->newAssignments() as $assignment) {
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
