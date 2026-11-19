<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Page;
use LogiDock\Core\Pagination;
use LogiDock\Core\Thousandths;
use LogiDock\Model\History\DocumentHistoryRow;
use LogiDock\Model\History\PaymentHistoryRow;
use LogiDock\Model\History\PaymentReport;
use LogiDock\Model\History\SupplierHistoryRow;
use LogiDock\Model\History\TripHistoryRow;
use LogiDock\Model\Trip;
use LogiDock\Model\TransportDocument;
use LogiDock\Repository\HistoryRepository;

/**
 * I report dello storico (viaggi, fornitori, incassi, documenti): filtri validati, limite di righe e
 * regola sul compenso. Il compenso dei trasportatori esce solo per un admin: per chi non lo è i dati
 * vengono tolti qui, non nella vista.
 */
final class HistoryService
{
    private const PAYMENT_STATES = [PaymentHistoryRow::PAID, PaymentHistoryRow::UNPAID, PaymentHistoryRow::PARTIAL];

    public function __construct(private readonly HistoryRepository $history)
    {
    }

    /**
     * Trasforma i parametri GET in filtri sicuri. Mai un errore: un valore sporco diventa null (= nessun filtro).
     *
     * Nomi in ingresso (URL) -> chiavi in uscita: dal -> from, al -> to, giro -> round, stato -> status,
     * fornitore -> supplier, cliente -> customer, pagamento -> payment, tipo -> type.
     * Date solo AAAA-MM-GG vere; se "dal" è dopo "al" si scambiano. "stato" solo tra gli stati dei viaggi,
     * "pagamento" solo paid|unpaid|partial, "tipo" solo load_list|ddt (e compensation solo per l'admin).
     *
     * @param array<string, mixed> $query
     * @return array{from: ?string, to: ?string, round: ?int, status: ?string, supplier: ?int, customer: ?int, payment: ?string, type: ?string}
     */
    public function normalizeFilters(array $query, bool $isAdmin): array
    {
        $from = $this->date($query['dal'] ?? null);
        $to = $this->date($query['al'] ?? null);
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $status = $this->text($query['stato'] ?? null);
        $payment = $this->text($query['pagamento'] ?? null);
        $allowedTypes = [TransportDocument::TYPE_LOAD_LIST, TransportDocument::TYPE_DDT];
        if ($isAdmin) {
            $allowedTypes[] = TransportDocument::TYPE_COMPENSATION;
        }
        $type = $this->text($query['tipo'] ?? null);

        return [
            'from' => $from,
            'to' => $to,
            'round' => $this->id($query['giro'] ?? null),
            'status' => isset(Trip::STATUS_LABELS[$status ?? '']) ? $status : null,
            'supplier' => $this->id($query['fornitore'] ?? null),
            'customer' => $this->id($query['cliente'] ?? null),
            'payment' => in_array($payment, self::PAYMENT_STATES, true) ? $payment : null,
            'type' => in_array($type, $allowedTypes, true) ? $type : null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return Page<TripHistoryRow>
     */
    public function trips(array $filters, bool $isAdmin, mixed $requestedPage, int $perPage): Page
    {
        $pagination = new Pagination($this->history->countTrips($filters), $perPage, $requestedPage);
        $rows = $this->history->trips($filters, $pagination->perPage, $pagination->offset());

        return new Page(
            $isAdmin ? $rows : array_map(static fn (TripHistoryRow $row): TripHistoryRow => $row->withoutCompensation(), $rows),
            $pagination
        );
    }

    /**
     * Una riga per fornitore e prodotto (righe piatte: la vista raggruppa per fornitore).
     *
     * @param array<string, mixed> $filters
     * @return Page<SupplierHistoryRow>
     */
    public function suppliers(array $filters, mixed $requestedPage, int $perPage): Page
    {
        $pagination = new Pagination($this->history->countSuppliers($filters), $perPage, $requestedPage);

        return new Page($this->history->suppliers($filters, $pagination->perPage, $pagination->offset()), $pagination);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function payments(array $filters, mixed $requestedPage, int $perPage): PaymentReport
    {
        // I totali sono sempre su TUTTE le richieste del filtro, non solo su quelle della pagina.
        $totals = $this->history->paymentTotals($filters);
        $pagination = new Pagination($this->history->countPayments($filters), $perPage, $requestedPage);

        return new PaymentReport(
            $this->history->payments($filters, $pagination->perPage, $pagination->offset()),
            $this->total($totals[PaymentHistoryRow::PAID] ?? '0'),
            $this->total($totals[PaymentHistoryRow::UNPAID] ?? '0'),
            $this->total($totals[PaymentHistoryRow::PARTIAL] ?? '0'),
            $pagination
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return Page<DocumentHistoryRow>
     */
    public function documents(array $filters, bool $isAdmin, mixed $requestedPage, int $perPage): Page
    {
        // Anche con un filtro costruito a mano, un non admin non vede mai i compensi.
        if (!$isAdmin && ($filters['type'] ?? null) === TransportDocument::TYPE_COMPENSATION) {
            return new Page([], new Pagination(0, $perPage, 1));
        }

        $pagination = new Pagination($this->history->countDocuments($filters, $isAdmin), $perPage, $requestedPage);

        return new Page($this->history->documents($filters, $isAdmin, $pagination->perPage, $pagination->offset()), $pagination);
    }

    /** Importo a due decimali passando dai millesimi interi (mai da float). */
    private function total(string $decimal): string
    {
        return substr(Thousandths::toDecimal(Thousandths::fromDecimal($decimal)), 0, -1);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /** Un id valido è un intero positivo scritto con sole cifre. */
    private function id(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9]\d{0,17}\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
