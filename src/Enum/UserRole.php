<?php

declare(strict_types=1);

namespace LogiDock\Enum;

/**
 * Ruoli degli utenti. I valori sono quelli dell'ENUM nella tabella users.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Operatore = 'operatore';
}
