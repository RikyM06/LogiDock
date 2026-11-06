<?php

declare(strict_types=1);

namespace LogiDock\Service;

use DateTimeImmutable;
use LogiDock\Core\Database;
use LogiDock\Core\ValidationException;
use LogiDock\Core\Validator;
use LogiDock\Model\InboundPallet;
use LogiDock\Model\NewPallet;
use LogiDock\Model\Product;
use LogiDock\Model\SupplierDocument;
use LogiDock\Repository\DuplicateEntryException;
use LogiDock\Repository\NumberSequenceRepository;
use LogiDock\Repository\PalletMovementRepository;
use LogiDock\Repository\PalletRepository;
use LogiDock\Repository\SupplierDocumentRepository;

/**
 * Arrivi di merce: un documento del fornitore con le sue date e uno o più bancali.
 *
 * receive() scrive tutto in UNA transazione (documento, bancali, codici, movimenti di ingresso):
 * se una riga è sbagliata o la posizione è piena non resta niente a metà.
 * Un arrivo registrato non si modifica: i movimenti sono uno storico. Una correzione si farà
 * con un movimento di rettifica, mai riscrivendo il passato.
 */
final class InboundService
{
    /** Righe (bancali) al massimo in un solo arrivo. */
    private const MAX_LINES = 200;

    public function __construct(
        private readonly Database $database,
        private readonly SupplierDocumentRepository $documents,
        private readonly PalletRepository $pallets,
        private readonly PalletMovementRepository $movements,
        private readonly NumberSequenceRepository $sequences,
        private readonly SupplierService $suppliers,
        private readonly ProductService $products,
        private readonly PalletTypeService $palletTypes,
        private readonly WarehouseLocationService $locations,
        private readonly SettingService $settings
    ) {
    }

    /**
     * @return list<SupplierDocument>
     */
    public function search(string $term = ''): array
    {
        return $this->documents->search(trim($term));
    }

    public function find(int $id): ?SupplierDocument
    {
        return $this->documents->findById($id);
    }

    /**
     * I bancali di un arrivo, con l'avviso di peso diverso dal calcolato (tolleranza dalle impostazioni).
     *
     * @return list<InboundPallet>
     */
    public function palletsOf(int $documentId): array
    {
        return $this->pallets->listByDocument(
            $documentId,
            $this->settings->get(SettingService::WEIGHT_TOLERANCE_PERCENT)
        );
    }

    /**
     * Registra un arrivo e restituisce l'id del documento.
     *
     * @param array<string, mixed> $input  dati grezzi del form: testata e "lines" (un array per bancale)
     * @param DateTimeImmutable|null $now  l'ora corrente; si passa solo nei test
     * @throws ValidationException con un errore per campo; per le righe la chiave è "lines.<n>.<campo>"
     */
    public function receive(array $input, int $userId, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');

        $header = new Validator($input);
        $supplierId = $header->requiredReference(
            'supplier_id',
            'Fornitore',
            $this->idsOf($this->suppliers->activeOnes())
        );
        $number = $header->requiredString('number', 'Numero del documento', 50);
        $documentDate = $header->requiredDate('document_date', 'Data del documento');
        $receivedDate = $header->requiredDate('received_date', 'Data di arrivo');
        $notes = $header->optionalString('notes', 'Note', 500);

        // Si confrontano solo date valide: nel formato AAAA-MM-GG l'ordine alfabetico è quello delle date.
        if ($this->isDate($receivedDate) && $receivedDate > $today) {
            $header->fail('received_date', 'La data di arrivo non può essere nel futuro.');
        }
        if ($this->isDate($documentDate) && $this->isDate($receivedDate) && $documentDate > $receivedDate) {
            $header->fail('document_date', 'La data del documento non può essere dopo quella di arrivo.');
        }

        $lines = $this->validateLines($input['lines'] ?? null, $receivedDate, $header);

        $header->assertValid();

        try {
            return $this->database->transaction(
                fn (): int => $this->store($supplierId, $number, $documentDate, $receivedDate, $notes, $lines, $userId, $now)
            );
        } catch (DuplicateEntryException) {
            // Lo decide il database (vincolo UNIQUE): vale anche con due utenti che salvano insieme.
            throw new ValidationException(['number' => 'Questo documento è già stato registrato per questo fornitore.']);
        }
    }

