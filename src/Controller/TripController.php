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
use LogiDock\Service\PlanChangedException;
use LogiDock\Service\TripNotCancellableException;
use LogiDock\Service\TripService;

/**
 * Viaggi: il piano di un giorno (proposto dal programma), la sua conferma e i viaggi confermati.
 * Li usa qualunque utente collegato. Controller sottile: le regole stanno in TripService.
 */
final class TripController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly TripService $trips
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

        if ($date === '') {
            // Senza un giorno scelto si parte dal primo che ha qualcosa da pianificare, altrimenti da oggi.
            $days = $this->trips->planningDays();
            $date = $days === [] ? date('Y-m-d') : $days[0]->date;
        }

        try {
            $plan = $this->trips->plan($date);
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

        try {
            $tripIds = $this->trips->confirm($date, $fingerprint, $user->id);
        } catch (ValidationException $e) {
            return $this->refuse('/trips', $e->errors()['date'] ?? 'Il giorno indicato non è valido.');
        } catch (PlanChangedException $e) {
            // Si torna sul piano di quel giorno: la pagina lo ricalcola e mostra quello aggiornato.
            return $this->refuse($this->planUrl($date), $e->getMessage());
        }

        $this->session->flash(
            'success',
            count($tripIds) === 1
                ? 'Viaggio confermato: i bancali sono prenotati e le richieste pianificate.'
                : count($tripIds) . ' viaggi confermati: i bancali sono prenotati e le richieste pianificate.'
        );

        return Response::redirect($this->planUrl($date));
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $trip = $this->findOrFail($params);

        return Response::html($this->view->render('trips/show', [
            'title' => 'Viaggio ' . $trip->code,
            'trip' => $trip,
            'items' => $this->trips->itemsOf($trip->id),
        ]));
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
    private function planUrl(string $date): string
    {
        return '/trips/plan?date=' . rawurlencode($date);
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
