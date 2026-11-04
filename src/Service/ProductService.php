<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\ValidationException;
use LogiDock\Model\Product;
use LogiDock\Model\ProductData;
use LogiDock\Repository\DuplicateEntryException;
use LogiDock\Repository\ProductRepository;

/**
 * Regole di business dell'anagrafica prodotti: validazione dell'input, unicità del codice,
 * disattivazione al posto della cancellazione (i prodotti hanno bancali collegati).
 */
final class ProductService
{
    /** Unità di misura ammesse: sigla => nome. La sigla è quella salvata nel database. */
    public const UNITS = [
        'pz' => 'Pezzi',
        'ct' => 'Cartoni',
        'cf' => 'Confezioni',
        'kg' => 'Chilogrammi',
        'lt' => 'Litri',
        'm' => 'Metri',
    ];

    public function __construct(private readonly ProductRepository $products)
    {
    }

    /**
     * @return list<Product>
     */
    public function search(string $term = ''): array
    {
        return $this->products->search(trim($term));
    }

    public function find(int $id): ?Product
    {
        return $this->products->findById($id);
    }

    /**
     * @param array<string, mixed> $input dati grezzi del form
     * @throws ValidationException
     */
    public function create(array $input): int
    {
        $data = $this->validate($input, null);

        try {
            return $this->products->insert($data);
        } catch (DuplicateEntryException) {
            // Un altro utente ha preso lo stesso codice tra il controllo e l'INSERT: lo decide il database.
            throw new ValidationException(['code' => 'Esiste già un prodotto con questo codice.']);
        }
    }

    /**
     * @param array<string, mixed> $input dati grezzi del form
     * @throws ValidationException
     */
    public function update(int $id, array $input): void
    {
        $data = $this->validate($input, $id);

        try {
            $this->products->update($id, $data);
        } catch (DuplicateEntryException) {
            throw new ValidationException(['code' => 'Esiste già un prodotto con questo codice.']);
        }
    }

    public function deactivate(int $id): void
    {
        $this->products->setActive($id, false);
    }

    public function activate(int $id): void
    {
        $this->products->setActive($id, true);
    }

    /**
     * @param array<string, mixed> $input
     * @param int|null             $currentId id del prodotto che si sta modificando (null se nuovo)
     * @throws ValidationException
     */
    private function validate(array $input, ?int $currentId): ProductData
    {
        $errors = [];

        // Il codice si normalizza in maiuscolo: "acq-050" e "ACQ-050" sono lo stesso prodotto.
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if ($code === '') {
            $errors['code'] = 'Il codice è obbligatorio.';
        } elseif (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,49}$/', $code) !== 1) {
            $errors['code'] = 'Usa solo lettere, numeri, punto, trattino e underscore (massimo 50 caratteri).';
        } else {
            $existing = $this->products->findByCode($code);
            if ($existing !== null && $existing->id !== $currentId) {
                $errors['code'] = 'Esiste già un prodotto con questo codice.';
            }
        }

        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            $errors['description'] = 'La descrizione è obbligatoria.';
        } elseif (mb_strlen($description) > 255) {
            $errors['description'] = 'La descrizione può essere lunga al massimo 255 caratteri.';
        }

        $unit = (string) ($input['unit_of_measure'] ?? '');
        if (!isset(self::UNITS[$unit])) {
            $errors['unit_of_measure'] = 'Scegli un\'unità di misura dall\'elenco.';
        }

        $weight = self::normalizeWeight((string) ($input['unit_weight_kg'] ?? ''));
        if ($weight === null) {
            $errors['unit_weight_kg'] = 'Inserisci un peso valido in kg (es. 12,5), con al massimo 3 decimali.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new ProductData($code, $description, $unit, (string) $weight);
    }

    /**
     * Accetta "12,5" o "12.5" e restituisce "12.500" (il formato di DECIMAL(10,3)), oppure null se
     * non è un numero valido. Lavora sulla stringa, senza mai convertire in float.
     */
    public static function normalizeWeight(string $raw): ?string
    {
        $value = str_replace(',', '.', trim($raw));

        if (preg_match('/^(\d{1,7})(?:\.(\d{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((string) (int) $matches[1]) . '.' . str_pad($matches[2] ?? '', 3, '0');
    }
}
