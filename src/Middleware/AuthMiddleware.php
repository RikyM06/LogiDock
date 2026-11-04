<?php

declare(strict_types=1);

namespace LogiDock\Middleware;

use LogiDock\Core\MiddlewareInterface;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Service\AuthService;

/**
 * Lascia passare solo gli utenti collegati (admin o operatore). Gli altri vanno al login.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect('/login');
        }

        return $next($request);
    }
}
