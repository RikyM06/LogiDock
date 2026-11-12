<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\Session;
use LogiDock\Core\ValidationException;
use LogiDock\Core\View;
use LogiDock\Model\Trip;
use LogiDock\Service\AuthService;
use LogiDock\Service\DocumentService;
use LogiDock\Service\PlanChangedException;
use LogiDock\Service\TripChangeNotAllowedException;
use LogiDock\Service\TripDepartureService;
use LogiDock\Service\TripNotCancellableException;
use LogiDock\Service\TripService;

/**
 * Viaggi: il piano di un giorno (proposto dal programma), la sua conferma, i viaggi confermati e la loro
 * partenza (con i documenti). Li usa qualunque utente collegato. Controller sottile: le regole stanno in
 * TripService (pianificazione), TripDepartureService (partenza) e DocumentService (documenti).
 */
final class TripController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly TripService $trips,
        private readonly TripDepartureService $departures,
        private readonly DocumentService $documents
    ) {
    }

    /**
     * I giorni che hanno ancora qualcosa da pianificare e gli ultimi viaggi confermati.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return Response::html($this->view->render('trips/index', [
            'title' => 'Viaggi',
            'days' => $this->trips->planningDays(),
            'items' => $this->trips->recent(),
            'today' => date('Y-m-d'),
        ]));
    }

    /**
     * Il piano proposto per un giorno. Si ricalcola a ogni apertura: non c'è niente di salvato.
     *
     * @param array<string, string> $params
     */
    public function plan(Request $request, array $params): Response
    {
        $date = $request->query('date', '');
        $date = is_string($date) ? trim($date) : '';
        // ?ricalcola=1 = il piano di ricalcolo: i bancali non bloccati dei viaggi già confermati si riassegnano.
        $replan = $request->query('ricalcola', '') === '1';

        if ($date === '') {
            // Senza un giorno scelto si parte dal primo che ha qualcosa da pianificare, altrimenti da oggi.
            $days = $this->trips->planningDays();
            $date = $days === [] ? date('Y-m-d') : $days[0]->date;
        }

        try {
            $plan = $this->trips->plan($date, $replan);
            $confirmedTrips = $this->trips->tripsOn($date);
        } catch (ValidationException $e) {
            return $this->refuse('/trips', $e->errors()['date'] ?? 'Il giorno indicato non è valido.');
        }

        return Response::html($this->view->render('trips/plan', [
            'title' => 'Piano del ' . date('d/m/Y', strtotime($plan->date)),
            'wide' => true,
            'plan' => $plan,
            'confirmedTrips' => $confirmedTrips,
            'vehiclesWithoutTrailerType' => $this->trips->vehiclesWithoutTrailerType(),
        ]));
    }

    /**
     * Conferma il piano che l'utente ha davanti (lo riconosce dall'impronta mandata dal form).
     *
     * @param array<string, string> $params
     */
    public function confirm(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');

        $date = $request->input('date', '');
        $date = is_string($date) ? trim($date) : '';
        $fingerprint = $request->input('fingerprint', '');
        $fingerprint = is_string($fingerprint) ? $fingerprint : '';
        $replan = $request->input('mode', '') === 'replan';

        try {
            $tripIds = $this->trips->confirm($date, $fingerprint, $user->id, null, $replan);
        } catch (ValidationException $e) {
            return $this->refuse('/trips', $e->errors()['date'] ?? 'Il giorno indicato non è valido.');
        } catch (PlanChangedException $e) {
            // Si torna sul piano di quel giorno: la pagina lo ricalcola e mostra quello aggiornato.
            return $this->refuse($this->planUrl($date, $replan), $e->getMessage());
        }

        $this->session->flash(
            'success',
            match (true) {
                $replan => 'Piano ricalcolato e confermato: i bancali bloccati a mano sono rimasti dove li avevi messi.',
                count($tripIds) === 1 => 'Viaggio confermato: i bancali sono prenotati e le richieste pianificate.',
                default => count($tripIds) . ' viaggi confermati: i bancali sono prenotati e le richieste pianificate.',
            }
        );

        return Response::redirect($this->planUrl($date));
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $trip = $this->findOrFail($params);
        $user = $this->auth->user();

        return Response::html($this->view->render('trips/show', [
            'title' => 'Viaggio ' . $trip->code,
            'trip' => $trip,
            'items' => $this->trips->itemsOf($trip->id),
            // Il prospetto del compenso lo vede solo l'admin.
            'documents' => $this->documents->forTrip($trip->id, $user !== null && $user->isAdmin()),
            // Le scelte a mano si offrono solo finché il viaggio è pianificato.
            'moveTargets' => $trip->isEditable() ? $this->trips->moveTargets($trip) : [],
            'freeVehicles' => $trip->isEditable() ? $this->trips->freeVehiclesOn($trip->plannedDate) : [],
        ]));
    }

    /**
     * Sposta a mano un bancale: su un altro viaggio dello stesso giro ("trip:12") o su un nuovo
     * viaggio con un mezzo libero ("vehicle:3"). Il bancale si blocca.
     *
     * @param array<string, string> $params
     */
    public function moveItem(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');
        $trip = $this->findOrFail($params);
        $palletId = $this->palletIdOrFail($params);

        $target = $request->input('target', '');
        $target = is_string($target) ? $target : '';

        try {
            if (preg_match('/^trip:(\d+)$/', $target, $match) === 1) {
                $this->trips->movePalletToTrip($trip->id, $palletId, (int) $match[1], $user->id);
                $message = 'Bancale spostato sull\'altro viaggio e bloccato: il ricalcolo del giorno non lo sposterà più.';
            } elseif (preg_match('/^vehicle:(\d+)$/', $target, $match) === 1) {
                $this->trips->movePalletToNewTrip($trip->id, $palletId, (int) $match[1], $user->id);
                $message = 'Bancale spostato su un nuovo viaggio e bloccato: il ricalcolo del giorno non lo sposterà più.';
            } else {
                return $this->refuse('/trips/' . $trip->id, 'Scegli dove spostare il bancale.');
            }
        } catch (TripChangeNotAllowedException $e) {
            return $this->refuse('/trips/' . $trip->id, $e->getMessage());
        }

        $this->session->flash('success', $message);

        return Response::redirect('/trips/' . $trip->id);
    }

    /**
     * Blocca o sblocca un bancale (campo "locked": 1 blocca, 0 sblocca).
     *
     * @param array<string, string> $params
     */
    public function lockItem(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');
        $trip = $this->findOrFail($params);
        $palletId = $this->palletIdOrFail($params);
        $locked = $request->input('locked', '') === '1';

        try {
            $this->trips->setPalletLock($trip->id, $palletId, $locked, $user->id);
        } catch (TripChangeNotAllowedException $e) {
            return $this->refuse('/trips/' . $trip->id, $e->getMessage());
        }

        $this->session->flash(
            'success',
            $locked
                ? 'Bancale bloccato: il ricalcolo del giorno non lo sposterà.'
                : 'Bancale sbloccato: il ricalcolo del giorno può spostarlo.'
        );

        return Response::redirect('/trips/' . $trip->id);
    }

    /**
     * Cambia il mezzo del viaggio con un altro mezzo libero quel giorno.
     *
     * @param array<string, string> $params
     */
    public function changeVehicle(Request $request, array $params): Response
    {
        $trip = $this->findOrFail($params);

        $vehicleId = $request->input('vehicle_id', '');
        if (!is_string($vehicleId) || !ctype_digit($vehicleId)) {
            return $this->refuse('/trips/' . $trip->id, 'Scegli il nuovo mezzo.');
        }

        try {
            $this->trips->changeVehicle($trip->id, (int) $vehicleId);
        } catch (TripChangeNotAllowedException $e) {
            return $this->refuse('/trips/' . $trip->id, $e->getMessage());
        }

        $this->session->flash('success', 'Mezzo cambiato: trasportatore e compenso sono quelli del nuovo mezzo.');

        return Response::redirect('/trips/' . $trip->id);
    }

    /**
     * Fa partire il viaggio: i bancali escono dal magazzino e si emettono i documenti.
     *
     * @param array<string, string> $params
     */
    public function depart(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');
        $trip = $this->findOrFail($params);

        try {
            $documents = $this->departures->depart($trip->id, $user->id);
        } catch (TripChangeNotAllowedException $e) {
            return $this->refuse('/trips/' . $trip->id, $e->getMessage());
        }

        $this->session->flash(
            'success',
            'Viaggio ' . $trip->code . ' partito: i bancali sono usciti dal magazzino e sono stati emessi '
            . (count($documents) === 1 ? '1 documento.' : count($documents) . ' documenti.')
        );

        return Response::redirect('/trips/' . $trip->id . '#documenti');
    }

    /**
     * @param array<string, string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        $trip = $this->findOrFail($params);

        try {
            $this->trips->cancel($trip->id);
        } catch (TripNotCancellableException $e) {
            return $this->refuse('/trips/' . $trip->id, $e->getMessage());
        }

        $this->session->flash(
            'success',
            'Viaggio ' . $trip->code . ' annullato: i bancali sono di nuovo liberi e le richieste tornano da pianificare.'
        );

        return Response::redirect('/trips/' . $trip->id);
    }

    private function refuse(string $url, string $message): Response
    {
        $this->session->flash('error', $message);

        return Response::redirect($url);
    }

    /**
     * La data è già stata controllata dal service; rawurlencode è una sicurezza in più sull'URL.
     */
    private function planUrl(string $date, bool $replan = false): string
    {
        return '/trips/plan?date=' . rawurlencode($date) . ($replan ? '&ricalcola=1' : '');
    }

    /**
     * @param array<string, string> $params
     */
    private function palletIdOrFail(array $params): int
    {
        $id = $params['palletId'] ?? '';
        if (!ctype_digit($id)) {
            throw new HttpException(404, 'Bancale non trovato: ' . $id);
        }

        return (int) $id;
    }

    /**
     * @param array<string, string> $params
     */
    private function findOrFail(array $params): Trip
    {
        $id = $params['id'] ?? '';
        $trip = ctype_digit($id) ? $this->trips->find((int) $id) : null;
        if ($trip === null) {
            throw new HttpException(404, 'Viaggio non trovato: ' . $id);
        }

        return $trip;
    }
}
