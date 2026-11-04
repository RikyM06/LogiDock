<?php

declare(strict_types=1);

use LogiDock\Controller\AuthController;
use LogiDock\Controller\HomeController;
use LogiDock\Controller\ProductController;
use LogiDock\Core\Router;
use LogiDock\Middleware\AdminMiddleware;
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

            // Prodotti: tutti possono consultare l'anagrafica...
            $router->get('/products', [ProductController::class, 'index']);

            // ...ma solo gli admin la modificano.
            $router->group([AdminMiddleware::class], static function (Router $router): void {
                $router->get('/products/create', [ProductController::class, 'create']);
                $router->post('/products', [ProductController::class, 'store']);
                $router->get('/products/{id}/edit', [ProductController::class, 'edit']);
                $router->post('/products/{id}', [ProductController::class, 'update']);
                $router->post('/products/{id}/deactivate', [ProductController::class, 'deactivate']);
                $router->post('/products/{id}/activate', [ProductController::class, 'activate']);
            });
        });
    });
};
