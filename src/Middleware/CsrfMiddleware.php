<?php

declare(strict_types=1);

namespace LogiDock\Middleware;

use LogiDock\Core\Csrf;
use LogiDock\Core\HttpException;
use LogiDock\Core\MiddlewareInterface;
use LogiDock\Core\Request;
use LogiDock\Core\Response;

/**
 * Rifiuta (419) ogni richiesta che modifica dati senza un token CSRF valido.
 * Il token arriva dal campo nascosto "_csrf" dei form oppure dall'header X-CSRF-Token (per JavaScript).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $submitted = $request->input('_csrf') ?? $request->server('HTTP_X_CSRF_TOKEN');

        if (!is_string($submitted) || !$this->csrf->isValid($submitted)) {
            throw new HttpException(419, 'Token CSRF mancante o non valido');
        }

        return $next($request);
    }
}