    /**
     * Controlla le righe e restituisce quelle valide. Le righe completamente vuote si ignorano
     * (il form ne mostra qualcuna in più). Gli errori finiscono nel validatore della testata.
     *
     * @return array<int|string, array{
     *     product_id: int, pallet_type_id: int, location_id: int, quantity: string, unit_price: string,
     *     recorded_weight_kg: string, lot_number: ?string, expiry_date: ?string
     * }>
     */
    private function validateLines(mixed $rawLines, string $receivedDate, Validator $header): array
    {
        if (!is_array($rawLines)) {
            $rawLines = [];
        }

        if (count($rawLines) > self::MAX_LINES) {
            $header->fail('lines', 'Un arrivo può avere al massimo ' . self::MAX_LINES . ' bancali.');

            return [];
        }

        $productIds = $this->idsOf(array_filter(
            $this->products->search(),
            static fn (Product $product): bool => $product->isActive
        ));
        $palletTypeIds = $this->idsOf($this->palletTypes->activeOnes());
        $locationIds = $this->idsOf($this->locations->activeOnes());

        $valid = [];
        foreach ($rawLines as $index => $rawLine) {
            if (!is_array($rawLine) || $this->isBlank($rawLine)) {
                continue;
            }

            $line = new Validator($rawLine);
            $values = [
                'product_id' => $line->requiredReference('product_id', 'Prodotto', $productIds),
                'pallet_type_id' => $line->requiredReference('pallet_type_id', 'Tipo di bancale', $palletTypeIds),
                'location_id' => $line->requiredReference('location_id', 'Posizione', $locationIds),
                // DECIMAL(12,3): 9 cifre intere. DECIMAL(12,4): 8 cifre intere.
                'quantity' => $line->requiredDecimal('quantity', 'Quantità', 9, 3, false),
                'unit_price' => $line->requiredDecimal('unit_price', 'Prezzo unitario (€)', 8, 4),
                'recorded_weight_kg' => $line->requiredDecimal('recorded_weight_kg', 'Peso segnato (kg)', 9, 3, false),
                'lot_number' => $line->optionalString('lot_number', 'Lotto', 50),
                'expiry_date' => $line->optionalDate('expiry_date', 'Scadenza'),
            ];

            if ($values['expiry_date'] !== null && $this->isDate($receivedDate) && $values['expiry_date'] < $receivedDate) {
                $line->fail('expiry_date', 'La scadenza è prima della data di arrivo: il prodotto sarebbe già scaduto.');
            }

            try {
                $line->assertValid();
                $valid[$index] = $values;
            } catch (ValidationException $e) {
                // Il nome del campo porta con sé la riga, così il form mostra l'errore sotto la cella giusta.
                foreach ($e->errors() as $field => $message) {
                    $header->fail('lines.' . $index . '.' . $field, $message);
                }
            }
        }

        if ($valid === [] && !$this->hasLineErrors($header)) {
            $header->fail('lines', 'Inserisci almeno un bancale.');
        }

        return $valid;
    }

    /**
     * La parte che scrive: gira dentro la transazione aperta da receive().
     *
     * @param array<int|string, array{
     *     product_id: int, pallet_type_id: int, location_id: int, quantity: string, unit_price: string,
     *     recorded_weight_kg: string, lot_number: ?string, expiry_date: ?string
     * }> $lines
     * @throws ValidationException se una posizione non ha abbastanza spazio
     * @throws DuplicateEntryException se il documento esiste già
     */
    private function store(
        int $supplierId,
        string $number,
        string $documentDate,
        string $receivedDate,
        ?string $notes,
        array $lines,
        int $userId,
        DateTimeImmutable $now
    ): int {
        // Prima il documento: se esiste già è il primo errore da mostrare (e quello più utile).
        // Il controllo di capacità viene dopo, ma conta solo i bancali già presenti: i nuovi non ci sono ancora.
        $documentId = $this->documents->insert($supplierId, $number, $documentDate, $receivedDate, $notes, $userId);

        $this->assertLocationsHaveRoom($lines);

        // Giorno di arrivo, a mezzanotte: l'avviso sui bancali fermi conta i giorni interi.
        $receivedAt = $receivedDate . ' 00:00:00';
        $year = (int) $now->format('Y');

        foreach ($lines as $line) {
            $code = sprintf('PAL-%d-%06d', $year, $this->sequences->next('pallet', $year));

            $palletId = $this->pallets->insert(new NewPallet(
                $code,
                $line['product_id'],
                $line['pallet_type_id'],
                $supplierId,
                $documentId,
                $line['location_id'],
                $line['quantity'],
                $line['unit_price'],
                $line['recorded_weight_kg'],
                $line['lot_number'],
                $line['expiry_date'],
                $receivedAt,
                $userId
            ));

            $this->movements->insertInbound(
                $palletId,
                $line['location_id'],
                $line['quantity'],
                $userId,
                $receivedAt,
                'Arrivo con documento ' . $number
            );
        }

        return $documentId;
    }

    /**
     * Nessuna posizione deve superare la sua capacità (propria o, se vuota, quella predefinita).
     * Il conteggio è fatto nella stessa transazione dell'inserimento. Resta una piccola finestra se due
     * persone riempiono la stessa posizione nello stesso istante: la capacità è un avviso pratico,
     * non un vincolo di integrità come il codice univoco.
     *
     * @param array<int|string, array{location_id: int}> $lines
     * @throws ValidationException
     */
    private function assertLocationsHaveRoom(array $lines): void
    {
        $byLocation = [];
        foreach ($lines as $index => $line) {
            $byLocation[$line['location_id']][] = $index;
        }

        $errors = [];
        foreach ($byLocation as $locationId => $indexes) {
            $location = $this->locations->find($locationId);
            if ($location === null) {
                continue;
            }

            $capacity = $location->capacity ?? $this->settings->getInt(SettingService::LOCATION_DEFAULT_CAPACITY);
            $free = max(0, $capacity - $this->pallets->countInLocation($locationId));

            if (count($indexes) > $free) {
                $errors['lines.' . $indexes[0] . '.location_id'] = sprintf(
                    'La posizione %s ha spazio per altri %d bancali, ne stai inserendo %d.',
                    $location->code,
                    $free,
                    count($indexes)
                );
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @param iterable<object> $items
     * @return list<int>
     */
    private function idsOf(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item->id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function isBlank(array $line): bool
    {
        foreach ($line as $value) {
            if (!is_scalar($value) || trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function hasLineErrors(Validator $validator): bool
    {
        try {
            $validator->assertValid();
        } catch (ValidationException $e) {
            foreach (array_keys($e->errors()) as $field) {
                if (str_starts_with($field, 'lines.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
