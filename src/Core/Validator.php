<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Legge e controlla i campi di un form. Ogni metodo restituisce il valore già pulito
 * (senza spazi, numeri normalizzati) e, se il campo non va bene, annota l'errore.
 * Alla fine assertValid() lancia UNA ValidationException con tutti gli errori insieme,
 * così l'utente li corregge in un colpo solo.
 *
 * Quando un campo obbligatorio è sbagliato il metodo restituisce un valore finto ('' o 0):
 * non viene mai usato, perché assertValid() interrompe prima.
 */
final class Validator
{
    /** @var array<string, string> campo => messaggio */
    private array $errors = [];

    /**
     * @param array<string, mixed> $input dati grezzi del form
     */
    public function __construct(private readonly array $input)
    {
    }

    public function requiredString(string $field, string $label, int $max): string
    {
        $value = $this->raw($field);

        if ($value === '') {
            $this->fail($field, 'Il campo «' . $label . '» è obbligatorio.');

            return '';
        }

        return $this->checkLength($field, $label, $value, $max);
    }

    /**
     * Codice identificativo (targa, codice posizione...): lo si scrive in maiuscolo, così "ab123cd"
     * e "AB123CD" sono la stessa cosa. Ammessi lettere, numeri, punto, trattino e underscore.
     */
    public function requiredCode(string $field, string $label, int $max): string
    {
        $value = strtoupper($this->requiredString($field, $label, $max));

        if ($value !== '' && preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $value) !== 1) {
            $this->fail($field, 'Il campo «' . $label . '» può contenere solo lettere, numeri, punto, trattino e underscore.');
        }

        return $value;
    }

    public function optionalString(string $field, string $label, int $max): ?string
    {
        $value = $this->raw($field);

        return $value === '' ? null : $this->checkLength($field, $label, $value, $max);
    }

    public function optionalEmail(string $field, string $label): ?string
    {
        $value = $this->optionalString($field, $label, 255);

        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($field, 'Il campo «' . $label . '» non è un indirizzo e-mail valido.');
        }

        return $value;
    }

    public function requiredInt(string $field, string $label, int $min, int $max): int
    {
        $value = $this->raw($field);

        if ($value === '') {
            $this->fail($field, 'Il campo «' . $label . '» è obbligatorio.');

            return 0;
        }

        return $this->checkInt($field, $label, $value, $min, $max);
    }

    public function optionalInt(string $field, string $label, int $min, int $max): ?int
    {
        $value = $this->raw($field);

        return $value === '' ? null : $this->checkInt($field, $label, $value, $min, $max);
    }

    /**
     * Numero positivo con virgola o punto ("12,5" o "12.5"), restituito come stringa nel formato
     * di DECIMAL ("12.50"). Non passa mai da float: niente errori di arrotondamento.
     *
     * @param int $integerDigits cifre massime prima della virgola
     * @param int $scale         cifre decimali (quelle di DECIMAL(p,s))
     */
    public function requiredDecimal(
        string $field,
        string $label,
        int $integerDigits,
        int $scale,
        bool $allowZero = true
    ): string {
        $value = str_replace(',', '.', $this->raw($field));

        if ($value === '') {
            $this->fail($field, 'Il campo «' . $label . '» è obbligatorio.');

            return '0';
        }

        $pattern = '/^(\d{1,' . $integerDigits . '})(?:\.(\d{1,' . $scale . '}))?$/';
        if (preg_match($pattern, $value, $matches) !== 1) {
            $this->fail(
                $field,
                'Il campo «' . $label . '» deve essere un numero positivo (es. 12,5) con al massimo '
                . $scale . ' decimali.'
            );

            return '0';
        }

        $normalized = ((string) (int) $matches[1]) . '.' . str_pad($matches[2] ?? '', $scale, '0');

        // "0.00" è zero se, tolti il punto e gli zeri iniziali, non resta nessuna cifra.
        if (!$allowZero && ltrim(str_replace('.', '', $normalized), '0') === '') {
            $this->fail($field, 'Il campo «' . $label . '» deve essere maggiore di zero.');
        }

        return $normalized;
    }

    /**
     * Valore da un elenco chiuso (es. le unità di misura).
     *
     * @param list<string> $allowed
     */
    public function requiredChoice(string $field, string $label, array $allowed): string
    {
        $value = $this->raw($field);

        if (!in_array($value, $allowed, true)) {
            $this->fail($field, 'Scegli un valore valido per «' . $label . '».');

            return '';
        }

        return $value;
    }

    /**
     * Riferimento facoltativo a un altro record (es. il giro di un cliente). L'id deve essere tra
     * quelli ammessi: così non si può agganciare un record inesistente o disattivato.
     *
     * @param list<int> $allowedIds
     */
    public function optionalReference(string $field, string $label, array $allowedIds): ?int
    {
        $value = $this->raw($field);
        if ($value === '') {
            return null;
        }

        if (!ctype_digit($value) || !in_array((int) $value, $allowedIds, true)) {
            $this->fail($field, 'Scegli un valore valido per «' . $label . '».');

            return null;
        }

        return (int) $value;
    }

    /**
     * Come optionalReference(), ma il campo è obbligatorio.
     *
     * @param list<int> $allowedIds
     */
    public function requiredReference(string $field, string $label, array $allowedIds): int
    {
        if ($this->raw($field) === '') {
            $this->fail($field, 'Il campo «' . $label . '» è obbligatorio.');

            return 0;
        }

        return $this->optionalReference($field, $label, $allowedIds) ?? 0;
    }

    /**
     * Data nel formato AAAA-MM-GG (quello dei campi <input type="date">), restituita uguale.
     * Rifiuta le date che non esistono, come il 31 febbraio.
     */
    public function requiredDate(string $field, string $label): string
    {
        $value = $this->raw($field);

        if ($value === '') {
            $this->fail($field, 'Il campo «' . $label . '» è obbligatorio.');

            return '';
        }

        return $this->checkDate($field, $label, $value);
    }

    public function optionalDate(string $field, string $label): ?string
    {
        $value = $this->raw($field);

        return $value === '' ? null : $this->checkDate($field, $label, $value);
    }

    public function fail(string $field, string $message): void
    {
        // Per ogni campo si mostra solo il primo errore.
        $this->errors[$field] ??= $message;
    }

    /**
     * @throws ValidationException se almeno un campo non è valido
     */
    public function assertValid(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
    }

    /** Valore grezzo come stringa senza spazi; un campo mancante o un array (field[]=x) vale "". */
    private function raw(string $field): string
    {
        $value = $this->input[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function checkDate(string $field, string $label, string $value): string
    {
        // Il "!" azzera l'ora; il confronto con format() scarta le date inesistenti (31/02 diventerebbe 03/03).
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->fail($field, 'Il campo «' . $label . '» non è una data valida.');
        }

        return $value;
    }

    private function checkLength(string $field, string $label, string $value, int $max): string
    {
        if (mb_strlen($value) > $max) {
            $this->fail($field, 'Il campo «' . $label . '» può essere lungo al massimo ' . $max . ' caratteri.');
        }

        return $value;
    }

    private function checkInt(string $field, string $label, string $value, int $min, int $max): int
    {
        if (preg_match('/^\d{1,9}$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            $this->fail(
                $field,
                'Il campo «' . $label . '» deve essere un numero intero tra ' . $min . ' e ' . $max . '.'
            );

            return 0;
        }

        return (int) $value;
    }
}
