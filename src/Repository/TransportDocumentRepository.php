<?php

declare(strict_types=1);

namespace LogiDock\Repository;

use LogiDock\Core\Database;
use LogiDock\Model\DocumentLine;
use LogiDock\Model\TransportDocument;

/**
 * Solo SQL sui documenti di trasporto emessi e sui dati che servono a scriverli. Il PDF sta nel
 * database (colonna pdf_content): si legge a parte con pdfOf(), perché è la parte pesante.
 */
final class TransportDocumentRepository
{
    /**
     * Importo di una riga di documento (quantità del bancale x prezzo della richiesta, due decimali).
     * Unica definizione: la usano il DDT e il totale da pagare, così le due cifre non possono divergere.
     * Alias da usare: ti = trip_items, ol = order_lines.
     */
    public const LINE_TOTAL_SQL = 'ROUND(ti.quantity * ol.unit_price, 2)';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * I bancali di un viaggio come righe di documento: cliente, prodotto, quantità e prezzo di vendita
     * della richiesta. L'importo di riga (quantità x prezzo, due decimali) lo calcola MySQL in DECIMAL,
     * non PHP. Stesso ordine della pagina del viaggio: cliente per cliente.
     *
     * Il JOIN su order_lines è volutamente "stretto": una riga senza prezzo non deve sparire in silenzio
     * da un documento, e il service controlla che il numero di righe sia quello dei bancali del viaggio.
     *
     * @return list<DocumentLine>
     */
    public function linesOfTrip(int $tripId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT c.id AS customer_id, c.name AS customer_name, c.tax_code AS customer_tax_code,
                    c.address AS customer_address, o.code AS order_code,
                    p.code AS pallet_code, pr.code AS product_code, pr.description AS product_description,
                    pr.unit_of_measure, p.lot_number, ti.quantity, ti.weight_kg,
                    ol.unit_price, ' . self::LINE_TOTAL_SQL . ' AS line_total
             FROM trip_items ti
             JOIN pallets p ON p.id = ti.pallet_id
             JOIN products pr ON pr.id = p.product_id
             JOIN orders o ON o.id = ti.order_id
             JOIN order_lines ol ON ol.id = ti.order_line_id
             JOIN customers c ON c.id = o.customer_id
             WHERE ti.trip_id = ?
             ORDER BY c.name, c.id, o.id, ti.sort_order, ti.id'
        );
        $statement->execute([$tripId]);

        return array_map(DocumentLine::fromRow(...), $statement->fetchAll());
    }

    /**
     * Salva un documento emesso con il suo PDF. Il numero (tipo, anno, numero) è unico: lo garantisce
     * il database, oltre al contatore di NumberSequenceRepository.
     *
     * @param string $issuedAt "AAAA-MM-GG HH:MM:SS" in UTC
     */
    public function insert(
        int $tripId,
        ?int $customerId,
        string $type,
        int $year,
        int $number,
        string $issuedAt,
        string $pdf,
        int $userId
    ): int {
        $this->database->pdo()
            ->prepare(
                'INSERT INTO transport_documents (trip_id, customer_id, type, year, number, issued_at, pdf_content, issued_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )
            ->execute([$tripId, $customerId, $type, $year, $number, $issuedAt, $pdf, $userId]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    /**
     * I documenti di un viaggio: prima il carico, poi i DDT (cliente per cliente), poi il compenso.
     *
     * @param bool $includeAdminOnly false = senza il prospetto del compenso (lo vede solo l'admin)
     * @return list<TransportDocument>
     */
    public function forTrip(int $tripId, bool $includeAdminOnly): array
    {
        $statement = $this->database->pdo()->prepare(
            $this->baseSelect() . ' WHERE d.trip_id = ?' . ($includeAdminOnly ? '' : " AND d.type <> 'compensation'")
            . " ORDER BY FIELD(d.type, 'load_list', 'ddt', 'compensation'), c.name, d.id"
        );
        $statement->execute([$tripId]);

        return array_map(TransportDocument::fromRow(...), $statement->fetchAll());
    }

    public function find(int $id): ?TransportDocument
    {
        $statement = $this->database->pdo()->prepare($this->baseSelect() . ' WHERE d.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : TransportDocument::fromRow($row);
    }

    /** Il PDF di un documento, esattamente com'era quando è stato emesso. */
    public function pdfOf(int $id): ?string
    {
        $statement = $this->database->pdo()->prepare('SELECT pdf_content FROM transport_documents WHERE id = ?');
        $statement->execute([$id]);
        $content = $statement->fetchColumn();

        return $content === false ? null : (string) $content;
    }

    private function baseSelect(): string
    {
        return 'SELECT d.id, d.trip_id, t.code AS trip_code, d.type, d.year, d.number, d.issued_at,
                    d.customer_id, c.name AS customer_name, u.name AS issued_by_name
                FROM transport_documents d
                JOIN trips t ON t.id = d.trip_id
                JOIN users u ON u.id = d.issued_by
                LEFT JOIN customers c ON c.id = d.customer_id';
    }
}
