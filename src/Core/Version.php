<?php

declare(strict_types=1);

namespace LogiDock\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Versione dell'applicazione nel formato MAGGIORE.MINORE.FASE.COMMIT (es. 0.0.10.0).
 *
 * - FASE: la fase di sviluppo in corso (per ora il terzo numero segue la roadmap);
 * - COMMIT: cresce di uno a ogni commit e riparte da 0 quando inizia una nuova fase.
 *
 * Non è "semantic versioning" (che ha tre numeri e regole proprie): è una numerazione interna,
 * per questo sta nel file VERSION e non in composer.json.
 */
final readonly class Version
{
    public function __construct(
        public int $major,
        public int $minor,
        public int $phase,
        public int $commit
    ) {
        foreach ([$major, $minor, $phase, $commit] as $number) {
            if ($number < 0) {
                throw new InvalidArgumentException('I numeri di versione non possono essere negativi.');
            }
        }
    }

    /**
     * @throws InvalidArgumentException se il testo non è nel formato N.N.N.N
     */
    public static function parse(string $text): self
    {
        if (preg_match('/^(\d{1,9})\.(\d{1,9})\.(\d{1,9})\.(\d{1,9})$/', trim($text), $m) !== 1) {
            throw new InvalidArgumentException('Versione non valida: "' . trim($text) . '" (atteso MAGGIORE.MINORE.FASE.COMMIT).');
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]);
    }

    /**
     * @throws RuntimeException se il file non si legge
     */
    public static function fromFile(string $path): self
    {
        $text = is_readable($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new RuntimeException('Non riesco a leggere il file della versione: ' . $path);
        }

        return self::parse($text);
    }

    /** La versione del commit successivo: +1 all'ultimo numero. */
    public function nextCommit(): self
    {
        return new self($this->major, $this->minor, $this->phase, $this->commit + 1);
    }

    /**
     * La prima versione di una nuova fase: terzo numero = fase, ultimo numero da capo.
     *
     * @throws InvalidArgumentException se la fase non è successiva a quella attuale
     */
    public function startPhase(int $phase): self
    {
        if ($phase <= $this->phase) {
            throw new InvalidArgumentException(
                'La nuova fase deve essere successiva a quella attuale (' . $this->phase . ').'
            );
        }

        return new self($this->major, $this->minor, $phase, 0);
    }

    public function toString(): string
    {
        return $this->major . '.' . $this->minor . '.' . $this->phase . '.' . $this->commit;
    }
}
