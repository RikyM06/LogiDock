<?php

declare(strict_types=1);

namespace LogiDock\Service;

use RuntimeException;

/**
 * Si è provato a modificare o annullare una richiesta che non è più "aperta" (già pianificata,
 * spedita o annullata). Non è un errore di programma: il controller lo mostra come messaggio.
 */
final class OrderNotEditableException extends RuntimeException
{
}
