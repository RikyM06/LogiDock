<?php

declare(strict_types=1);

namespace LogiDock\Service;

use RuntimeException;

/**
 * Troppi tentativi di accesso falliti: il login è bloccato per un po'. Il messaggio è pronto per l'utente
 * e vale uguale per un'e-mail registrata e per una che non esiste.
 */
final class TooManyLoginAttemptsException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        $minutes = self::minutesFor($retryAfterSeconds);

        parent::__construct(sprintf(
            'Troppi tentativi di accesso. Riprova tra %d %s.',
            $minutes,
            $minutes === 1 ? 'minuto' : 'minuti'
        ));
    }

    /** Minuti da aspettare, arrotondati per eccesso (almeno 1). */
    public function minutes(): int
    {
        return self::minutesFor($this->retryAfterSeconds);
    }

    private static function minutesFor(int $seconds): int
    {
        return max(1, (int) ceil($seconds / 60));
    }
}
