<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

use ArrayObject;
use LogiDock\Core\MiddlewareInterface;
use LogiDock\Core\Request;
use LogiDock\Core\Response;

/**
 * Middleware finto: scrive la sua etichetta nel registro prima e dopo il resto della catena,
 * così i test vedono in che ordine girano. Le sottoclassi servono solo a dare nomi diversi
 * (il Container distingue i servizi dal nome della classe).
 */
abstract class TraceMiddleware implements MiddlewareInterface
{
    protected const LABEL = '?';

    /**
     * @param ArrayObject<int, string> $log
     */
    public function __construct(private readonly ArrayObject $log)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->log->append('>' . static::LABEL);
        $response = $next($request);
        $this->log->append('<' . static::LABEL);

        return $response;
    }
}
