<?php

declare(strict_types=1);

namespace LogiDock\Pdf;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogiDock\Core\Thousandths;
use LogiDock\Model\CompanyData;
use LogiDock\Model\DocumentLine;
use LogiDock\Model\Trip;

/**
 * Disegna i tre documenti di un viaggio e restituisce il PDF come stringa di byte.
 *
 * È un calcolo "puro": prende dati già pronti e non sa niente di database né di regole (chi può
 * partire, quale numero usare): quelle stanno in DocumentService. Per questo si prova facilmente.
 *
 * Quantità, pesi e importi arrivano come stringhe decimali e si sommano in millesimi interi
 * (Core\Thousandths): mai float, come nel resto dell'applicazione.
 */
final class DocumentRenderer
{
    /** Altezza di una riga di tabella, in millimetri. */
    private const ROW = 6.0;

    /**
     * @param bool $compress false solo nei test: con i flussi compressi il testo non si può cercare nel PDF
     */
    public function __construct(private readonly bool $compress = true)
    {
    }

    /**
     * Documento di carico: tutto quello che è sul camion, tappa per tappa, senza prezzi.
     *
     * @param list<DocumentLine> $lines i bancali del viaggio, cliente per cliente
     * @param string             $issuedAt quando è stato emesso, "AAAA-MM-GG HH:MM:SS" in UTC
     */
    public function loadList(CompanyData $company, Trip $trip, array $lines, string $code, string $issuedAt): string
    {
        $p = new Sheet($company, 'Documento di carico', $code, $this->localTime($issuedAt), $this->compress);
        $p->AddPage();

        $this->twoBoxes(
            $p,
            'Viaggio',
            [
                'Codice: ' . $trip->code,
                'Giorno: ' . $this->day($trip->plannedDate),
                'Giro: ' . $trip->roundName,
            ],
            'Mezzo',
            [
                'Targa: ' . $trip->vehiclePlate,
                'Rimorchio: ' . ($trip->trailerTypeName ?? 'non indicato'),
                'Trasportatore: ' . ($trip->carrierName ?? 'non indicato'),
            ]
        );

        $columns = [
            ['Bancale', 32.0, 'L'],
            ['Prodotto', 70.0, 'L'],
            ['Richiesta', 28.0, 'L'],
            ['Quantità', 28.0, 'R'],
            ['Peso (kg)', 22.0, 'R'],
        ];
        $this->tableHead($p, $columns);

        $customerId = null;
        foreach ($lines as $line) {
            if ($line->customerId !== $customerId) {
                $customerId = $line->customerId;
                // Intestazione della tappa + almeno una riga: non si lascia da solo in fondo alla pagina.
                $this->ensureSpace($p, self::ROW * 2, $columns);
                $this->groupRow($p, $line);
            }

            $this->ensureSpace($p, self::ROW, $columns);
            $this->row($p, $columns, [
                $line->palletCode,
                $line->productCode . ' ' . $line->productDescription,
                $line->orderCode,
                $this->decimal($line->quantity, 0, 3) . ' ' . $line->unitOfMeasure,
                $this->decimal($line->weightKg, 0, 3),
            ]);
        }

        $this->ensureSpace($p, 52.0, null);
        $this->totals($p, [
            'Bancali' => (string) count($lines),
            'Tappe (clienti)' => (string) count(array_unique(array_map(static fn (DocumentLine $l): int => $l->customerId, $lines))),
            'Peso totale' => $this->decimal($this->totalWeight($lines), 0, 3) . ' kg',
        ]);
        $this->signatures($p, 'Firma del conducente (carico verificato)', 'Firma del magazziniere');

        return $p->Output('S');
    }

