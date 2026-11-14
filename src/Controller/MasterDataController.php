<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\Session;
use LogiDock\Core\ValidationException;
use LogiDock\Core\View;
use LogiDock\Enum\UserRole;
use LogiDock\Service\AuthService;
use LogiDock\Service\MasterDataService;

/**
 * Flusso comune delle anagrafiche: lista con ricerca, nuovo, modifica, disattiva/riattiva.
 * Resta un controller sottile: legge la richiesta, chiama il service, sceglie la risposta.
 * Ogni anagrafica aggiunge solo i suoi campi e i suoi nomi (vedi i metodi astratti).
 *
 * I template stanno in templates/<cartella>/index.php e form.php, dove <cartella> è path() senza "/".
 * Chi può fare cosa lo decidono i middleware in config/routes.php, non questa classe.
 */
abstract class MasterDataController
{
    public function __construct(
        protected readonly View $view,
        protected readonly Session $session,
        protected readonly AuthService $auth
    ) {
    }

    abstract protected function service(): MasterDataService;

    /** Percorso base delle rotte, es. "/suppliers". */
    abstract protected function path(): string;

    /**
     * Nomi per i titoli e i messaggi. "ending" è la desinenza italiana ("o" cliente, "a" posizione).
     *
     * @return array{singular: string, plural: string, ending: string}
     */
    abstract protected function labels(): array;

    /**
     * Nomi dei campi del form.
     *
     * @return list<string>
     */
    abstract protected function fields(): array;

    /**
     * Valori da mettere nei campi quando si modifica un record (sempre stringhe).
     *
     * @return array<string, string>
     */
    abstract protected function valuesOf(object $entity): array;

    /** Come chiamare il record nei messaggi ("Fornitore «Rossi» disattivato"). */
    abstract protected function nameOf(object $entity): string;

    /**
     * Dati in più per il form, es. le opzioni di un menu a tendina.
     *
     * @return array<string, mixed>
     */
    protected function formExtras(?object $current): array
    {
        return [];
    }

    /**
     * Voci di un menu a tendina (id => testo): gli elementi attivi, più quello già assegnato al record
     * che si sta modificando, anche se nel frattempo è stato disattivato (con la nota "disattivato").
     *
     * @param MasterDataService<object> $source   anagrafica da cui prendere le voci
     * @param callable(object): string  $label    come mostrare una voce
     * @return array<string, string>
     */
    protected function options(MasterDataService $source, ?int $currentId, callable $label): array
    {
        $options = [];
        foreach ($source->activeOnes() as $item) {
            $options[(string) $item->id] = $label($item);
        }

        if ($currentId !== null && !isset($options[(string) $currentId])) {
            $current = $source->find($currentId);
            if ($current !== null) {
                $options[(string) $currentId] = $label($current) . ' (disattivato)';
            }
        }

        return $options;
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return Response::html($this->view->render($this->folder() . '/index', [
            'title' => $this->labels()['plural'],
            'items' => $this->service()->search($search),
            'search' => $search,
            'canManage' => $this->auth->hasRole(UserRole::Admin),
            'path' => $this->path(),
            'labels' => $this->labels(),
        ]));
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        return $this->renderForm(
            'Nuov' . $this->labels()['ending'] . ' ' . lcfirst($this->labels()['singular']),
            $this->path(),
            array_fill_keys($this->fields(), ''),
            null
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        try {
            $this->service()->create($request->allInput());
        } catch (ValidationException $e) {
            return $this->renderForm(
                'Nuov' . $this->labels()['ending'] . ' ' . lcfirst($this->labels()['singular']),
                $this->path(),
                $this->formValues($request),
                null,
                $e->errors(),
                422
            );
        }

        $this->session->flash('success', $this->labels()['singular'] . ' creat' . $this->labels()['ending'] . '.');

        return Response::redirect($this->path());
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $entity = $this->findOrFail($params);

        return $this->renderForm(
            'Modifica ' . lcfirst($this->labels()['singular']),
            $this->path() . '/' . $entity->id,
            $this->valuesOf($entity),
            $entity
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $entity = $this->findOrFail($params);

        try {
            $this->service()->update($entity->id, $request->allInput());
        } catch (ValidationException $e) {
            return $this->renderForm(
                'Modifica ' . lcfirst($this->labels()['singular']),
                $this->path() . '/' . $entity->id,
                $this->formValues($request),
                $entity,
                $e->errors(),
                422
            );
        }

        $this->session->flash('success', $this->labels()['singular'] . ' aggiornat' . $this->labels()['ending'] . '.');

        return Response::redirect($this->path());
    }

    /**
     * @param array<string, string> $params
     */
    public function deactivate(Request $request, array $params): Response
    {
        $entity = $this->findOrFail($params);
        $this->service()->deactivate($entity->id);
        $this->session->flash('success', $this->describe($entity, 'disattivat'));

        return Response::redirect($this->path());
    }

    /**
     * @param array<string, string> $params
     */
    public function activate(Request $request, array $params): Response
    {
        $entity = $this->findOrFail($params);
        $this->service()->activate($entity->id);
        $this->session->flash('success', $this->describe($entity, 'riattivat'));

        return Response::redirect($this->path());
    }

    private function describe(object $entity, string $verb): string
    {
        return $this->labels()['singular'] . ' «' . $this->nameOf($entity) . '» ' . $verb . $this->labels()['ending'] . '.';
    }

    private function folder(): string
    {
        return ltrim($this->path(), '/');
    }

    /**
     * @param array<string, string> $params
     */
    private function findOrFail(array $params): object
    {
        $id = $params['id'] ?? '';

        // Un id non numerico o inesistente è semplicemente una pagina che non c'è.
        $entity = ctype_digit($id) ? $this->service()->find((int) $id) : null;
        if ($entity === null) {
            throw new HttpException(404, $this->labels()['singular'] . ' non trovat' . $this->labels()['ending'] . ': ' . $id);
        }

        return $entity;
    }

    /**
     * Solo i campi del form, sempre come stringhe: sono i valori da rimettere nei campi dopo un errore.
     *
     * @return array<string, string>
     */
    private function formValues(Request $request): array
    {
        $values = [];
        foreach ($this->fields() as $field) {
            $input = $request->input($field, '');
            $values[$field] = is_scalar($input) ? (string) $input : '';
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(
        string $title,
        string $action,
        array $values,
        ?object $current,
        array $errors = [],
        int $status = 200
    ): Response {
        return Response::html($this->view->render($this->folder() . '/form', [
            'title' => $title,
            'action' => $action,
            'values' => $values,
            'errors' => $errors,
            'path' => $this->path(),
            // Serve alle briciole di pane (il nome al plurale dell'anagrafica).
            'labels' => $this->labels(),
            ...$this->formExtras($current),
        ]), $status);
    }
}
