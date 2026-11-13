<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\History\DocumentHistoryRow;
use LogiDock\Model\History\PaymentHistoryRow;
use LogiDock\Model\History\SupplierHistoryRow;
use LogiDock\Model\History\TripHistoryRow;

/**
 * Solo letture, per le quattro schede dello storico. Nessuna regola: i filtri arrivano già validati
 * dal service (valori null = nessun filtro) e passano sempre come parametri. Le liste sono limitate.
 *
 * Gli importi usano TransportDocumentRepository::LINE_TOTAL_SQL (alias ti = trip_items, ol = order_lines),
 * la stessa espressione del DDT, e contano solo i viaggi partiti (in_transit o completed).
 *
 * @phpstan-type Filters array{from: ?string, to: ?string, round: ?int, status: ?string, supplier: ?int, customer: ?int, payment: ?string, type: ?string}
 */
final class HistoryRepository
{
    private const DEPARTED = "('in_transit', 'completed')";

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Viaggi del periodo (giorno pianificato), tutti gli stati. Il totale fatturato c'è solo per i partiti.
     * Il compenso (fee, data di pagamento) viene letto sempre: è il service a toglierlo a chi non è admin.
     *
     * @param array<string, mixed> $filters
     * @return list<TripHistoryRow>
     */
    public function trips(array $filters, int $limit): array
    {
        [$where, $params] = $this->conditions([
            ['t.planned_date >= ?', $filters['from'] ?? null],
            ['t.planned_date <= ?', $filters['to'] ?? null],
            ['t.round_id = ?', $filters['round'] ?? null],
            ['t.status = ?', $filters['status'] ?? null],
        ]);

        $statement = $this->database->pdo()->prepare(
            'SELECT t.id, t.code, t.planned_date, r.name AS round_name, v.plate AS vehicle_plate,
                    ca.name AS carrier_name, t.status, t.carrier_fee, t.compensation_paid_at,
                    COUNT(ti.id) AS pallet_count,
                    COUNT(DISTINCT o.customer_id) AS customer_count,
                    SUM(CASE WHEN t.status IN ' . self::DEPARTED . ' THEN ' . TransportDocumentRepository::LINE_TOTAL_SQL . ' END) AS billed_total
             FROM trips t
             JOIN rounds r ON r.id = t.round_id
             JOIN vehicles v ON v.id = t.vehicle_id
             LEFT JOIN carriers ca ON ca.id = t.carrier_id
             LEFT JOIN trip_items ti ON ti.trip_id = t.id
             LEFT JOIN orders o ON o.id = ti.order_id
             LEFT JOIN order_lines ol ON ol.id = ti.order_line_id' . $where . '
             GROUP BY t.id, r.name, v.plate, ca.name
             ORDER BY t.planned_date DESC, t.id DESC
             LIMIT ' . max(1, $limit)
        );
        $statement->execute($params);

        return array_map(TripHistoryRow::fromRow(...), $statement->fetchAll());
    }

    /**
     * Merce fornita per fornitore e prodotto (righe piatte), nel periodo di ricevimento.
     * La quantità è la somma dei movimenti "inbound": dopo la partenza il bancale ha quantità 0, il
     * movimento di ingresso invece resta, quindi il dato non cambia. Il valore è quantità x prezzo d'acquisto.
     *
     * @param array<string, mixed> $filters
     * @return list<SupplierHistoryRow>
     */
    public function suppliers(array $filters, int $limit): array
    {
        [$where, $params] = $this->conditions([
            ['sd.received_date >= ?', $filters['from'] ?? null],
            ['sd.received_date <= ?', $filters['to'] ?? null],
            ['sd.supplier_id = ?', $filters['supplier'] ?? null],
        ]);

        $statement = $this->database->pdo()->prepare(
            "SELECT s.id AS supplier_id, s.name AS supplier_name, pr.id AS product_id, pr.code AS product_code,
                    pr.description AS product_description, pr.unit_of_measure,
                    COUNT(DISTINCT sd.id) AS document_count,
                    COUNT(DISTINCT p.id) AS pallet_count,
                    SUM(m.quantity_delta) AS quantity,
                    ROUND(COALESCE(SUM(m.quantity_delta * p.unit_price), 0), 2) AS purchase_value
             FROM supplier_documents sd
             JOIN suppliers s ON s.id = sd.supplier_id
             JOIN pallets p ON p.supplier_document_id = sd.id
             JOIN pallet_movements m ON m.pallet_id = p.id AND m.type = 'inbound'
             JOIN products pr ON pr.id = p.product_id" . $where . '
             GROUP BY s.id, s.name, pr.id, pr.code, pr.description, pr.unit_of_measure
             ORDER BY s.name, pr.code
             LIMIT ' . max(1, $limit)
        );
        $statement->execute($params);

        return array_map(SupplierHistoryRow::fromRow(...), $statement->fetchAll());
    }

    /**
     * Richieste con almeno un viaggio partito (periodo = giorno di consegna). Stato di pagamento:
     * paid, unpaid (spedita e non pagata) o partial (non ancora spedita del tutto). Importo = righe già partite.
     *
     * @param array<string, mixed> $filters
     * @return list<PaymentHistoryRow>
     */
    public function payments(array $filters, int $limit): array
    {
        [$sql, $params] = $this->paymentsSql($filters);

        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM (' . $sql . ') x ORDER BY x.delivery_date DESC, x.id DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute($params);

        return array_map(PaymentHistoryRow::fromRow(...), $statement->fetchAll());
    }

