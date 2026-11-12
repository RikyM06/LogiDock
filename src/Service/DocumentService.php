<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use LogiDock\Core\Database;
use LogiDock\Model\CompanyData;
use LogiDock\Model\DocumentLine;
use LogiDock\Model\TransportDocument;
use LogiDock\Model\Trip;
use LogiDock\Pdf\DocumentRenderer;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\TransportDocumentRepository;

/**
 * Emette i documenti di un viaggio che parte e li rende disponibili.
 *
 * Alla partenza escono, nella stessa transazione, tre tipi di documento:
 *  - un documento di carico per il camion (senza prezzi);
 *  - un DDT per ogni cliente del viaggio, con i prezzi di vendita;
 *  - il prospetto del compenso del trasportatore (solo se il viaggio ha un trasportatore con un compenso).
 *
 * Ogni documento ha un numero progressivo per tipo e anno (number_sequences, senza buchi) e il PDF viene
 * salvato nel database. Un documento emesso non si rigenera mai: si rilegge sempre lo stesso PDF, anche
 * se in seguito cambiano i prezzi, i dati del cliente o quelli dell'azienda.
 *
 * Chi decide QUANDO emettere (la partenza) è TripDepartureService; qui ci sono solo le regole di
 * emissione. Il disegno del foglio sta in Pdf\DocumentRenderer, che non conosce il database.
 */
final class DocumentService
{
    public function __construct(
        private readonly Database $database,
        private readonly TransportDocumentRepository $documents,
        private readonly NumberSequenceRepository $sequences,
        private readonly SettingService $settings,
        private readonly DocumentRenderer $renderer
    ) {
    }

    /**
     * Emette tutti i documenti di un viaggio.
     *
     * @param DateTimeImmutable $now ora di emissione (si passa nei test; l'anno del numero è quello locale)
     * @return list<TransportDocument> i documenti emessi, nell'ordine: carico, DDT, compenso
     * @throws LogicException se le righe del viaggio non sono tutte leggibili (una riga senza prezzo)
     */
    public function issueForTrip(Trip $trip, int $userId, DateTimeImmutable $now): array
    {
        return $this->database->transaction(function () use ($trip, $userId, $now): array {
            $lines = $this->documents->linesOfTrip($trip->id);

            // Il JOIN sulle righe delle richieste è "stretto": se un bancale manca qui, un documento
            // uscirebbe incompleto. Meglio fermare la partenza.
            if (count($lines) !== $trip->palletCount) {
                throw new LogicException(sprintf(
                    'Il viaggio %s ha %d bancali ma solo %d righe di documento: manca la riga della richiesta di qualche bancale.',
                    $trip->code,
                    $trip->palletCount,
                    count($lines)
                ));
            }

            $company = $this->company();
            $year = (int) $now->format('Y');
            $issuedAt = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $issued = [];

            $number = $this->sequences->next(TransportDocument::TYPE_LOAD_LIST, $year);
            $issued[] = $this->save(
                $trip,
                null,
                TransportDocument::TYPE_LOAD_LIST,
                $year,
                $number,
                $issuedAt,
                $userId,
                fn (string $code): string => $this->renderer->loadList($company, $trip, $lines, $code, $issuedAt)
            );

            foreach ($this->groupByCustomer($lines) as $customerId => $customerLines) {
                $number = $this->sequences->next(TransportDocument::TYPE_DDT, $year);
                $issued[] = $this->save(
                    $trip,
                    $customerId,
                    TransportDocument::TYPE_DDT,
                    $year,
                    $number,
                    $issuedAt,
                    $userId,
                    fn (string $code): string => $this->renderer->ddt($company, $trip, $customerLines, $code, $issuedAt)
                );
            }

            if ($trip->carrierName !== null && $trip->carrierFee !== null) {
                $number = $this->sequences->next(TransportDocument::TYPE_COMPENSATION, $year);
                $issued[] = $this->save(
                    $trip,
                    null,
                    TransportDocument::TYPE_COMPENSATION,
                    $year,
                    $number,
                    $issuedAt,
                    $userId,
                    fn (string $code): string => $this->renderer->compensation($company, $trip, $code, $issuedAt)
                );
            }

            return $issued;
        });
    }

    /**
     * I documenti di un viaggio. Il prospetto del compenso lo vede solo l'admin.
     *
     * @return list<TransportDocument>
     */
    public function forTrip(int $tripId, bool $isAdmin): array
    {
        return $this->documents->forTrip($tripId, $isAdmin);
    }

    public function find(int $id): ?TransportDocument
    {
        return $this->documents->find($id);
    }

    /** Il PDF di un documento emesso, identico a ogni lettura. */
    public function pdf(int $id): ?string
    {
        return $this->documents->pdfOf($id);
    }

    /**
     * Disegna il PDF e lo salva. $render riceve il codice stampabile del documento (DDT-2026-000001).
     *
     * @param callable(string): string $render
     */
    private function save(
        Trip $trip,
        ?int $customerId,
        string $type,
        int $year,
        int $number,
        string $issuedAt,
        int $userId,
        callable $render
    ): TransportDocument {
        $pdf = $render(TransportDocument::formatCode($type, $year, $number));
        $id = $this->documents->insert($trip->id, $customerId, $type, $year, $number, $issuedAt, $pdf, $userId);

        return $this->documents->find($id)
            ?? throw new LogicException('Il documento ' . $id . ' è stato salvato ma non si legge più.');
    }

    /**
     * Le righe divise per cliente, mantenendo l'ordine (sono già ordinate per cliente).
     *
     * @param list<DocumentLine> $lines
     * @return array<int, non-empty-list<DocumentLine>> id del cliente => righe
     */
    private function groupByCustomer(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $groups[$line->customerId][] = $line;
        }

        return $groups;
    }

    private function company(): CompanyData
    {
        return new CompanyData(
            $this->settings->get(SettingService::COMPANY_NAME),
            $this->settings->get(SettingService::COMPANY_ADDRESS),
            $this->settings->get(SettingService::COMPANY_VAT),
            $this->settings->get(SettingService::COMPANY_FOOTER)
        );
    }
}
