<?php

declare(strict_types=1);

namespace LogiDock\Core;

use Dotenv\Dotenv;
use LogiDock\Controller\AuthController;
use LogiDock\Controller\CarrierController;
use LogiDock\Controller\CustomerController;
use LogiDock\Controller\HomeController;
use LogiDock\Controller\PalletTypeController;
use LogiDock\Controller\ProductController;
use LogiDock\Controller\RoundController;
use LogiDock\Controller\SettingController;
use LogiDock\Controller\SupplierController;
use LogiDock\Controller\TrailerTypeController;
use LogiDock\Controller\VehicleController;
use LogiDock\Controller\WarehouseLocationController;
use LogiDock\Middleware\AdminMiddleware;
use LogiDock\Middleware\AuthMiddleware;
use LogiDock\Middleware\CsrfMiddleware;
use LogiDock\Repository\CarrierRepository;
use LogiDock\Repository\CustomerRepository;
use LogiDock\Repository\PalletTypeRepository;
use LogiDock\Repository\ProductRepository;
use LogiDock\Repository\RoundRepository;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\SupplierRepository;
use LogiDock\Repository\TrailerTypeRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Repository\VehicleRepository;
use LogiDock\Repository\WarehouseLocationRepository;
use LogiDock\Service\AuthService;
use LogiDock\Service\CarrierService;
use LogiDock\Service\CustomerService;
use LogiDock\Service\PalletTypeService;
use LogiDock\Service\ProductService;
use LogiDock\Service\RoundService;
use LogiDock\Service\SettingService;
use LogiDock\Service\SupplierService;
use LogiDock\Service\TrailerTypeService;
use LogiDock\Service\VehicleService;
use LogiDock\Service\WarehouseLocationService;

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

        // Impostazioni globali
        $c->set(SettingRepository::class, fn (Container $c): SettingRepository => new SettingRepository(
            $c->get(Database::class)
        ));
        $c->set(SettingService::class, fn (Container $c): SettingService => new SettingService(
            $c->get(SettingRepository::class),
            $c->get(Database::class)
        ));

        // Anagrafiche: ogni repository riceve il Database, ogni service il suo repository
        // (più le altre anagrafiche a cui si riferisce).
        $c->set(SupplierRepository::class, fn (Container $c): SupplierRepository => new SupplierRepository(
            $c->get(Database::class)
        ));
        $c->set(SupplierService::class, fn (Container $c): SupplierService => new SupplierService(
            $c->get(SupplierRepository::class)
        ));

        $c->set(RoundRepository::class, fn (Container $c): RoundRepository => new RoundRepository(
            $c->get(Database::class)
        ));
        $c->set(RoundService::class, fn (Container $c): RoundService => new RoundService(
            $c->get(RoundRepository::class)
        ));

        $c->set(CustomerRepository::class, fn (Container $c): CustomerRepository => new CustomerRepository(
            $c->get(Database::class)
        ));
        $c->set(CustomerService::class, fn (Container $c): CustomerService => new CustomerService(
            $c->get(CustomerRepository::class),
            $c->get(RoundService::class)
        ));

        $c->set(CarrierRepository::class, fn (Container $c): CarrierRepository => new CarrierRepository(
            $c->get(Database::class)
        ));
        $c->set(CarrierService::class, fn (Container $c): CarrierService => new CarrierService(
            $c->get(CarrierRepository::class)
        ));

        $c->set(TrailerTypeRepository::class, fn (Container $c): TrailerTypeRepository => new TrailerTypeRepository(
            $c->get(Database::class)
        ));
        $c->set(TrailerTypeService::class, fn (Container $c): TrailerTypeService => new TrailerTypeService(
            $c->get(TrailerTypeRepository::class)
        ));

        $c->set(VehicleRepository::class, fn (Container $c): VehicleRepository => new VehicleRepository(
            $c->get(Database::class)
        ));
        $c->set(VehicleService::class, fn (Container $c): VehicleService => new VehicleService(
            $c->get(VehicleRepository::class),
            $c->get(TrailerTypeService::class),
            $c->get(CarrierService::class)
        ));

        $c->set(WarehouseLocationRepository::class, fn (Container $c): WarehouseLocationRepository => new WarehouseLocationRepository(
            $c->get(Database::class)
        ));
        $c->set(WarehouseLocationService::class, fn (Container $c): WarehouseLocationService => new WarehouseLocationService(
            $c->get(WarehouseLocationRepository::class)
        ));

        $c->set(PalletTypeRepository::class, fn (Container $c): PalletTypeRepository => new PalletTypeRepository(
            $c->get(Database::class)
        ));
        $c->set(PalletTypeService::class, fn (Container $c): PalletTypeService => new PalletTypeService(
            $c->get(PalletTypeRepository::class)
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

        // Controller delle anagrafiche: tutti con View, Session e AuthService, più il loro service.
        $c->set(SupplierController::class, fn (Container $c): SupplierController => new SupplierController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(SupplierService::class)
        ));
        $c->set(RoundController::class, fn (Container $c): RoundController => new RoundController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(RoundService::class)
        ));
        $c->set(CustomerController::class, fn (Container $c): CustomerController => new CustomerController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(CustomerService::class),
            $c->get(RoundService::class)
        ));
        $c->set(CarrierController::class, fn (Container $c): CarrierController => new CarrierController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(CarrierService::class)
        ));
        $c->set(TrailerTypeController::class, fn (Container $c): TrailerTypeController => new TrailerTypeController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(TrailerTypeService::class)
        ));
        $c->set(VehicleController::class, fn (Container $c): VehicleController => new VehicleController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(VehicleService::class),
            $c->get(TrailerTypeService::class),
            $c->get(CarrierService::class)
        ));
        $c->set(WarehouseLocationController::class, fn (Container $c): WarehouseLocationController => new WarehouseLocationController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(WarehouseLocationService::class),
            $c->get(SettingService::class)
        ));
        $c->set(PalletTypeController::class, fn (Container $c): PalletTypeController => new PalletTypeController(
            $c->get(View::class),
            $c->get(Session::class),
            $c->get(AuthService::class),
            $c->get(PalletTypeService::class)
        ));
        $c->set(SettingController::class, fn (Container $c): SettingController => new SettingController(
            $c->get(View::class),
            $c->get(SettingService::class),
            $c->get(AuthService::class),
            $c->get(Session::class)
        ));
    }
}
