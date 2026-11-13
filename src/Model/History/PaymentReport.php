<?php

declare(strict_types=1);

namespace LogiDock\Model\History;

/**
 * La scheda Incassi: le righe (al massimo 200) e i tre totali, calcolati su TUTTE le richieste che
 * rispettano i filtri (non solo sulle righe mostrate). Importi come stringhe decimali a due cifre.
 */
final readonly class PaymentReport
{
    /**
     * @param list<PaymentHistoryRow> $rows
     * @param string $collectedTotal incassato (richieste pagate)
     * @param string $dueTotal ancora da incassare (spedite, non pagate)
     * @param string $partialTotal spedito ma non ancora incassabile (richieste parziali)
     */
    public function __construct(
        public array $rows,
        public string $collectedTotal,
        public string $dueTotal,
        public string $partialTotal
    ) {
    }
}
