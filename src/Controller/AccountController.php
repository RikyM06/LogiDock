<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\Session;
use LogiDock\Core\ValidationException;
use LogiDock\Core\View;
use LogiDock\Service\AuthService;
use LogiDock\Service\UserService;

/**
 * «La mia password»: ogni utente collegato (admin o operatore) cambia la propria password.
 */
final class AccountController
{
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
    public function passwordForm(Request $request, array $params): Response
    {
        return $this->render();
    }

    /**
     * @param array<string, string> $params
     */
    public function changePassword(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');

        try {
            $this->users->changeOwnPassword($user->id, $request->allInput());
        } catch (ValidationException $e) {
            return $this->render($e->errors(), 422);
        }

        // Si resta collegati in questa sessione; le altre sessioni dello stesso utente si chiudono.
        $this->auth->refreshAfterOwnPasswordChange();
        $this->session->flash('success', 'Password cambiata. Se eri collegato anche da altri dispositivi, lì dovrai rientrare.');

        return Response::redirect('/');
    }

    /**
     * @param array<string, string> $errors
     */
    private function render(array $errors = [], int $status = 200): Response
    {
        return Response::html($this->view->render('account/password', [
            'title' => 'La mia password',
            'values' => ['current_password' => '', 'password' => '', 'password_confirmation' => ''],
            'errors' => $errors,
        ]), $status);
    }
}
