<?php

declare(strict_types=1);

namespace LogiDock\Service;

/**
 * Regole per le password, in un unico posto: le usano la gestione utenti, "La mia password"
 * e il comando bin/create-user.php.
 *
 * - almeno 12 caratteri: la lunghezza conta più dei simboli strani;
 * - al massimo 72 byte: oltre, l'algoritmo bcrypt (quello di password_hash oggi) ignora il resto
 *   senza dirlo, quindi due password diverse dopo il 72° byte varrebbero uguali;
 * - niente spazi all'inizio o alla fine: di solito sono errori di copia e incolla.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_BYTES = 72;

    /**
     * Il problema della password, o null se va bene.
     */
    public static function problem(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'La password deve avere almeno ' . self::MIN_LENGTH . ' caratteri.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'La password può essere lunga al massimo ' . self::MAX_BYTES . ' byte (circa 70 caratteri).';
        }
        if (trim($password) !== $password) {
            return 'La password non può iniziare o finire con uno spazio.';
        }

        return null;
    }
}
