<?php

declare(strict_types=1);

namespace LogiDock\Service;

use RuntimeException;

/**
 * Una modifica a un utente che le regole non permettono (disattivare sé stessi, lasciare
 * l'applicazione senza admin...). Non è un errore di programma: il controller la mostra come messaggio.
 */
final class UserChangeNotAllowedException extends RuntimeException
{
}
