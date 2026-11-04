<?php

declare(strict_types=1);

use LogiDock\Controller\AuthController;
use LogiDock\Controller\HomeController;
use LogiDock\Core\Router;
use LogiDock\Middleware\AuthMiddleware;
use LogiDock\Middleware\CsrfMiddleware;

// Tutte le rotte dell'applicazione stanno qui, in un unico posto.
// Formato: $router->get('/percorso/{parametro}', [Controller::class, 'metodo'], [Middleware::class]);
// I middleware di un group() valgono per tutte le rotte al suo interno.

return static function (Router $router): void {
    // Tutta l'applicazione è protetta da CSRF: ogni POST deve portare il token.
    $router->group([CsrfMiddleware::class], static function (Router $router): void {
        // Rotte pubbliche
        $router->get('/login', [AuthController::class, 'showLogin']);
        $router->post('/login', [AuthController::class, 'login']);

        // Rotte per qualunque utente collegato (admin o operatore)
        $router->group([AuthMiddleware::class], static function (Router $router): void {
            $router->get('/', [HomeController::class, 'index']);
            $router->post('/logout', [AuthController::class, 'logout']);

            // Le rotte riservate agli admin useranno [AdminMiddleware::class] (vedi Fase 4).
        });
    });
};
