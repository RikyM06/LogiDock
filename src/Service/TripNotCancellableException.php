<?php

declare(strict_types=1);

namespace LogiDock\Service;

use RuntimeException;

/**
 * Si è provato ad annullare un viaggio che non è più "pianificato" (già partito, concluso o già
 * annullato). Non è un errore di programma: il controller lo mostra come messaggio.
 */
final class TripNotCancellableException extends RuntimeException
{
}
