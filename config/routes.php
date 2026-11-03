<?php

declare(strict_types=1);

use LogiDock\Controller\HomeController;
use LogiDock\Core\Router;

// Tutte le rotte dell'applicazione stanno qui, in un unico posto.
// Formato: $router->get('/percorso/{parametro}', [Controller::class, 'metodo'], [Middleware::class]);

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
};
