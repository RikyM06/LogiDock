<?php

declare(strict_types=1);

namespace LogiDock\Service;

use RuntimeException;

/**
 * Una modifica a mano a un viaggio non si può fare (il viaggio non è più "pianificato", il carico non
 * entra nel mezzo, il mezzo è già occupato...). Non è un errore di programma: il controller mostra
 * il messaggio, già scritto in italiano per l'utente.
 */
final class TripChangeNotAllowedException extends RuntimeException
{
}
