<?php

declare(strict_types=1);

namespace LogiDock\Core;

use Dotenv\Dotenv;
use LogiDock\Controller\AuthController;
use LogiDock\Controller\HomeController;
use LogiDock\Controller\ProductController;
use LogiDock\Middleware\AdminMiddleware;
use LogiDock\Middleware\AuthMiddleware;
use LogiDock\Middleware\CsrfMiddleware;
use LogiDock\Repository\ProductRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Service\AuthService;
use LogiDock\Service\ProductService;

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

        $this->container->get(Session::class)->start();

        // Dati disponibili in ogni template (layout compreso).
        $view = $this->container->get(View::class);
        $view->share('currentPath', $request->path());
        $view->share('currentUser', $this->container->get(AuthService::class)->user());
        $view->share('csrfToken', $this->container->get(Csrf::class)->token());
        $view->share('flash', $this->container->get(Session::class)->pullFlash());

        $response = $this->container->get(Router::class)->dispatch($request);
        $response->send();
    }

    private function registerServices(): void
    {
        $c = $this->container;
        $config = $this->config;

        $c->set(Logger::class, fn (): Logger => new Logger((string) $config['log_path']));

        $c->set(Database::class, fn (): Database => new Database($config['db']));

        // Valori di partenza per il layout: servono anche alla pagina di errore, che può essere
        // mostrata prima che sessione e utente siano pronti (es. database non raggiungibile).
        $c->set(View::class, fn (): View => new View(
            $this->basePath . '/templates',
            [
                'appName' => (string) $config['name'],
                'currentPath' => '/',
                'currentUser' => null,
                'csrfToken' => '',
                'flash' => [],
            ]
        ));

        $c->set(Session::class, fn (): Session => new Session(
            (string) $config['session']['name'],
            (int) $config['session']['lifetime_minutes'] * 60,
            (bool) $config['session']['secure']
        ));
        $c->set(Csrf::class, fn (Container $c): Csrf => new Csrf($c->get(Session::class)));

        $c->set(UserRepository::class, fn (Container $c): UserRepository => new UserRepository(
            $c->get(Database::class)
        ));
        $c->set(AuthService::class, fn (Container $c): AuthService => new AuthService(
            $c->get(UserRepository::class),
            $c->get(Session::class),
            $c->get(Csrf::class),
            $c->get(Logger::class)
        ));

        $c->set(ProductRepository::class, fn (Container $c): ProductRepository => new ProductRepository(
            $c->get(Database::class)
        ));
        $c->set(ProductService::class, fn (Container $c): ProductService => new ProductService(
            $c->get(ProductRepository::class)
        ));

        // Middleware
        $c->set(CsrfMiddleware::class, fn (Container $c): CsrfMiddleware => new CsrfMiddleware($c->get(Csrf::class)));
        $c->set(AuthMiddleware::class, fn (Container $c): AuthMiddleware => new AuthMiddleware($c->get(AuthService::class)));
        $c->set(AdminMiddleware::class, fn (Container $c): AdminMiddleware => new AdminMiddleware($c->get(AuthService::class)));

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
        $c->set(ProductController::class, fn (Container $c): ProductController => new ProductController(
            $c->get(View::class),
            $c->get(ProductService::class),
            $c->get(AuthService::class),
            $c->get(Session::class)
        ));
        $c->set(AuthController::class, fn (Container $c): AuthController => new AuthController(
            $c->get(View::class),
            $c->get(AuthService::class),
            $c->get(Session::class)
        ));
    }
}
