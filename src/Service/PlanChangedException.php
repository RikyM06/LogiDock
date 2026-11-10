<?php

declare(strict_types=1);

namespace LogiDock\Service;

use RuntimeException;

/**
 * Tra quando il piano è stato mostrato e quando si è premuto "Conferma" qualcosa è cambiato
 * (un bancale prenotato da un altro, una richiesta modificata, un mezzo già impegnato...).
 * Non è un errore di programma: non si scrive niente e il controller rimanda al piano aggiornato.
 */
final class PlanChangedException extends RuntimeException
{
    public function __construct(
        string $message = 'Il piano è cambiato nel frattempo (richieste, bancali o mezzi): ricontrollalo e conferma di nuovo.'
    ) {
        parent::__construct($message);
    }
}
