<?php

declare(strict_types=1);

namespace LogiDock\Core;

use InvalidArgumentException;

/**
 * Conti esatti su quantità e pesi senza usare i float.
 *
 * Nel database quantità e chili sono DECIMAL con al massimo tre decimali, e in PHP viaggiano come
 * stringhe ("12.500"). Per sommarli e confrontarli si trasformano in millesimi interi (12.500 kg
 * = 12500 grammi): con gli interi 0,1 + 0,2 fa esattamente 0,3, con i float no.
 */
final class Thousandths
{
    /**
     * "12.5" -> 12500. Accetta solo numeri scritti come li scrive MySQL (punto, massimo tre decimali).
     */
    public static function fromDecimal(string $decimal): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,3}))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException('Numero decimale non valido: ' . $decimal);
        }

        // "5" diventa "500": i decimali mancanti a destra sono zeri.
        $value = (int) $matches[2] * 1000 + (int) str_pad($matches[3] ?? '', 3, '0');

        return $matches[1] === '-' ? -$value : $value;
    }

    /**
     * 12500 -> "12.500": sempre tre decimali, come una colonna DECIMAL(x,3).
     */
    public static function toDecimal(int $thousandths): string
    {
        $absolute = abs($thousandths);

        return sprintf('%s%d.%03d', $thousandths < 0 ? '-' : '', intdiv($absolute, 1000), $absolute % 1000);
    }
}
