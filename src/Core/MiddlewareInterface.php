<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Un middleware sta "davanti" al controller: può bloccare la richiesta
 * (restituendo subito una Response) oppure passarla avanti con $next($request).
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
