<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use RuntimeException;

/**
 * Il database ha rifiutato la scrittura per un vincolo UNIQUE (es. codice già esistente).
 * Il repository traduce l'errore del driver in questa eccezione, così i livelli sopra
 * non devono conoscere i codici di errore di MySQL.
 */
final class DuplicateEntryException extends RuntimeException
{
}
