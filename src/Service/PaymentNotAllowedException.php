<?php

declare(strict_types=1);

namespace LogiDock\Service;

use RuntimeException;

/**
 * Un pagamento non si può registrare o annullare (richiesta non spedita, già pagata, viaggio senza compenso...).
 * Non è un errore di programma: il controller mostra il messaggio, già scritto in italiano per l'utente.
 */
final class PaymentNotAllowedException extends RuntimeException
{
}
