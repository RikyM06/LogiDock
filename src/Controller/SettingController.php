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
use LogiDock\Service\SettingService;

/**
 * Schermata delle impostazioni, riservata agli admin dal middleware (vedi config/routes.php).
 */
final class SettingController
{
    public function __construct(
        private readonly View $view,
        private readonly SettingService $settings,
        private readonly AuthService $auth,
        private readonly Session $session
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        return $this->renderForm($this->settings->values());
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $user = $this->auth->user() ?? throw new HttpException(403, 'Utente non collegato');

        try {
            $this->settings->update($request->allInput(), $user->id);
        } catch (ValidationException $e) {
            // Rimette nei campi quello che l'utente aveva scritto, non i valori salvati.
            $values = $this->settings->values();
            foreach (array_keys($values) as $name) {
                $input = $request->input($name, $values[$name]);
                $values[$name] = is_scalar($input) ? (string) $input : '';
            }

            return $this->renderForm($values, $e->errors(), 422);
        }

        $this->session->flash('success', 'Impostazioni salvate.');

        return Response::redirect('/settings');
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(array $values, array $errors = [], int $status = 200): Response
    {
        $groups = [];
        foreach ($this->settings->definitions() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        return Response::html($this->view->render('settings/form', [
            'title' => 'Impostazioni',
            'groups' => $groups,
            'values' => $values,
            'errors' => $errors,
        ]), $status);
    }
}
