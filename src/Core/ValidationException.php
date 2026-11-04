<?php

declare(strict_types=1);

namespace LogiDock\Core;

use RuntimeException;

/**
 * L'input dell'utente non è valido. Porta un messaggio per ogni campo sbagliato:
 * il controller lo cattura e ripresenta il form con gli errori (non è un errore del programma).
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors campo => messaggio
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Dati non validi: ' . implode(', ', array_keys($errors)));
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
