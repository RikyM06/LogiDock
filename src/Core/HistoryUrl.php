<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Gli indirizzi dello storico: le schede e l'URL della scheda corrente con i suoi filtri.
 * Serve ai form "Segna pagata" per tornare esattamente alla stessa lista (campo "return").
 */
final class HistoryUrl
{
    public const DEFAULT_TAB = 'viaggi';

    /** Scheda => nome mostrato. */
    public const TABS = [
        'viaggi' => 'Viaggi',
        'fornitori' => 'Fornitori',
        'incassi' => 'Incassi',
        'documenti' => 'Documenti',
    ];

    /** Filtri che ogni scheda usa (nomi dell'URL). */
    private const TAB_FILTERS = [
        'viaggi' => ['dal', 'al', 'giro', 'stato'],
        'fornitori' => ['dal', 'al', 'fornitore'],
        'incassi' => ['dal', 'al', 'cliente', 'pagamento'],
        'documenti' => ['dal', 'al', 'tipo', 'cliente'],
    ];

    /** Nome nell'URL => chiave dei filtri normalizzati (vedi HistoryService::normalizeFilters). */
    private const URL_TO_FILTER = [
        'dal' => 'from',
        'al' => 'to',
        'giro' => 'round',
        'stato' => 'status',
        'fornitore' => 'supplier',
        'cliente' => 'customer',
        'pagamento' => 'payment',
        'tipo' => 'type',
    ];

    /** Una scheda sconosciuta (o non testuale) diventa quella di default. */
    public static function tab(mixed $value): string
    {
        return is_string($value) && isset(self::TABS[$value]) ? $value : self::DEFAULT_TAB;
    }

    /**
     * "/storico?scheda=...&filtri" con i soli filtri della scheda che hanno un valore.
     * http_build_query codifica gli spazi, quindi il risultato è accettato da ReturnUrl::pick().
     *
     * @param array<string, mixed> $filters filtri già normalizzati
     */
    public static function build(string $tab, array $filters): string
    {
        $tab = self::tab($tab);
        $query = ['scheda' => $tab];
        foreach (self::TAB_FILTERS[$tab] as $urlName) {
            $value = $filters[self::URL_TO_FILTER[$urlName]] ?? null;
            if ($value !== null && $value !== '') {
                $query[$urlName] = (string) $value;
            }
        }

        return '/storico?' . http_build_query($query);
    }
}