    /**
     * DDT (documento di trasporto) di UN cliente, con i prezzi di vendita.
     *
     * @param non-empty-list<DocumentLine> $lines i bancali di quel cliente
     * @param string                       $issuedAt "AAAA-MM-GG HH:MM:SS" in UTC
     */
    public function ddt(CompanyData $company, Trip $trip, array $lines, string $code, string $issuedAt): string
    {
        if ($lines === []) {
            throw new InvalidArgumentException('Un DDT ha bisogno di almeno una riga.');
        }

        $customer = $lines[0];
        $orders = implode(', ', array_values(array_unique(array_map(static fn (DocumentLine $l): string => $l->orderCode, $lines))));

        $p = new Sheet($company, 'Documento di trasporto (DDT)', $code, $this->localTime($issuedAt), $this->compress);
        $p->AddPage();

        $recipient = [$customer->customerName];
        if ($customer->customerAddress !== null && $customer->customerAddress !== '') {
            $recipient[] = $customer->customerAddress;
        }
        if ($customer->customerTaxCode !== null && $customer->customerTaxCode !== '') {
            $recipient[] = 'P. IVA / C.F.: ' . $customer->customerTaxCode;
        }
        $recipient[] = 'Richiesta: ' . $orders;

        $this->twoBoxes(
            $p,
            'Destinatario',
            $recipient,
            'Trasporto',
            [
                'Causale: vendita',
                'Trasporto a cura del mittente',
                'Viaggio: ' . $trip->code . ' del ' . $this->day($trip->plannedDate),
                'Vettore: ' . ($trip->carrierName ?? 'non indicato'),
                'Targa: ' . $trip->vehiclePlate,
            ]
        );

        $columns = [
            ['Bancale', 30.0, 'L'],
            ['Prodotto', 60.0, 'L'],
            ['Lotto', 20.0, 'L'],
            ['Quantità', 24.0, 'R'],
            ['Prezzo (€)', 20.0, 'R'],
            ['Importo (€)', 26.0, 'R'],
        ];
        $this->tableHead($p, $columns);

        foreach ($lines as $line) {
            $this->ensureSpace($p, self::ROW, $columns);
            $this->row($p, $columns, [
                $line->palletCode,
                $line->productCode . ' ' . $line->productDescription,
                $line->lotNumber ?? '',
                $this->decimal($line->quantity, 0, 3) . ' ' . $line->unitOfMeasure,
                $this->decimal($line->unitPrice, 2, 4),
                $this->decimal($line->lineTotal, 2, 2),
            ]);
        }

        $this->ensureSpace($p, 52.0, null);
        $this->totals($p, [
            'Bancali' => (string) count($lines),
            'Peso totale' => $this->decimal($this->totalWeight($lines), 0, 3) . ' kg',
            'Totale merce (IVA esclusa)' => $this->decimal($this->totalAmount($lines), 2, 2) . ' €',
        ]);
        $this->signatures($p, 'Firma del conducente', 'Firma del destinatario (per ricevuta)');

        return $p->Output('S');
    }

    /**
     * Prospetto del compenso del trasportatore per un viaggio: l'importo fisso fotografato alla conferma.
     *
     * @param string $issuedAt "AAAA-MM-GG HH:MM:SS" in UTC
     * @throws InvalidArgumentException se il viaggio non ha un trasportatore con un compenso
     */
    public function compensation(CompanyData $company, Trip $trip, string $code, string $issuedAt): string
    {
        if ($trip->carrierName === null || $trip->carrierFee === null) {
            throw new InvalidArgumentException('Il viaggio non ha un trasportatore con un compenso.');
        }

        $p = new Sheet($company, 'Compenso del trasportatore', $code, $this->localTime($issuedAt), $this->compress);
        $p->AddPage();

        $carrier = [$trip->carrierName];
        if ($trip->carrierTaxCode !== null && $trip->carrierTaxCode !== '') {
            $carrier[] = 'P. IVA / C.F.: ' . $trip->carrierTaxCode;
        }

        $this->twoBoxes(
            $p,
            'Trasportatore',
            $carrier,
            'Viaggio',
            [
                'Codice: ' . $trip->code,
                'Giorno: ' . $this->day($trip->plannedDate),
                'Giro: ' . $trip->roundName,
                'Targa: ' . $trip->vehiclePlate,
            ]
        );

        $columns = [
            ['Descrizione', 150.0, 'L'],
            ['Importo (€)', 30.0, 'R'],
        ];
        $this->tableHead($p, $columns);
        $this->row($p, $columns, [
            'Trasporto del viaggio ' . $trip->code . ' (' . $trip->palletCount . ' bancali)',
            $this->decimal($trip->carrierFee, 2, 2),
        ]);

        $p->Ln(4);
        $this->totals($p, ['Totale compenso' => $this->decimal($trip->carrierFee, 2, 2) . ' €']);

        $p->SetFont('Helvetica', '', 8.5);
        $p->SetTextColor(...Sheet::MUTED);
        $p->MultiCell(Sheet::WIDTH, 4.2, $p->t(
            'Compenso fisso per viaggio, stabilito per questo trasportatore. L\'importo è quello registrato alla conferma del viaggio: '
            . 'se la tariffa cambia in seguito, questo documento non cambia.'
        ));
        $p->SetTextColor(...Sheet::INK);

        $this->signatures($p, 'Firma per l\'azienda', 'Firma del trasportatore (per ricevuta)');

        return $p->Output('S');
    }