    /**
     * Totale per stato di pagamento su TUTTE le richieste che rispettano i filtri (non solo quelle mostrate).
     * Le somme le fa MySQL in DECIMAL. Una pagata conta per l'importo pagato (fotografato).
     *
     * @param array<string, mixed> $filters
     * @return array<string, string> stato => somma ("120.00"); gli stati senza righe mancano
     */
    public function paymentTotals(array $filters): array
    {
        [$sql, $params] = $this->paymentsSql($filters);

        $statement = $this->database->pdo()->prepare(
            "SELECT x.payment_state, SUM(CASE WHEN x.payment_state = 'paid' THEN x.paid_amount ELSE x.amount END) AS total
             FROM (" . $sql . ') x GROUP BY x.payment_state'
        );
        $statement->execute($params);

        $totals = [];
        foreach ($statement->fetchAll() as $row) {
            $totals[(string) $row['payment_state']] = (string) $row['total'];
        }

        return $totals;
    }

    /**
     * Documenti emessi nel periodo (data di emissione), con i filtri tipo e cliente.
     *
     * @param array<string, mixed> $filters
     * @param bool $includeCompensation false = i prospetti del compenso non escono mai (solo admin)
     * @return list<DocumentHistoryRow>
     */
    public function documents(array $filters, bool $includeCompensation, int $limit): array
    {
        $rules = [
            ['d.issued_at >= ?', $filters['from'] ?? null],
            // "al" è un giorno intero: fino a mezzanotte del giorno dopo, estremo escluso.
            ['d.issued_at < DATE_ADD(?, INTERVAL 1 DAY)', $filters['to'] ?? null],
            ['d.type = ?', $filters['type'] ?? null],
            ['d.customer_id = ?', $filters['customer'] ?? null],
        ];
        [$where, $params] = $this->conditions($rules);
        if (!$includeCompensation) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . "d.type <> 'compensation'";
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT d.id, d.type, d.year, d.number, d.trip_id, t.code AS trip_code, c.name AS customer_name, d.issued_at
             FROM transport_documents d
             JOIN trips t ON t.id = d.trip_id
             LEFT JOIN customers c ON c.id = d.customer_id' . $where . '
             ORDER BY d.issued_at DESC, d.id DESC
             LIMIT ' . max(1, $limit)
        );
        $statement->execute($params);

        return array_map(DocumentHistoryRow::fromRow(...), $statement->fetchAll());
    }

    /**
     * Query base degli incassi (una riga per richiesta), usata sia per le righe sia per i totali.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<string|int>}
     */
    private function paymentsSql(array $filters): array
    {
        $rules = [
            ['o.delivery_date >= ?', $filters['from'] ?? null],
            ['o.delivery_date <= ?', $filters['to'] ?? null],
            ['o.customer_id = ?', $filters['customer'] ?? null],
        ];
        $payment = $filters['payment'] ?? null;
        $paymentConditions = [
            'paid' => 'o.paid_at IS NOT NULL',
            'unpaid' => "o.paid_at IS NULL AND o.status = 'shipped'",
            'partial' => "o.paid_at IS NULL AND o.status <> 'shipped'",
        ];
        [$where, $params] = $this->conditions($rules);
        $where .= ($where === '' ? ' WHERE ' : ' AND ') . "o.status <> 'cancelled'";
        if (is_string($payment) && isset($paymentConditions[$payment])) {
            $where .= ' AND ' . $paymentConditions[$payment];
        }

        $sql = "SELECT o.id, o.code, o.customer_id, c.name AS customer_name, o.delivery_date, o.paid_at, o.paid_amount,
                       SUM(" . TransportDocumentRepository::LINE_TOTAL_SQL . ") AS amount,
                       CASE WHEN o.paid_at IS NOT NULL THEN 'paid'
                            WHEN o.status = 'shipped' THEN 'unpaid'
                            ELSE 'partial' END AS payment_state
                FROM orders o
                JOIN customers c ON c.id = o.customer_id
                JOIN trip_items ti ON ti.order_id = o.id
                JOIN trips t ON t.id = ti.trip_id AND t.status IN " . self::DEPARTED . '
                JOIN order_lines ol ON ol.id = ti.order_line_id' . $where . '
                GROUP BY o.id, o.code, o.customer_id, c.name, o.delivery_date, o.paid_at, o.paid_amount, o.status';

        return [$sql, $params];
    }

    /**
     * Costruisce " WHERE a AND b" con le sole condizioni il cui valore non è null. Le condizioni sono
     * testo fisso scritto in questa classe: i valori viaggiano sempre come parametri.
     *
     * @param list<array{0: string, 1: string|int|null}> $rules
     * @return array{0: string, 1: list<string|int>}
     */
    private function conditions(array $rules): array
    {
        $conditions = [];
        $params = [];
        foreach ($rules as [$condition, $value]) {
            if ($value !== null) {
                $conditions[] = $condition;
                $params[] = $value;
            }
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }
}
