<?php

declare(strict_types=1);

namespace LogiDock\Middleware;

use LogiDock\Core\HttpException;
use LogiDock\Core\MiddlewareInterface;
use LogiDock\Core\Request;
use LogiDock\Core\Response;
use LogiDock\Enum\UserRole;
use LogiDock\Service\AuthService;

/**
 * Solo per gli admin. Chi non è collegato va al login, chi è collegato ma è operatore riceve 403.
 * Con due ruoli basta una classe per ruolo: se ne arrivassero altri, si può generalizzare.
 */
final class AdminMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect('/login');
        }

        if (!$this->auth->hasRole(UserRole::Admin)) {
            throw new HttpException(403, 'Richiesto il ruolo admin');
        }

        return $next($request);
    }
}
