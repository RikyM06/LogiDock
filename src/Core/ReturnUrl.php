<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Il campo opzionale "return" dei form: dice dove tornare dopo l'azione.
 * Si accetta solo un percorso interno dello storico ("/storico" o "/storico?..."): qualunque altra cosa
 * (indirizzi esterni, "//sito", altre pagine, a capo) si ignora, così non nasce un redirect aperto.
 */
final class ReturnUrl
{
    public static function pick(mixed $value, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }

        $isHistory = $value === '/storico' || str_starts_with($value, '/storico?');
        // Niente caratteri di controllo (a capo, tab...) né backslash: non devono finire nell'header Location.
        if (!$isHistory || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            return $default;
        }

        return $value;
    }
}