    /**
     * Un numero decimale come si legge in Italia: punto per le migliaia, virgola per i decimali.
     * Senza float: si lavora sulla stringa. Si tolgono gli zeri finali dei decimali, ma se ne
     * tengono almeno $minDecimals (2 per gli importi: "7,80", non "7,8") e al massimo $maxDecimals.
     *
     * "1234.5000" con (2, 4) -> "1.234,50"; "96.000" con (0, 3) -> "96"; "12.500" con (0, 3) -> "12,5".
     */
    public function decimal(string $value, int $minDecimals, int $maxDecimals): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Numero decimale non valido: ' . $value);
        }

        [, $sign, $integer] = $matches;
        $fraction = substr($matches[3] ?? '', 0, $maxDecimals);
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $minDecimals, '0');

        // Le migliaia si separano da destra: "1234567" -> "1.234.567".
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer) ?? $integer;

        return $sign . $grouped . ($fraction === '' ? '' : ',' . $fraction);
    }

    /**
     * @param list<DocumentLine> $lines
     */
    private function totalWeight(array $lines): string
    {
        $sum = 0;
        foreach ($lines as $line) {
            $sum += Thousandths::fromDecimal($line->weightKg);
        }

        return Thousandths::toDecimal($sum);
    }

    /**
     * Somma degli importi di riga (già arrotondati a due decimali da MySQL).
     *
     * @param list<DocumentLine> $lines
     */
    private function totalAmount(array $lines): string
    {
        $sum = 0;
        foreach ($lines as $line) {
            $sum += Thousandths::fromDecimal($line->lineTotal);
        }

        return Thousandths::toDecimal($sum);
    }

    /** Il giorno da mostrare, GG/MM/AAAA, da una data AAAA-MM-GG. */
    private function day(string $date): string
    {
        return (new DateTimeImmutable($date))->format('d/m/Y');
    }

    /** L'ora di emissione: nel database è in UTC, sul foglio va nel fuso orario dell'applicazione. */
    private function localTime(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('d/m/Y H:i');
    }

    /**
     * Due riquadri affiancati con un titoletto e qualche riga di testo ciascuno.
     *
     * @param list<string> $left
     * @param list<string> $right
     */
    private function twoBoxes(Sheet $p, string $leftTitle, array $left, string $rightTitle, array $right): void
    {
        $top = $p->GetY();
        $height = 8.0 + max(count($left), count($right)) * 4.8;

        $this->box($p, Sheet::MARGIN, $top, 88.0, $height, $leftTitle, $left);
        $this->box($p, Sheet::MARGIN + 92, $top, 88.0, $height, $rightTitle, $right);

        $p->SetY($top + $height + 6);
    }

    /**
     * @param list<string> $lines
     */
    private function box(Sheet $p, float $x, float $y, float $width, float $height, string $title, array $lines): void
    {
        $p->SetDrawColor(...Sheet::BORDER);
        $p->Rect($x, $y, $width, $height);

        $p->SetXY($x + 2, $y + 1.5);
        $p->SetFont('Helvetica', 'B', 8);
        $p->SetTextColor(...Sheet::BRAND);
        $p->Cell($width - 4, 4.5, $p->t(mb_strtoupper($title)), 0, 2);

        $p->SetFont('Helvetica', '', 9);
        $p->SetTextColor(...Sheet::INK);
        foreach ($lines as $text) {
            $p->Cell($width - 4, 4.8, $p->fit($text, $width - 4), 0, 2);
        }
    }

    /**
     * @param list<array{0: string, 1: float, 2: string}> $columns titolo, larghezza, allineamento
     */
    private function tableHead(Sheet $p, array $columns): void
    {
        $p->SetX(Sheet::MARGIN);
        $p->SetFont('Helvetica', 'B', 8.5);
        $p->SetFillColor(...Sheet::SOFT);
        $p->SetDrawColor(...Sheet::BORDER);
        $p->SetTextColor(...Sheet::INK);

        foreach ($columns as [$title, $width, $align]) {
            $p->Cell($width, self::ROW + 0.5, $p->t($title), 1, 0, $align, true);
        }

        $p->Ln();
        $p->SetFont('Helvetica', '', 8.5);
    }

    /**
     * Va a una nuova pagina se la prossima riga non ci sta (stessa regola con cui FPDF andrebbe a capo
     * da solo, ma così si ripete l'intestazione della tabella e non si spezza una riga a metà).
     *
     * @param list<array{0: string, 1: float, 2: string}>|null $columns la tabella da riprendere, o null
     */
    private function ensureSpace(Sheet $p, float $height, ?array $columns): void
    {
        if ($p->GetY() + $height <= $p->GetPageHeight() - Sheet::BOTTOM) {
            return;
        }

        $p->AddPage();
        if ($columns !== null) {
            $this->tableHead($p, $columns);
        }
    }

    /**
     * @param list<array{0: string, 1: float, 2: string}> $columns
     * @param list<string>                                $cells
     */
    private function row(Sheet $p, array $columns, array $cells): void
    {
        $p->SetX(Sheet::MARGIN);
        $p->SetFont('Helvetica', '', 8.5);
        $p->SetTextColor(...Sheet::INK);

        foreach ($columns as $index => [, $width, $align]) {
            $p->Cell($width, self::ROW, $p->fit($cells[$index], $width), 1, 0, $align);
        }

        $p->Ln();
    }

    /** La riga che apre la tappa di un cliente nel documento di carico. */
    private function groupRow(Sheet $p, DocumentLine $line): void
    {
        $text = $line->customerName;
        if ($line->customerAddress !== null && $line->customerAddress !== '') {
            $text .= ' - ' . $line->customerAddress;
        }

        $p->SetX(Sheet::MARGIN);
        $p->SetFont('Helvetica', 'B', 8.5);
        $p->SetFillColor(...Sheet::SOFT);
        $p->SetTextColor(...Sheet::INK);
        $p->Cell(Sheet::WIDTH, self::ROW, $p->fit($text, Sheet::WIDTH), 1, 1, 'L', true);
        $p->SetFont('Helvetica', '', 8.5);
    }

    /**
     * Il riepilogo sotto la tabella, allineato a destra: etichetta e valore.
     *
     * @param array<string, string> $values
     */
    private function totals(Sheet $p, array $values): void
    {
        $p->Ln(3);
        $labelWidth = 70.0;
        $valueWidth = 40.0;
        $x = Sheet::MARGIN + Sheet::WIDTH - $labelWidth - $valueWidth;
        $last = array_key_last($values);

        foreach ($values as $label => $value) {
            $isLast = $label === $last;

            $p->SetX($x);
            $p->SetFont('Helvetica', $isLast ? 'B' : '', $isLast ? 10 : 9);
            $p->Cell($labelWidth, 6, $p->t($label), 0, 0, 'R');
            $p->SetFont('Helvetica', 'B', $isLast ? 10 : 9);
            $p->Cell($valueWidth, 6, $p->t($value), 0, 1, 'R');
        }

        $p->Ln(2);
    }

    /** Due righe per le firme, in fondo al contenuto. */
    private function signatures(Sheet $p, string $left, string $right): void
    {
        $p->Ln(14);
        $y = $p->GetY();
        $width = 80.0;

        $p->SetDrawColor(...Sheet::INK);
        $p->Line(Sheet::MARGIN, $y, Sheet::MARGIN + $width, $y);
        $p->Line(Sheet::MARGIN + Sheet::WIDTH - $width, $y, Sheet::MARGIN + Sheet::WIDTH, $y);

        $p->SetFont('Helvetica', '', 8);
        $p->SetTextColor(...Sheet::MUTED);
        $p->SetXY(Sheet::MARGIN, $y + 1);
        $p->Cell($width, 4, $p->fit($left, $width), 0, 0, 'L');
        $p->SetX(Sheet::MARGIN + Sheet::WIDTH - $width);
        $p->Cell($width, 4, $p->fit($right, $width), 0, 0, 'L');
        $p->SetTextColor(...Sheet::INK);
    }
}
