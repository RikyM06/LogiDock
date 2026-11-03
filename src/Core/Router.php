<?php

declare(strict_types=1);

namespace LogiDock\Core;

use LogicException;

/**
 * Router: associa metodo + percorso a un metodo di controller.
 * Ogni pattern viene compilato una volta in un'espressione regolare;
 * "{id}" diventa un gruppo con nome che finisce in $params del controller.
 */
final class Router
{
    /**
     * @var list<array{
     *     method: string,
     *     regex: string,
     *     handler: array{0: class-string, 1: string},
     *     middleware: list<class-string<MiddlewareInterface>>
     * }>
     */
    private array $routes = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array{0: class-string, 1: string}               $handler
     * @param list<class-string<MiddlewareInterface>>         $middleware
     */
    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string}               $handler
     * @param list<class-string<MiddlewareInterface>>         $middleware
     */
    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string}               $handler
     * @param list<class-string<MiddlewareInterface>>         $middleware
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $this->compile($pattern),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $request->method()) {
                continue;
            }

            // Tiene solo i gruppi con nome ({id}), scartando quelli numerici di preg_match.
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return $this->run($route, $request, $params);
        }

        // Il percorso esiste ma con un altro metodo: 405 invece di 404.
        throw new HttpException($pathMatched ? 405 : 404, $request->method() . ' ' . $request->path());
    }

    private function compile(string $pattern): string
    {
        // preg_quote protegge i caratteri speciali, poi "\{nome\}" diventa un gruppo con nome.
        $quoted = preg_quote('/' . trim($pattern, '/'), '#');
        $withGroups = preg_replace('#\\\\\{(\w+)\\\\\}#', '(?P<$1>[^/]+)', $quoted);

        return '#^' . $withGroups . '$#';
    }

    /**
     * @param array{
     *     handler: array{0: class-string, 1: string},
     *     middleware: list<class-string<MiddlewareInterface>>
     * }                           $route
     * @param array<string, string> $params
     */
    private function run(array $route, Request $request, array $params): Response
    {
        [$controllerClass, $method] = $route['handler'];

        $core = function (Request $request) use ($controllerClass, $method, $params): Response {
            $controller = $this->container->get($controllerClass);
            $response = $controller->$method($request, $params);

            if (!$response instanceof Response) {
                throw new LogicException($controllerClass . '::' . $method . ' deve restituire una Response.');
            }

            return $response;
        };

        // Costruisce la catena di middleware dall'ultimo al primo, così il primo dichiarato
        // è quello più esterno (eseguito per primo).
        $pipeline = array_reduce(
            array_reverse($route['middleware']),
            function (callable $next, string $middlewareClass): callable {
                return function (Request $request) use ($middlewareClass, $next): Response {
                    $middleware = $this->container->get($middlewareClass);

                    return $middleware->handle($request, $next);
                };
            },
            $core
        );

        return $pipeline($request);
    }
}
