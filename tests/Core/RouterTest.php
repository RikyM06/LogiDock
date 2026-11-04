<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use ArrayObject;
use LogicException;
use LogiDock\Core\Container;
use LogiDock\Core\HttpException;
use LogiDock\Core\Request;
use LogiDock\Core\Router;
use LogiDock\Tests\Support\BlockingMiddleware;
use LogiDock\Tests\Support\FakeController;
use LogiDock\Tests\Support\InnerMiddleware;
use LogiDock\Tests\Support\OuterMiddleware;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    /** @var ArrayObject<int, string> registro dell'ordine in cui girano i middleware */
    private ArrayObject $log;

    private Router $router;

    protected function setUp(): void
    {
        $this->log = new ArrayObject();

        $container = new Container();
        $container->set(FakeController::class, static fn (): FakeController => new FakeController());
        $container->set(OuterMiddleware::class, fn (): OuterMiddleware => new OuterMiddleware($this->log));
        $container->set(InnerMiddleware::class, fn (): InnerMiddleware => new InnerMiddleware($this->log));
        $container->set(BlockingMiddleware::class, static fn (): BlockingMiddleware => new BlockingMiddleware());

        $this->router = new Router($container);
    }

    public function testDispatchesToTheMatchingController(): void
    {
        $this->router->get('/hello', [FakeController::class, 'hello']);

        $response = $this->router->dispatch(new Request('GET', '/hello'));

        self::assertSame(200, $response->status());
        self::assertSame('ciao', $response->body());
    }

    public function testPassesNamedParametersToTheController(): void
    {
        $this->router->get('/products/{id}/files/{slug}', [FakeController::class, 'show']);

        $response = $this->router->dispatch(new Request('GET', '/products/42/files/scheda-tecnica'));

        self::assertSame('id=42;slug=scheda-tecnica', $response->body());
    }

    public function testTrailingSlashIsIgnored(): void
    {
        $this->router->get('/hello', [FakeController::class, 'hello']);

        $response = $this->router->dispatch(new Request('GET', '/hello/'));

        self::assertSame('ciao', $response->body());
    }

    public function testParameterDoesNotCrossSlashes(): void
    {
        $this->router->get('/products/{id}', [FakeController::class, 'show']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('GET /products/1/2');

        $this->router->dispatch(new Request('GET', '/products/1/2'));
    }

    public function testUnknownPathGives404(): void
    {
        $this->router->get('/hello', [FakeController::class, 'hello']);

        try {
            $this->router->dispatch(new Request('GET', '/non-esiste'));
            self::fail('Attesa una HttpException');
        } catch (HttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }

    public function testKnownPathWithWrongMethodGives405(): void
    {
        $this->router->get('/hello', [FakeController::class, 'hello']);

        try {
            $this->router->dispatch(new Request('POST', '/hello'));
            self::fail('Attesa una HttpException');
        } catch (HttpException $e) {
            self::assertSame(405, $e->getStatusCode());
        }
    }

    public function testSamePathCanHaveDifferentMethods(): void
    {
        $this->router->get('/products/{id}', [FakeController::class, 'show']);
        $this->router->post('/products/{id}', [FakeController::class, 'hello']);

        self::assertSame('id=7;slug=', $this->router->dispatch(new Request('GET', '/products/7'))->body());
        self::assertSame('ciao', $this->router->dispatch(new Request('POST', '/products/7'))->body());
    }

    public function testSpecialCharactersInThePatternAreTakenLiterally(): void
    {
        // Il punto di "/a.b" non deve valere come "qualsiasi carattere" delle espressioni regolari.
        $this->router->get('/a.b', [FakeController::class, 'hello']);

        self::assertSame('ciao', $this->router->dispatch(new Request('GET', '/a.b'))->body());

        $this->expectException(HttpException::class);
        $this->router->dispatch(new Request('GET', '/aXb'));
    }

    public function testMiddlewareRunsOutsideInAndBackInsideOut(): void
    {
        $this->router->get('/hello', [FakeController::class, 'hello'], [OuterMiddleware::class, InnerMiddleware::class]);

        $this->router->dispatch(new Request('GET', '/hello'));

        // Il primo dichiarato è il più esterno: entra per primo ed esce per ultimo.
        self::assertSame(['>outer', '>inner', '<inner', '<outer'], $this->log->getArrayCopy());
    }

    public function testMiddlewareCanBlockTheRequest(): void
    {
        $this->router->get('/secret', [FakeController::class, 'hello'], [BlockingMiddleware::class, InnerMiddleware::class]);

        $response = $this->router->dispatch(new Request('GET', '/secret'));

        self::assertSame(403, $response->status());
        self::assertSame('bloccato', $response->body());
        self::assertSame([], $this->log->getArrayCopy(), 'Il middleware dopo il blocco non deve girare.');
    }

    public function testGroupAppliesItsMiddlewareOnlyToRoutesInsideIt(): void
    {
        $this->router->group([OuterMiddleware::class], function (Router $router): void {
            $router->get('/inside', [FakeController::class, 'hello']);
        });
        $this->router->get('/outside', [FakeController::class, 'hello']);

        $this->router->dispatch(new Request('GET', '/outside'));
        self::assertSame([], $this->log->getArrayCopy());

        $this->router->dispatch(new Request('GET', '/inside'));
        self::assertSame(['>outer', '<outer'], $this->log->getArrayCopy());
    }

    public function testNestedGroupsRunOuterMiddlewareFirst(): void
    {
        $this->router->group([OuterMiddleware::class], function (Router $router): void {
            $router->group([InnerMiddleware::class], function (Router $router): void {
                $router->get('/deep', [FakeController::class, 'hello']);
            });
        });

        $this->router->dispatch(new Request('GET', '/deep'));

        self::assertSame(['>outer', '>inner', '<inner', '<outer'], $this->log->getArrayCopy());
    }

    public function testGroupMiddlewareIsRestoredEvenIfTheGroupThrows(): void
    {
        try {
            $this->router->group([OuterMiddleware::class], static function (): void {
                throw new LogicException('errore dentro il gruppo');
            });
        } catch (LogicException) {
            // atteso
        }

        $this->router->get('/after', [FakeController::class, 'hello']);
        $this->router->dispatch(new Request('GET', '/after'));

        self::assertSame([], $this->log->getArrayCopy(), 'Il middleware del gruppo non deve restare attivo.');
    }

    public function testControllerMustReturnAResponse(): void
    {
        $this->router->get('/broken', [FakeController::class, 'broken']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('deve restituire una Response');

        $this->router->dispatch(new Request('GET', '/broken'));
    }
}
