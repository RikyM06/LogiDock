<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * La regola dell'avviso di giacenza, scritta in un posto solo.
 *
 * Un bancale è "fermo" quando è in magazzino da PIÙ giorni della soglia (impostazione
 * aging_threshold_days, 2 di partenza). I giorni sono giorni di calendario contati dalla data di
 * arrivo, anche se nel frattempo il bancale è stato spostato: con soglia 2, un bancale arrivato
 * lunedì è in magazzino da 3 giorni giovedì, e da giovedì è segnalato.
 * Da quando contare i giorni è un'ipotesi da confermare con l'azienda (domanda nella issue #26).
 *
 * Non usa il database: i test la provano con date fisse, senza dipendere dal giorno in cui girano.
 */
final readonly class AgingRule
{
    private string $today;

    /**
     * @param DateTimeImmutable $today la data di oggi nel fuso dell'applicazione (conta solo il giorno)
     */
    public function __construct(public int $thresholdDays, DateTimeImmutable $today)
    {
        $this->today = $today->format('Y-m-d');
    }

    /**
     * Giorni di calendario passati dall'arrivo. Accetta "2026-11-06" o "2026-11-06 14:30:00";
     * non è mai negativo (un arrivo nel futuro non dovrebbe esistere, ma conta come zero).
     */
    public function daysInStock(string $receivedAt): int
    {
        // Le date si confrontano in UTC: così il cambio dell'ora legale non sposta i giorni.
        $utc = new DateTimeZone('UTC');
        $arrival = new DateTimeImmutable(substr($receivedAt, 0, 10), $utc);
        $today = new DateTimeImmutable($this->today, $utc);

        return max(0, (int) $arrival->diff($today)->format('%r%a'));
    }

    public function isAging(string $receivedAt): bool
    {
        return $this->daysInStock($receivedAt) > $this->thresholdDays;
    }

    /**
     * La stessa regola detta al database: un bancale è fermo se è arrivato PRIMA di questa data.
     * Esempio: oggi 9/11 e soglia 2 danno "2026-11-07"; un arrivo del 6/11 (3 giorni) è fermo,
     * uno del 7/11 (2 giorni) no.
     */
    public function receivedBefore(): string
    {
        return (new DateTimeImmutable($this->today, new DateTimeZone('UTC')))
            ->modify('-' . $this->thresholdDays . ' days')
            ->format('Y-m-d');
    }
}
