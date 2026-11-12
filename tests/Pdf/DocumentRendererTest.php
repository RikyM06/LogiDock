<?php

declare(strict_types=1);

namespace LogiDock\Tests\Pdf;

use InvalidArgumentException;
use LogiDock\Model\CompanyData;
use LogiDock\Model\DocumentLine;
use LogiDock\Model\Trip;
use LogiDock\Pdf\DocumentRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Il disegno dei documenti non usa il database: si prova con dati scritti qui. I PDF si generano
 * senza compressione ($compress = false), così il testo si può cercare dentro i byte.
 *
 * FPDF scrive in Windows-1252: una "à" nel PDF è il byte 0xE0, non i due byte dell'UTF-8.
 */
final class DocumentRendererTest extends TestCase
{
    private const ISSUED_AT = '2026-11-12 08:30:00';

    private DocumentRenderer $renderer;
    private CompanyData $company;

    protected function setUp(): void
    {
        $this->renderer = new DocumentRenderer(false);
        $this->company = new CompanyData('Ortofrutta Ferrari S.r.l.', 'Via Roma 1, Cagliari', 'IT01234567890', 'Documento generato da LogiDock');
    }

    // --- numeri ---

    /**
     * @return array<string, array{string, int, int, string}>
     */
    public static function decimals(): array
    {
        return [
            'importo con due decimali' => ['1234.50', 2, 2, '1.234,50'],
            'prezzo a quattro decimali con zeri finali' => ['7.8000', 2, 4, '7,80'],
            'prezzo che usa tutti i decimali' => ['0.1235', 2, 4, '0,1235'],
            'quantità intera' => ['96.000', 0, 3, '96'],
            'quantità con decimali' => ['12.500', 0, 3, '12,5'],
            'migliaia' => ['1234567.000', 0, 3, '1.234.567'],
            'meno di mille' => ['999.990', 2, 2, '999,99'],
            'zero' => ['0.00', 2, 2, '0,00'],
            'negativo' => ['-5.50', 2, 2, '-5,50'],
            'senza decimali nell\'origine' => ['150', 2, 2, '150,00'],
        ];
    }

    #[DataProvider('decimals')]
    public function testDecimalIsWrittenTheItalianWay(string $value, int $min, int $max, string $expected): void
    {
        self::assertSame($expected, $this->renderer->decimal($value, $min, $max));
    }

