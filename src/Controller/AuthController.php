<?php

declare(strict_types=1);

namespace LogiDock\Controller;

use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Core\Session;
use LogiDock\Core\View;
use LogiDock\Service\AuthService;

final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly Session $session
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function showLogin(Request $request, array $params): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect('/');
        }

        return $this->renderLogin();
    }

    /**
     * @param array<string, string> $params
     */
    public function login(Request $request, array $params): Response
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        // Validazione minima: i dettagli sulle credenziali non si rivelano mai.
        if ($email === '' || $password === '' || strlen($email) > 255 || strlen($password) > 1024) {
            return $this->renderLogin($email, 'Inserisci email e password.', 422);
        }

        $user = $this->auth->attempt($email, $password, (string) $request->server('REMOTE_ADDR', ''));

        if ($user === null) {
            return $this->renderLogin($email, 'Email o password non corrette.', 401);
        }

        return Response::redirect('/');
    }

    /**
     * @param array<string, string> $params
     */
    public function logout(Request $request, array $params): Response
    {
        $this->auth->logout();
        $this->session->flash('success', 'Sei uscito dall\'applicazione.');

        return Response::redirect('/login');
    }

    private function renderLogin(string $email = '', ?string $error = null, int $status = 200): Response
    {
        return Response::html(
            $this->view->render('auth/login', ['title' => 'Accesso', 'email' => $email, 'error' => $error]),
            $status
        );
    }
}
