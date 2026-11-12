<?php

declare(strict_types=1);

namespace LogiDock\Pdf;

use FPDF;
use LogiDock\Model\CompanyData;

/**
 * Un foglio A4 di LogiDock: FPDF con l'intestazione dell'azienda e il piè di pagina già pronti.
 *
 * FPDF scrive i testi nella codifica Windows-1252 (quella di Word per l'Europa occidentale: ha accenti
 * ed euro), mentre l'applicazione lavora in UTF-8: ogni testo che arriva dai dati passa da t() o da fit().
 * Il cambio di codifica NON si fa dentro Cell(): FPDF la richiama da sola in MultiCell e nel piè di
 * pagina, e il testo verrebbe convertito due volte.
 *
 * Header() e Footer() sono chiamati da FPDF a ogni nuova pagina (il nome con la maiuscola è il suo).
 */
final class Sheet extends FPDF
{
    /** Margine laterale e superiore, in millimetri. */
    public const MARGIN = 15.0;

    /** Spazio lasciato in fondo a ogni pagina per il piè di pagina. */
    public const BOTTOM = 26.0;

    /** Larghezza utile del foglio (210 mm meno i due margini). */
    public const WIDTH = 180.0;

    /** Colori come terne RGB: il verde oliva del sito, il grigio dei testi secondari, il crema delle intestazioni. */
    public const BRAND = [65, 102, 28];
    public const MUTED = [90, 90, 90];
    public const INK = [30, 30, 30];
    public const SOFT = [236, 240, 226];
    public const BORDER = [190, 190, 190];

    public function __construct(
        private readonly CompanyData $company,
        private readonly string $docTitle,
        private readonly string $docCode,
        private readonly string $docDate,
        bool $compress = true
    ) {
        parent::__construct('P', 'mm', 'A4');

        $this->SetMargins(self::MARGIN, 12, self::MARGIN);
        $this->SetAutoPageBreak(true, self::BOTTOM);
        $this->SetCompression($compress);
        // Sostituisce {nb} nel piè di pagina con il numero totale di pagine.
        $this->AliasNbPages();
        $this->SetTitle($docTitle . ' ' . $docCode, true);
        $this->SetCreator('LogiDock', true);
        $this->SetAuthor($company->name !== '' ? $company->name : 'LogiDock', true);
    }

    /**
     * Da UTF-8 alla codifica di FPDF. Un carattere che Windows-1252 non ha (un ideogramma, un emoji)
     * diventa "?": meglio un punto interrogativo che un documento che non si apre.
     */
    public function t(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    /**
     * Il testo convertito e, se non entra in una cella larga $width, accorciato con "...".
     * Il font va scelto PRIMA di chiamarla: la larghezza dipende da quello.
     */
    public function fit(string $text, float $width): string
    {
        $converted = $this->t($text);
        // Dentro una cella FPDF lascia 1 mm di margine per lato.
        $room = $width - 2;

        if ($this->GetStringWidth($converted) <= $room) {
            return $converted;
        }

        while ($converted !== '' && $this->GetStringWidth($converted . '...') > $room) {
            $converted = substr($converted, 0, -1);
        }

        return $converted . '...';
    }

    public function Header(): void
    {
        $name = $this->company->name !== '' ? $this->company->name : '(dati dell\'azienda non impostati)';

        $this->SetXY(self::MARGIN, 12);
        $this->SetTextColor(...self::INK);
        $this->SetFont('Helvetica', 'B', 13);
        $this->Cell(95, 6, $this->fit($name, 95), 0, 2);
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...self::MUTED);

        if ($this->company->address !== '') {
            $this->Cell(95, 4.5, $this->fit($this->company->address, 95), 0, 2);
        }
        if ($this->company->vat !== '') {
            $this->Cell(95, 4.5, $this->fit('P. IVA ' . $this->company->vat, 95), 0, 2);
        }

        $this->SetXY(110, 12);
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetTextColor(...self::BRAND);
        $this->Cell(85, 7, $this->fit($this->docTitle, 85), 0, 2, 'R');
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(...self::INK);
        $this->Cell(85, 5, $this->t('N. ' . $this->docCode), 0, 2, 'R');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...self::MUTED);
        $this->Cell(85, 5, $this->t('Emesso il ' . $this->docDate), 0, 2, 'R');

        $this->SetDrawColor(...self::BRAND);
        $this->SetLineWidth(0.5);
        $this->Line(self::MARGIN, 34, self::MARGIN + self::WIDTH, 34);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(...self::BORDER);
        $this->SetTextColor(...self::INK);

        $this->SetY(40);
    }

    public function Footer(): void
    {
        $top = -self::BOTTOM + 3;

        $this->SetY($top);
        $this->SetDrawColor(...self::BORDER);
        $this->Line(self::MARGIN, $this->GetY(), self::MARGIN + self::WIDTH, $this->GetY());

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...self::MUTED);

        $this->SetXY(self::MARGIN, $top + 1.5);
        if ($this->company->footer !== '') {
            // 'L' = allineato a sinistra: il predefinito di MultiCell è "giustificato" e allarga gli spazi.
            $this->MultiCell(145, 3.6, $this->t($this->company->footer), 0, 'L');
        }

        $this->SetXY(self::MARGIN + 145, $top + 1.5);
        $this->Cell(35, 4, 'Pagina ' . $this->PageNo() . ' di {nb}', 0, 0, 'R');

        $this->SetTextColor(...self::INK);
    }
}
