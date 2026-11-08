<?php

declare(strict_types=1);

use LogiDock\Controller\AccountController;
use LogiDock\Controller\AuthController;
use LogiDock\Controller\CarrierController;
use LogiDock\Controller\CustomerController;
use LogiDock\Controller\HomeController;
use LogiDock\Controller\InboundController;
use LogiDock\Controller\OrderController;
use LogiDock\Controller\PalletTypeController;
use LogiDock\Controller\ProductController;
use LogiDock\Controller\RoundController;
use LogiDock\Controller\SettingController;
use LogiDock\Controller\SupplierController;
use LogiDock\Controller\TrailerTypeController;
use LogiDock\Controller\UserController;
use LogiDock\Controller\VehicleController;
use LogiDock\Controller\WarehouseLocationController;
use LogiDock\Core\Router;
use LogiDock\Middleware\AdminMiddleware;
use LogiDock\Middleware\AuthMiddleware;
use LogiDock\Middleware\CsrfMiddleware;

// Tutte le rotte dell'applicazione stanno qui, in un unico posto.
// Formato: $router->get('/percorso/{parametro}', [Controller::class, 'metodo'], [Middleware::class]);
// I middleware di un group() valgono per tutte le rotte al suo interno.

return static function (Router $router): void {
    // Le anagrafiche (fornitori, clienti...) hanno tutte le stesse rotte, con un controller ciascuna.
    // Consultazione: la lista. Scrittura: nuovo, modifica, disattiva, riattiva.
    $masterDataRead = static function (Router $router, string $path, string $controller): void {
        $router->get($path, [$controller, 'index']);
    };
    $masterDataWrite = static function (Router $router, string $path, string $controller): void {
        $router->get($path . '/create', [$controller, 'create']);
        $router->post($path, [$controller, 'store']);
        $router->get($path . '/{id}/edit', [$controller, 'edit']);
        $router->post($path . '/{id}', [$controller, 'update']);
        $router->post($path . '/{id}/deactivate', [$controller, 'deactivate']);
        $router->post($path . '/{id}/activate', [$controller, 'activate']);
    };

    // Anagrafiche che tutti possono consultare ma solo gli admin modificano.
    $sharedMasterData = [
        '/suppliers' => SupplierController::class,
        '/customers' => CustomerController::class,
        '/rounds' => RoundController::class,
        '/vehicles' => VehicleController::class,
        '/trailer-types' => TrailerTypeController::class,
        '/locations' => WarehouseLocationController::class,
        '/pallet-types' => PalletTypeController::class,
    ];

    // Tutta l'applicazione è protetta da CSRF: ogni POST deve portare il token.
    $router->group([CsrfMiddleware::class], static function (Router $router) use (
        $masterDataRead,
        $masterDataWrite,
        $sharedMasterData
    ): void {
        // Rotte pubbliche
        $router->get('/login', [AuthController::class, 'showLogin']);
        $router->post('/login', [AuthController::class, 'login']);

        // Rotte per qualunque utente collegato (admin o operatore)
        $router->group([AuthMiddleware::class], static function (Router $router) use (
            $masterDataRead,
            $masterDataWrite,
            $sharedMasterData
        ): void {
            $router->get('/', [HomeController::class, 'index']);
            $router->get('/master-data', [HomeController::class, 'masterData']);
            $router->post('/logout', [AuthController::class, 'logout']);

            // «La mia password»: ogni utente collegato cambia la propria.
            $router->get('/account/password', [AccountController::class, 'passwordForm']);
            $router->post('/account/password', [AccountController::class, 'changePassword']);

            // Arrivi di merce: li registra chiunque lavori in magazzino, non solo gli admin.
            // "/inbound/create" va prima di "/inbound/{id}", altrimenti "create" sarebbe preso per un id.
            $router->get('/inbound', [InboundController::class, 'index']);
            $router->get('/inbound/create', [InboundController::class, 'create']);
            $router->post('/inbound', [InboundController::class, 'store']);
            $router->get('/inbound/{id}', [InboundController::class, 'show']);

            // Richieste dei clienti: anche queste fanno parte del lavoro di ogni giorno.
            // "/orders/create" va prima di "/orders/{id}" per lo stesso motivo degli arrivi.
            $router->get('/orders', [OrderController::class, 'index']);
            $router->get('/orders/create', [OrderController::class, 'create']);
            $router->post('/orders', [OrderController::class, 'store']);
            $router->get('/orders/{id}', [OrderController::class, 'show']);
            $router->get('/orders/{id}/edit', [OrderController::class, 'edit']);
            $router->post('/orders/{id}', [OrderController::class, 'update']);
            $router->post('/orders/{id}/cancel', [OrderController::class, 'cancel']);

            // Prodotti: tutti possono consultare l'anagrafica...
            $router->get('/products', [ProductController::class, 'index']);

            foreach ($sharedMasterData as $path => $controller) {
                $masterDataRead($router, $path, $controller);
            }

            // ...ma solo gli admin la modificano.
            $router->group([AdminMiddleware::class], static function (Router $router) use (
                $masterDataRead,
                $masterDataWrite,
                $sharedMasterData
            ): void {
                $router->get('/products/create', [ProductController::class, 'create']);
                $router->post('/products', [ProductController::class, 'store']);
                $router->get('/products/{id}/edit', [ProductController::class, 'edit']);
                $router->post('/products/{id}', [ProductController::class, 'update']);
                $router->post('/products/{id}/deactivate', [ProductController::class, 'deactivate']);
                $router->post('/products/{id}/activate', [ProductController::class, 'activate']);

                foreach ($sharedMasterData as $path => $controller) {
                    $masterDataWrite($router, $path, $controller);
                }

                // Trasportatori (compenso per viaggio): anche la lista è solo per gli admin.
                $masterDataRead($router, '/carriers', CarrierController::class);
                $masterDataWrite($router, '/carriers', CarrierController::class);

                // Impostazioni globali.
                $router->get('/settings', [SettingController::class, 'edit']);
                $router->post('/settings', [SettingController::class, 'update']);

                // Gestione degli utenti ("/users/create" prima di "/users/{id}...").
                $router->get('/users', [UserController::class, 'index']);
                $router->get('/users/create', [UserController::class, 'create']);
                $router->post('/users', [UserController::class, 'store']);
                $router->get('/users/{id}/edit', [UserController::class, 'edit']);
                $router->post('/users/{id}', [UserController::class, 'update']);
                $router->post('/users/{id}/deactivate', [UserController::class, 'deactivate']);
                $router->post('/users/{id}/activate', [UserController::class, 'activate']);
                $router->get('/users/{id}/password', [UserController::class, 'passwordForm']);
                $router->post('/users/{id}/password', [UserController::class, 'resetPassword']);
            });
        });
    });
};
