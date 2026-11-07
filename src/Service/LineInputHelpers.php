<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use LogiDock\Core\ValidationException;
use LogiDock\Core\Validator;

/**
 * Piccoli aiuti per i form con una testata e più righe (arrivi, richieste dei clienti).
 * È un trait perché sono metodi privati di comodo, uguali in più service, senza stato proprio.
 */
trait LineInputHelpers
{
    /**
     * Gli id di un elenco di record (servono come "valori ammessi" per i menu a tendina).
     *
     * @param iterable<object> $items
     * @return list<int>
     */
    private function idsOf(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item->id;
        }

        return $ids;
    }

    /**
     * Una riga del form lasciata completamente vuota: si ignora (il form ne mostra qualcuna in più).
     *
     * @param array<string, mixed> $line
     */
    private function isBlank(array $line): bool
    {
        foreach ($line as $value) {
            if (!is_scalar($value) || trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Data valida nel formato AAAA-MM-GG (in questo formato l'ordine alfabetico è quello delle date). */
    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** Il validatore ha già annotato errori su qualche riga ("lines.<n>.<campo>")? */
    private function hasLineErrors(Validator $validator): bool
    {
        try {
            $validator->assertValid();
        } catch (ValidationException $e) {
            foreach (array_keys($e->errors()) as $field) {
                if (str_starts_with($field, 'lines.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
