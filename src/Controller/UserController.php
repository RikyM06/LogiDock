<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\Session;
use LogiDock\Core\ValidationException;
use LogiDock\Core\View;
use LogiDock\Model\User;
use LogiDock\Service\AuthService;
use LogiDock\Service\UserChangeNotAllowedException;
use LogiDock\Service\UserService;

/**
 * Gestione degli utenti: tutte le rotte sono riservate agli admin dal middleware (config/routes.php).
 * Controller sottile: le regole (anche quelle per non restare senza admin) stanno in UserService.
 */
final class UserController
{
    /** Ruolo => descrizione mostrata nel form. */
    private const ROLE_OPTIONS = [
        'operatore' => 'Operatore: registra arrivi e richieste, consulta il resto',
        'admin' => 'Admin: gestisce anche anagrafiche, impostazioni e utenti',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AuthService $auth,
        private readonly UserService $users
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return Response::html($this->view->render('users/index', [
            'title' => 'Utenti',
            'items' => $this->users->search($search),
            'search' => $search,
            'canManage' => true,
            'path' => '/users',
            'labels' => ['singular' => 'Utente', 'plural' => 'Utenti', 'ending' => 'o'],
            'me' => $this->me(),
        ]));
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        return $this->renderForm(null, ['email' => '', 'name' => '', 'role' => 'operatore']);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        try {
            $id = $this->users->create($request->allInput());
        } catch (ValidationException $e) {
            return $this->renderForm(null, $this->formValues($request, ['email', 'name', 'role']), $e->errors(), 422);
        }

        $this->session->flash('success', 'Utente ' . ($this->users->find($id)?->email ?? '') . ' creato.');

        return Response::redirect('/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $user = $this->findOrFail($params);

        return $this->renderForm($user, ['email' => $user->email, 'name' => $user->name, 'role' => $user->role->value]);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $user = $this->findOrFail($params);

        try {
            $this->users->update($user->id, $request->allInput(), $this->me()->id);
        } catch (ValidationException $e) {
            $values = ['email' => $user->email] + $this->formValues($request, ['name', 'role']);

            return $this->renderForm($user, $values, $e->errors(), 422);
        }

        $this->session->flash('success', 'Utente ' . $user->email . ' aggiornato.');

        return Response::redirect('/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function deactivate(Request $request, array $params): Response
    {
        $user = $this->findOrFail($params);

        try {
            $this->users->deactivate($user->id, $this->me()->id);
        } catch (UserChangeNotAllowedException $e) {
            $this->session->flash('error', $e->getMessage());

            return Response::redirect('/users');
        }

        $this->session->flash('success', 'Utente ' . $user->email . ' disattivato: non può più entrare.');

        return Response::redirect('/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function activate(Request $request, array $params): Response
    {
        $user = $this->findOrFail($params);
        $this->users->activate($user->id);
        $this->session->flash('success', 'Utente ' . $user->email . ' riattivato.');

        return Response::redirect('/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function passwordForm(Request $request, array $params): Response
    {
        $user = $this->findOrFail($params);

        // Per sé stessi c'è «La mia password», che chiede anche quella attuale.
        if ($user->id === $this->me()->id) {
            return Response::redirect('/account/password');
        }

        return $this->renderPasswordForm($user);
    }

    /**
     * @param array<string, string> $params
     */
    public function resetPassword(Request $request, array $params): Response
    {
        $user = $this->findOrFail($params);

        try {
            $this->users->resetPassword($user->id, $request->allInput(), $this->me()->id);
        } catch (UserChangeNotAllowedException $e) {
            $this->session->flash('error', $e->getMessage());

            return Response::redirect('/users');
        } catch (ValidationException $e) {
            return $this->renderPasswordForm($user, $e->errors(), 422);
        }

        $this->session->flash('success', 'Nuova password impostata per ' . $user->email . '. Le sue sessioni aperte sono state chiuse.');

        return Response::redirect('/users');
    }

    private function me(): User
    {
        return $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');
    }

    /**
     * @param array<string, string> $params
     */
    private function findOrFail(array $params): User
    {
        $id = $params['id'] ?? '';
        $user = ctype_digit($id) ? $this->users->find((int) $id) : null;
        if ($user === null) {
            throw new HttpException(404, 'Utente non trovato: ' . $id);
        }

        return $user;
    }

    /**
     * Valori da rimettere nel form dopo un errore. Le password non si rimettono MAI nei campi.
     *
     * @param list<string> $fields
     * @return array<string, string>
     */
    private function formValues(Request $request, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $input = $request->input($field, '');
            $values[$field] = is_scalar($input) ? (string) $input : '';
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(?User $user, array $values, array $errors = [], int $status = 200): Response
    {
        return Response::html($this->view->render('users/form', [
            'title' => $user === null ? 'Nuovo utente' : 'Modifica utente',
            'action' => $user === null ? '/users' : '/users/' . $user->id,
            'path' => '/users',
            'isNew' => $user === null,
            'isSelf' => $user !== null && $user->id === $this->me()->id,
            'values' => $values + ['password' => '', 'password_confirmation' => ''],
            'errors' => $errors,
            'roleOptions' => self::ROLE_OPTIONS,
        ]), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderPasswordForm(User $user, array $errors = [], int $status = 200): Response
    {
        return Response::html($this->view->render('users/password', [
            'title' => 'Nuova password per ' . $user->name,
            'user' => $user,
            'values' => ['password' => '', 'password_confirmation' => ''],
            'errors' => $errors,
        ]), $status);
    }
}
