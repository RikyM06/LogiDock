<?php

declare(strict_types=1);

namespace LogiDock\Model\History;

use LogiDock\Core\Pagination;

/**
 * La scheda Incassi: le righe della pagina e i tre totali, calcolati su TUTTE le richieste che
 * rispettano i filtri (non solo sulle righe della pagina). Importi come stringhe decimali a due cifre.
 */
final readonly class PaymentReport
{
    /**
     * @param list<PaymentHistoryRow> $rows
     * @param string $collectedTotal incassato (richieste pagate)
     * @param string $dueTotal ancora da incassare (spedite, non pagate)
     * @param string $partialTotal spedito ma non ancora incassabile (richieste parziali)
     * @param Pagination $pagination la pagina mostrata e il totale delle richieste
     */
    public function __construct(
        public array $rows,
        public string $collectedTotal,
        public string $dueTotal,
        public string $partialTotal,
        public Pagination $pagination
    ) {
    }
}
