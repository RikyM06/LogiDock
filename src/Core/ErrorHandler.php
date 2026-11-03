<?php

declare(strict_types=1);

namespace LogiDock\Core;

use ErrorException;
use Throwable;

/**
 * Gestore centrale di errori ed eccezioni.
 * - Trasforma warning/notice PHP in eccezioni (nulla passa in silenzio).
 * - Scrive tutto su file di log.
 * - Mostra all'utente una pagina generica; i dettagli solo se APP_DEBUG=true.
 */
final class ErrorHandler
{
    private const PUBLIC_MESSAGES = [
        403 => 'Non hai i permessi per accedere a questa pagina.',
        404 => 'La pagina che cerchi non esiste.',
        405 => 'Operazione non consentita.',
        419 => 'La sessione è scaduta. Ricarica la pagina e riprova.',
        500 => 'Si è verificato un errore interno. Riprova più tardi.',
    ];

    public function __construct(
        private readonly Logger $logger,
        private readonly View $view,
        private readonly bool $debug
    ) {
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        // Gli errori non vanno mai stampati a video: li gestiamo noi.
        ini_set('display_errors', '0');

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * @throws ErrorException
     */
    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Rispetta l'operatore @ e error_reporting().
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleException(Throwable $e): void
    {
        // Scarta un eventuale output a metà, altrimenti la pagina di errore si mescola con il resto.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $this->logger->exception($e, $status >= 500 ? 'error' : 'warning');

        $message = self::PUBLIC_MESSAGES[$status] ?? self::PUBLIC_MESSAGES[500];

        try {
            $body = $this->view->render('errors/error', [
                'title' => 'Errore ' . $status,
                'status' => $status,
                'message' => $message,
                'details' => $this->debug ? (string) $e : null,
            ]);
        } catch (Throwable $renderError) {
            // Se è rotta anche la pagina di errore, pagina minimale e log del secondo errore.
            $this->logger->exception($renderError);
            $body = '<!DOCTYPE html><html lang="it"><meta charset="UTF-8"><title>Errore</title>'
                . '<h1>Errore ' . $status . '</h1><p>' . e($message) . '</p></html>';
        }

        Response::html($body, $status)->send();
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

        // Gli errori fatali non passano da set_error_handler: li intercettiamo qui.
        if ($error !== null && in_array($error['type'], $fatal, true)) {
            $this->handleException(
                new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
            );
        }
    }
}
