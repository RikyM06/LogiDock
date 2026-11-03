<?php

declare(strict_types=1);

namespace LogiDock\Core;

use Dotenv\Dotenv;
use LogiDock\Controller\HomeController;

/**
 * Punto di avvio: carica la configurazione, registra i servizi nel container,
 * installa il gestore errori ed esegue la richiesta.
 */
final class Application
{
    private Container $container;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(private readonly string $basePath)
    {
        $this->config = self::loadConfig($this->basePath);

        date_default_timezone_set((string) $this->config['timezone']);

        $this->container = new Container();
        $this->registerServices();

        $this->container->get(ErrorHandler::class)->register();
    }

    /**
     * Carica .env e restituisce la configurazione. È statico perché serve anche agli script
     * da riga di comando (bin/migrate.php, bin/seed.php), che non avviano l'intera applicazione web.
     *
     * @return array<string, mixed>
     */
    public static function loadConfig(string $basePath): array
    {
        // safeLoad: se .env manca (es. variabili passate dal server) non è un errore.
        Dotenv::createImmutable($basePath)->safeLoad();

        /** @var array<string, mixed> */
        return require $basePath . '/config/app.php';
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        $this->container->get(View::class)->share('currentPath', $request->path());

        $response = $this->container->get(Router::class)->dispatch($request);
        $response->send();
    }

    private function registerServices(): void
    {
        $c = $this->container;
        $config = $this->config;

        $c->set(Logger::class, fn (): Logger => new Logger((string) $config['log_path']));

        $c->set(Database::class, fn (): Database => new Database($config['db']));

        $c->set(View::class, fn (): View => new View(
            $this->basePath . '/templates',
            ['appName' => (string) $config['name'], 'currentPath' => '/']
        ));

        $c->set(ErrorHandler::class, fn (Container $c): ErrorHandler => new ErrorHandler(
            $c->get(Logger::class),
            $c->get(View::class),
            (bool) $config['debug']
        ));

        $c->set(Router::class, function (Container $c): Router {
            $router = new Router($c);
            /** @var callable(Router): void $defineRoutes */
            $defineRoutes = require $this->basePath . '/config/routes.php';
            $defineRoutes($router);

            return $router;
        });

        // Controller: uno per riga, con le dipendenze esplicite.
        $c->set(HomeController::class, fn (Container $c): HomeController => new HomeController(
            $c->get(View::class)
        ));
    }
}
