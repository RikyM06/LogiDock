<?php

declare(strict_types=1);

namespace LogiDock\Core;

use RuntimeException;
use Throwable;

/**
 * Errore con uno status HTTP (404, 403, 405...). Il gestore centrale lo trasforma
 * in una pagina di errore con lo status corretto.
 */
final class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message !== '' ? $message : 'Errore HTTP ' . $statusCode, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
