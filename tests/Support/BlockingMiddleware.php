<?php

declare(strict_types=1);

namespace LogiDock\Tests\Support;

use LogiDock\Core\MiddlewareInterface;
use LogiDock\Core\Request;
use LogiDock\Core\Response;

/**
 * Middleware finto che blocca sempre la richiesta (come AdminMiddleware per un operatore).
 */
final class BlockingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::html('bloccato', 403);
    }
}