    public function testDecimalRejectsAnythingThatIsNotADecimalString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer->decimal('12,5', 0, 3);
    }

    // --- documenti ---

    public function testDdtContainsTheCustomerThePricesAndTheTotals(): void
    {
        $lines = [
            $this->line(1, 'Supermercato Il Girasole', 'PAL-2026-000001', '96.000', '610.500', '7.8000', '748.80'),
            $this->line(1, 'Supermercato Il Girasole', 'PAL-2026-000002', '96.000', '606.000', '7.8000', '748.80'),
        ];

        $pdf = $this->renderer->ddt($this->company, $this->trip(), $lines, 'DDT-2026-000007', self::ISSUED_AT);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('DDT-2026-000007', $pdf);
        self::assertStringContainsString('Ortofrutta Ferrari S.r.l.', $pdf, 'intestazione con i dati dell\'azienda');
        self::assertStringContainsString('Supermercato Il Girasole', $pdf);
        self::assertStringContainsString('PAL-2026-000002', $pdf);
        self::assertStringContainsString('7,80', $pdf, 'prezzo unitario');
        self::assertStringContainsString('1.497,60', $pdf, 'totale merce: 748,80 + 748,80');
        self::assertStringContainsString('1.216,5', $pdf, 'peso totale: 610,5 + 606 kg');
        self::assertStringContainsString('Emesso il 12/11/2026', $pdf);
    }

    public function testAccentsAndTheEuroSignSurviveTheConversionToWindows1252(): void
    {
        $lines = [$this->line(1, 'Caffè della Città', 'PAL-2026-000001', '10.000', '100.000', '2.0000', '20.00')];

        $pdf = $this->renderer->ddt($this->company, $this->trip(), $lines, 'DDT-2026-000001', self::ISSUED_AT);

        self::assertStringContainsString("Caff\xE8 della Citt\xE0", $pdf, 'è = 0xE8, à = 0xE0 in Windows-1252');
        self::assertStringContainsString("\x80", $pdf, 'il segno dell\'euro in Windows-1252 è 0x80');
    }

    public function testCharactersOutsideWindows1252BecomeAQuestionMarkInsteadOfBreakingTheDocument(): void
    {
        $lines = [$this->line(1, 'Cliente 東京', 'PAL-2026-000001', '10.000', '100.000', '2.0000', '20.00')];

        $pdf = $this->renderer->ddt($this->company, $this->trip(), $lines, 'DDT-2026-000001', self::ISSUED_AT);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('Cliente ??', $pdf);
    }

    public function testLoadListHasNoPricesAndGroupsTheStopsByCustomer(): void
    {
        $lines = [
            $this->line(1, 'Alfa Market', 'PAL-2026-000001', '30.000', '300.000', '2.5000', '75.00'),
            $this->line(2, 'Beta Bar', 'PAL-2026-000002', '30.000', '300.000', '2.5000', '75.00'),
        ];

        $pdf = $this->renderer->loadList($this->company, $this->trip(), $lines, 'CAR-2026-000001', self::ISSUED_AT);

        self::assertStringContainsString('Documento di carico', $pdf);
        self::assertStringContainsString('CAR-2026-000001', $pdf);
        self::assertStringContainsString('Alfa Market', $pdf);
        self::assertStringContainsString('Beta Bar', $pdf);
        self::assertStringContainsString('AB123CD', $pdf, 'la targa del mezzo');
        self::assertStringNotContainsString('2,50', $pdf, 'il documento di carico non mostra i prezzi');
        self::assertStringNotContainsString('75,00', $pdf);
    }

    public function testALongLoadListContinuesOnANewPageAndRepeatsTheHeader(): void
    {
        $lines = [];
        for ($i = 1; $i <= 45; $i++) {
            $lines[] = $this->line($i <= 25 ? 1 : 2, $i <= 25 ? 'Alfa Market' : 'Beta Bar', sprintf('PAL-2026-%06d', $i), '30.000', '300.000', '2.5000', '75.00');
        }

        $pdf = $this->renderer->loadList($this->company, $this->trip(), $lines, 'CAR-2026-000001', self::ISSUED_AT);

        self::assertGreaterThanOrEqual(2, preg_match_all('/\/Type \/Page\b/', $pdf));
        self::assertStringContainsString('Pagina 1 di 2', $pdf, '{nb} è sostituito dal numero reale di pagine');
        self::assertStringContainsString('Pagina 2 di 2', $pdf);
        // Nel PDF le parentesi dentro un testo si scrivono con la barra: "Peso \(kg\)".
        self::assertSame(2, substr_count($pdf, 'Peso \(kg\)'), 'l\'intestazione della tabella si ripete su ogni pagina');
        self::assertStringContainsString('PAL-2026-000045', $pdf, 'nessuna riga si perde nel cambio pagina');
        self::assertStringContainsString('13.500', $pdf, 'peso totale: 45 x 300 kg');
    }

    public function testTooLongTextIsCutWithDotsInsteadOfOverflowingTheCell(): void
    {
        $line = new DocumentLine(
            1, 'Alfa Market', null, null, 'ORD-2026-000001', 'PAL-2026-000001', 'PAS-500',
            str_repeat('Descrizione lunghissima ', 20), 'pz', null, '10.000', '100.000', '2.0000', '20.00'
        );

        $pdf = $this->renderer->ddt($this->company, $this->trip(), [$line], 'DDT-2026-000001', self::ISSUED_AT);

        self::assertStringContainsString('...', $pdf);
        self::assertStringNotContainsString(str_repeat('Descrizione lunghissima ', 20), $pdf);
    }

    public function testCompensationShowsTheCarrierAndTheFixedFee(): void
    {
        $pdf = $this->renderer->compensation($this->company, $this->trip(), 'COM-2026-000003', self::ISSUED_AT);

        self::assertStringContainsString('Compenso del trasportatore', $pdf);
        self::assertStringContainsString('COM-2026-000003', $pdf);
        self::assertStringContainsString('Trasporti Pinna', $pdf);
        self::assertStringContainsString('IT09876543210', $pdf);
        self::assertStringContainsString('120,00', $pdf);
    }

    public function testCompensationNeedsACarrierWithAFee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer->compensation($this->company, $this->trip(carrierName: null, carrierFee: null), 'COM-2026-000001', self::ISSUED_AT);
    }

    public function testDdtNeedsAtLeastOneLine(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer->ddt($this->company, $this->trip(), [], 'DDT-2026-000001', self::ISSUED_AT);
    }

    public function testMissingCompanyDataIsFlaggedInsteadOfLeavingAnEmptyHeader(): void
    {
        $lines = [$this->line(1, 'Alfa Market', 'PAL-2026-000001', '10.000', '100.000', '2.0000', '20.00')];

        $pdf = $this->renderer->ddt(new CompanyData('', '', '', ''), $this->trip(), $lines, 'DDT-2026-000001', self::ISSUED_AT);

        self::assertStringContainsString('dati dell\'azienda non impostati', $pdf);
    }

    public function testTheIssueTimeIsShownInTheApplicationTimeZone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Rome');

        try {
            $lines = [$this->line(1, 'Alfa Market', 'PAL-2026-000001', '10.000', '100.000', '2.0000', '20.00')];
            // 08:30 UTC = 09:30 a Roma (novembre, ora solare).
            $pdf = $this->renderer->ddt($this->company, $this->trip(), $lines, 'DDT-2026-000001', self::ISSUED_AT);
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertStringContainsString('Emesso il 12/11/2026 09:30', $pdf);
    }

    private function line(
        int $customerId,
        string $customerName,
        string $palletCode,
        string $quantity,
        string $weightKg,
        string $unitPrice,
        string $lineTotal
    ): DocumentLine {
        return new DocumentLine(
            $customerId, $customerName, 'IT11122233344', 'Via Torino 5', 'ORD-2026-00000' . $customerId, $palletCode,
            'ACQ-050', 'Acqua naturale 0,5 l', 'ct', 'L2611', $quantity, $weightKg, $unitPrice, $lineTotal
        );
    }

    private function trip(?string $carrierName = 'Trasporti Pinna', ?string $carrierFee = '120.00'): Trip
    {
        return new Trip(
            1, 'VIA-2026-000001', 'Giro Nord', 'AB123CD', 'Iveco Stralis', 'Semirimorchio', $carrierName, $carrierFee,
            '2026-11-13', 'in_transit', 2, '1216.500', 1, 1, 0, 'IT09876543210'
        );
    }
}
